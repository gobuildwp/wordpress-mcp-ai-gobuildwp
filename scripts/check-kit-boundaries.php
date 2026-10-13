<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Fail the build when a kit leans on something it will not have once exported.
 *
 *   php scripts/check-kit-boundaries.php [--pro-src=../wppilot-pro]
 *
 * A kit is copied into plugins that are not GoBuildWP (scripts/export-kit.php). Anything it takes
 * from GoBuildWP directly — a wppilot_* function, a WPPILOT_* constant, a class from another
 * GoBuildWP namespace — works here and fatals there, on a site this repository never sees. So
 * this reads every kit under includes/kits (and Pro's, with --pro-src) plus the runtime, token
 * by token, and refuses:
 *
 *   - calls to wppilot_* functions, WPPILOT_* constants, and strings naming a wppilot_* or
 *     wppilot-* hook, option or handle other than the kit's own wppilot_kit_* / wppilot-kit*
 *     (the exporter renames only those);
 *   - names under WPPilot\ other than the kit's own namespace and WPPilot\Kits\Runtime;
 *   - global-namespace functions or classes, Composer autoloading and spl_autoload_register(),
 *     each of which collides the moment two plugins carry the same kit;
 *   - syntax or library functions newer than PHP 8.0, which both plugins still promise: enums,
 *     readonly, `never`, `new` in initializers, first-class callables, intersection types,
 *     `true` or standalone `false`/`null` types, typed class constants, octal `0o` literals,
 *     dynamic class constant fetch, 8.2+ attributes, 8.4 member access on `new` without
 *     parentheses and asymmetric visibility;
 *   - a spread inside an array literal, which with string keys needs PHP 8.1. Every such spread
 *     is flagged; mark a spread of a known list with `// kit-lint: list-spread` on its line;
 *   - a post, meta or metadata write whose value is not wp_slash(...): WordPress unslashes what
 *     it stores, so builder JSON and code lose every backslash. Mark a value that is already
 *     slashed with `// kit-lint: slashed` on the call's lines or the line above;
 *   - shop_order read through the posts table, which returns nothing on an HPOS store;
 *   - a kit hooking wp_abilities_api_init or wp_abilities_api_categories_init itself: the
 *     runtime owns those hooks, once per copy, and a registration outside them is silent;
 *   - an i18n call without GoBuildWP's literal text domain, which the exporter cannot rewrite;
 *   - an ability declaring meta.safety.min_profile whose registration does not call
 *     Runtime\require_profile() with its own name: before WordPress 7.1 the key alone is
 *     enforced by nothing outside the host's own gate.
 *
 * Per kit it also checks kit.json (slug, version, namespace, tier, runtime, abilities, and the
 * optional requires.kits and vendor_storage), that the
 * literal wp_register_ability() names in its PHP equal kit.json `abilities[].name` both ways,
 * that each declared skill has skills/<slug>/SKILL.md (and each SKILL.md is declared), and that
 * declared tests exist.
 *
 * GoBuildWP-side glue — _runtime/hosts/wppilot.php, includes/kits/loader.php and skills.php — is
 * never exported, so it may name GoBuildWP freely; it is held to the PHP 8.0 rules only.
 *
 * Development-only: package.sh excludes scripts/.
 */

namespace WPPilot\Scripts\Kits\Boundaries;

use function WPPilot\Scripts\Kits\argument;
use function WPPilot\Scripts\Kits\call_args;
use function WPPilot\Scripts\Kits\called_function;
use function WPPilot\Scripts\Kits\closes;
use function WPPilot\Scripts\Kits\files_under;
use function WPPilot\Scripts\Kits\kit_dirs;
use function WPPilot\Scripts\Kits\literal;
use function WPPilot\Scripts\Kits\matching_close;
use function WPPilot\Scripts\Kits\next_significant;
use function WPPilot\Scripts\Kits\opens;
use function WPPilot\Scripts\Kits\prev_significant;
use function WPPilot\Scripts\Kits\profile_gate_problems;
use function WPPilot\Scripts\Kits\registered_ability_literals;
use function WPPilot\Scripts\Kits\relative;
use function WPPilot\Scripts\Kits\tokens;
use function WPPilot\Scripts\Kits\top_level_declarations;

use const WPPilot\Scripts\Kits\ABILITY_NAME_PATTERN;

require_once __DIR__ . '/lib/kit-tools.php';

/** Library functions a PHP 8.0 install does not have. */
const NEWER_FUNCTIONS = [
    'array_is_list' => '8.1', 'enum_exists' => '8.1', 'fsync' => '8.1', 'fdatasync' => '8.1',
    'ini_parse_quantity' => '8.2', 'memory_reset_peak_usage' => '8.2', 'mysqli_execute_query' => '8.2',
    'openssl_cipher_key_length' => '8.2', 'json_validate' => '8.3', 'mb_str_pad' => '8.3',
    'str_increment' => '8.3', 'str_decrement' => '8.3', 'stream_context_set_options' => '8.3',
    'array_find' => '8.4', 'array_find_key' => '8.4', 'array_any' => '8.4', 'array_all' => '8.4',
    'mb_trim' => '8.4', 'mb_ltrim' => '8.4', 'mb_rtrim' => '8.4', 'mb_ucfirst' => '8.4', 'mb_lcfirst' => '8.4',
];

/** Attributes that only mean something on PHP 8.2 and later. */
const NEWER_ATTRIBUTES = ['override' => '8.3', 'sensitiveparameter' => '8.2', 'deprecated' => '8.4'];

/** Writes WordPress unslashes, and the position (and name) of the value they store. */
const SLASHED_WRITES = [
    'update_post_meta' => [2, 'meta_value'], 'add_post_meta' => [2, 'meta_value'],
    'update_user_meta' => [2, 'meta_value'], 'add_user_meta' => [2, 'meta_value'],
    'update_term_meta' => [2, 'meta_value'], 'add_term_meta' => [2, 'meta_value'],
    'update_comment_meta' => [2, 'meta_value'], 'add_comment_meta' => [2, 'meta_value'],
    'update_metadata' => [3, 'meta_value'], 'add_metadata' => [3, 'meta_value'],
    'wp_update_post' => [0, 'postarr'], 'wp_insert_post' => [0, 'postarr'],
];

/** i18n functions and the position of their text domain. */
const DOMAIN_POSITION = [
    '__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1, 'esc_attr__' => 1, 'esc_attr_e' => 1,
    '_x' => 2, '_ex' => 2, 'esc_html_x' => 2, 'esc_attr_x' => 2, '_n_noop' => 2,
    '_n' => 3, '_nx_noop' => 3, '_nx' => 4,
];

final class Report
{
    /** @var list<string> */
    public array $problems = [];

    public function add(string $where, int $line, string $message): void
    {
        $this->problems[] = $line > 0 ? "{$where}:{$line}: {$message}" : "{$where}: {$message}";
    }
}

/**
 * Whether a `// kit-lint: <name>` marker sits on the lines a construct spans, or the line above.
 *
 * @param list<string> $lines
 */
function marked(array $lines, int $from, int $to, string $marker): bool
{
    for ($line = max(1, $from - 1); $line <= $to; $line++) {
        if (str_contains($lines[$line - 1] ?? '', '// kit-lint: ' . $marker)) {
            return true;
        }
    }
    return false;
}

function short_name(string $called): string
{
    return substr($called, (int) strrpos('\\' . $called, '\\'));
}

/**
 * The PHP 8.0 rules, which every file under includes/kits obeys, glue included.
 *
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 * @param list<string> $lines
 */
function check_syntax(array $tokens, array $lines, string $where, Report $report): void
{
    $count = count($tokens);
    /** @var list<string> $stack What each open bracket is: array, call, index, block. */
    $stack = [];
    $value_end = [T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_CONSTANT_ENCAPSED_STRING];

    for ($i = 0; $i < $count; $i++) {
        [$id, $text, $line] = $tokens[$i];
        $name = token_name($id);

        if ($name === 'T_ENUM') {
            $report->add($where, $line, 'enum needs PHP 8.1');
        } elseif ($name === 'T_READONLY') {
            $report->add($where, $line, 'readonly needs PHP 8.1');
        } elseif (in_array($name, ['T_PUBLIC_SET', 'T_PROTECTED_SET', 'T_PRIVATE_SET'], true)) {
            $report->add($where, $line, 'asymmetric visibility needs PHP 8.4');
        } elseif (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE], true) && ($tokens[$i + 1][1] ?? '') === '(') {
            $report->add($where, $line, 'asymmetric visibility needs PHP 8.4');
        } elseif ($id === T_LNUMBER && preg_match('/^0[oO]/', $text) === 1) {
            $report->add($where, $line, 'an 0o octal literal needs PHP 8.1');
        } elseif ($id === T_DOUBLE_COLON && ($tokens[next_significant($tokens, $i)][1] ?? '') === '{') {
            $report->add($where, $line, 'dynamic class constant fetch needs PHP 8.3');
        } elseif ($name === 'T_ATTRIBUTE') {
            $attribute = next_significant($tokens, $i);
            $short = strtolower(short_name(ltrim($tokens[$attribute][1] ?? '', '\\')));
            if (isset(NEWER_ATTRIBUTES[$short])) {
                $report->add($where, $line, sprintf('#[%s] only means something on PHP %s', $tokens[$attribute][1], NEWER_ATTRIBUTES[$short]));
            }
        } elseif ($id === T_CONST) {
            $prev = prev_significant($tokens, $i);
            $first = next_significant($tokens, $i);
            $second = next_significant($tokens, $first);
            if (($tokens[$prev][0] ?? 0) !== T_USE && $second !== -1 && !in_array($tokens[$second][1], ['=', ';', ','], true)) {
                $report->add($where, $line, 'a typed class constant needs PHP 8.3');
            }
            for ($j = $i; $j < $count && $tokens[$j][1] !== ';'; $j++) {
                if ($tokens[$j][0] === T_NEW) {
                    $report->add($where, $line, '`new` in a constant expression needs PHP 8.1');
                    break;
                }
            }
        } elseif ($id === T_STATIC && ($tokens[next_significant($tokens, $i)][0] ?? 0) === T_VARIABLE) {
            for ($j = $i; $j < $count && $tokens[$j][1] !== ';'; $j++) {
                if ($tokens[$j][0] === T_NEW) {
                    $report->add($where, $line, '`new` in a static variable initializer needs PHP 8.1');
                    break;
                }
            }
        } elseif ($id === T_NEW) {
            $prev = prev_significant($tokens, $i);
            $class = next_significant($tokens, $i);
            $open = $class === -1 ? -1 : next_significant($tokens, $class);
            if ($open !== -1 && $tokens[$open][1] === '(' && ($tokens[$prev][1] ?? '') !== '(') {
                $after = next_significant($tokens, matching_close($tokens, $open));
                if ($after !== -1 && in_array($tokens[$after][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    $report->add($where, $line, 'member access on `new` without parentheses needs PHP 8.4');
                }
            }
        } elseif ($id === T_FUNCTION || $id === T_FN) {
            check_signature($tokens, $i, $where, $report);
        } elseif ($text === '(' && $id === 0) {
            $ellipsis = next_significant($tokens, $i);
            if ($ellipsis !== -1 && $tokens[$ellipsis][0] === T_ELLIPSIS && ($tokens[next_significant($tokens, $ellipsis)][1] ?? '') === ')') {
                $report->add($where, $line, 'a first-class callable `f(...)` needs PHP 8.1');
            }
        }

        $called = called_function($tokens, $i);
        if ($called !== null && !str_contains($called, '\\') && isset(NEWER_FUNCTIONS[$called])) {
            $report->add($where, $line, sprintf('%s() needs PHP %s', $called, NEWER_FUNCTIONS[$called]));
        }

        // Spreads inside array literals.
        if (opens($tokens[$i])) {
            $prev = prev_significant($tokens, $i);
            if ($text === '[') {
                $is_index = $prev !== -1 && (in_array($tokens[$prev][0], $value_end, true) || in_array($tokens[$prev][1], [')', ']', '}'], true));
                $stack[] = $is_index ? 'index' : 'array';
            } elseif ($text === '(') {
                $stack[] = $prev !== -1 && in_array($tokens[$prev][0], [T_ARRAY, T_LIST], true) ? 'array' : 'call';
            } else {
                $stack[] = 'block';
            }
        } elseif (closes($tokens[$i])) {
            array_pop($stack);
        } elseif ($id === T_ELLIPSIS && end($stack) === 'array' && !marked($lines, $line, $line, 'list-spread')) {
            $report->add($where, $line, 'a spread inside an array literal needs PHP 8.1 when the keys are strings; add `// kit-lint: list-spread` if it is a list');
        }
    }
}

/**
 * Parameter and return types of the function whose `function`/`fn` keyword is at $i.
 *
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 */
function check_signature(array $tokens, int $i, string $where, Report $report): void
{
    $open = next_significant($tokens, $i);
    if ($open !== -1 && $tokens[$open][1] === '&') {
        $open = next_significant($tokens, $open);
    }
    if ($open !== -1 && $tokens[$open][0] === T_STRING) {
        $open = next_significant($tokens, $open);
    }
    if ($open === -1 || $tokens[$open][1] !== '(') {
        return;
    }
    $close = matching_close($tokens, $open);
    $types = [];
    foreach (call_args($tokens, $open) as [$first, $last]) {
        $type = '';
        $type_line = $tokens[$first][2];
        for ($j = $first; $j <= $last; $j++) {
            [$id, $text] = $tokens[$j];
            if ($id === T_VARIABLE || $id === T_ELLIPSIS || ($text === '&' && ($tokens[next_significant($tokens, $j)][0] ?? 0) === T_VARIABLE)) {
                break;
            }
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_PUBLIC, T_PROTECTED, T_PRIVATE], true) || token_name($id) === 'T_READONLY') {
                continue;
            }
            $type .= $text;
        }
        for ($j = $first; $j <= $last; $j++) {
            if ($tokens[$j][0] === T_NEW) {
                $report->add($where, $tokens[$j][2], '`new` in a parameter default needs PHP 8.1');
                break;
            }
        }
        $types[] = [$type, $type_line];
    }
    $colon = next_significant($tokens, $close);
    if ($colon !== -1 && $tokens[$colon][1] === ':') {
        $type = '';
        for ($j = $colon + 1; $j < count($tokens) && !in_array($tokens[$j][1], ['{', ';'], true) && $tokens[$j][0] !== T_DOUBLE_ARROW; $j++) {
            if (!in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $type .= $tokens[$j][1];
            }
        }
        $types[] = [$type, $tokens[$colon][2]];
    }
    foreach ($types as [$type, $line]) {
        if ($type === '') {
            continue;
        }
        $lower = strtolower($type);
        if ($lower === 'never') {
            $report->add($where, $line, 'the never type needs PHP 8.1');
        }
        if (str_contains($type, '&')) {
            $report->add($where, $line, "intersection type {$type} needs PHP 8.1");
        }
        $parts = explode('|', trim($lower, '()'));
        if (in_array('true', $parts, true)) {
            $report->add($where, $line, "type {$type} uses `true`, which needs PHP 8.2; use bool");
        }
        if (in_array(ltrim($lower, '?'), ['false', 'null'], true)) {
            $report->add($where, $line, "standalone type {$type} needs PHP 8.2");
        }
    }
}

/**
 * The rules for code that is exported: kits and the runtime, not GoBuildWP's glue.
 *
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 * @param list<string> $lines
 * @param string|null $own The kit's namespace, or null for the runtime.
 */
function check_portable(array $tokens, array $lines, string $where, ?string $own, Report $report): void
{
    $count = count($tokens);
    // A kit may name itself and the runtime; the runtime only itself.
    $allowed_namespaces = $own !== null ? ['WPPilot\\Kits\\Runtime', $own] : ['WPPilot\\Kits\\Runtime'];
    $in_allowed = static function (string $name) use ($allowed_namespaces): bool {
        $name = ltrim($name, '\\');
        foreach ($allowed_namespaces as $allowed) {
            if ($name === $allowed || str_starts_with($name, $allowed . '\\')) {
                return true;
            }
        }
        return false;
    };

    $has_namespace = false;
    for ($i = 0; $i < $count; $i++) {
        [$id, $text, $line] = $tokens[$i];

        if ($id === T_NAMESPACE) {
            $name = next_significant($tokens, $i);
            if ($name !== -1 && in_array($tokens[$name][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $has_namespace = true;
                if (!$in_allowed($tokens[$name][1]) || ($own !== null && !str_starts_with($tokens[$name][1] . '\\', $own . '\\'))) {
                    $report->add($where, $line, sprintf('namespace %s is not %s', $tokens[$name][1], $own ?? 'WPPilot\\Kits\\Runtime'));
                }
            }
            continue;
        }

        if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/^\\\\?WPPilot\\\\/i', $text) === 1 && !$in_allowed($text)) {
            $report->add($where, $line, "{$text} is outside the kit and the runtime; the export will not have it");
        }

        if (($id === T_STRING || $id === T_NAME_FULLY_QUALIFIED) && preg_match('/^\\\\?WPPILOT_[A-Z0-9_]+$/', $text) === 1
            && ($tokens[next_significant($tokens, $i)][1] ?? '') !== '(') {
            $report->add($where, $line, "{$text} is a GoBuildWP constant; ask the host instead");
        }

        $value = literal($tokens[$i]);
        if ($id === T_ENCAPSED_AND_WHITESPACE) {
            // A piece of an interpolated string, which is where SQL usually is.
            $value = $text;
        }
        if ($value !== null) {
            // 'wppilot-pro' alone is Pro's text domain, which the exporter rewrites.
            if ($value !== 'wppilot-pro' && preg_match('/(?<![A-Za-z0-9])wppilot(?:_(?!kit_)|-(?!kit))[a-z0-9_-]*/i', $value, $m) === 1) {
                $report->add($where, $line, "'{$m[0]}' names GoBuildWP's own hook, option or handle; kits may only use wppilot_kit_* and wppilot-kit* names");
            }
            if (preg_match('/WPPilot\\\\{1,2}(?!Kits\\\\)[A-Za-z]/', $value) === 1) {
                $report->add($where, $line, "a string names a GoBuildWP class outside the kits: {$value}");
            }
            if ($value === 'shop_order') {
                for ($j = prev_significant($tokens, $i), $n = 0; $j !== -1 && $n < 8; $j = prev_significant($tokens, $j), $n++) {
                    if (literal($tokens[$j]) === 'post_type') {
                        $report->add($where, $line, 'shop_order queried as a post type returns nothing on an HPOS store; use wc_get_orders()');
                        break;
                    }
                }
            } elseif (stripos($value, 'shop_order') !== false && preg_match('/\b(select|update|delete|insert|from|join|where|posts)\b/i', $value) === 1) {
                $report->add($where, $line, 'SQL over shop_order in the posts table misses every HPOS order; use wc_get_orders()');
            }
            if (preg_match('/(^|\/)vendor\/autoload\.php$|(^|\/)autoload\.php$/', $value) === 1) {
                $report->add($where, $line, 'Composer autoloading: two plugins carrying this kit would register the same classes twice');
            }
        }

        $called = called_function($tokens, $i);
        if ($called === null) {
            continue;
        }
        $short = short_name($called);
        $open = next_significant($tokens, $i);
        $args = call_args($tokens, $open);
        $close_line = $tokens[matching_close($tokens, $open)][2];

        if (str_starts_with($short, 'wppilot_')) {
            $report->add($where, $line, "{$short}() is GoBuildWP's; a kit reaches GoBuildWP only through Runtime\\host()");
        }
        if ($short === 'spl_autoload_register') {
            $report->add($where, $line, 'spl_autoload_register(): two plugins carrying this kit would both answer for the same classes');
        }
        if ($own !== null && in_array($short, ['add_action', 'add_filter'], true)) {
            $hook = argument($tokens, $args, 0, 'hook_name');
            $hook_name = $hook !== null && $hook[0] === $hook[1] ? literal($tokens[$hook[0]]) : null;
            if (in_array($hook_name, ['wp_abilities_api_init', 'wp_abilities_api_categories_init'], true)) {
                $report->add($where, $line, "a kit never hooks {$hook_name}: return ability_files from bootstrap.php and the runtime registers them");
            }
        }
        if (isset(SLASHED_WRITES[$short])) {
            [$position, $name] = SLASHED_WRITES[$short];
            $range = argument($tokens, $args, $position, $name);
            if ($range !== null && !is_slashed($tokens, $range) && !marked($lines, $line, $close_line, 'slashed')) {
                $report->add($where, $line, "{$short}() stores a value that is not wp_slash(...); WordPress unslashes it. Add `// kit-lint: slashed` if it already is");
            }
        }
        if (isset(DOMAIN_POSITION[$short])) {
            $range = argument($tokens, $args, DOMAIN_POSITION[$short], 'domain');
            $domain = $range !== null && $range[0] === $range[1] ? literal($tokens[$range[0]]) : null;
            if (!in_array($domain, ['wppilot', 'wppilot-pro'], true)) {
                $report->add($where, $line, "{$short}() needs the literal text domain 'wppilot' (or 'wppilot-pro'), which the exporter rewrites");
            }
        }
    }

    if (!$has_namespace) {
        foreach (top_level_declarations(implode('', array_column($tokens, 1))) as $declaration) {
            $report->add($where, $declaration['line'], "{$declaration['name']} is declared in the global namespace");
        }
    }
}

/**
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 * @param array{0: int, 1: int} $range
 */
function is_slashed(array $tokens, array $range): bool
{
    [$first, $last] = $range;
    if ($first === $last) {
        [$id, $text] = $tokens[$first];
        if (in_array($id, [T_LNUMBER, T_DNUMBER], true) || in_array(strtolower($text), ['true', 'false', 'null'], true)) {
            return true;
        }
        $value = literal($tokens[$first]);
        return $value !== null && !str_contains($value, '\\');
    }
    if (!in_array(strtolower(ltrim($tokens[$first][1], '\\')), ['wp_slash'], true)) {
        return false;
    }
    $open = next_significant($tokens, $first);
    return $open !== -1 && $tokens[$open][1] === '(' && matching_close($tokens, $open) === $last;
}

/**
 * @param array<string, mixed> $manifest
 */
function check_manifest(array $manifest, string $slug, string $tier, string $where, Report $report): void
{
    foreach (['slug', 'version', 'namespace', 'tier', 'runtime'] as $key) {
        if (!is_string($manifest[$key] ?? null) || $manifest[$key] === '') {
            $report->add($where, 0, "kit.json needs a string `{$key}`");
        }
    }
    if (($manifest['slug'] ?? null) !== $slug) {
        $report->add($where, 0, "kit.json slug must equal the folder name {$slug}");
    }
    if (is_string($manifest['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $manifest['version']) !== 1) {
        $report->add($where, 0, 'kit.json version must be MAJOR.MINOR.PATCH');
    }
    if (is_string($manifest['namespace'] ?? null) && preg_match('/^WPPilot\\\\Kits\\\\[A-Z][A-Za-z0-9]*$/', $manifest['namespace']) !== 1) {
        $report->add($where, 0, 'kit.json namespace must be WPPilot\\Kits\\<Name>');
    }
    if (($manifest['namespace'] ?? null) === 'WPPilot\\Kits\\Runtime') {
        $report->add($where, 0, 'kit.json namespace must not be the runtime\'s');
    }
    if (!in_array($manifest['tier'] ?? null, ['free', 'pro'], true)) {
        $report->add($where, 0, 'kit.json tier must be free or pro');
    } elseif ($manifest['tier'] !== $tier) {
        $report->add($where, 0, "kit.json says tier {$manifest['tier']}, but the kit lives in the {$tier} repository");
    }
    if (is_string($manifest['runtime'] ?? null) && preg_match('/^\^\d+\.\d+$/', $manifest['runtime']) !== 1) {
        $report->add($where, 0, 'kit.json runtime must be a caret constraint such as ^1.0');
    }
    if (!is_array($manifest['abilities'] ?? null)) {
        $report->add($where, 0, 'kit.json needs an `abilities` array (empty is fine)');
    }
    $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
    if (isset($requires['kits'])) {
        if (!is_array($requires['kits']) || array_values($requires['kits']) !== $requires['kits']) {
            $report->add($where, 0, 'kit.json requires.kits must be a list of kit slugs');
        } else {
            foreach ($requires['kits'] as $required) {
                if (!is_string($required) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $required) !== 1 || $required === $slug) {
                    $report->add($where, 0, 'kit.json requires.kits must name other kits by slug');
                }
            }
        }
    }
    if (isset($manifest['vendor_storage'])) {
        $storage = $manifest['vendor_storage'];
        if (!is_array($storage) || ($storage !== [] && array_values($storage) === $storage)) {
            $report->add($where, 0, 'kit.json vendor_storage must be an object of option, meta, transient and cron lists');
            return;
        }
        foreach ($storage as $kind => $names) {
            if (!in_array($kind, ['option', 'meta', 'transient', 'cron'], true)) {
                $report->add($where, 0, "kit.json vendor_storage has an unknown kind `{$kind}` (option, meta, transient or cron)");
                continue;
            }
            foreach (is_array($names) && array_values($names) === $names ? $names : [null] as $name) {
                // A name the kit owns has to be renamed on export, which is what the coexistence
                // gate catches; declaring it vendor-owned would silence that.
                if (!is_string($name) || $name === '' || stripos($name, 'wppilot') !== false) {
                    $report->add($where, 0, "kit.json vendor_storage.{$kind} must list the other plugin's names, none of them GoBuildWP's");
                    break;
                }
            }
        }
    }
}

/**
 * `requires.kits` must name kits that exist, and a Free kit may only require Free kits: Free is
 * exported without Pro's source, so a Free kit needing a Pro kit could not be exported at all.
 *
 * @param array<string, array{tier: string, manifest: array<string, mixed>, where: string}> $kits
 */
function check_required_kits(array $kits, Report $report): void
{
    foreach ($kits as $kit) {
        $requires = is_array($kit['manifest']['requires'] ?? null) ? $kit['manifest']['requires'] : [];
        foreach (is_array($requires['kits'] ?? null) ? $requires['kits'] : [] as $required) {
            if (!is_string($required)) {
                continue;
            }
            if (!isset($kits[$required])) {
                $report->add($kit['where'], 0, "kit.json requires kit {$required}, which does not exist");
            } elseif ($kit['tier'] === 'free' && $kits[$required]['tier'] !== 'free') {
                $report->add($kit['where'], 0, "a Free kit cannot require the Pro kit {$required}");
            }
        }
    }
}

/**
 * @return list<string> The problems found, as "path:line: message".
 */
function check(string $free, string $pro): array
{
    $report = new Report();
    $all_kits = [];
    $sources = ['free' => $free];
    if ($pro !== '') {
        $sources['pro'] = $pro;
    }

    $glue = ['includes/kits/_runtime/hosts/wppilot.php', 'includes/kits/loader.php', 'includes/kits/skills.php'];
    foreach (files_under($free . '/includes/kits/_runtime') as $file) {
        if (!str_ends_with($file, '.php')) {
            continue;
        }
        $where = relative($free, $file);
        $source = (string) file_get_contents($file);
        $tokens = tokens($source);
        $lines = preg_split('/\R/', $source) ?: [];
        check_syntax($tokens, $lines, $where, $report);
        if (!in_array($where, $glue, true)) {
            check_portable($tokens, $lines, $where, null, $report);
        }
    }
    foreach (['includes/kits/loader.php', 'includes/kits/skills.php'] as $where) {
        if (is_file($free . '/' . $where)) {
            $source = (string) file_get_contents($free . '/' . $where);
            check_syntax(tokens($source), preg_split('/\R/', $source) ?: [], $where, $report);
        }
    }

    foreach ($sources as $tier => $root) {
        $label = $tier === 'pro' ? 'pro:' : '';
        foreach (kit_dirs($root . '/includes/kits') as $slug => $dir) {
            $kit_where = $label . relative($root, $dir);
            if (!is_file($dir . '/kit.json')) {
                $report->add($kit_where, 0, 'no kit.json');
                continue;
            }
            $manifest = json_decode((string) file_get_contents($dir . '/kit.json'), true);
            if (!is_array($manifest)) {
                $report->add($kit_where . '/kit.json', 0, 'not valid JSON');
                continue;
            }
            check_manifest($manifest, $slug, $tier, $kit_where . '/kit.json', $report);
            $all_kits[$slug] = ['tier' => $tier, 'manifest' => $manifest, 'where' => $kit_where . '/kit.json'];
            if (!is_file($dir . '/bootstrap.php')) {
                $report->add($kit_where, 0, 'no bootstrap.php');
            }
            if (is_file($dir . '/composer.json') || is_dir($dir . '/vendor')) {
                $report->add($kit_where, 0, 'a kit carries no Composer dependencies: two plugins with the same kit would load them twice');
            }
            $own = is_string($manifest['namespace'] ?? null) ? $manifest['namespace'] : 'WPPilot\\Kits\\' . $slug;

            $registered = [];
            foreach (files_under($dir) as $file) {
                if (!str_ends_with($file, '.php') || str_starts_with(relative($dir, $file), 'tests/')) {
                    continue;
                }
                $where = $label . relative($root, $file);
                $source = (string) file_get_contents($file);
                $tokens = tokens($source);
                $lines = preg_split('/\R/', $source) ?: [];
                check_syntax($tokens, $lines, $where, $report);
                check_portable($tokens, $lines, $where, $own, $report);
                foreach (profile_gate_problems($tokens) as $problem) {
                    $report->add($where, $problem['line'], $problem['message']);
                }
                foreach (registered_ability_literals($source) as $ability) {
                    $registered[$ability['name']] = $where . ':' . $ability['line'];
                }
                foreach ($tokens as $i => $token) {
                    if (called_function($tokens, $i) !== 'wp_register_ability') {
                        continue;
                    }
                    $first = argument($tokens, call_args($tokens, next_significant($tokens, $i)), 0, 'name');
                    if ($first === null || $first[0] !== $first[1] || literal($tokens[$first[0]]) === null) {
                        $report->add($where, $token[2], 'register kit abilities with a literal name, so every verifier counts them');
                    }
                }
            }

            $declared = [];
            foreach (is_array($manifest['abilities'] ?? null) ? $manifest['abilities'] : [] as $ability) {
                $name = is_array($ability) ? (string) ($ability['name'] ?? '') : '';
                if (preg_match(ABILITY_NAME_PATTERN, $name) !== 1 || !str_starts_with($name, 'wppilot/')) {
                    $report->add($kit_where . '/kit.json', 0, "ability name '{$name}' must be wppilot/<name> within WordPress's " . ABILITY_NAME_PATTERN);
                }
                $declared[$name] = true;
            }
            foreach (array_diff_key($registered, $declared) as $name => $where) {
                $report->add($where, 0, "registers {$name}, which kit.json abilities does not declare");
            }
            foreach (array_diff_key($declared, $registered) as $name => $unused) {
                $report->add($kit_where . '/kit.json', 0, "declares {$name}, which no literal wp_register_ability() in the kit registers");
            }

            $skills = is_array($manifest['skills'] ?? null) ? array_map('strval', $manifest['skills']) : [];
            foreach ($skills as $skill) {
                if (!is_file("{$dir}/skills/{$skill}/SKILL.md")) {
                    $report->add($kit_where . '/kit.json', 0, "declares skill {$skill}, but skills/{$skill}/SKILL.md does not exist");
                }
            }
            foreach (glob($dir . '/skills/*/SKILL.md') ?: [] as $skill_file) {
                if (!in_array(basename(dirname($skill_file)), $skills, true)) {
                    $report->add($label . relative($root, $skill_file), 0, 'this skill is not declared in kit.json skills');
                }
            }
            foreach (is_array($manifest['tests'] ?? null) ? $manifest['tests'] : [] as $test) {
                if (!file_exists($root . '/' . trim((string) $test, '/'))) {
                    $report->add($kit_where . '/kit.json', 0, "declares tests at {$test}, which does not exist");
                }
            }
        }
    }
    check_required_kits($all_kits, $report);

    $problems = array_values(array_unique($report->problems));
    sort($problems, SORT_NATURAL);
    return $problems;
}

$options = getopt('', ['pro-src:']);
$free = str_replace('\\', '/', dirname(__DIR__));
$pro = is_array($options) && isset($options['pro-src']) ? rtrim(str_replace('\\', '/', (string) $options['pro-src']), '/') : '';
if ($pro !== '' && !is_dir($pro . '/includes')) {
    fwrite(STDERR, "--pro-src {$pro} has no includes/\n");
    exit(1);
}

$problems = check($free, $pro);
if ($problems !== []) {
    fwrite(STDERR, "Kit boundary violations:\n\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "  - {$problem}\n");
    }
    fwrite(STDERR, "\nA kit is exported into plugins that have none of GoBuildWP; see the header of scripts/check-kit-boundaries.php.\n");
    exit(1);
}

echo 'Kits stay inside their boundaries (' . count(kit_dirs($free . '/includes/kits')) . ' free'
    . ($pro !== '' ? ', ' . count(kit_dirs($pro . '/includes/kits')) . ' pro' : '') . ").\n";
