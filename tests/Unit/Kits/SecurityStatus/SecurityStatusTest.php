<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SecurityStatus;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\SecurityStatus as S;

/**
 * The security-status kit: redaction (IP networks, masked usernames, no emails), severity mapping,
 * provider selection and isolation, both vendors' readers, and standing aside when the names are
 * taken.
 */
final class SecurityStatusTest extends TestCase
{
    private static ReadHost $host;

    /** @var array<string, mixed> */
    private static array $kit = [];

    private mixed $savedWpdb = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once __DIR__ . '/doubles.php';
        self::$host = new ReadHost();
        Runtime\host(self::$host);
        self::$kit = require dirname(__DIR__, 4) . '/includes/kits/security-status/bootstrap.php';
    }

    protected function setUp(): void
    {
        Caps::$granted = ['manage_options'];
        Registrations::$args = [];
        self::$host->enabled = true;
        Runtime\host(self::$host);
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['itsec_lockout'] = new FakeLockout();
        \wfBlock::$blocks = [];
        \ITSEC_Core::$broken = false;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        unset($GLOBALS['itsec_lockout']);
    }

    // Registration.

    public function testTheThreeReadsRegisterWhileTheirNamesAreFree(): void
    {
        $this->registerAbilities();

        $names = ['wppilot/security-plugin-status', 'wppilot/security-scan-findings', 'wppilot/security-lockouts'];
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/security-status/kit.json'), true);
        self::assertSame($names, array_column($manifest['abilities'], 'name'));
        foreach ($names as $name) {
            $args = Registrations::$args[$name];
            self::assertSame('security', $args['category']);
            self::assertTrue($args['meta']['annotations']['readonly'], $name);
            self::assertFalse($args['meta']['annotations']['destructive'], $name);
            self::assertTrue(($args['permission_callback'])(), $name);
        }
        Caps::$granted = [];
        self::assertFalse((Registrations::$args['wppilot/security-lockouts']['permission_callback'])(), 'manage_options is required');
    }

    // Redaction.

    public function testIpsAreCutToTheirNetwork(): void
    {
        self::assertSame('203.0.113.0/24', S\mask_ip('203.0.113.45'));
        self::assertSame('203.0.113.0/24', S\mask_ip('::ffff:203.0.113.45'), 'IPv4-mapped IPv6 masked as IPv4');
        self::assertSame('2001:db8:abcd::/48', S\mask_ip('2001:db8:abcd:12:1:2:3:4'));
        self::assertNull(S\mask_ip('example.com'), 'a non-IP is dropped, not echoed');
        self::assertNull(S\mask_ip(''));
        self::assertSame('198.51.100.0/24', S\mask_host('198.51.100.7/28'), 'a narrow range is widened');
        self::assertSame('10.0.0.0/8', S\mask_host('10.1.2.3/8'), 'a wide range keeps its width');
        self::assertSame('203.0.113.0/24', S\mask_host('203.0.113.*'), 'a legacy wildcard counts its fixed octets');
    }

    public function testLoginsBecomeAUserIdOrAMask(): void
    {
        self::assertSame(['user_id' => 1], S\describe_login('admin'));
        self::assertSame(['user_id' => 1], S\describe_login('owner@example.com'));
        self::assertSame(['username' => 'g****'], S\describe_login('ghost@example.net'));
        self::assertSame(['username' => 'n****'], S\describe_login('nosuchuser'));
    }

    public function testFreeTextLosesEmailsAndFullIps(): void
    {
        self::assertSame(
            'Seen from 198.51.100.0/24 and 2001:db8:1::/48 by [email] at 10:30:00',
            S\redact_text('Seen from 198.51.100.77 and 2001:db8:1:2::9 by bob@example.org <b>at</b> 10:30:00'),
        );
        self::assertSame('Hello Dolly <= 1.7.2 - Stored XSS', S\redact_text('Hello Dolly <= 1.7.2 - Stored XSS'), 'a "<=" survives');
    }

    public function testPathsAreRelativeToTheWordPressRoot(): void
    {
        self::assertSame('wp-content/uploads/x.php', S\relative_path(ABSPATH . 'wp-content/uploads/x.php'));
        self::assertSame('wp-config.php', S\relative_path('./wp-config.php'));
        self::assertSame('…/passwd', S\relative_path('/etc/passwd'));
        self::assertSame([1, 200, 50], [S\clamp_limit(0), S\clamp_limit(999), S\clamp_limit(null)]);
    }

    // Provider selection.

    public function testProvidersAreSelectedFromTheActiveOnes(): void
    {
        self::assertSame(['wordfence', 'solid-security'], S\active_providers());
        self::assertInstanceOf(WP_Error::class, S\selected_providers(['provider' => 'bogus']));
        self::assertSame(['solid-security'], S\selected_providers(['provider' => 'solid-security']));
    }

    // Wordfence.

    public function testWordfenceStatusCarriesNoSecrets(): void
    {
        $GLOBALS['wpdb']->blocks = 2;

        $status = S\plugin_status(['provider' => 'wordfence']);
        $wf = $status['providers'][0];

        self::assertSame(['wordfence', '9.0.1'], [$wf['provider'], $wf['version']]);
        self::assertSame(['learning-mode', true], [$wf['firewall']['mode'], $wf['firewall']['enabled']]);
        self::assertSame(['ok', '2026-09-21T14:13:20Z'], [$wf['scans']['last_scan_result'], $wf['scans']['last_scan_at']]);
        self::assertSame(['available' => false], $wf['login_protection']['two_factor']);
        self::assertSame(2, $wf['active_blocks']);
        self::assertSame(
            'SELECT COUNT(*) FROM `wp_wfblocks7` WHERE `expiration` = 0 OR `expiration` > UNIX_TIMESTAMP()',
            end($GLOBALS['wpdb']->queries),
            'the blocks table is bound as a %i identifier',
        );
        self::assertStringNotContainsString('SECRET', (string) json_encode($status));
    }

    public function testWordfenceFindings(): void
    {
        $GLOBALS['wpdb']->issues = [
            ['id' => 1, 'time' => 1790000000, 'lastUpdated' => 1790000100, 'type' => 'file', 'severity' => 100, 'shortMsg' => 'Malicious file from 203.0.113.9', 'data' => serialize(['file' => 'wp-content/uploads/evil.php'])],
            ['id' => 2, 'time' => 1790000000, 'lastUpdated' => 1790000000, 'type' => 'wfPluginUpgrade', 'severity' => 50, 'shortMsg' => 'Update Hello Dolly', 'data' => serialize(['pluginFile' => 'hello-dolly/hello.php'])],
            ['id' => 3, 'time' => 1790000000, 'lastUpdated' => 1790000000, 'type' => 'wfThemeUpgrade', 'severity' => 25, 'shortMsg' => 'Update theme', 'data' => 'O:8:"stdClass":0:{}'],
        ];

        $findings = S\scan_findings(['provider' => 'wordfence', 'limit' => 2]);

        self::assertSame(['critical' => 1, 'high' => 0, 'medium' => 1, 'low' => 1, 'info' => 0], $findings['counts']);
        self::assertSame([3, 2, true], [$findings['total'], $findings['returned'], $findings['truncated']]);
        self::assertSame('wp-content/uploads/evil.php', $findings['findings'][0]['path']);
        self::assertSame('Malicious file from 203.0.113.0/24', $findings['findings'][0]['description']);
        self::assertSame(['type' => 'plugin', 'slug' => 'hello-dolly'], $findings['findings'][1]['component']);

        $only = S\scan_findings(['provider' => 'wordfence', 'severity' => ['medium']]);
        self::assertSame(['medium'], array_column($only['findings'], 'severity'));
        $queries = $GLOBALS['wpdb']->queries;
        self::assertStringContainsString("`status` = 'new'", (string) end($queries), 'only open issues');
        self::assertStringContainsString(
            "FROM `wp_wfissues` WHERE `status` = 'new' AND ((`severity` >= 50 AND `severity` < 75)) ORDER BY",
            (string) end($queries),
            'table bound as %i, band values bound as %d',
        );
        self::assertContains(
            "SELECT `severity`, COUNT(*) AS `n` FROM `wp_wfissues` WHERE `status` = 'new' GROUP BY `severity`",
            $queries,
        );

        $low = S\scan_findings(['provider' => 'wordfence', 'severity' => ['low']]);
        self::assertSame(['type' => 'theme'], $low['findings'][0]['component'], 'serialized objects are refused, not revived');
    }

    public function testWordfenceLockoutsNameNobody(): void
    {
        \wfBlock::$blocks = [
            new \wfBlock(9, 7, '::ffff:203.0.113.46', time() - 60, "Exceeded the maximum number of login failures which is: 20. The last username they tried to sign in with was: 'admin'", time() - 60, 1, time() + 3600),
            new \wfBlock(8, 7, '203.0.113.45', time() - 120, "Used an invalid username 'ghost@example.net' to try to sign in", time() - 120, 3, time() + 3600),
            new \wfBlock(7, 1, '2001:db8:5:6::1', time() - 180, 'Manual block by administrator', 0, 0, 0),
        ];
        $GLOBALS['wpdb']->blocks = 2;

        $locks = S\lockouts(['provider' => 'wordfence', 'limit' => 2]);

        self::assertSame([2, 2, true], [$locks['active'], $locks['returned'], $locks['truncated']]);
        $first = $locks['lockouts'][0];
        self::assertSame(1, $first['user_id']);
        self::assertStringContainsString("'user #1'", $first['reason']);
        self::assertStringNotContainsString('admin', $first['reason']);
        $second = $locks['lockouts'][1];
        self::assertSame('g****', $second['username']);
        self::assertStringNotContainsString('example.net', (string) json_encode($second));
        self::assertSame(['203.0.113.0/24', '203.0.113.0/24'], [$first['ip_network'], $second['ip_network']]);

        $all = S\lockouts(['provider' => 'wordfence']);
        self::assertSame([true, null, '2001:db8:5::/48'], [$all['lockouts'][2]['permanent'], $all['lockouts'][2]['expires_at'], $all['lockouts'][2]['ip_network']]);
    }

    // Solid Security.

    public function testSolidStatusAndLockouts(): void
    {
        $solid = S\plugin_status(['provider' => 'solid-security'])['providers'][0];
        self::assertSame(['Kadence Security Basic', true], [$solid['name'], $solid['firewall']['enabled']]);
        self::assertTrue($solid['login_protection']['brute_force_module_enabled']);
        self::assertFalse($solid['login_protection']['brute_force_protection'], 'not running without IP detection');
        self::assertSame('never_run', $solid['scans']['last_scan_result']);

        $GLOBALS['itsec_lockout']->rows = [
            ['lockout_id' => '3', 'lockout_type' => 'brute_force', 'lockout_start_gmt' => gmdate('Y-m-d H:i:s', time() - 30), 'lockout_expire_gmt' => gmdate('Y-m-d H:i:s', time() + 900), 'lockout_host' => null, 'lockout_user' => null, 'lockout_username' => 'phantom@example.net', 'lockout_active' => '1'],
            ['lockout_id' => '2', 'lockout_type' => 'brute_force', 'lockout_start_gmt' => gmdate('Y-m-d H:i:s', time() - 40), 'lockout_expire_gmt' => gmdate('Y-m-d H:i:s', time() + 900), 'lockout_host' => null, 'lockout_user' => '7', 'lockout_username' => null, 'lockout_active' => '1'],
            ['lockout_id' => '1', 'lockout_type' => 'four_oh_four', 'lockout_start_gmt' => gmdate('Y-m-d H:i:s', time() - 5000), 'lockout_expire_gmt' => gmdate('Y-m-d H:i:s', time() - 4000), 'lockout_host' => '198.51.100.20', 'lockout_user' => null, 'lockout_username' => null, 'lockout_active' => '0'],
        ];

        $sl = S\lockouts(['provider' => 'solid-security']);
        self::assertSame(2, $sl['active'], 'lifted lockouts are listed but not counted active');
        self::assertCount(3, $sl['lockouts']);
        self::assertSame('p****', $sl['lockouts'][0]['username']);
        self::assertSame(7, $sl['lockouts'][1]['user_id']);
        self::assertSame([false, '198.51.100.0/24', 'four_oh_four'], [$sl['lockouts'][2]['active'], $sl['lockouts'][2]['ip_network'], $sl['lockouts'][2]['reason']]);
        self::assertCount(2, S\lockouts(['provider' => 'solid-security', 'include_expired' => false])['lockouts']);
    }

    public function testOneProviderFailingNeverHidesTheOther(): void
    {
        \ITSEC_Core::$broken = true;

        $both = S\plugin_status([]);

        self::assertSame('wordfence', $both['providers'][0]['provider']);
        self::assertArrayHasKey('version', $both['providers'][0]);
        self::assertSame('solid-security', $both['providers'][1]['provider']);
        self::assertArrayHasKey('error', $both['providers'][1]);
    }

    // Last: it leaves the names claimed in the shared registry, which the tests above need free.
    public function testTheKitStandsAsideWhenAnotherPluginRegisteredTheNamesFirst(): void
    {
        foreach (['wppilot/security-plugin-status', 'wppilot/security-scan-findings', 'wppilot/security-lockouts'] as $name) {
            if (!\wp_has_ability($name)) {
                \wp_register_ability($name, ['label' => 'Registered first elsewhere']);
            }
        }

        $this->registerAbilities();

        self::assertSame([], Registrations::$args);
    }

    private function registerAbilities(): void
    {
        foreach (self::$kit['ability_files'] as $file) {
            require $file;
        }
    }
}
