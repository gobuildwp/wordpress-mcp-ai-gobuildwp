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
final class TsfTest extends SeoBasicsCase
{
    protected function setUp(): void
    {
        $this->boot(['THE_SEO_FRAMEWORK_VERSION']);
    }

    public function testReadMapsTheQubits(): void
    {
        foreach ([[1, 'noindex', 'nofollow'], [-1, 'index', 'follow'], [0, 'default', 'default']] as [$qubit, $index, $follow]) {
            Kit_Test_Site::set_meta(10, '_genesis_noindex', $qubit);
            Kit_Test_Site::set_meta(10, '_genesis_nofollow', $qubit);
            $seo = $this->run_ability('wppilot/tsf-get-post-seo', ['post_id' => 10])['seo'];
            self::assertSame([$index, $follow], [$seo['robots_index'], $seo['robots_follow']], "qubit {$qubit}");
        }
    }

    public function testUpdateGoesThroughSaveMetaWithoutLosingBackslashesAnywhere(): void
    {
        // A field this call does not touch: save_meta() rewrites it too, so it must survive.
        Kit_Test_Site::set_meta(10, '_open_graph_title', 'OG \"kept\"');
        Kit_Test_Site::set_meta(10, '_genesis_description', 'Old');

        $result = $this->run_ability('wppilot/tsf-update-post-seo', [
            'post_id' => 10,
            'title' => self::SLASHY,
            'robots_index' => 'index',
            'robots_follow' => 'nofollow',
        ]);

        self::assertIsArray($result);
        self::assertSame(['title', 'robots_index', 'robots_follow'], $result['changed']);
        self::assertSame([self::SLASHY], $this->raw()['_genesis_title']);
        self::assertSame(['OG \"kept\"'], $this->raw()['_open_graph_title']);
        self::assertSame(['-1'], $this->raw()['_genesis_noindex']);
        self::assertSame(['1'], $this->raw()['_genesis_nofollow']);

        $snapshot = $this->ledger->all()[0]['rollback']['snapshot'];
        self::assertContains('_open_graph_title', array_keys($snapshot['meta']), 'the before-image covers every key save_meta() rewrites');

        $this->undo_last();

        self::assertArrayNotHasKey('_genesis_title', $this->raw());
        self::assertArrayNotHasKey('_genesis_noindex', $this->raw());
        self::assertSame(['Old'], $this->raw()['_genesis_description']);
        self::assertSame(['OG \"kept\"'], $this->raw()['_open_graph_title']);
    }

    public function testDefaultClearsTheQubit(): void
    {
        Kit_Test_Site::set_meta(10, '_genesis_noindex', 1);

        $result = $this->run_ability('wppilot/tsf-update-post-seo', ['post_id' => 10, 'robots_index' => 'default']);

        self::assertSame('default', $result['seo']['robots_index']);
        self::assertArrayNotHasKey('_genesis_noindex', $this->raw());
    }

    public function testAnUnknownRobotsValueWritesNothing(): void
    {
        $result = $this->run_ability('wppilot/tsf-update-post-seo', ['post_id' => 10, 'title' => 'T', 'robots_follow' => 'noindex']);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('tsf_invalid_input', $result->get_error_code());
        self::assertSame([], $this->raw());
    }
}
