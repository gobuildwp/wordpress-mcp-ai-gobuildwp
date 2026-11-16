<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SearchReplace;

use Kit_Test_Site;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\SearchReplace as SR;

require_once __DIR__ . '/harness.php';

/**
 * A $wpdb over the stored options, answering the three option queries the kit makes.
 *
 * Values come back as WordPress stores them: arrays serialized, an already-serialized string
 * serialized again, scalars as strings. Each option keeps the id it was first seen with.
 */
final class FakeOptionsWpdb
{
    public string $options = 'wp_options';

    /** @var array<string, int> */
    private array $ids = [];

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $index = 0;
        return (string) preg_replace_callback('/%[sd]/', static function (array $match) use (&$index, $args): string {
            $value = $args[$index++] ?? null;
            return $match[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    /** @return list<array<string, mixed>> */
    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        $rows = $this->rows();
        if (preg_match('/option_id IN \(([\d,\s]*)\)/', $sql, $in) === 1) {
            $wanted = array_map('intval', explode(',', $in[1]));
            $out = [];
            foreach ($rows as $row) {
                if (in_array($row['option_id'], $wanted, true)) {
                    $out[] = ['option_id' => (string) $row['option_id'], 'option_value' => $row['option_value']];
                }
            }
            return $out;
        }
        preg_match('/option_id > (\d+)/', $sql, $after);
        preg_match('/LIMIT (\d+)/', $sql, $limit);
        $out = [];
        foreach ($rows as $row) {
            if ($row['option_id'] <= (int) $after[1]) {
                continue;
            }
            $out[] = ['option_id' => (string) $row['option_id'], 'option_name' => $row['option_name'], 'bytes' => (string) strlen($row['option_value'])];
            if (count($out) >= (int) $limit[1]) {
                break;
            }
        }
        return $out;
    }

    public function get_var(string $sql): ?string
    {
        preg_match("/option_name = '((?:[^'\\\\]|\\\\.)*)'/", $sql, $name);
        foreach ($this->rows() as $row) {
            if ($row['option_name'] === stripslashes($name[1])) {
                return $row['option_value'];
            }
        }
        return null;
    }

    /** @return list<array{option_id: int, option_name: string, option_value: string}> */
    private function rows(): array
    {
        $rows = [];
        foreach (Kit_Test_Site::options() as $name => $value) {
            $name = (string) $name;
            $this->ids[$name] ??= count($this->ids) + 1;
            $stored = maybe_serialize($value);
            $rows[] = ['option_id' => $this->ids[$name], 'option_name' => $name, 'option_value' => is_string($stored) ? $stored : (string) $stored];
        }
        usort($rows, static fn(array $a, array $b): int => $a['option_id'] <=> $b['option_id']);
        return $rows;
    }
}

/**
 * The options target: the protected-options denylist, preview, apply, and the exact-bytes undo.
 */
final class OptionsTest extends TestCase
{
    private FakeHost $host;

    private mixed $previousWpdb = null;

    /** @var array<string, mixed> */
    private array $previousOptions = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/search-replace/bootstrap.php';
    }

    protected function setUp(): void
    {
        Kit_Test_Site::reset();
        Kit_Test_Site::as_user(1, 'manage_options', 'edit_post');
        $this->previousOptions = Kit_Test_Site::replace_options([]);
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new FakeOptionsWpdb();
        $this->host = new FakeHost();
        Runtime\host($this->host);
        $kit = require dirname(__DIR__, 4) . '/includes/kits/search-replace/bootstrap.php';
        ($kit['boot'])($this->host);

        Kit_Test_Site::replace_options([
            'siteurl' => 'https://example.com',
            'home' => 'https://example.com',
            'blogdescription' => 'Visit example.com today',
            'widget_text' => [2 => ['title' => 'Find us', 'text' => 'See example.com for hours'], '_multiwidget' => 1],
            'acme_settings' => ['label' => 'Shop', 'api_key' => 'example.com-12345'],
            'acme_license_key' => 'example.com-LICENCE',
            '_transient_feed' => 'example.com feed',
            'wp_user_roles' => ['editor' => ['name' => 'example.com editor']],
            'unrelated' => 'nothing to see',
        ]);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        Kit_Test_Site::replace_options($this->previousOptions);
        Kit_Test_Site::reset();
    }

    /** @return array<string, mixed> */
    private static function preview(array $input = []): array
    {
        $result = SR\preview($input + ['search' => 'example.com', 'replace' => 'example.org', 'target' => 'options']);
        self::assertIsArray($result, $result instanceof WP_Error ? $result->get_error_message() : '');
        return $result;
    }

    /** @return array<string, mixed> */
    private static function apply(array $input): array
    {
        $result = SR\apply($input + ['confirm' => true]);
        self::assertIsArray($result, $result instanceof WP_Error ? $result->get_error_message() : '');
        return $result;
    }

    public function testTheDenylistCoversAddressPluginsSecretsRolesCronTransientsAndCredentials(): void
    {
        foreach (['siteurl', 'home', 'active_plugins', 'cron', 'auth_key', 'logged_in_salt', 'NONCE_SALT', 'wp_user_roles', 'wp2_user_roles', 'default_role', '_transient_x', '_site_transient_y', 'wppilot_kit_search_replace_plan_x', 'acme_api_key', 'mailer_smtp_password', 'stripe_secret', 'acme_license_key', 'gh_oauth_state'] as $name) {
            self::assertNotNull(SR\option_denied($name), $name);
        }
        foreach (['blogname', 'blogdescription', 'widget_text', 'theme_mods_twentytwentyfour', 'elementor_active_kit'] as $name) {
            self::assertNull(SR\option_denied($name), $name);
        }
    }

    public function testTheHostCanOnlyAddProtectedPrefixes(): void
    {
        $host = new class extends FakeHostBase {
            public function extension(string $point): mixed
            {
                return $point === 'protected-option-prefixes' ? ['acme_', 42, ''] : null;
            }
        };
        Runtime\host($host);
        self::assertNotNull(SR\option_denied('acme_anything'));
        self::assertNotNull(SR\option_denied('_transient_still_denied'));
        self::assertNull(SR\option_denied('blogname'));
    }

    public function testPreviewListsMatchesAndLeavesProtectedAndCredentialOptionsOut(): void
    {
        $result = self::preview();

        self::assertSame('options', $result['target']);
        self::assertIsString($result['plan_id']);
        $names = array_column($result['options'], 'option');
        self::assertSame(['blogdescription', 'widget_text'], $names);
        // siteurl, home, acme_license_key, the transient and the roles option.
        self::assertSame(5, $result['totals']['protected']);
        $skipped = array_column($result['skipped'], 'reason', 'option');
        self::assertSame('kit_sr_option_credential', $skipped['acme_settings'] ?? null);
        self::assertSame('serialized', $result['options'][1]['changes'][0]['encoding']);
        self::assertStringNotContainsString('12345', (string) wp_json_encode($result));
        // Nothing written by a preview.
        self::assertSame('Visit example.com today', get_option('blogdescription'));
    }

    public function testApplyWritesSerializedValuesWithCorrectLengthsAndRecordsAnUndoableRowEach(): void
    {
        $plan = self::preview()['plan_id'];
        $result = self::apply(['plan_id' => $plan]);

        self::assertSame(2, $result['applied']);
        self::assertSame(0, $result['remaining']);
        self::assertSame('Visit example.org today', get_option('blogdescription'));
        $widget = get_option('widget_text');
        self::assertSame('See example.org for hours', $widget[2]['text']);
        self::assertSame(1, $widget['_multiwidget']);
        $stored = $GLOBALS['wpdb']->get_var("SELECT option_value FROM wp_options WHERE option_name = 'widget_text' LIMIT 1");
        self::assertStringContainsString('s:25:"See example.org for hours"', (string) $stored);
        self::assertNotNull(unserialize((string) $stored, ['allowed_classes' => false]));

        self::assertSame('https://example.com', get_option('siteurl'));
        self::assertSame('example.com-12345', get_option('acme_settings')['api_key']);
        self::assertSame('example.com feed', get_option('_transient_feed'));

        $rows = $this->host->ledger->rows();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame(SR\OPTION_STRATEGY, $row['before']['type']);
            self::assertArrayHasKey('after', $row['before']);
        }
    }

    public function testApplyNeedsConfirmAndManageOptions(): void
    {
        $plan = self::preview()['plan_id'];
        $refused = SR\apply(['plan_id' => $plan]);
        self::assertInstanceOf(WP_Error::class, $refused);

        Kit_Test_Site::as_user(1, 'edit_post');
        $denied = SR\apply(['plan_id' => $plan, 'confirm' => true]);
        self::assertInstanceOf(WP_Error::class, $denied);
        self::assertSame('kit_sr_forbidden', $denied->get_error_code());
        self::assertSame('Visit example.com today', get_option('blogdescription'));

        $preview = SR\preview(['search' => 'example.com', 'target' => 'options']);
        self::assertInstanceOf(WP_Error::class, $preview);
    }

    public function testAnOptionChangedSinceThePreviewIsSkipped(): void
    {
        $plan = self::preview()['plan_id'];
        update_option('blogdescription', 'Someone edited example.com');

        $result = self::apply(['plan_id' => $plan]);

        self::assertSame(1, $result['applied']);
        self::assertSame('kit_sr_changed_since_preview', $result['skipped'][0]['reason']);
        self::assertSame('Someone edited example.com', get_option('blogdescription'));
    }

    public function testApplyCanBeNarrowedToNamedOptions(): void
    {
        $plan = self::preview()['plan_id'];
        $result = self::apply(['plan_id' => $plan, 'options' => ['widget_text']]);

        self::assertSame(1, $result['applied']);
        self::assertSame('Visit example.com today', get_option('blogdescription'));
        $status = SR\status(['plan_id' => $plan]);
        self::assertIsArray($status);
        self::assertSame('options', $status['plan']['target']);
        self::assertSame(2, $status['plan']['options']);
        self::assertSame(1, $status['plan']['counts']['pending']);
    }

    public function testNamingAProtectedOptionOrAPostScopeIsRefused(): void
    {
        $protected = SR\preview(['search' => 'example.com', 'target' => 'options', 'options' => ['siteurl']]);
        self::assertInstanceOf(WP_Error::class, $protected);
        self::assertSame('kit_sr_option_protected', $protected->get_error_code());

        $mixed = SR\preview(['search' => 'example.com', 'target' => 'options', 'post_types' => ['page']]);
        self::assertInstanceOf(WP_Error::class, $mixed);
        self::assertSame('kit_sr_bad_scope', $mixed->get_error_code());

        $posts = SR\preview(['search' => 'example.com', 'options' => ['blogname']]);
        self::assertInstanceOf(WP_Error::class, $posts);
        self::assertSame('kit_sr_bad_scope', $posts->get_error_code());
    }

    public function testUndoPutsBackTheExactBytesAndRefusesAfterALaterEditUnlessForced(): void
    {
        $before = $GLOBALS['wpdb']->get_var("SELECT option_value FROM wp_options WHERE option_name = 'widget_text' LIMIT 1");
        self::apply(['plan_id' => self::preview()['plan_id']]);
        $widget_row = null;
        foreach ($this->host->ledger->rows() as $row) {
            if ($row['before']['option'] === 'widget_text') {
                $widget_row = $row['before'];
            }
        }
        self::assertNotNull($widget_row);

        $restored = SR\restore_option($widget_row);
        self::assertIsArray($restored);
        self::assertTrue($restored['verified']);
        self::assertSame($before, $GLOBALS['wpdb']->get_var("SELECT option_value FROM wp_options WHERE option_name = 'widget_text' LIMIT 1"));

        // Apply again, then someone edits the widget: undo would throw the edit away.
        $this->host->ledger->calls = [];
        self::apply(['plan_id' => self::preview()['plan_id']]);
        foreach ($this->host->ledger->rows() as $row) {
            if ($row['before']['option'] === 'widget_text') {
                $widget_row = $row['before'];
            }
        }
        update_option('widget_text', [2 => ['title' => 'Edited', 'text' => 'new'], '_multiwidget' => 1]);

        $refused = SR\restore_option($widget_row);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('kit_sr_option_changed_since', $refused->get_error_code());
        self::assertSame('Edited', get_option('widget_text')[2]['title']);

        $forced = SR\restore_option($widget_row, ['force' => true]);
        self::assertIsArray($forced);
        self::assertTrue($forced['verified']);
        self::assertSame('See example.com for hours', get_option('widget_text')[2]['text']);
    }

    public function testUndoRefusesAProtectedOption(): void
    {
        $refused = SR\restore_option(['type' => SR\OPTION_STRATEGY, 'option' => 'siteurl', 'value' => 'https://evil.test', 'fingerprint' => '']);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('https://example.com', get_option('siteurl'));
    }
}

/** FakeHost with an overridable extension(), for the protected-prefix test. */
abstract class FakeHostBase implements Runtime\Host
{
    private FakeHost $inner;

    public function __construct()
    {
        $this->inner = new FakeHost();
    }

    public function id(): string
    {
        return 'test';
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
        return 'production';
    }

    public function ledger(): Runtime\Ledger
    {
        return $this->inner->ledger;
    }

    public function jobs(): Runtime\Jobs
    {
        return $this->inner->jobs;
    }

    public function admin_parent_slug(): string
    {
        return 'tools.php';
    }

    public function confirm_guard(string $ability_name, array $input): bool|WP_Error
    {
        return $this->inner->confirm_guard($ability_name, $input);
    }
}
