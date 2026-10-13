<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Pro integration catalog the upsell names detected plugins from.
 */
final class ProUpsellCatalogTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/admin/pro-upsell.php';
    }

    public function testEveryRowIsDetectableAndCounted(): void
    {
        $labels = [];
        foreach (\wppilot_pro_integration_catalog() as $row) {
            $detectors = array_filter([$row['constant'] ?? '', $row['class'] ?? '', $row['function'] ?? ''], static fn(string $v): bool => $v !== '');
            self::assertCount(1, $detectors, $row['label'] . ' declares exactly one detection symbol');
            self::assertGreaterThan(0, $row['abilities'] ?? 0, $row['label'] . ' carries an ability count');
            self::assertNotSame('', $row['category']);
            $labels[] = $row['label'];
        }
        self::assertSame($labels, array_unique($labels), 'labels are unique');
    }

    public function testThePro110IntegrationsAreListed(): void
    {
        $by_label = array_column(\wppilot_pro_integration_catalog(), null, 'label');
        $expected = [
            'UpdraftPlus' => ['backups', 'class', 'UpdraftPlus', 1],
            'Duplicator' => ['backups', 'class', 'Duplicator\\Package\\DupPackage', 1],
            'BackWPup' => ['backups', 'class', 'BackWPup', 1],
            'Wordfence' => ['security', 'constant', 'WORDFENCE_VERSION', 5],
            'Solid Security' => ['security', 'class', 'ITSEC_Core', 5],
            'The SEO Framework' => ['seo', 'constant', 'THE_SEO_FRAMEWORK_VERSION', 3],
            'Slim SEO' => ['seo', 'constant', 'SLIM_SEO_VER', 3],
            'SmartCrawl' => ['seo', 'constant', 'SMARTCRAWL_VERSION', 3],
            'Forminator' => ['forms', 'constant', 'FORMINATOR_VERSION', 3],
            'WS Form' => ['forms', 'constant', 'WS_FORM_VERSION', 4],
            'Kadence Blocks patterns' => ['builder', 'constant', 'KADENCE_BLOCKS_VERSION', 2],
            'FunnelKit' => ['commerce', 'constant', 'WFFN_VERSION', 4],
        ];
        foreach ($expected as $label => [$category, $kind, $symbol, $abilities]) {
            self::assertArrayHasKey($label, $by_label);
            self::assertSame($category, $by_label[$label]['category'], $label);
            self::assertSame($symbol, $by_label[$label][$kind] ?? null, $label);
            self::assertSame($abilities, $by_label[$label]['abilities'], $label);
        }
    }
}
