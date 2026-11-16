<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_State;

/**
 * run-wp-cli's command rules, Read Mode check, version pins and in-process output.
 */
final class WpCliPolicyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        unset(WPPilot_Test_State::$options[WPPILOT_WP_CLI_POLICY_OPTION]);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
    }

    /** @param list<string> $args */
    private static function code(array $args, bool $read_mode = false, ?array $policy = null): ?string
    {
        $error = wppilot_wp_cli_policy_check($args, $policy ?? wppilot_wp_cli_default_policy(), $read_mode);

        return $error instanceof WP_Error ? $error->get_error_code() : null;
    }

    public function testDefaultDenyRulesRefuseTheDangerousCommands(): void
    {
        foreach ([
            ['db', 'drop', '--yes'],
            ['db', 'reset', '--yes'],
            ['eval', 'echo 1;'],
            ['eval-file', 'x.php'],
            ['--url=example.com', 'EVAL', 'echo 1;'],
            ['shell'],
            ['user', 'application-password', 'create', '1', 'x'],
            ['user', 'app-password', 'create', '1', 'x'],
            ['config', 'set', 'AUTH_KEY', 'x'],
            ['config', 'set', 'nonce_salt', 'x'],
            ['config', 'shuffle-salts'],
        ] as $args) {
            self::assertSame('wp_cli_command_denied', self::code($args), implode(' ', $args));
        }

        foreach ([['plugin', 'list'], ['db', 'export', 'x.sql'], ['config', 'set', 'WP_DEBUG', 'true'], ['option', 'update', 'blogname', 'db drop']] as $args) {
            self::assertNull(self::code($args), implode(' ', $args));
        }
    }

    public function testGlobalArgumentsThatEscapeTheRulesAreAlwaysRefused(): void
    {
        foreach ([['--exec=phpinfo();', 'option', 'list'], ['--require=x.php', 'plugin', 'list'], ['--path=/srv/other', 'plugin', 'list'], ['--ssh=host', 'plugin', 'list'], ['--HTTP=https://x', 'cli', 'info'], ['@prod', 'plugin', 'list']] as $args) {
            self::assertSame('wp_cli_argument_refused', self::code($args, policy: ['deny' => [], 'allow' => []]), implode(' ', $args));
        }
        self::assertNull(self::code(['plugin', 'list', '--pathless-flag'], policy: ['deny' => [], 'allow' => []]));
    }

    public function testAnAllowListOnlyLetsMatchingCommandsRunAndDenyStillWins(): void
    {
        $policy = ['deny' => ['plugin delete'], 'allow' => ['plugin *', 'cache flush']];
        self::assertNull(self::code(['plugin', 'list'], policy: $policy));
        self::assertNull(self::code(['cache', 'flush'], policy: $policy));
        self::assertSame('wp_cli_command_not_allowed', self::code(['user', 'list'], policy: $policy));
        self::assertSame('wp_cli_command_denied', self::code(['plugin', 'delete', 'hello'], policy: $policy));
        self::assertSame('wp_cli_no_command', self::code(['--info'], policy: $policy));
    }

    public function testReadModeOnlyRunsReadCommands(): void
    {
        foreach ([['plugin', 'list'], ['post', 'meta', 'list', '5'], ['option', 'get', 'home'], ['core', 'version'], ['cron', 'event', 'list'], ['db', 'size'], ['help', 'post']] as $args) {
            self::assertNull(self::code($args, read_mode: true), implode(' ', $args));
        }
        foreach ([['plugin', 'install', 'x'], ['post', 'meta', 'update', '5', 'list', 'x'], ['option', 'update', 'x', 'get'], ['config', 'get', 'DB_PASSWORD'], ['db', 'export'], ['search-replace', 'a', 'b'], ['cache', 'flush']] as $args) {
            self::assertSame('wppilot_read_mode', self::code($args, read_mode: true), implode(' ', $args));
        }
    }

    public function testRulesAreNormalizedAndADamagedOptionReadsAsTheDefaults(): void
    {
        self::assertSame(['db drop', 'eval*'], wppilot_wp_cli_normalize_rules("  DB   drop \n# comment\n\neval*\ndb drop\n"));

        WPPilot_Test_State::$options[WPPILOT_WP_CLI_POLICY_OPTION] = 'garbage';
        self::assertSame(wppilot_wp_cli_default_policy(), wppilot_wp_cli_policy());

        WPPilot_Test_State::$options[WPPILOT_WP_CLI_POLICY_OPTION] = ['deny' => [], 'allow' => ['plugin list']];
        self::assertSame(['deny' => [], 'allow' => ['plugin list']], wppilot_wp_cli_policy());
    }

    public function testSavingRulesIsLedgeredAndAnUnchangedSaveIsNot(): void
    {
        $before = count(wppilot_get_change_log());
        self::assertTrue(wppilot_wp_cli_save_policy(['db drop'], []));
        self::assertSame(['deny' => ['db drop'], 'allow' => []], wppilot_wp_cli_policy());

        $log = wppilot_get_change_log();
        self::assertCount($before + 1, $log);
        $row = end($log);
        self::assertSame(WPPILOT_WP_CLI_POLICY_LEDGER_NAME, $row['ability']);
        self::assertFalse($row['rollback']['reversible']);
        self::assertContains('eval*', $row['result']['removed_deny']);

        self::assertFalse(wppilot_wp_cli_save_policy(['db drop'], []));
        self::assertCount($before + 1, wppilot_get_change_log());
    }

    public function testVersionPins(): void
    {
        self::assertTrue(wppilot_wp_cli_version_matches('10.2.1', '10.2'));
        self::assertTrue(wppilot_wp_cli_version_matches('6.9.1', '6.9.1'));
        self::assertFalse(wppilot_wp_cli_version_matches('10.20', '10.2'));
        self::assertFalse(wppilot_wp_cli_version_matches('6.9', '6.9.1'));
        self::assertFalse(wppilot_wp_cli_version_matches('', '6.9'));

        $installed = ['core' => '6.9.1', 'php' => '8.3.4', 'plugin' => ['woocommerce' => '10.2.3'], 'theme' => []];
        self::assertSame([], wppilot_wp_cli_expect_mismatches(['core' => '6.9', 'plugin' => ['woocommerce' => '10.2']], $installed));
        self::assertSame(
            ['WordPress is 6.9.1, expected 7.0.', 'The plugin "woocommerce" is 10.2.3, expected 10.3.', 'The plugin "jetpack" is not installed, expected 14.', 'The theme "astra" is not installed, expected 4.'],
            wppilot_wp_cli_expect_mismatches(['core' => '7.0', 'plugin' => ['woocommerce' => '10.3', 'jetpack' => '14'], 'theme' => ['astra' => '4']], $installed),
        );
    }

    public function testInProcessRowsFormatLikeWpCli(): void
    {
        $rows = [['name' => 'akismet', 'status' => 'inactive', 'update' => 'none', 'version' => '5.3']];
        self::assertSame("name\tstatus\tupdate\tversion\nakismet\tinactive\tnone\t5.3\n", wppilot_wp_cli_format_rows($rows, 'table'));
        self::assertSame("name,status,update,version\nakismet,inactive,none,5.3\n", wppilot_wp_cli_format_rows($rows, 'csv'));
        self::assertSame("1\n", wppilot_wp_cli_format_rows($rows, 'count'));
        self::assertSame($rows, json_decode(wppilot_wp_cli_format_rows($rows, 'json'), true));
        self::assertNull(wppilot_wp_cli_run_in_process(['plugin', 'install', 'x']));
        self::assertNull(wppilot_wp_cli_run_in_process(['core', 'version', 'extra']));
    }
}
