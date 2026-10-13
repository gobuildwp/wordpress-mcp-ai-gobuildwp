<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPPilot_Test_Rest_Request;
use WPPilot_Test_Rest_Response;
use WPPilot_Test_State;

use function WPPilot\Mcp\SkillResources\build_entries;
use function WPPilot\Mcp\SkillResources\dispatch;
use function WPPilot\Mcp\SkillResources\entries;
use function WPPilot\Mcp\SkillResources\is_valid_skill_name;
use function WPPilot\Mcp\SkillResources\legacy_resource_read;
use function WPPilot\Mcp\SkillResources\legacy_resources_list;
use function WPPilot\Mcp\SkillResources\render_skill_file;
use function WPPilot\Mcp\with_skills_extension;
use function WPPilot\Mcp\build_capabilities;

require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__, 2) . '/includes/skills/cpt.php';
require_once dirname(__DIR__, 2) . '/includes/skills/parser.php';
require_once dirname(__DIR__, 2) . '/includes/skills/sources.php';
require_once dirname(__DIR__, 2) . '/includes/skills/built-in.php';
require_once dirname(__DIR__, 2) . '/includes/mcp/skill-resources.php';

/**
 * Skills and industry briefs served as `skill://` resources (SEP-2640).
 *
 * The visibility rule under test is the catalog's own: a skill an agent could not find through
 * discover-abilities must not be readable as a resource either.
 */
final class SkillResourcesTest extends TestCase
{
    protected function setUp(): void
    {
        WPPilot_Test_State::reset();
        foreach (['wppilot_skill_lookup_sources', 'wppilot_industry_briefs', 'wppilot_pro_status', 'wppilot_mcp_skill_resources'] as $hook) {
            remove_all_filters($hook);
        }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    /**
     * Register a fake skill source, as a companion plugin would.
     *
     * @param list<array<string, mixed>> $skills
     */
    private static function source(array $skills, string $id = 'test', int $priority = 5): void
    {
        add_filter('wppilot_skill_lookup_sources', static function (array $sources) use ($skills, $id, $priority): array {
            $sources[$id] = ['id' => $id, 'priority' => $priority, 'label' => 'Test', 'loader' => static fn(): array => $skills];

            return $sources;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function skill(string $slug, array $overrides = []): array
    {
        return $overrides + [
            'slug' => $slug,
            'name' => ucfirst($slug),
            'description' => 'Use when testing ' . $slug . '.',
            'content' => "# {$slug}\n\nDo the thing.",
            'enable_prompt' => false,
            'enable_agentic' => true,
        ];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'simple' => ['landing-page', true];
        yield 'digits' => ['h1-fixer2', true];
        yield 'underscore' => ['custom_page', false];
        yield 'leading hyphen' => ['-page', false];
        yield 'double hyphen' => ['a--b', false];
        yield 'upper case' => ['Page', false];
        yield 'encoded unicode' => ['caf%c3%a9', false];
        yield 'too long' => [str_repeat('a', 65), false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('names')]
    public function testSkillNamesFollowTheAgentSkillsRules(string $name, bool $valid): void
    {
        self::assertSame($valid, is_valid_skill_name($name));
    }

    public function testFrontmatterInTheFileMatchesTheListing(): void
    {
        $file = render_skill_file(['name' => 'x', 'description' => 'Tricky: "quoted" text', 'enable_prompt' => false], 'Body');

        self::assertStringStartsWith("---\nname: \"x\"\ndescription: \"Tricky: \\\"quoted\\\" text\"\nenable_prompt: false\n---\n\nBody", $file['text']);
        self::assertSame(['name' => 'x', 'description' => 'Tricky: "quoted" text', 'enable_prompt' => false], $file['frontmatter']);
    }

    public function testEntriesUseSkillMdUrisAndSkipWhatTheCatalogWouldHide(): void
    {
        $entries = build_entries([
            self::skill('landing-page'),
            self::skill('no-description', ['description' => '  ']),
            self::skill('empty-body', ['content' => '']),
            self::skill('custom_underscore'),
            self::skill('landing-page', ['content' => 'A shadowed copy from a later source.']),
        ], [
            ['slug' => 'bakery_cafe', 'title' => 'Bakery', 'description' => 'A bakery.', 'text' => 'Brief body'],
            ['slug' => 'plumbing', 'title' => 'Plumbing', 'description' => '', 'text' => 'Brief body'],
        ]);

        self::assertSame([
            'skill://landing-page/SKILL.md',
            'skill://industry-briefs/bakery-cafe/SKILL.md',
            'skill://industry-briefs/plumbing/SKILL.md',
        ], array_column($entries, 'uri'));
        self::assertStringContainsString('Do the thing.', $entries[0]['text']);
        // A brief without a description falls back to its title, since SEP-2640 requires one.
        self::assertSame('Plumbing', $entries[2]['frontmatter']['description']);
        // The final URI segment is the declared name.
        self::assertSame('bakery-cafe', $entries[1]['frontmatter']['name']);
    }

    public function testRuntimeEntriesRespectAgenticVisibility(): void
    {
        self::source([
            self::skill('visible'),
            self::skill('prompt-only', ['enable_agentic' => false, 'enable_prompt' => true]),
        ]);

        $uris = array_column(entries(), 'uri');

        self::assertContains('skill://visible/SKILL.md', $uris);
        self::assertNotContains('skill://prompt-only/SKILL.md', $uris);
    }

    public function testBundledIndustryBriefsAreServedButLockedProBriefsAreNot(): void
    {
        add_filter('wppilot_industry_briefs', static function (array $briefs): array {
            $briefs[] = ['slug' => 'members-club', 'industry' => 'Clubs', 'title' => 'Members club', 'description' => 'Pro brief.', 'pro' => true, 'body' => 'Pro body'];

            return $briefs;
        });

        $uris = array_column(entries(), 'uri');
        self::assertContains('skill://industry-briefs/bakery-cafe/SKILL.md', $uris);
        self::assertNotContains('skill://industry-briefs/members-club/SKILL.md', $uris);

        add_filter('wppilot_pro_status', static fn(): array => ['licensed' => true]);
        self::assertContains('skill://industry-briefs/members-club/SKILL.md', array_column(entries(), 'uri'));
    }

    public function testBriefTextIsTheComposedBriefWithTheStandards(): void
    {
        $contents = dispatch('resources/read', ['uri' => 'skill://industry-briefs/bakery-cafe/SKILL.md'], 7)['body']['result']['contents'];

        self::assertCount(1, $contents);
        self::assertSame('text/markdown', $contents[0]['mimeType']);
        self::assertStringContainsString('**Page builder:** ', $contents[0]['text']);
        self::assertStringContainsString(\WPPilot\PromptLibrary\standards(), $contents[0]['text']);
    }

    public function testModernResourcesListDescribesMarkdownResources(): void
    {
        self::source([self::skill('visible')]);

        $outcome = dispatch('resources/list', [], 1);
        $resources = $outcome['body']['result']['resources'];
        $visible = array_values(array_filter($resources, static fn(array $r): bool => $r['uri'] === 'skill://visible/SKILL.md'))[0];

        self::assertSame(200, $outcome['status']);
        self::assertSame('visible', $visible['name']);
        self::assertSame('Visible', $visible['title']);
        self::assertSame('text/markdown', $visible['mimeType']);
        self::assertGreaterThan(0, $visible['size']);
        self::assertSame(60000, $outcome['body']['result']['ttlMs']);
    }

    public function testModernReadReturnsTheSkillAndRejectsUnknownUris(): void
    {
        self::source([self::skill('visible'), self::skill('hidden', ['enable_agentic' => false])]);

        $read = dispatch('resources/read', ['uri' => 'skill://visible/SKILL.md'], 2)['body'];
        self::assertStringContainsString("name: \"visible\"", $read['result']['contents'][0]['text']);

        foreach (['skill://hidden/SKILL.md', 'skill://nope/SKILL.md', 'file:///etc/passwd', ''] as $uri) {
            $error = dispatch('resources/read', ['uri' => $uri], 3)['body'];
            self::assertSame(-32602, $error['error']['code'], $uri);
            self::assertArrayNotHasKey('result', $error);
        }
    }

    public function testSkillsListAndGetCarryVerifiableDigests(): void
    {
        self::source([self::skill('visible')]);

        $listed = dispatch('skills/list', [], 4)['body']['result'];
        $entry = array_values(array_filter($listed['skills'], static fn(array $s): bool => $s['uri'] === 'skill://visible/SKILL.md'))[0];
        self::assertSame(60000, $listed['ttlMs']);

        $text = dispatch('resources/read', ['uri' => 'skill://visible/SKILL.md'], 5)['body']['result']['contents'][0]['text'];
        self::assertSame([[
            'uri' => 'skill://visible/SKILL.md',
            'digest' => 'sha256:' . hash('sha256', $text),
            'size' => strlen($text),
        ]], $entry['resources']);
        self::assertSame('visible', $entry['frontmatter']['name']);

        $got = dispatch('skills/get', ['uri' => 'skill://visible/SKILL.md'], 6)['body']['result']['skill'];
        self::assertSame($entry, $got);

        self::assertSame(-32602, dispatch('skills/get', ['uri' => 'skill://missing/SKILL.md'], 7)['body']['error']['code']);
    }

    public function testOtherMethodsAreLeftToTheTransport(): void
    {
        self::assertNull(dispatch('tools/list', [], 1));
    }

    public function testDiscoveryDeclaresTheExtensionOnlyWithResources(): void
    {
        self::assertArrayNotHasKey('extensions', with_skills_extension(build_capabilities(1, 0, 0)));

        $capabilities = with_skills_extension(build_capabilities(1, 0, 3));
        self::assertSame('{"io.modelcontextprotocol/skills":{}}', json_encode($capabilities['extensions'], JSON_UNESCAPED_SLASHES));
        self::assertArrayHasKey('resources', $capabilities);
    }

    public function testLegacyListIsExtendedOnlyForWppilotServers(): void
    {
        self::source([self::skill('visible')]);
        $server = new class('wppilot') {
            public function __construct(private string $route)
            {
            }

            public function get_server_route(): string
            {
                return $this->route;
            }

            public function get_server_route_namespace(): string
            {
                return 'mcp';
            }
        };

        $listed = legacy_resources_list(['existing'], $server);
        self::assertSame('existing', $listed[0]);
        self::assertContains('skill://visible/SKILL.md', array_column(array_slice($listed, 1), 'uri'));

        $foreign = new $server('elementor-mcp');
        self::assertSame(['existing'], legacy_resources_list(['existing'], $foreign));
    }

    public function testLegacyReadCompletesOnlyTheAdaptersNotFoundForSkillUris(): void
    {
        self::source([self::skill('visible')]);
        $body = ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'resources/read', 'params' => ['uri' => 'skill://visible/SKILL.md']];
        $not_found = ['jsonrpc' => '2.0', 'id' => 9, 'error' => ['code' => -32002, 'message' => 'Resource not found']];

        $response = new WPPilot_Test_Rest_Response($not_found, 404);
        legacy_resource_read($response, null, new WPPilot_Test_Rest_Request('/mcp/wppilot', 'POST', $body));
        self::assertSame(200, $response->get_status());
        self::assertSame(9, $response->get_data()['id']);
        self::assertSame('text/markdown', $response->get_data()['result']['contents'][0]['mimeType']);

        // A permission failure is a different error and must survive untouched.
        $denied = ['jsonrpc' => '2.0', 'id' => 9, 'error' => ['code' => -32008, 'message' => 'Permission denied']];
        $response = new WPPilot_Test_Rest_Response($denied, 403);
        legacy_resource_read($response, null, new WPPilot_Test_Rest_Request('/mcp/wppilot', 'POST', $body));
        self::assertSame($denied, $response->get_data());

        // Another plugin's MCP route is not ours to answer on.
        $response = new WPPilot_Test_Rest_Response($not_found, 404);
        legacy_resource_read($response, null, new WPPilot_Test_Rest_Request('/mcp/elementor-mcp', 'POST', $body));
        self::assertSame($not_found, $response->get_data());

        // A hidden skill stays not-found.
        $hidden = ['params' => ['uri' => 'skill://ghost/SKILL.md']] + $body;
        $response = new WPPilot_Test_Rest_Response($not_found, 404);
        legacy_resource_read($response, null, new WPPilot_Test_Rest_Request('/mcp/wppilot', 'POST', $hidden));
        self::assertSame(404, $response->get_status());
    }
}
