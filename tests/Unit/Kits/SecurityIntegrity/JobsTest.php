<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Tests\Unit\Kits\SecurityIntegrity;

require_once __DIR__ . '/harness.php';

use GoBuildWP\Kits\SecurityIntegrity as SI;
use Kit_Test_Site;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;

/**
 * The eight abilities on the real job runner: start, Cloud-pumped status, results paging,
 * reuse, resume, cancel and the safe-updates lock.
 */
final class JobsTest extends TestCase
{
    private const NAMES = [
        'gobuildwp/integrity-scan-start',
        'gobuildwp/integrity-scan-status',
        'gobuildwp/integrity-scan-results',
        'gobuildwp/integrity-scan-cancel',
        'gobuildwp/db-security-scan-start',
        'gobuildwp/db-security-scan-status',
        'gobuildwp/db-security-scan-results',
        'gobuildwp/db-security-scan-cancel',
    ];

    private FakeHost $host;

    private Fixture $site;

    private mixed $previousWpdb = null;

    protected function setUp(): void
    {
        reset_state();
        Kit_Test_Site::as_user(1, 'manage_options');
        $this->site = new Fixture();
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new FakeWpdb();
        $this->host = new FakeHost();
        Runtime\host($this->host);
        $kit = require dirname(__DIR__, 4) . '/includes/kits/security-integrity/bootstrap.php';
        ($kit['boot'])($this->host);
        foreach ($kit['ability_files'] as $file) {
            require $file;
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        $this->site->remove();
        reset_state();
    }

    /** @return array<string, mixed>|WP_Error */
    private static function call(string $name, array $input = []): array|WP_Error
    {
        $args = Kit_Test_Site::registration($name);
        self::assertNotNull($args, $name);
        return ($args['execute_callback'])($input);
    }

    /** @return array<string, mixed> */
    private static function ok(string $name, array $input = []): array
    {
        $result = self::call($name, $input);
        self::assertIsArray($result, $result instanceof WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '');
        return $result;
    }

    /** Pump status until the job leaves queued/running. @return array<string, mixed> */
    private static function finish(string $status_ability, string $job_id, int $max = 50): array
    {
        for ($i = 0; $i < $max; $i++) {
            $status = self::ok($status_ability, ['job_id' => $job_id]);
            if (!in_array($status['state'], ['queued', 'running'], true)) {
                return $status;
            }
        }
        self::fail('The scan did not finish in ' . $max . ' steps.');
    }

    private function uploads_with_shell(): void
    {
        $this->site->put('wp-content/uploads/2026/shell.php', '<?php echo 1;');
        $this->site->put('wp-content/uploads/2026/photo.jpg', 'JPEG');
    }

    public function test_abilities_are_public_readonly_and_need_manage_options(): void
    {
        foreach (self::NAMES as $name) {
            $args = Kit_Test_Site::registration($name);
            self::assertNotNull($args, $name);
            self::assertSame('security-integrity', $args['category'], $name);
            self::assertTrue($args['meta']['annotations']['readonly'], $name);
            self::assertFalse($args['meta']['annotations']['destructive'], $name);
            self::assertTrue($args['meta']['mcp']['public'], $name);
            self::assertTrue($args['meta']['show_in_rest'], $name);
            self::assertArrayNotHasKey('safety', $args['meta'], $name . ' has no min_profile');
        }
        Kit_Test_Site::as_user(1, 'edit_posts');
        foreach (self::NAMES as $name) {
            self::assertFalse((Kit_Test_Site::registration($name)['permission_callback'])(), $name);
        }
        $this->host->cloudVerified = true;
        foreach (self::NAMES as $name) {
            self::assertFalse((Kit_Test_Site::registration($name)['permission_callback'])(), $name . ' still needs manage_options');
        }
    }

    /**
     * Starting and cancelling a scan run only from a signed Cloud request; reading a scan's
     * status and results stays open to any administrator.
     */
    public function test_start_and_cancel_need_a_verified_cloud_request_and_reads_do_not(): void
    {
        $writes = [
            'gobuildwp/integrity-scan-start',
            'gobuildwp/integrity-scan-cancel',
            'gobuildwp/db-security-scan-start',
            'gobuildwp/db-security-scan-cancel',
            'gobuildwp/security-fix-apply',
            'gobuildwp/security-fix-undo',
        ];
        $names = [...self::NAMES, 'gobuildwp/security-fix-apply', 'gobuildwp/security-fix-undo', 'gobuildwp/security-fix-list'];
        foreach ($names as $name) {
            self::assertNotNull(Kit_Test_Site::registration($name), $name);
            $allowed = (Kit_Test_Site::registration($name)['permission_callback'])();
            if (in_array($name, $writes, true)) {
                self::assertInstanceOf(WP_Error::class, $allowed, $name);
                self::assertSame('gobuildwp_cloud_pro_required', $allowed->get_error_code(), $name);
                self::assertSame(403, $allowed->get_error_data()['status'], $name);
            } else {
                self::assertTrue($allowed, $name);
            }
        }

        $this->host->cloudVerified = true;
        foreach ($names as $name) {
            self::assertTrue((Kit_Test_Site::registration($name)['permission_callback'])(), $name);
        }
    }

    public function test_status_with_advance_runs_the_scan_to_done_and_pages_results(): void
    {
        $this->uploads_with_shell();
        $start = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        self::assertSame('queued', $start['state']);
        self::assertFalse($start['reused']);

        $status = self::ok('gobuildwp/integrity-scan-status', ['job_id' => $start['job_id'], 'advance' => false]);
        self::assertSame('queued', $status['state'], 'advance=false only reads');

        $done = self::finish('gobuildwp/integrity-scan-status', $start['job_id']);
        self::assertSame('done', $done['state']);
        self::assertSame('uploads', $done['scope']);
        self::assertSame(1.0, (float) $done['progress']['done']);
        self::assertSame('checked', $done['coverage']['uploads']['state']);
        self::assertSame('skipped', $done['coverage']['core']['state']);
        self::assertSame('', $done['error']);
        self::assertSame(['wp_version', 'locale', 'php_version', 'cron_disabled'], array_keys($done['env']));
        self::assertSame(1, $done['results_count']);

        $page = self::ok('gobuildwp/integrity-scan-results', ['job_id' => $start['job_id']]);
        self::assertSame(1, $page['total']);
        self::assertNull($page['next_cursor']);
        self::assertSame('uploads.php_file', $page['items'][0]['rule_id']);
        self::assertSame('wp-content/uploads/2026/shell.php', $page['items'][0]['path']);

        $after = self::ok('gobuildwp/integrity-scan-results', ['job_id' => $start['job_id'], 'cursor' => 1]);
        self::assertSame([], $after['items']);
    }

    public function test_a_second_start_reuses_the_open_job_and_a_held_mutex_refuses(): void
    {
        $first = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        $second = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'core']);
        self::assertSame($first['job_id'], $second['job_id']);
        self::assertTrue($second['reused']);

        $db = self::ok('gobuildwp/db-security-scan-start', ['mode' => 'light']);
        self::assertNotSame($first['job_id'], $db['job_id'], 'one open job per kind, not per site');

        update_option(SI\START_MUTEX_PREFIX . 'any', time() + 30);
        $busy = self::call('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        self::assertInstanceOf(WP_Error::class, $busy);
        self::assertSame('kit_security_integrity_scan_starting', $busy->get_error_code());
        self::assertSame(409, $busy->get_error_data()['status']);
    }

    public function test_integrity_and_db_starts_back_to_back_both_stay_findable(): void
    {
        $integrity = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        $db = self::ok('gobuildwp/db-security-scan-start', ['mode' => 'light']);
        self::ok('gobuildwp/integrity-scan-status', ['job_id' => $integrity['job_id']]);
        self::ok('gobuildwp/db-security-scan-status', ['job_id' => $db['job_id']]);

        // One mutex covers every kind, since every kind rewrites the same index.
        update_option(SI\START_MUTEX_PREFIX . 'any', time() + 30);
        $busy = self::call('gobuildwp/db-security-scan-start', ['mode' => 'full']);
        self::assertInstanceOf(WP_Error::class, $busy);
        self::assertSame('kit_security_integrity_scan_starting', $busy->get_error_code());

        // A stale mutex from a crashed request is taken over.
        update_option(SI\START_MUTEX_PREFIX . 'any', time() - 1);
        self::ok('gobuildwp/db-security-scan-start', ['mode' => 'full']);
    }

    public function test_a_stale_runner_index_lock_does_not_lose_a_job(): void
    {
        update_option(Runtime\Runner::INDEX_OPTION . '_lock', time() - 1);
        $start = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        self::assertContains($start['job_id'], get_option(Runtime\Runner::INDEX_OPTION));
        self::assertFalse(get_option(Runtime\Runner::INDEX_OPTION . '_lock'), 'lock released');
    }

    public function test_cancel_stops_the_job_and_a_new_start_makes_a_new_one(): void
    {
        $start = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        $cancelled = self::ok('gobuildwp/integrity-scan-cancel', ['job_id' => $start['job_id']]);
        self::assertSame('cancelled', $cancelled['state']);
        self::assertSame('cancelled', self::ok('gobuildwp/integrity-scan-status', ['job_id' => $start['job_id']])['state'], 'advance does not revive it');

        $again = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        self::assertNotSame($start['job_id'], $again['job_id']);
        self::assertFalse($again['reused']);
    }

    public function test_unknown_ids_and_the_wrong_kind_are_not_found(): void
    {
        $start = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        foreach ([
            self::call('gobuildwp/integrity-scan-status', ['job_id' => 'nope']),
            self::call('gobuildwp/db-security-scan-status', ['job_id' => $start['job_id']]),
            self::call('gobuildwp/db-security-scan-results', ['job_id' => $start['job_id']]),
            self::call('gobuildwp/db-security-scan-cancel', ['job_id' => $start['job_id']]),
        ] as $error) {
            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('kit_security_integrity_scan_not_found', $error->get_error_code());
            self::assertSame(404, $error->get_error_data()['status']);
        }
    }

    public function test_the_safe_updates_lock_makes_the_scan_wait(): void
    {
        $this->uploads_with_shell();
        $start = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        update_option(SI\SAFE_UPDATES_LOCK, time() + 600);
        $waiting = self::ok('gobuildwp/integrity-scan-status', ['job_id' => $start['job_id']]);
        self::assertSame('running', $waiting['state']);
        self::assertSame('waiting_for_updates', $waiting['progress']['phase']);
        self::assertSame(0, $waiting['progress']['files_checked']);

        delete_option(SI\SAFE_UPDATES_LOCK);
        update_option('core_updater.lock', time());
        self::assertSame('waiting_for_updates', self::ok('gobuildwp/integrity-scan-status', ['job_id' => $start['job_id']])['progress']['phase']);

        update_option('core_updater.lock', time() - 3600);
        self::assertSame('done', self::finish('gobuildwp/integrity-scan-status', $start['job_id'])['state'], 'a stale core lock does not block');
    }

    public function test_an_incomplete_scan_resumes_on_the_same_request_only(): void
    {
        $this->uploads_with_shell();
        add_filter('gobuildwp_kit_security_integrity_budgets', static fn(array $b): array => ['files' => 1, 'entries' => 1] + $b);
        $start = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        self::assertSame('running', self::ok('gobuildwp/integrity-scan-status', ['job_id' => $start['job_id']])['state']);

        // Spend the job's whole time allowance: the next step stops it as incomplete.
        $this->edit_job($start['job_id'], static function (array $job): array {
            $job['state']['elapsed'] = (float) SI\BUDGETS['job_seconds'];
            return $job;
        });
        $stopped = self::ok('gobuildwp/integrity-scan-status', ['job_id' => $start['job_id']]);
        self::assertSame('incomplete', $stopped['state']);
        self::assertStringContainsString('start the same scan again', $stopped['error']);
        self::assertSame('partial', $stopped['coverage']['uploads']['state']);

        $other = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'core']);
        self::assertNotSame($start['job_id'], $other['job_id'], 'a different scope is a new scan');
        self::ok('gobuildwp/integrity-scan-cancel', ['job_id' => $other['job_id']]);

        $resumed = self::ok('gobuildwp/integrity-scan-start', ['scope' => 'uploads']);
        self::assertSame($start['job_id'], $resumed['job_id']);
        self::assertTrue($resumed['reused']);
        self::assertSame('running', $resumed['state']);

        $done = self::finish('gobuildwp/integrity-scan-status', $start['job_id']);
        self::assertSame('done', $done['state']);
        $page = self::ok('gobuildwp/integrity-scan-results', ['job_id' => $start['job_id']]);
        self::assertSame(['uploads.php_file'], array_column($page['items'], 'rule_id'), 'results from before and after the resume, once each');
    }

    /** Change a stored job as another request would. */
    private function edit_job(string $id, callable $change): void
    {
        $job = $this->host->jobs->get($id);
        self::assertIsArray($job);
        update_option(Runtime\Runner::OPTION_PREFIX . $id, $change($job));
    }

    public function test_light_database_scan_runs_through_the_runner(): void
    {
        $start = self::ok('gobuildwp/db-security-scan-start', ['mode' => 'light']);
        $done = self::finish('gobuildwp/db-security-scan-status', $start['job_id']);
        self::assertSame('done', $done['state']);
        self::assertSame('light', $done['scope']);
        self::assertSame('skipped', $done['coverage']['posts']['state']);
        self::assertSame('unavailable', $done['coverage']['roles']['state']);
        $page = self::ok('gobuildwp/db-security-scan-results', ['job_id' => $start['job_id']]);
        self::assertContains('users.privileged_count', array_column($page['items'], 'rule_id'));
    }
}
