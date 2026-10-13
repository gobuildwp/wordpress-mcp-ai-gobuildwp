<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot_Test_State;

use function WPPilot\Abilities\WordPress\wordpress_first_meta;

/**
 * The SEO title and description read-content reports, across the plugins that store them as post
 * meta, including Slim SEO's single array.
 */
final class ContentReadSeoMetaTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(WPPilot_Test_State::$post_meta[901], WPPilot_Test_State::$post_meta[902]);
    }

    public function testPlainAndArrayMetaAreBothRead(): void
    {
        WPPilot_Test_State::$post_meta[901] = ['_genesis_description' => ['From TSF']];
        WPPilot_Test_State::$post_meta[902] = ['slim_seo' => [serialize(['title' => 'Slim title', 'description' => 'Slim description'])]];

        self::assertSame('From TSF', wordpress_first_meta(901, ['_yoast_wpseo_metadesc', '_genesis_description']));
        self::assertSame('Slim description', wordpress_first_meta(902, ['_genesis_description', 'slim_seo[description]']));
        self::assertSame('Slim title', wordpress_first_meta(902, ['slim_seo[title]']));
        self::assertSame('', wordpress_first_meta(902, ['slim_seo[canonical]', '_wds_metadesc']));
    }
}
