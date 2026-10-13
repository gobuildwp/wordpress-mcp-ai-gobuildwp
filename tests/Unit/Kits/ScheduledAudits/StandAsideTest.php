<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\ScheduledAudits;

use PHPUnit\Framework\TestCase;
use WPPilot\Kits\Runtime;

/**
 * The kit steps aside for another copy of routines on the same site: entirely, when the host says
 * one runs this site's routines, and name by name, when an ability is already registered.
 */
final class StandAsideTest extends TestCase
{
    private const KIT = 'scheduled-audits';

    private TestHost $host;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once __DIR__ . '/doubles.php';
    }

    protected function setUp(): void
    {
        $this->host = new TestHost();
        Runtime\host($this->host);
        Site::reset();
        Site::$registered = [];
    }

    protected function tearDown(): void
    {
        Site::$active = false;
    }

    private static function kitsDir(): string
    {
        return dirname(__DIR__, 4) . '/includes/kits';
    }

    public function testTheKitDoesNotLoadWhileTheHostNamesAnotherOwner(): void
    {
        $this->host->extensions['routines-elsewhere'] = 'Another plugin runs this site\'s routines.';

        $descriptor = require self::kitsDir() . '/' . self::KIT . '/bootstrap.php';
        $report = Runtime\load_kits(self::kitsDir(), [self::KIT]);

        self::assertSame(['skip' => 'Another plugin runs this site\'s routines.'], $descriptor);
        self::assertSame([], $report['loaded']);
        self::assertSame('Another plugin runs this site\'s routines.', $report['skipped'][self::KIT]);
    }

    public function testAnEmptyAnswerLeavesTheKitToLoad(): void
    {
        $this->host->extensions['routines-elsewhere'] = '';

        $descriptor = require self::kitsDir() . '/' . self::KIT . '/bootstrap.php';

        self::assertIsArray($descriptor);
        self::assertArrayNotHasKey('skip', $descriptor);
        self::assertCount(5, $descriptor['ability_files']);
        self::assertIsCallable($descriptor['boot']);
    }

    /**
     * A name registered first — by Pro 1.10.0, at priority 10 — stays that plugin's; the kit
     * registers the rest.
     */
    public function testAnAbilityRegisteredFirstIsLeftToItsOwner(): void
    {
        $descriptor = require self::kitsDir() . '/' . self::KIT . '/bootstrap.php';
        \wp_register_ability('wppilot/routines-save', ['category' => 'elsewhere']);

        foreach ($descriptor['ability_files'] as $file) {
            require $file;
        }

        self::assertSame(
            ['wppilot/routines-list', 'wppilot/routines-report', 'wppilot/routines-delete', 'wppilot/routines-run-now'],
            array_keys(Site::$registered),
        );
        self::assertSame('routines', Site::$registered['wppilot/routines-list']['category']);
    }
}
