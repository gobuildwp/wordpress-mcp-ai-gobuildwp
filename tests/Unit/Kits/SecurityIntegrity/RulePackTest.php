<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Tests\Unit\Kits\SecurityIntegrity;

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__, 4) . '/includes/cloud/request-signing.php';

use GoBuildWP\Kits\SecurityIntegrity as SI;
use PHPUnit\Framework\TestCase;

/**
 * The Cloud rule pack: what is accepted, from whom, and how its rules and hashes join the scan.
 * Samples are built from pieces so this file holds no live call of its own.
 */
final class RulePackTest extends TestCase
{
    private Fixture $site;

    protected function setUp(): void
    {
        reset_state();
        gobuildwp_cloud_request_set_verified(false);
        $this->site = new Fixture();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
        gobuildwp_cloud_request_set_verified(false);
        reset_state();
    }

    /** @param list<array<string, mixed>> $rules @param list<string> $hashes */
    private static function offer(array $rules = [], array $hashes = [], string $version = 'rp1-test'): array|\WP_Error
    {
        gobuildwp_cloud_request_set_verified(true);
        return SI\accept_pack(['pack' => ['version' => $version, 'rules' => $rules, 'hashes' => $hashes]]);
    }

    private static function rule(string $id, string $pattern = '/zz_marker_\d+/', string $severity = 'high', string $applies = 'any'): array
    {
        return ['id' => $id, 'pattern' => $pattern, 'severity' => $severity, 'applies_to' => $applies];
    }

    public function test_bad_rules_are_dropped_and_good_ones_kept(): void
    {
        $pack = SI\validate_pack(['version' => 'v1', 'rules' => [
            self::rule('susp.good_one'),
            self::rule('evil.not_susp'),
            self::rule('susp.Upper'),
            self::rule('susp.' . str_repeat('a', 41)),
            self::rule('susp.known_bad_hash'),
            self::rule('susp.no_compile', '/(unclosed/'),
            self::rule('susp.old_modifier', '/x/e'),
            self::rule('susp.too_long', '/' . str_repeat('a', SI\PACK_MAX_PATTERN) . '/'),
            self::rule('susp.bad_severity', '/x/', 'urgent'),
            self::rule('susp.bad_applies', '/x/', 'high', 'css'),
            ['id' => 'susp.raw_string', 'pattern' => '/x/', 'severity' => 'low', 'raw' => 'yes'],
            self::rule('susp.good_one', '/dup/'),
            'not a rule',
        ], 'hashes' => []]);
        self::assertNotNull($pack);
        self::assertSame(['susp.good_one'], array_keys($pack['rules']));
        self::assertSame(['/zz_marker_\d+/', 'high', 'any', false], $pack['rules']['susp.good_one']);
        self::assertSame(12, $pack['dropped']);
    }

    public function test_an_unreadable_pack_is_refused_whole(): void
    {
        self::assertNull(SI\validate_pack(null));
        self::assertNull(SI\validate_pack(['rules' => []]));
        self::assertNull(SI\validate_pack(['version' => 'bad version!', 'rules' => []]));
        self::assertNull(SI\validate_pack(['version' => 'v1', 'rules' => 'x']));
        $refused = self::offer([], [], '');
        self::assertInstanceOf(\WP_Error::class, $refused);
        self::assertSame('', SI\pack_version());
    }

    public function test_caps_on_rules_and_hashes(): void
    {
        $rules = [];
        for ($i = 0; $i < SI\PACK_MAX_RULES + 5; $i++) {
            $rules[] = self::rule('susp.r' . $i);
        }
        $hashes = [];
        for ($i = 0; $i < SI\PACK_MAX_HASHES + 5; $i++) {
            $hashes[] = hash('sha256', (string) $i);
        }
        $hashes[] = 'NOT-A-HASH';
        $pack = SI\validate_pack(['version' => 'v1', 'rules' => $rules, 'hashes' => $hashes]);
        self::assertNotNull($pack);
        self::assertCount(SI\PACK_MAX_RULES, $pack['rules']);
        self::assertSame(5, $pack['dropped']);
        self::assertCount(SI\PACK_MAX_HASHES, $pack['hashes']);
    }

    public function test_only_a_verified_cloud_request_may_store_a_pack(): void
    {
        $body = ['pack' => ['version' => 'v1', 'rules' => [self::rule('susp.zz')], 'hashes' => []]];

        gobuildwp_cloud_request_set_verified(false);
        $refused = SI\accept_pack($body);
        self::assertInstanceOf(\WP_Error::class, $refused);
        self::assertSame('gobuildwp_rules_unsigned', $refused->get_error_code());
        self::assertFalse(get_option(SI\PACK_OPTION, false));
        self::assertArrayNotHasKey('susp.zz', SI\rules());

        gobuildwp_cloud_request_set_verified(true);
        $stored = SI\accept_pack($body);
        self::assertIsArray($stored);
        self::assertSame(['stored', 'v1', 1, 0], [$stored['state'], $stored['version'], $stored['rules'], $stored['hashes']]);
        self::assertSame('v1', SI\pack_version());
        self::assertArrayHasKey('susp.zz', SI\rules());

        $again = SI\accept_pack($body);
        self::assertSame('unchanged', $again['state']);

        // An offer of a version alone asks; it never replaces the stored pack.
        self::assertSame(['state' => 'unchanged', 'version' => 'v1', 'rules' => 1, 'hashes' => 0], SI\accept_pack(['pack' => ['version' => 'v1']]));
        self::assertSame(['state' => 'wanted', 'version' => 'v1'], SI\accept_pack(['pack' => ['version' => 'v2']]));
        self::assertSame('v1', SI\pack_version());
        self::assertArrayHasKey('susp.zz', SI\rules());
    }

    public function test_pack_is_stored_without_autoload(): void
    {
        self::offer([self::rule('susp.zz')]);
        $stored = get_option(SI\PACK_OPTION);
        self::assertIsArray($stored);
        self::assertSame('rp1-test', $stored['version']);
        self::assertFalse($GLOBALS['wppilot_test_option_autoload'][SI\PACK_OPTION] ?? null);
    }

    public function test_built_in_rule_wins_an_id_clash(): void
    {
        $builtin = SI\builtin_rules()['susp.hide_plugins'];
        self::offer([self::rule('susp.hide_plugins', '/anything/', 'critical'), self::rule('susp.zz')]);
        self::assertSame($builtin, SI\rules()['susp.hide_plugins']);
        self::assertSame(count(SI\builtin_rules()) + 1, count(SI\rules()));
    }

    public function test_pack_rules_hit_by_file_kind(): void
    {
        self::offer([
            self::rule('susp.zz_php', '/zz_marker_1/', 'high', 'php'),
            self::rule('susp.zz_js', '/zz_marker_1/', 'medium', 'js'),
            self::rule('susp.zz_any', '/zz_marker_1/', 'low', 'any'),
            self::rule('susp.zz_slow', '/(?:a+)+b/', 'high', 'any'),
        ]);
        $php = array_column(SI\scan_text("<?php\n\$a = 'zz_marker_1';"), 'severity_hint', 'rule_id');
        self::assertSame(['susp.zz_php' => 'high', 'susp.zz_any' => 'low'], array_intersect_key($php, ['susp.zz_php' => 1, 'susp.zz_js' => 1, 'susp.zz_any' => 1]));
        $js = array_column(SI\scan_text("var a = 'zz_marker_1';", false), 'rule_id');
        self::assertContains('susp.zz_js', $js);
        self::assertContains('susp.zz_any', $js);
        self::assertNotContains('susp.zz_php', $js);

        // Catastrophic backtracking runs into the backtrack limit: no hit, no error.
        self::assertNotContains('susp.zz_slow', array_column(SI\scan_text('<?php ' . str_repeat('a', 5000)), 'rule_id'));
        // A comment is not code, unless the rule asks for raw text.
        self::assertNotContains('susp.zz_php', array_column(SI\scan_text("<?php\n// zz_marker_1\n"), 'rule_id'));
    }

    public function test_known_bad_hash_is_reported_critical(): void
    {
        $shell = "<?php\n// harmless test body\n\$x = 1;\n";
        $big = "<?php\n" . str_repeat("// padding line\n", (int) (SI\RULE_READ_BYTES / 16) + 10);
        self::offer([], [hash('sha256', $shell), hash('sha256', $big)]);
        $this->site->put('wp-content/uploads/2026/10/x.php', $shell);
        $this->site->put('wp-content/uploads/2026/10/big.php', $big);
        $this->site->put('wp-content/uploads/2026/10/other.php', $shell . ' ');

        $run = run_files(['scope' => 'uploads']);
        $bad = array_values(array_filter($run['items'], static fn(array $i): bool => $i['rule_id'] === SI\KNOWN_BAD_RULE));
        $paths = array_column($bad, 'path');
        sort($paths);
        self::assertSame(['wp-content/uploads/2026/10/big.php', 'wp-content/uploads/2026/10/x.php'], $paths);
        foreach ($bad as $item) {
            self::assertSame(['suspicious', 'critical'], [$item['result'], $item['severity_hint']]);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ((array) $item['detail'])['sha256']);
        }
    }

    public function test_no_hash_list_reads_nothing_extra(): void
    {
        self::assertNull(SI\known_bad_file(__FILE__, '', new SI\Budget(SI\budgets(), 30)));
    }
}
