<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Admin\Routines {
    /** The one hook the owner check reads, answered from the test. */
    function has_action(string $hook, mixed $callback = false): bool|int
    {
        return \WPPilot\Tests\Unit\RoutinesOwnerTest::$hooked[$hook . '|' . (is_string($callback) ? $callback : '')] ?? false;
    }
}

namespace WPPilot\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use WPPilot\Admin\Routines;

    /**
     * Who runs this site's routines: GoBuildWP Pro 1.10.0 while its own routines kit is hooked, so
     * the scheduled-audits kit stands aside and one copy answers each tick; otherwise GoBuildWP.
     */
    final class RoutinesOwnerTest extends TestCase
    {
        /** @var array<string, int|false> */
        public static array $hooked = [];

        public static function setUpBeforeClass(): void
        {
            require_once dirname(__DIR__, 2) . '/includes/admin/routines.php';
        }

        protected function tearDown(): void
        {
            self::$hooked = [];
        }

        public function testProOneTenStillRunningRoutinesIsNamedAsTheOwner(): void
        {
            self::$hooked['plugins_loaded|WPPilot\\Pro\\Kits\\register_routines'] = 25;

            $answer = Routines\routines_elsewhere(null, 'routines-elsewhere');

            self::assertIsString($answer);
            self::assertStringContainsString('GoBuildWP Pro', $answer);
        }

        public function testWithoutProsHookWPPilotRunsThem(): void
        {
            self::assertNull(Routines\routines_elsewhere(null, 'routines-elsewhere'));
        }

        public function testOtherExtensionPointsPassThrough(): void
        {
            self::$hooked['plugins_loaded|WPPilot\\Pro\\Kits\\register_routines'] = 25;
            $registry = new \stdClass();

            self::assertSame($registry, Routines\routines_elsewhere($registry, 'seo-providers'));
        }

        public function testReportLinksPointAtTheRoutinesScreen(): void
        {
            self::assertSame('wppilot-routines', Routines\PAGE_SLUG);
            $map = Routines\register_nav([]);
            self::assertSame('activity', $map['wppilot-routines']['group']);
        }
    }
}
