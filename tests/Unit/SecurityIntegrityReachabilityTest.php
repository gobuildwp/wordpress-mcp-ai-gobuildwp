<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_Ability;
use WPPilot_Test_State;

use function WPPilot\OAuth\Middleware\record_oauth_identity;
use function WPPilot\OAuth\Middleware\reset_request_context;

/**
 * The Security Guardian integrity abilities are reachable where Cloud and agents look for
 * them: the site's own MCP tools/list, under the Read-only profile, and through a GoBuildWP
 * Cloud token with a read-only ceiling (null scope, `gobuildwp/*`, or the category).
 *
 * Kept outside the exported kit's tests because it exercises GoBuildWP's own transport and
 * token policy. Run apart because it needs a wp_get_abilities() double.
 */
final class SecurityIntegrityReachabilityTest extends TestCase
{
    private const NAMES = [
        'gobuildwp/db-security-scan-cancel',
        'gobuildwp/db-security-scan-results',
        'gobuildwp/db-security-scan-start',
        'gobuildwp/db-security-scan-status',
        'gobuildwp/integrity-scan-cancel',
        'gobuildwp/integrity-scan-results',
        'gobuildwp/integrity-scan-start',
        'gobuildwp/integrity-scan-status',
    ];

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheEightAbilitiesAreListedForTheSiteAndForAReadOnlyCloudToken(): void
    {
        require_once __DIR__ . '/Kits/SecurityIntegrity/harness.php';
        \GoBuildWP\Tests\Unit\Kits\SecurityIntegrity\reset_state();

        $host = new \GoBuildWP\Tests\Unit\Kits\SecurityIntegrity\FakeHost();
        \WPPilot\Kits\Runtime\host($host);
        $kit = require dirname(__DIR__, 2) . '/includes/kits/security-integrity/bootstrap.php';
        ($kit['boot'])($host);
        foreach ($kit['ability_files'] as $file) {
            require $file;
        }

        $abilities = [];
        foreach (self::NAMES as $name) {
            $args = \Kit_Test_Site::registration($name);
            self::assertIsArray($args, $name);
            $abilities[] = new WP_Ability(
                $name,
                $args['meta'],
                $args['input_schema'] ?? [],
                (string) $args['category'],
                (string) ($args['label'] ?? ''),
                (string) ($args['description'] ?? ''),
                $args['output_schema'] ?? [],
            );
        }
        // A write-class ability on the same site, so the read-only cases prove they filter.
        $abilities[] = new WP_Ability('gobuildwp/some-write', ['mcp' => ['public' => true]], ['type' => 'object'], 'security-integrity');
        $GLOBALS['si_reachability_abilities'] = $abilities;
        eval('function wp_get_abilities(array $args = []): array { return $GLOBALS["si_reachability_abilities"]; }');

        $wpdb = new ReachabilityTokenWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        unset(WPPilot_Test_State::$options['wppilot_ability_rules']);
        reset_request_context();

        $expected = array_map(static fn(string $n): string => \WPPilot\Mcp\tool_name($n), self::NAMES);
        sort($expected);
        $listed = static function (): array {
            $names = array_column(\WPPilot\Mcp\list_tools(), 'name');
            sort($names);
            return $names;
        };
        $si_only = static fn(array $names): array => array_values(array_filter(
            $names,
            static fn(string $n): bool => $n !== \WPPilot\Mcp\tool_name('gobuildwp/some-write'),
        ));

        // The site's own MCP server, on Production Safe and on Read-only.
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
        self::assertSame($expected, $si_only($listed()));
        self::assertContains(\WPPilot\Mcp\tool_name('gobuildwp/some-write'), $listed());
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'readonly';
        self::assertSame($expected, $listed(), 'Read-only keeps all eight and drops the write');
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';

        // A Cloud token as pairing mints it: no scope, read-only ceiling.
        foreach ([
            'cloud default' => null,
            'provider wildcard' => ['abilities' => ['gobuildwp/*'], 'categories' => []],
            'category' => ['abilities' => [], 'categories' => ['security-integrity']],
        ] as $case => $scope) {
            $id = 40 + count($wpdb->rows);
            $wpdb->rows[$id] = [
                'id' => $id,
                'user_id' => 1,
                'name' => 'GoBuildWP Cloud',
                'token_hash' => str_repeat('b', 64),
                'expires' => null,
                'scope' => $scope === null ? null : (string) json_encode($scope),
                'ceiling' => 'readonly',
            ];
            reset_request_context();
            record_oauth_identity(1, ['mcp'], 'token-' . $id, via: 'token');
            self::assertSame('readonly', wppilot_effective_safety_profile(), $case);
            self::assertSame($expected, $listed(), $case . ': all eight, and not the write');
            foreach ($abilities as $ability) {
                if ($ability->get_name() !== 'gobuildwp/some-write') {
                    self::assertNull(wppilot_agent_scope_error($ability), $case . ' ' . $ability->get_name());
                    self::assertTrue(wppilot_safety_profile_allows_ability($ability), $case . ' ' . $ability->get_name());
                }
            }
            wppilot_token_policy_cache($id, forget: true);
        }

        // A token scoped to something else does not see them.
        $wpdb->rows[90] = [
            'id' => 90, 'user_id' => 1, 'name' => 'Other', 'token_hash' => str_repeat('c', 64), 'expires' => null,
            'scope' => (string) json_encode(['abilities' => ['wppilot/get-post'], 'categories' => []]), 'ceiling' => 'readonly',
        ];
        reset_request_context();
        record_oauth_identity(1, ['mcp'], 'token-90', via: 'token');
        self::assertSame([], $listed());
        wppilot_token_policy_cache(90, forget: true);
        reset_request_context();
    }
}

/**
 * The token table, answering the lookup by id that the token policy makes.
 */
final class ReachabilityTokenWpdb
{
    public string $prefix = 'wp_';

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function query(string $query): int
    {
        return 0;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $index = 0;
        return (string) preg_replace_callback('/%[ds]/', static function (array $match) use (&$index, $args): string {
            $value = $args[$index++] ?? '';
            return $match[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    /** @return array<string, mixed>|null */
    public function get_row(string $query, mixed $output = null): ?array
    {
        if (preg_match('/WHERE id = (\d+)/', $query, $match) === 1) {
            return $this->rows[(int) $match[1]] ?? null;
        }
        return null;
    }

    /** @return list<mixed> */
    public function get_results(string $query, mixed $output = null): array
    {
        return [];
    }

    public function get_var(string $query): mixed
    {
        return null;
    }
}
