<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Suspicious-indicator rules: code patterns often left behind by attackers.
 *
 * A hit is a hint, not a verdict; legitimate code uses some of these too, which is why the rules
 * run only on files already flagged (modified, unexpected) or found in uploads, and why the Cloud
 * labels every hit a hypothesis. A hit reports the rule id and the line it starts on, never the
 * text around it.
 *
 * Patterns are written so that their own source does not contain the call they look for.
 *
 * The built-in rules plus the rules of the last GoBuildWP Cloud rule pack this site accepted
 * (pack.php). A built-in rule keeps its id when the pack names the same one. A pack rule carries
 * two more fields: which files it reads (php, js or any) and whether it reads comments too.
 *
 * @return array<string, array{0: string, 1: string, 2?: string, 3?: bool}> rule id => [pattern, severity_hint, applies_to, raw]
 */
function rules(): array
{
    return builtin_rules() + pack()['rules'];
}

/**
 * The rules shipped with the plugin.
 *
 * @return array<string, array{0: string, 1: string}> rule id => [pattern, severity_hint]
 */
function builtin_rules(): array
{
    $request = '\$_(?:POST|GET|REQUEST|COOKIE|SERVER|FILES)\b';
    $user_input = '\$_(?:POST|GET|REQUEST|COOKIE)\b';
    // A dangerous function name written as joined string pieces (as + sert): a `'.'` join may follow any
    // letter; the first lookahead refuses the plain name, the second asks for a join after the first piece.
    // A split base64_decode is left out: well-known SDKs (Freemius) write it that way to pass review.
    $names = ['gzinflate', 'str_rot13', 'assert', 'create_function', 'shell_exec', 'passthru', 'system', 'eval'];
    $join = '(?:[\'"]\s*\.\s*[\'"])?';
    $split = implode('|', array_map(static fn(string $name): string => implode($join, str_split($name)), $names));
    return [
        'susp.eval_base64' => ['/\beval\s*\(\s*base64_decode\s*\(/i', 'high'],
        'susp.eval_decoder' => ['/\beval\s*\(\s*(?:gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin|convert_uudecode)\s*\(/i', 'high'],
        'susp.gzinflate_base64' => ['/\b(?:gzinflate|gzuncompress|gzdecode)\s*\(\s*base64_decode\s*\(/i', 'high'],
        'susp.decoder_chain' => ['/\b(?:str_rot13|strrev)\s*\(\s*(?:base64_decode|gzinflate|str_rot13)\s*\(/i', 'medium'],
        'susp.eval_request' => ['/\beval\s*\(\s*(?:stripslashes\s*\(\s*)?' . $request . '/i', 'critical'],
        'susp.assert_request' => ['/\bassert\s*\(\s*(?:stripslashes\s*\(\s*)?' . $request . '/i', 'critical'],
        'susp.preg_replace_e' => ['/\bpreg_replace\s*\(\s*([\'"])([^\w\s\\\\]).{0,300}?\2[imsxuADSUX]*e[imsxuADSUX]*\1/', 'high'],
        'susp.request_call' => ['/' . $request . '\s*\[[^\]]{1,80}\]\s*\(/i', 'critical'],
        'susp.shell_request' => ['/\b(?:system|exec|shell_exec|passthru|popen|proc_open)\s*\(\s*(?:stripslashes\s*\(\s*)?' . $request . '/i', 'critical'],
        'susp.include_request' => ['/\b(?:include|require)(?:_once)?\s*\(?\s*' . $request . '/i', 'critical'],
        'susp.write_request' => ['/\b(?:file_put_contents|fwrite|fputs)\s*\([^;]{0,200}?(?<!\\\\)' . $request . '/i', 'high'],
        'susp.create_function' => ['/\bcreate_function\s*\(/i', 'medium'],
        'susp.base64_blob' => ['/[A-Za-z0-9+\/]{2048,}+={0,2}/', 'medium'],
        'susp.hex_blob' => ['/(?:\\\\x[0-9a-fA-F]{2}){512,}+|[0-9a-fA-F]{2048,}+/', 'medium'],
        'susp.chr_chain' => ['/(?:chr\s*\(\s*\d{1,3}\s*\)\s*\.\s*){12,}/i', 'medium'],
        'susp.globals_call' => ['/\$GLOBALS\s*\[\s*[\'"][^\'"]{1,40}[\'"]\s*\]\s*\[\s*\d+\s*\]\s*\(/', 'medium'],
        'susp.webshell_marker' => ['/\b(?:File[s]Man|c99she[l]l|r57she[l]l|b374[k]|IndoXploi[t]|AnonymousFo[x]|WS[O]\s+[0-9.]+\s*shell|[a]lfa\s*(?:team\s*)?shell|ALFA_DAT[A]|0byt3m1n[1]|xle[e]t|Lea[f]\s*PHPMailer)\b/i', 'critical'],
        'susp.ini_prepend' => ['/\bauto_(?:prepend|append)_file\b/i', 'medium'],
        'susp.js_eval_charcode' => ['/\beval\s*\(\s*String\.fromCharCode\s*\(/', 'high'],
        'susp.js_write_unescape' => ['/document\.write\s*\(\s*unescape\s*\(/i', 'medium'],
        'susp.js_eval_atob' => ['/\beval\s*\(\s*atob\s*\(/', 'high'],
        'susp.eval_remote' => ['/\beval\s*\(\s*(?:file_get_contents\s*\(\s*[\'"]\s*(?:https?|ftp):|curl_exec\s*\()/i', 'critical'],
        'susp.include_remote' => ['/\b(?:include|require)(?:_once)?\s*\(?\s*[\'"]\s*(?:https?|ftp):\/\//i', 'critical'],
        'susp.dropper' => ['/\bfile_put_contents\s*\([^;]{0,300}?\b(?:(?:gzinflate|gzuncompress|gzdecode)\s*\(|base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/=]{40})/i', 'high'],
        'susp.eval_variable' => ['/@\s*eval\s*\(\s*\$/i', 'high'],
        'susp.decode_exec' => ['/(\$\w{1,40})\s*=\s*(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13)\s*\([^;\n]{0,2000}\)\s*;[^\n]{0,500}?(?:\beval\s*\(\s*\1\b|\1\s*\()/i', 'high'],
        'susp.split_function' => ['/[\'"](?!(?:' . implode('|', $names) . ')[\'"])(?=[a-z0-9_]{1,15}[\'"]\s*\.\s*[\'"])(?:' . $split . ')[\'"]/i', 'high'],
        'susp.hex_name' => ['/(?:\$GLOBALS\s*\[\s*|\$\{\s*)"[A-Za-z0-9_]{0,10}\\\\x[0-9a-fA-F]{2}/', 'high'],
        'susp.backdoor_admin' => ['/^(?=[^\n]{0,2000}\bwp_(?:create|insert)_user\s*\()(?=[^\n]{0,2000}administrator)[^\n]{0,2000}?' . $user_input . '/im', 'critical'],
        'susp.login_bypass' => ['/\bwp_set_auth_cookie\s*\(\s*(?:\(\s*int\s*\)\s*|(?:intval|absint)\s*\(\s*)?' . $user_input . '/i', 'critical'],
        'susp.upload_shell' => ['/^(?![^\n]{0,1000}\b(?:wp_check_filetype|wp_handle_upload|pathinfo|getimagesize|mime_content_type|finfo_file|in_array|preg_match)\b)[^\n]{0,1000}?\bmove_uploaded_file\s*\(\s*\$_FILES\s*\[[^\]\n]{1,40}\]\s*\[\s*[\'"]tmp_name[\'"]\s*\]\s*,[^;\n]{0,200}?\$_FILES\s*\[[^\]\n]{1,40}\]\s*\[\s*[\'"]name[\'"]/im', 'high'],
        'susp.hide_plugins' => ['/\badd_filter\s*\(\s*[\'"]all_plugins[\'"]/i', 'medium'],
        'susp.js_charcode_list' => ['/String\.fromCharCode\s*\(\s*(?:\d{1,5}\s*,\s*){19,}+\d/i', 'high'],
    ];
}

/** Rules matched on the raw text, comments included: shell kits sign their name in a comment. */
const RAW_RULES = ['susp.webshell_marker'];

/** Opening PHP in a file whose name says it is not PHP (an image, an icon). */
const PHP_TAG_RULE = 'susp.php_tag_in_non_php';

/**
 * Code inside a binary file such as an image: a PHP open tag (`<?=` only before a variable or
 * whitespace and a name, so random image bytes don't match it), eval( or base64_decode(.
 */
const BINARY_CODE = '/<\?php\b|<\?=(?:\s+[A-Za-z_$]|\$[A-Za-z_])|\beval\s*\(|\bbase64_decode\s*\(/i';

function binary_code_hit(string $data): bool
{
    return preg_match(BINARY_CODE, $data) === 1;
}

/**
 * Run every rule over a piece of text; each rule reports its first hit.
 *
 * @return list<array{rule_id: string, line: int, severity_hint: string}>
 */
function scan_text(string $text, bool $is_php = true): array
{
    $hits = [];
    // Comments don't run: a URL or a $_GET named in a docblock is not a hit.
    $code = $is_php ? without_comments($text) : $text;
    $previous = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '100000');
    try {
        foreach (rules() as $rule => $entry) {
            [$pattern, $severity] = $entry;
            $applies = $entry[2] ?? 'any';
            if (($applies === 'php' && !$is_php) || ($applies === 'js' && $is_php)) {
                continue;
            }
            $raw = ($entry[3] ?? false) === true || in_array($rule, RAW_RULES, strict: true);
            // A pack pattern that errors at match time (backtrack limit, bad UTF-8) answers false: no hit.
            if (@preg_match($pattern, $raw ? $text : $code, $match, PREG_OFFSET_CAPTURE) === 1) {
                $hits[] = ['rule_id' => $rule, 'line' => line_at($text, (int) $match[0][1]), 'severity_hint' => $severity];
            }
        }
        if (!$is_php && preg_match('/<\?(?:php\b|=)/i', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $hits[] = ['rule_id' => PHP_TAG_RULE, 'line' => line_at($text, (int) $match[0][1]), 'severity_hint' => 'high'];
        }
    } finally {
        if (is_string($previous)) {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }
    return $hits;
}

/**
 * The hits a fix acts on and a baseline scan reports: high and critical only.
 *
 * @return list<array{rule_id: string, line: int, severity_hint: string}>
 */
function serious_hits(string $text, bool $is_php = true): array
{
    return array_values(array_filter(scan_text($text, $is_php), static fn(array $hit): bool => in_array($hit['severity_hint'], ['high', 'critical'], strict: true)));
}

/** PHP with every comment blanked to spaces, newlines kept, so offsets and lines stay the same. */
function without_comments(string $text): string
{
    if (!str_contains($text, '<?')) {
        return $text;
    }
    $out = '';
    foreach (token_get_all($text) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            $out .= (string) preg_replace('/[^\n]/', ' ', $token[1]);
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
}

function line_at(string $text, int $offset): int
{
    return substr_count($text, "\n", 0, $offset) + 1;
}

/**
 * Handler directives in an .htaccess that make the server run other files as PHP. The usual
 * hardening lines (`php_flag engine off`, `AddHandler cgi-script ...` beside `Options -ExecCGI`,
 * `SetHandler none`) do the opposite and are not reported.
 *
 * @return int|null The line of the first one, or null.
 */
function htaccess_handler_line(string $text): ?int
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^php_(?:value|flag|admin_value|admin_flag)\s+engine\s+(?:off|0)\b/i', $line) === 1
            || preg_match('/^AddHandler\s+cgi-script\b/i', $line) === 1) {
            continue;
        }
        if (preg_match('/^(?:AddHandler|SetHandler|AddType|ForceType|Action)\b.*(?:php|phtml|x-httpd)/i', $line) === 1
            || preg_match('/^php_(?:value|flag|admin_value|admin_flag)\s+auto_(?:prepend|append)_file\b/i', $line) === 1) {
            return $index + 1;
        }
    }
    return null;
}
