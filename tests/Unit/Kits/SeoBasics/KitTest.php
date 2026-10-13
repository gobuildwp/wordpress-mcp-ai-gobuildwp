<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SeoBasics;

use Kit_Test_Site;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/harness.php';

/**
 * What the kit loads, and when it stands aside for a copy registered first.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class KitTest extends SeoBasicsCase
{
    private const ALL = ['WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION', 'SLIM_SEO_VER', 'SMARTCRAWL_VERSION'];

    public function testTheKitIsSkippedWhenNoSeoPluginIsActive(): void
    {
        $this->boot([]);

        self::assertStringContainsString('none of Yoast SEO', (string) $this->kit['skip']);
        self::assertNull(Kit_Test_Site::registration('wppilot/yoast-get-post-seo'));
    }

    public function testOnlyTheActivePluginsAbilitiesAreRegistered(): void
    {
        $this->boot(['SLIM_SEO_VER', 'WPSEO_VERSION']);

        self::assertSame(['yoast.php', 'slim.php'], array_map('basename', $this->kit['ability_files']));
        self::assertIsArray(Kit_Test_Site::registration('wppilot/slim-seo-update-post-seo'));
        self::assertIsArray(Kit_Test_Site::registration('wppilot/yoast-edit-post-seo'));
        self::assertNull(Kit_Test_Site::registration('wppilot/rank-math-get-post-seo'));
        self::assertNull(Kit_Test_Site::registration('wppilot/aioseo-edit-post-seo'));
    }

    public function testEveryAbilityMatchesKitJson(): void
    {
        $this->boot(self::ALL);
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/seo-basics/kit.json'), true);

        self::assertCount(14, $manifest['abilities']);
        foreach ($manifest['abilities'] as $declared) {
            $args = Kit_Test_Site::registration($declared['name']);
            self::assertIsArray($args, $declared['name']);
            self::assertSame('seo', $args['category'], $declared['name']);
            $annotations = $args['meta']['annotations'];
            self::assertSame([$declared['readonly'], $declared['destructive']], [$annotations['readonly'], $annotations['destructive']], $declared['name']);
            self::assertSame($declared['readonly'], $declared['ledger'] === 'self', $declared['name'] . ' ledger agrees with readonly');
            self::assertFalse($args['input_schema']['additionalProperties'], $declared['name']);
        }
    }

    public function testACopyRegisteredFirstKeepsItsNameAndTheKitLeavesItsUndoAlone(): void
    {
        // What Pro 1.10.0 does on a licensed site: its copy is registered before the kit loader runs.
        require_once dirname(__DIR__, 3) . '/doubles/kit-site.php';
        $pro = ['label' => 'Pro copy', 'execute_callback' => static fn(array $input): array => ['pro' => true]];
        wp_register_ability('wppilot/slim-seo-update-post-seo', $pro);

        $this->boot(['SLIM_SEO_VER']);

        self::assertSame('Pro copy', Kit_Test_Site::registration('wppilot/slim-seo-update-post-seo')['label']);
        // The read Pro did not claim is still registered by the kit.
        self::assertSame('Get Post SEO (Slim SEO)', Kit_Test_Site::registration('wppilot/slim-seo-get-post-seo')['label']);

        // No before-image is attached to the name: running Pro's ability records nothing for the kit.
        $input = ['post_id' => 10, 'title' => 'T'];
        $this->ledger->before('wppilot/slim-seo-update-post-seo', $input);
        $this->ledger->after('wppilot/slim-seo-update-post-seo', $input, ['pro' => true]);
        self::assertSame([], $this->ledger->all());
    }

    public function testAnEditorWhoCannotEditThePostIsRefused(): void
    {
        $this->boot(['SLIM_SEO_VER']);
        Kit_Test_Site::as_user(2, 'manage_options', 'edit_posts');

        $result = $this->run_ability('wppilot/slim-seo-update-post-seo', ['post_id' => 10, 'title' => 'T']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('slim_seo_forbidden', $result->get_error_code());
        self::assertSame([], $this->raw());
    }

    public function testRevisionsAndMissingPostsAreRefused(): void
    {
        $this->boot(['SMARTCRAWL_VERSION']);
        Kit_Test_Site::insert(['ID' => 11, 'post_type' => 'revision', 'post_status' => 'inherit']);

        $revision = $this->run_ability('wppilot/smartcrawl-get-post-seo', ['post_id' => 11]);
        $missing = $this->run_ability('wppilot/smartcrawl-get-post-seo', ['post_id' => 999]);

        self::assertSame('smartcrawl_invalid_post', $revision->get_error_code());
        self::assertSame('smartcrawl_post_not_found', $missing->get_error_code());
    }
}
