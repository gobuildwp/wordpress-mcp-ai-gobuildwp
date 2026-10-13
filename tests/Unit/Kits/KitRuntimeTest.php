<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPPilot\Kits\Runtime;

/**
 * The runtime's loader decisions, before any kit code runs.
 */
final class KitRuntimeTest extends TestCase
{
    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/kits/_runtime/runtime.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wppilot-kits-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/good', recursive: true);
        mkdir($this->dir . '/broken');
        mkdir($this->dir . '/_runtime');
        file_put_contents($this->dir . '/good/kit.json', (string) json_encode(['slug' => 'good', 'runtime' => '^1.0']));
        file_put_contents($this->dir . '/broken/kit.json', '{"slug": "broken", "namespace": "A\B"}');
        file_put_contents($this->dir . '/_runtime/kit.json', (string) json_encode(['slug' => 'runtime']));
    }

    protected function tearDown(): void
    {
        foreach (['good', 'broken', '_runtime'] as $sub) {
            @unlink($this->dir . '/' . $sub . '/kit.json');
            @rmdir($this->dir . '/' . $sub);
        }
        @rmdir($this->dir);
    }

    #[DataProvider('constraints')]
    public function testRuntimeConstraint(string $constraint, bool $accepted): void
    {
        self::assertSame($accepted, Runtime\runtime_satisfies($constraint));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function constraints(): array
    {
        return [
            'same major, older minor' => ['^1.0', true],
            'same major, the previous minor' => ['^1.1', true],
            'same major and minor' => ['^1.2', true],
            'newer minor than the runtime' => ['^1.3', false],
            'other major' => ['^2.0', false],
            'no caret' => ['1.0', false],
            'garbage' => ['latest', false],
        ];
    }

    /**
     * A kit.json that does not parse is reported, not silently dropped; the
     * runtime's own folder is never mistaken for a kit.
     */
    public function testDiscoverReportsBrokenManifestsAndSkipsTheRuntime(): void
    {
        $found = Runtime\discover($this->dir);

        self::assertSame(['broken', 'good'], array_column($found, 'slug'));
        self::assertSame('kit.json is not valid JSON with a slug', Runtime\incompatibility($found[0]));
        self::assertSame('', Runtime\incompatibility($found[1]));
    }

    public function testIncompatibilityNamesWhatIsMissing(): void
    {
        self::assertStringContainsString('runtime ^2.0', Runtime\incompatibility(['slug' => 'x', 'runtime' => '^2.0']));
        self::assertStringContainsString(
            'NoSuchPlugin',
            Runtime\incompatibility(['slug' => 'x', 'runtime' => '^1.0', 'requires' => ['classes' => ['NoSuchPlugin']]]),
        );
        self::assertStringContainsString(
            'PHP 99.0',
            Runtime\incompatibility(['slug' => 'x', 'runtime' => '^1.0', 'requires' => ['php' => '99.0']]),
        );
    }

    /**
     * A condition kit.json cannot state is the bootstrap's to report: the kit is skipped with its
     * reason, never booted, and none of its abilities are queued.
     */
    public function testABootstrapCanSkipItsKitWithAReason(): void
    {
        mkdir($this->dir . '/skipper');
        file_put_contents($this->dir . '/skipper/kit.json', (string) json_encode(['slug' => 'skipper', 'runtime' => '^1.0']));
        file_put_contents(
            $this->dir . '/skipper/bootstrap.php',
            "<?php return ['skip' => 'Not here.', 'boot' => static function (): void { throw new \\LogicException('booted'); }, 'ability_files' => ['x.php']];",
        );
        $queued = count(Runtime\pending_kits());

        try {
            $report = Runtime\load_kits($this->dir, ['skipper']);
        } finally {
            @unlink($this->dir . '/skipper/kit.json');
            @unlink($this->dir . '/skipper/bootstrap.php');
            @rmdir($this->dir . '/skipper');
        }

        self::assertSame(['loaded' => [], 'skipped' => ['skipper' => 'Not here.']], $report);
        self::assertSame('Not here.', Runtime\registry()['skipped']['skipper']);
        self::assertCount($queued, Runtime\pending_kits());
    }

    /**
     * The multisite kit loads its abilities on a network only; on a single site it says why not.
     */
    public function testTheMultisiteKitSkipsItselfOffANetwork(): void
    {
        $bootstrap = dirname(__DIR__, 3) . '/includes/kits/multisite/bootstrap.php';

        \WPPilot_Test_State::$multisite = false;
        $single = require $bootstrap;
        self::assertSame('This site is not a multisite network.', $single['skip']);
        self::assertSame([], $single['ability_files']);

        \WPPilot_Test_State::$multisite = true;
        try {
            $network = require $bootstrap;
        } finally {
            \WPPilot_Test_State::$multisite = false;
        }
        self::assertArrayNotHasKey('skip', $network);
        self::assertSame(['network-list-sites.php', 'network-run-ability.php'], array_map('basename', $network['ability_files']));
        self::assertIsCallable($network['boot']);
    }
}
