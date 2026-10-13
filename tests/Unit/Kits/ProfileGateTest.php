<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Hosts\StandaloneHost;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\MiniLedger;
use WPPilot\Kits\Runtime\ProfileGate;

use function WPPilot\Scripts\Kits\profile_gate_problems;
use function WPPilot\Scripts\Kits\tokens;

/**
 * meta.safety.min_profile is enforced by the kit itself, not only by WordPress 7.1's filter.
 */
final class ProfileGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 3) . '/includes/kits/_runtime/hosts/standalone.php';
        require_once __DIR__ . '/doubles/runtime-abilities.php';
        require_once dirname(__DIR__, 3) . '/scripts/lib/kit-tools.php';
    }

    protected function setUp(): void
    {
        RuntimeAbilities::$abilities = [];
        RuntimeAbilities::add('kitprobe/dev-read', [
            'annotations' => ['readonly' => true],
            'safety' => ['min_profile' => 'developer', 'audit_reads' => true],
        ]);
        RuntimeAbilities::add('kitprobe/plain-read', ['annotations' => ['readonly' => true]]);
        RuntimeAbilities::add('kitprobe/prod-write', ['safety' => ['min_profile' => 'production']]);
        \WPPilot_Test_State::$options = [];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function profiles(): array
    {
        return [
            'developer ability on developer' => ['developer', 'kitprobe/dev-read', true],
            'developer ability on production' => ['production', 'kitprobe/dev-read', false],
            'developer ability on readonly' => ['readonly', 'kitprobe/dev-read', false],
            'no min_profile on readonly' => ['readonly', 'kitprobe/plain-read', true],
            'production ability on readonly' => ['readonly', 'kitprobe/prod-write', false],
            'production ability on production' => ['production', 'kitprobe/prod-write', true],
            'unknown profile counts as production' => ['bogus', 'kitprobe/dev-read', false],
        ];
    }

    #[DataProvider('profiles')]
    public function testStandaloneHostComparesItsConfiguredProfile(string $profile, string $ability, bool $allowed): void
    {
        $host = new StandaloneHost('kitprobe', ['safety_profile' => $profile]);
        Runtime\host($host);

        $answer = Runtime\require_profile($ability);

        if ($allowed) {
            self::assertTrue($answer);
            return;
        }
        self::assertInstanceOf(WP_Error::class, $answer);
        self::assertSame('kit_safety_profile_blocked', $answer->get_error_code());
        self::assertSame(403, $answer->get_error_data()['status']);
    }

    /**
     * On 7.1 the same answer arrives through the core filter, but only for this host's abilities
     * and never to turn an upstream refusal into a grant.
     */
    public function testTheCoreFilterGivesTheSameAnswerAndOnlyDenies(): void
    {
        $host = new StandaloneHost('kitprobe', ['safety_profile' => 'production']);

        self::assertInstanceOf(WP_Error::class, $host->enforce_min_profile(true, 'kitprobe/dev-read', []));
        self::assertTrue($host->enforce_min_profile(true, 'kitprobe/plain-read', []));
        self::assertTrue($host->enforce_min_profile(true, 'other/dev-read', []), 'another plugin\'s ability is not ours to judge');
        self::assertFalse($host->enforce_min_profile(false, 'kitprobe/plain-read', []));
    }

    /**
     * Inside GoBuildWP the answer is GoBuildWP's own profile rule, read from its setting.
     */
    public function testTheWPPilotHostAnswersWithItsOwnProfile(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/kits/_runtime/hosts/wppilot.php';
        Runtime\host(new \WPPilot\Kits\Runtime\Hosts\WPPilotHost());

        \WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
        $blocked = Runtime\require_profile('kitprobe/dev-read');
        self::assertInstanceOf(WP_Error::class, $blocked);
        self::assertSame('wppilot_safety_profile_blocked', $blocked->get_error_code());
        self::assertTrue(Runtime\require_profile('kitprobe/plain-read'));

        \WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'developer';
        self::assertTrue(Runtime\require_profile('kitprobe/dev-read'));

        \WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'readonly';
        self::assertInstanceOf(WP_Error::class, Runtime\require_profile('kitprobe/prod-write'), 'Read Only refuses a write whatever its min_profile');
    }

    public function testAnAbilityWhosePolicyCannotBeReadIsRefused(): void
    {
        Runtime\host(new StandaloneHost('kitprobe', ['safety_profile' => 'developer']));

        $answer = Runtime\require_profile('kitprobe/not-registered');

        self::assertInstanceOf(WP_Error::class, $answer);
        self::assertSame('kit_ability_unknown', $answer->get_error_code());
    }

    public function testAHostThatAnswersItselfIsAsked(): void
    {
        $host = self::host('developer', static fn(string $name): bool|WP_Error => new WP_Error('host_says_no', $name));
        Runtime\host($host);

        $answer = Runtime\require_profile('kitprobe/plain-read');

        self::assertInstanceOf(WP_Error::class, $answer);
        self::assertSame('host_says_no', $answer->get_error_code());
    }

    /**
     * A host written against runtime 1.0 has no ProfileGate; its safety_profile() still decides.
     */
    public function testAHostWithoutAGateFallsBackToItsProfile(): void
    {
        Runtime\host(self::host('production', null));
        self::assertInstanceOf(WP_Error::class, Runtime\require_profile('kitprobe/dev-read'));

        Runtime\host(self::host('developer', null));
        self::assertTrue(Runtime\require_profile('kitprobe/dev-read'));
    }

    public function testTheRuntimeVersionAdvertisesTheHelper(): void
    {
        self::assertTrue(Runtime\runtime_satisfies('^1.1'));
        self::assertTrue(Runtime\runtime_satisfies('^1.0'));
    }

    /**
     * Standalone, a read that asks for audit_reads leaves a row saying who read, never what.
     */
    public function testTheMiniLedgerAuditsReadsThatAskForIt(): void
    {
        $ledger = new MiniLedger('kitprobe');

        $ledger->before('kitprobe/dev-read', ['sql' => 'SELECT 1', 'api_key' => 'secret']);
        $ledger->after('kitprobe/dev-read', ['sql' => 'SELECT 1'], ['rows' => [['secret' => 'value']]]);
        $ledger->before('kitprobe/plain-read', []);
        $ledger->after('kitprobe/plain-read', [], ['rows' => []]);
        $ledger->before('other/dev-read', []);
        $ledger->after('other/dev-read', [], []);

        $rows = $ledger->all();
        self::assertCount(1, $rows);
        self::assertSame('audit-read', $rows[0]['kind']);
        self::assertSame('kitprobe/dev-read', $rows[0]['ability']);
        self::assertSame('[redacted]', $rows[0]['input']['api_key']);
        self::assertSame(['rows'], $rows[0]['result'], 'only the shape of the result is kept');
        self::assertSame('not-reversible', MiniLedger::status($rows[0]));
    }

    public function testAMiniLedgerWithoutAScopeAuditsNothing(): void
    {
        $ledger = new MiniLedger();

        $ledger->before('kitprobe/dev-read', []);
        $ledger->after('kitprobe/dev-read', [], []);

        self::assertSame([], $ledger->all());
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function lintCases(): array
    {
        $register = static fn(string $callback, string $meta = "'safety' => ['min_profile' => 'developer']"): string => <<<PHP
            <?php
            wp_register_ability('wppilot/x', [
                'permission_callback' => {$callback},
                'meta' => [{$meta}],
            ]);
            PHP;

        return [
            'gated with its own name' => [
                $register("static fn(): bool|WP_Error => Runtime\\can_run() ? Runtime\\require_profile('wppilot/x') : false"),
                [],
            ],
            'not gated' => [
                $register('static fn(): bool => Runtime\\can_run()'),
                ["wppilot/x declares meta.safety.min_profile but its permission callback never calls Runtime\\require_profile('wppilot/x'); before WordPress 7.1 nothing else enforces it"],
            ],
            'gated with another ability\'s name' => [
                $register("static fn(): bool|WP_Error => Runtime\\require_profile('wppilot/y')"),
                ['wppilot/x calls Runtime\\require_profile() with a name other than its own literal name'],
            ],
            'no min_profile needs no gate' => [
                $register('static fn(): bool => Runtime\\can_run()', "'annotations' => ['readonly' => true]"),
                [],
            ],
            'min_profile in a comment is prose' => [
                $register('static fn(): bool => Runtime\\can_run()', "// 'min_profile' => 'developer'\n"),
                [],
            ],
            'meta built outside the call hides the declaration' => [
                "<?php\n\$meta = ['safety' => ['min_profile' => 'developer']];\nwp_register_ability('wppilot/x', ['meta' => \$meta]);\n",
                ["'min_profile' outside a wp_register_ability() call: declare meta.safety inline so the require_profile() check can see it"],
            ],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('lintCases')]
    public function testTheBoundaryLintDemandsTheGate(string $source, array $expected): void
    {
        self::assertSame($expected, array_column(profile_gate_problems(tokens($source)), 'message'));
    }

    /**
     * @param (callable(string): (bool|WP_Error))|null $gate
     */
    private static function host(string $profile, ?callable $gate): Host
    {
        $base = new class ($profile) implements Host {
            public function __construct(private string $profile)
            {
            }

            public function id(): string
            {
                return 'kitprobe';
            }

            public function can_manage(): bool
            {
                return true;
            }

            public function is_enabled(): bool
            {
                return true;
            }

            public function safety_profile(): string
            {
                return $this->profile;
            }

            public function ledger(): Ledger
            {
                throw new \LogicException('not used');
            }

            public function jobs(): Jobs
            {
                throw new \LogicException('not used');
            }

            public function extension(string $point): mixed
            {
                return null;
            }

            public function admin_parent_slug(): string
            {
                return 'tools.php';
            }

            public function confirm_guard(string $ability_name, array $input): bool|WP_Error
            {
                return true;
            }
        };
        if ($gate === null) {
            return $base;
        }
        return new class ($base, $gate) implements Host, ProfileGate {
            /** @var callable(string): (bool|WP_Error) */
            private $gate;

            public function __construct(private Host $inner, callable $gate)
            {
                $this->gate = $gate;
            }

            public function profile_allows(string $ability_name): bool|WP_Error
            {
                return ($this->gate)($ability_name);
            }

            public function id(): string
            {
                return $this->inner->id();
            }

            public function can_manage(): bool
            {
                return true;
            }

            public function is_enabled(): bool
            {
                return true;
            }

            public function safety_profile(): string
            {
                return $this->inner->safety_profile();
            }

            public function ledger(): Ledger
            {
                return $this->inner->ledger();
            }

            public function jobs(): Jobs
            {
                return $this->inner->jobs();
            }

            public function extension(string $point): mixed
            {
                return null;
            }

            public function admin_parent_slug(): string
            {
                return 'tools.php';
            }

            public function confirm_guard(string $ability_name, array $input): bool|WP_Error
            {
                return true;
            }
        };
    }
}
