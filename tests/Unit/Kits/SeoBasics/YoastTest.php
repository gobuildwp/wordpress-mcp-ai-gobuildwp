<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SeoBasics;

use Kit_Test_Site;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_Error;

require_once __DIR__ . '/harness.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class YoastTest extends SeoBasicsCase
{
    protected function setUp(): void
    {
        $this->boot(['WPSEO_VERSION']);
    }

    public function testReadMapsYoastsIndexCodes(): void
    {
        foreach (['0' => 'default', '1' => 'noindex', '2' => 'index'] as $code => $label) {
            Kit_Test_Site::set_meta(10, '_yoast_wpseo_meta-robots-noindex', (string) $code);
            $seo = $this->run_ability('wppilot/yoast-get-post-seo', ['id' => 10])['seo'];
            self::assertSame($label, $seo['robots']['index'], "code {$code}");
        }
        Kit_Test_Site::set_meta(10, '_yoast_wpseo_meta-robots-nofollow', '1');
        self::assertSame('nofollow', $this->run_ability('wppilot/yoast-get-post-seo', ['post_id' => 10])['seo']['robots']['follow']);
    }

    public function testANeverSetPostReadsAsYoastsDefaults(): void
    {
        $seo = $this->run_ability('wppilot/yoast-get-post-seo', ['post_id' => 10])['seo'];

        self::assertSame(['seo_title' => '', 'meta_description' => '', 'robots' => ['index' => 'default', 'follow' => 'follow']], $seo);
    }

    public function testEditWritesTheCodesKeepsBackslashesAndUndoPutsBackOnlyWhatItWrote(): void
    {
        Kit_Test_Site::set_meta(10, '_yoast_wpseo_title', 'Old \"title\"');
        Kit_Test_Site::set_meta(10, '_yoast_wpseo_focuskw', 'untouched');

        $result = $this->run_ability('wppilot/yoast-edit-post-seo', [
            'post_id' => 10,
            'seo_title' => self::SLASHY,
            'meta_description' => 'New <b>description</b>',
            'robots' => ['index' => 'index', 'follow' => 'nofollow'],
        ]);

        self::assertIsArray($result);
        self::assertSame(['seo_title', 'meta_description', 'robots.index', 'robots.follow'], $result['changed']);
        self::assertSame([self::SLASHY], $this->raw()['_yoast_wpseo_title']);
        self::assertSame(['New description'], $this->raw()['_yoast_wpseo_metadesc']);
        self::assertSame(['2'], $this->raw()['_yoast_wpseo_meta-robots-noindex'], 'index is Yoast code 2, not 1');
        self::assertSame(['1'], $this->raw()['_yoast_wpseo_meta-robots-nofollow']);

        $this->undo_last();

        self::assertSame(['Old \"title\"'], $this->raw()['_yoast_wpseo_title']);
        self::assertArrayNotHasKey('_yoast_wpseo_metadesc', $this->raw());
        self::assertArrayNotHasKey('_yoast_wpseo_meta-robots-noindex', $this->raw());
        self::assertArrayNotHasKey('_yoast_wpseo_meta-robots-nofollow', $this->raw());
        self::assertSame(['untouched'], $this->raw()['_yoast_wpseo_focuskw']);
    }

    public function testTheBeforeImageCoversOnlyTheKeysSent(): void
    {
        $this->run_ability('wppilot/yoast-edit-post-seo', ['post_id' => 10, 'robots' => ['follow' => 'nofollow']]);

        $row = $this->ledger->all()[0];
        self::assertSame(['_yoast_wpseo_meta-robots-nofollow'], array_keys($row['rollback']['snapshot']['meta']));
    }

    public function testDefaultIndexRemovesTheOverride(): void
    {
        Kit_Test_Site::set_meta(10, '_yoast_wpseo_meta-robots-noindex', '1');

        $result = $this->run_ability('wppilot/yoast-edit-post-seo', ['post_id' => 10, 'robots' => ['index' => 'default']]);

        self::assertSame('default', $result['seo']['robots']['index']);
        self::assertArrayNotHasKey('_yoast_wpseo_meta-robots-noindex', $this->raw());
        $this->undo_last();
        self::assertSame(['1'], $this->raw()['_yoast_wpseo_meta-robots-noindex']);
    }

    public function testABadRobotsValueWritesNothing(): void
    {
        $result = $this->run_ability('wppilot/yoast-edit-post-seo', ['post_id' => 10, 'seo_title' => 'T', 'robots' => ['index' => 'yes']]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('yoast_invalid_input', $result->get_error_code());
        self::assertSame([], $this->raw());
    }

    public function testNothingToChangeIsAnError(): void
    {
        $result = $this->run_ability('wppilot/yoast-edit-post-seo', ['post_id' => 10]);

        self::assertSame('yoast_no_changes', $result->get_error_code());
    }
}
