<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SiteKitSharing;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\SiteKitSharing as S;

/**
 * site-kit-sharing: the request Site Kit's sharing route receives (existing roles kept, only the
 * modules this user may manage), each module's outcome, the owner named on refusal, and the
 * option rows before, after and after undo — including the PageSpeed Insights owner Site Kit
 * reassigns on every save.
 */
final class SiteKitSharingTest extends TestCase
{
    private static SharingHost $host;

    /** @var array<string, mixed> */
    private static array $kit = [];

    private mixed $savedWpdb = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once __DIR__ . '/doubles.php';
        if (!defined('GOOGLESITEKIT_VERSION')) {
            define('GOOGLESITEKIT_VERSION', '1.189.0');
        }
        self::$host = new SharingHost(new RecordingLedger());
        Runtime\host(self::$host);
        self::$kit = require dirname(__DIR__, 4) . '/includes/kits/site-kit-sharing/bootstrap.php';
    }

    protected function setUp(): void
    {
        Runtime\host(self::$host);
        FakeSiteKit::reset();
        Store::$rows = [];
        Store::$user = 1;
        remove_all_filters('wppilot_kit_site_kit_sharing_pre_request');
        add_filter('wppilot_kit_site_kit_sharing_pre_request', [FakeSiteKit::class, 'handle'], 10, 4);
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
    }

    public function testTheAbilityRegistersAsAConfirmedWriteWithItsUndo(): void
    {
        require dirname(__DIR__, 4) . '/includes/kits/site-kit-sharing/src/abilities/site-kit-enable-sharing.php';
        $args = Registrations::$args['wppilot/site-kit-enable-sharing'];

        self::assertSame(['includes/kits/site-kit-sharing/src/abilities/site-kit-enable-sharing.php'], array_map(
            static fn(string $f): string => str_replace('\\', '/', substr($f, strlen(dirname(__DIR__, 4)) + 1)),
            self::$kit['ability_files'],
        ));
        self::assertSame('site-kit', $args['category']);
        self::assertTrue($args['meta']['annotations']['destructive']);
        self::assertFalse($args['meta']['annotations']['readonly']);
        self::assertArrayHasKey('confirm', $args['input_schema']['properties']);
        self::assertArrayHasKey('wppilot/site-kit-enable-sharing', self::$host->ledger->captures);

        (self::$kit['boot'])(self::$host);
        self::assertArrayHasKey(S\STRATEGY, self::$host->ledger->strategies);
    }

    public function testSharingIsSentTheWaySiteKitsDialogSendsItAndUndoneExactly(): void
    {
        // A site where Search Console is already shared with editors, and nothing else is stored.
        Store::$rows['googlesitekit_dashboard_sharing'] = ['search-console' => ['sharedRoles' => ['editor'], 'management' => 'owner']];
        Store::$rows['googlesitekit_pagespeed-insights_settings'] = ['ownerID' => 7];
        FakeSiteKit::$modules['pagespeed-insights']['owner'] = 7;
        $before = Store::$rows;
        $snapshot = (self::$host->ledger->captures['wppilot/site-kit-enable-sharing'] ?? static fn(array $i): array => S\snapshot())([]);

        $result = S\enable_sharing(['confirm' => true]);

        self::assertIsArray($result);
        self::assertTrue($result['changed']);
        $post = FakeSiteKit::$calls[2];
        self::assertSame(['method' => 'POST', 'route' => 'core/modules/data/sharing-settings'], ['method' => $post['method'], 'route' => $post['route']]);
        self::assertSame([
            'search-console' => ['sharedRoles' => ['editor', 'administrator']],
            'analytics-4' => ['sharedRoles' => ['administrator']],
            'pagespeed-insights' => ['sharedRoles' => ['administrator']],
        ], $post['params']['data'], 'existing roles are carried, since Site Kit replaces sharedRoles whole');
        foreach (S\MODULES as $slug) {
            self::assertSame('shared', $result['modules'][$slug]['status'], $slug);
            self::assertContains('administrator', Store::$rows['googlesitekit_dashboard_sharing'][$slug]['sharedRoles']);
        }
        self::assertSame(['pagespeed-insights' => 1], (array) $result['new_owner_ids']);
        self::assertSame(1, Store::$rows['googlesitekit_pagespeed-insights_settings']['ownerID'], 'Site Kit made the saving user the owner');

        $restored = S\restore(['snapshot' => $snapshot]);

        self::assertTrue($restored['verified']);
        self::assertSame($before, Store::$rows, 'both rows exactly as before, owner 7 included');
    }

    public function testAnOptionThatDidNotExistIsRemovedAgainOnUndo(): void
    {
        $snapshot = S\snapshot();
        self::assertSame(['absent' => true], $snapshot['options']['googlesitekit_dashboard_sharing'], 'Site Kit\'s filtered default is not mistaken for a stored row');

        S\enable_sharing(['confirm' => true, 'modules' => ['analytics-4']]);
        self::assertArrayHasKey('googlesitekit_dashboard_sharing', Store::$rows);

        $restored = S\restore(['snapshot' => $snapshot]);
        self::assertTrue($restored['verified']);
        self::assertSame([], Store::$rows);
    }

    public function testNothingIsSentWhenEveryModuleIsAlreadyShared(): void
    {
        $shared = ['sharedRoles' => ['administrator'], 'management' => 'owner'];
        Store::$rows['googlesitekit_dashboard_sharing'] = ['search-console' => $shared, 'analytics-4' => $shared, 'pagespeed-insights' => $shared];

        $result = S\enable_sharing(['confirm' => true]);

        self::assertFalse($result['changed']);
        self::assertSame(['already_shared'], array_values(array_unique(array_column($result['modules'], 'status'))));
        self::assertCount(2, FakeSiteKit::$calls, 'only the connection and the module list were read');
    }

    public function testAModuleOwnedBySomeoneElseIsReportedWithItsOwner(): void
    {
        FakeSiteKit::$modules['search-console']['owner'] = 3;
        FakeSiteKit::$modules['analytics-4']['owner'] = 3;

        $result = S\enable_sharing(['confirm' => true]);

        self::assertIsArray($result);
        self::assertSame('not_permitted', $result['modules']['search-console']['status']);
        self::assertSame(['id' => 3, 'login' => 'owner3'], $result['modules']['search-console']['owner']);
        self::assertSame('shared', $result['modules']['pagespeed-insights']['status'], 'PageSpeed Insights is all_admins by default');
        self::assertSame(['pagespeed-insights'], array_keys(FakeSiteKit::$calls[2]['params']['data']));

        FakeSiteKit::reset();
        FakeSiteKit::$modules['search-console']['owner'] = 3;
        $refused = S\enable_sharing(['confirm' => true, 'modules' => ['search-console']]);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('kit_site_kit_sharing_not_permitted', $refused->get_error_code());
        self::assertStringContainsString('search-console: owner3 (user 3)', $refused->get_error_message());
        self::assertCount(2, FakeSiteKit::$calls, 'nothing was posted');
    }

    public function testASiteWhoseSetupIsNotCompleteIsNotConnected(): void
    {
        // Site Kit 1.189.0 reports Search Console connected before anyone signed in to Google.
        FakeSiteKit::$setupCompleted = false;
        FakeSiteKit::$signedIn = false;

        $error = S\enable_sharing(['confirm' => true]);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('kit_site_kit_not_connected', $error->get_error_code());
        self::assertCount(1, FakeSiteKit::$calls, 'stopped at the connection');
    }

    public function testASiteNotConnectedToGoogleHasNothingToShare(): void
    {
        foreach (S\MODULES as $slug) {
            FakeSiteKit::$modules[$slug] = ['active' => false, 'connected' => false, 'owner' => 0];
        }
        FakeSiteKit::$signedIn = false;

        $error = S\enable_sharing(['confirm' => true]);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('kit_site_kit_not_connected', $error->get_error_code());
        self::assertSame('not_connected', $error->get_error_data()['modules']['analytics-4']['status']);
        self::assertSame([], Store::$rows, 'nothing written');
    }

    public function testSiteKitRefusingAnAdminNotSignedInWithGoogleIsReportedAsNotPermitted(): void
    {
        FakeSiteKit::$signedIn = false;
        // The per-module check also fails, so nothing is sent at all.
        $error = S\enable_sharing(['confirm' => true]);
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('kit_site_kit_sharing_not_permitted', $error->get_error_code());
    }

    public function testModulesInputIsValidated(): void
    {
        foreach ([[], ['tagmanager'], 'search-console'] as $bad) {
            $error = S\enable_sharing(['confirm' => true, 'modules' => $bad]);
            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('kit_site_kit_invalid_input', $error->get_error_code());
        }
    }

    public function testADamagedSnapshotIsRefusedRatherThanHalfRestored(): void
    {
        $error = S\restore(['snapshot' => ['type' => S\STRATEGY, 'options' => ['googlesitekit_dashboard_sharing' => ['absent' => true]]]]);
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('kit_rollback_invalid', $error->get_error_code());
    }

    public function testADamagedSecondRowLeavesBothCurrentOptionsUntouched(): void
    {
        Store::$rows[S\SHARING_OPTION] = ['analytics-4' => ['sharedRoles' => ['administrator']]];
        Store::$rows[S\PSI_SETTINGS_OPTION] = ['ownerID' => 7];
        $before = Store::$rows;
        foreach (['damaged', ['absent' => false]] as $broken) {
            $error = S\restore(['options' => [S\SHARING_OPTION => ['absent' => true], S\PSI_SETTINGS_OPTION => $broken]]);
            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame($before, Store::$rows);
        }
    }
}
