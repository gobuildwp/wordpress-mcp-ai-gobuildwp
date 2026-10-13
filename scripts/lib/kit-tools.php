<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Scripts\Kits;

/**
 * Shared pieces of the kit tooling: export-kit.php, check-kit-boundaries.php and
 * test-kit-coexistence.php.
 *
 * Everything here reads PHP through token_get_all() rather than regular expressions over the
 * source. A regex cannot tell a string from a comment from a name, and every rule these scripts
 * enforce depends on exactly that difference: an ability name in a docblock is prose, the same
 * text as the first argument of wp_register_ability() is a registration.
 *
 * Development-only: package.sh excludes scripts/, so none of this ships.
 */

const SIGNIFICANT_SKIP = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

/** WordPress's own rule for an ability name (WP_Abilities_Registry::register()). */
const ABILITY_NAME_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+$/';

/** WordPress's own rule for an ability category slug (WP_Ability_Categories_Registry::register()). */
const CATEGORY_SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

/**
 * Tokens as uniform [id, text, line] triples; a single-character token gets id 0.
 *
 * @return list<array{0: int, 1: string, 2: int}>
 */
function tokens(string $source): array
{
    $out = [];
    $line = 1;
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $out[] = [$token[0], $token[1], $token[2]];
            $line = $token[2] + substr_count($token[1], "\n");
        } else {
            $out[] = [0, $token, $line];
        }
    }
    return $out;
}

/** @param list<array{0: int, 1: string, 2: int}> $tokens */
function next_significant(array $tokens, int $i): int
{
    $count = count($tokens);
    for ($j = $i + 1; $j < $count; $j++) {
        if (!in_array($tokens[$j][0], SIGNIFICANT_SKIP, true)) {
            return $j;
        }
    }
    return -1;
}

/** @param list<array{0: int, 1: string, 2: int}> $tokens */
function prev_significant(array $tokens, int $i): int
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if (!in_array($tokens[$j][0], SIGNIFICANT_SKIP, true)) {
            return $j;
        }
    }
    return -1;
}

/** Token opens a bracket that a `}` / `)` / `]` closes. */
function opens(array $token): bool
{
    return in_array($token[1], ['(', '[', '{'], true)
        || in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)
        || (defined('T_ATTRIBUTE') && $token[0] === T_ATTRIBUTE);
}

function closes(array $token): bool
{
    return $token[0] === 0 && in_array($token[1], [')', ']', '}'], true);
}

/**
 * Index of the bracket that closes the one opened at $open.
 *
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 */
function matching_close(array $tokens, int $open): int
{
    $depth = 0;
    $count = count($tokens);
    for ($j = $open; $j < $count; $j++) {
        if (opens($tokens[$j])) {
            $depth++;
        } elseif (closes($tokens[$j])) {
            $depth--;
            if ($depth === 0) {
                return $j;
            }
        }
    }
    return $count - 1;
}

/**
 * The arguments of the call whose `(` is at $open, as [first, last] token index ranges with
 * surrounding trivia trimmed. A named argument keeps its `name:` prefix inside the range.
 *
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 * @return list<array{0: int, 1: int}>
 */
function call_args(array $tokens, int $open): array
{
    $close = matching_close($tokens, $open);
    $args = [];
    $start = $open + 1;
    $depth = 0;
    for ($j = $open + 1; $j <= $close; $j++) {
        if ($j === $close || ($depth === 0 && $tokens[$j][1] === ',' && $tokens[$j][0] === 0)) {
            $first = $start;
            $last = $j - 1;
            while ($first <= $last && in_array($tokens[$first][0], SIGNIFICANT_SKIP, true)) {
                $first++;
            }
            while ($last >= $first && in_array($tokens[$last][0], SIGNIFICANT_SKIP, true)) {
                $last--;
            }
            if ($first <= $last) {
                $args[] = [$first, $last];
            }
            $start = $j + 1;
            continue;
        }
        if (opens($tokens[$j])) {
            $depth++;
        } elseif (closes($tokens[$j])) {
            $depth--;
        }
    }
    return $args;
}

/**
 * The argument at a position, or by name when the call used a named argument.
 *
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 * @param list<array{0: int, 1: int}> $args
 * @return array{0: int, 1: int}|null The value's range, without the `name:` prefix.
 */
function argument(array $tokens, array $args, int $position, string $name): ?array
{
    $positional = 0;
    $by_position = null;
    foreach ($args as $range) {
        [$first, $last] = $range;
        $colon = next_significant($tokens, $first);
        $is_named = $tokens[$first][0] === T_STRING && $colon !== -1 && $colon <= $last && $tokens[$colon][1] === ':'
            && $tokens[$colon][0] === 0;
        if ($is_named) {
            if (strtolower($tokens[$first][1]) === strtolower($name)) {
                return [next_significant($tokens, $colon), $last];
            }
            continue;
        }
        if ($positional === $position) {
            $by_position = $range;
        }
        $positional++;
    }
    return $by_position;
}

/**
 * The function a call at $i names, lower-cased and without a leading backslash, or null when
 * the token is not a plain function call (a method, a static call, a declaration, `new`).
 *
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 */
function called_function(array $tokens, int $i): ?string
{
    if (!in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
        return null;
    }
    $next = next_significant($tokens, $i);
    if ($next === -1 || $tokens[$next][1] !== '(') {
        return null;
    }
    $prev = prev_significant($tokens, $i);
    if ($prev !== -1 && in_array($tokens[$prev][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CONST], true)) {
        return null;
    }
    return strtolower(ltrim($tokens[$i][1], '\\'));
}

/**
 * The value of a plain string literal token, or null for anything else.
 */
function literal(array $token): ?string
{
    if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }
    $raw = $token[1];
    $body = substr($raw, 1, -1);
    if ($raw[0] === "'") {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
    }
    return stripcslashes($body);
}

/**
 * Class constants and namespace constants that a file assigns a plain string, by name.
 *
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 * @return array<string, string>
 */
function string_constants(array $tokens): array
{
    $found = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i][0] !== T_CONST) {
            continue;
        }
        $name = next_significant($tokens, $i);
        $eq = $name === -1 ? -1 : next_significant($tokens, $name);
        $value = $eq === -1 ? -1 : next_significant($tokens, $eq);
        $end = $value === -1 ? -1 : next_significant($tokens, $value);
        if ($eq !== -1 && $tokens[$eq][1] === '=' && $value !== -1 && $end !== -1 && in_array($tokens[$end][1], [';', ','], true)) {
            $string = literal($tokens[$value]);
            if ($string !== null) {
                $found[$tokens[$name][1]] = $string;
            }
        }
    }
    return $found;
}

/**
 * The string an argument evaluates to, when it is a literal or a constant this file defines
 * (`self::OPTION`, `static::OPTION`, `Runner::CRON_HOOK`, `TYPE`), else null.
 *
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 * @param array<string, string> $constants
 */
function static_string(array $tokens, ?array $range, array $constants): ?string
{
    if ($range === null) {
        return null;
    }
    [$first, $last] = $range;
    if ($first === $last) {
        $value = literal($tokens[$first]);
        if ($value !== null) {
            return $value;
        }
        return $tokens[$first][0] === T_STRING ? ($constants[$tokens[$first][1]] ?? null) : null;
    }
    $colon = next_significant($tokens, $first);
    $name = $colon === -1 ? -1 : next_significant($tokens, $colon);
    if ($colon !== -1 && $tokens[$colon][0] === T_DOUBLE_COLON && $name === $last && $tokens[$name][0] === T_STRING) {
        return $constants[$tokens[$name][1]] ?? null;
    }
    return null;
}

/**
 * Every file under a directory, sorted byte-wise so every run walks them in the same order.
 *
 * @return list<string> Absolute paths with forward slashes.
 */
function files_under(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $found = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $found[] = str_replace('\\', '/', $file->getPathname());
        }
    }
    sort($found, SORT_STRING);
    return $found;
}

function relative(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $path = str_replace('\\', '/', $path);
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}

/**
 * The kit folders under an includes/kits directory, by slug; `_runtime` and other
 * underscore-prefixed folders are the runtime's, not kits.
 *
 * @return array<string, string> slug => absolute directory
 */
function kit_dirs(string $kits_root): array
{
    $found = [];
    $dirs = glob(rtrim($kits_root, '/\\') . '/*', GLOB_ONLYDIR);
    foreach (is_array($dirs) ? $dirs : [] as $dir) {
        $slug = basename($dir);
        if (!str_starts_with($slug, '_')) {
            $found[$slug] = str_replace('\\', '/', $dir);
        }
    }
    ksort($found, SORT_STRING);
    return $found;
}

/**
 * Literal first arguments of wp_register_ability() in one PHP source, with their lines.
 *
 * @return list<array{name: string, line: int}>
 */
function registered_ability_literals(string $source, array $functions = ['wp_register_ability']): array
{
    $tokens = tokens($source);
    $found = [];
    foreach ($tokens as $i => $token) {
        $called = called_function($tokens, $i);
        if ($called === null) {
            continue;
        }
        $short = substr($called, (int) strrpos('\\' . $called, '\\'));
        if (!in_array($short, $functions, true)) {
            continue;
        }
        $args = call_args($tokens, next_significant($tokens, $i));
        $first = argument($tokens, $args, 0, 'name');
        if ($first !== null && $first[0] === $first[1]) {
            $name = literal($tokens[$first[0]]);
            if ($name !== null) {
                $found[] = ['name' => $name, 'line' => $token[2]];
            }
        }
    }
    return $found;
}

/**
 * HEAD of the git checkout a directory belongs to, and whether the given paths differ from it.
 *
 * @param list<string> $paths
 * @return array{sha: string, dirty: bool}
 */
function git_state(string $dir, array $paths): array
{
    $quoted = implode(' ', array_map('escapeshellarg', $paths));
    $sha = trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' rev-parse HEAD 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
    if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
        return ['sha' => 'unknown', 'dirty' => true];
    }
    $status = (string) shell_exec('git -C ' . escapeshellarg($dir) . ' status --porcelain --untracked-files=all -- ' . $quoted);
    return ['sha' => $sha, 'dirty' => trim($status) !== ''];
}

/**
 * Run `php -l` on a file with the interpreter running this script.
 *
 * @return string '' when it parses, else PHP's message.
 */
function lint_file(string $path): string
{
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    return $code === 0 ? '' : trim(implode("\n", $output));
}

/**
 * Top-level declarations (functions, classes, interfaces, traits, enums, constants) a PHP file
 * makes, keyed by fully qualified name. Conditional declarations inside a block are skipped,
 * as check-duplicate-declarations.php does, because a guarded polyfill is meant to exist twice.
 *
 * @return list<array{name: string, line: int}>
 */
function top_level_declarations(string $source): array
{
    $tokens = tokens($source);
    $namespace = '';
    $namespace_depth = 0;
    $depth = 0;
    $found = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (opens($token)) {
            $depth++;
            continue;
        }
        if (closes($token)) {
            $depth--;
            continue;
        }
        if ($token[0] === T_NAMESPACE) {
            $next = next_significant($tokens, $i);
            if ($next !== -1 && in_array($tokens[$next][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = $tokens[$next][1];
                $after = next_significant($tokens, $next);
                $namespace_depth = $after !== -1 && $tokens[$after][1] === '{' ? $depth + 1 : $depth;
            } elseif ($next !== -1 && $tokens[$next][1] === '{') {
                $namespace = '';
                $namespace_depth = $depth + 1;
            }
            continue;
        }
        if ($depth !== $namespace_depth) {
            continue;
        }
        $kinds = [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_CONST];
        if (defined('T_ENUM')) {
            $kinds[] = T_ENUM;
        }
        if (!in_array($token[0], $kinds, true)) {
            continue;
        }
        $prev = prev_significant($tokens, $i);
        // `Foo::class`, `new class` and `use function Foo\bar;` are not declarations.
        if ($prev !== -1 && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_NEW, T_USE], true)) {
            continue;
        }
        $name = next_significant($tokens, $i);
        if ($name !== -1 && $tokens[$name][1] === '&') {
            $name = next_significant($tokens, $name);
        }
        if ($name === -1 || $tokens[$name][0] !== T_STRING) {
            continue;
        }
        if ($token[0] === T_CONST) {
            // const A = 1, B = 2;
            $j = $name;
            while ($j !== -1 && $j < $count) {
                if ($tokens[$j][0] === T_STRING) {
                    $found[] = ['name' => ltrim($namespace . '\\' . $tokens[$j][1], '\\'), 'line' => $tokens[$j][2]];
                }
                $level = 0;
                $j++;
                while ($j < $count && !($level === 0 && in_array($tokens[$j][1], [',', ';'], true))) {
                    if (opens($tokens[$j])) {
                        $level++;
                    } elseif (closes($tokens[$j])) {
                        $level--;
                    }
                    $j++;
                }
                if ($j >= $count || $tokens[$j][1] === ';') {
                    break;
                }
                $j = next_significant($tokens, $j);
            }
            continue;
        }
        $kind = $token[0] === T_FUNCTION ? 'function ' : 'class ';
        $found[] = ['name' => $kind . ltrim($namespace . '\\' . $tokens[$name][1], '\\'), 'line' => $tokens[$name][2]];
    }
    return $found;
}

/**
 * Registrations that declare `meta.safety.min_profile` without enforcing it.
 *
 * The key alone is a declaration. On WordPress before 7.1 nothing core runs reads it, so a
 * Developer-only ability declared this way runs under Production Safe for every caller that
 * does not pass through the host's own gate. Each such registration must call
 * Runtime\require_profile() with its own literal name inside the wp_register_ability() call,
 * where the permission callback is, so the rule is visible to a reader and to this check.
 *
 * A file that names min_profile anywhere outside a registration is reported too: meta built in
 * a variable would hide the declaration from the check.
 *
 * @param list<array{0: int, 1: string, 2: int}> $tokens
 * @return list<array{line: int, message: string}>
 */
function profile_gate_problems(array $tokens): array
{
    $problems = [];
    $covered = [];
    foreach ($tokens as $i => $token) {
        $called = called_function($tokens, $i);
        if ($called === null || substr($called, (int) strrpos('\\' . $called, '\\')) !== 'wp_register_ability') {
            continue;
        }
        $open = next_significant($tokens, $i);
        $close = matching_close($tokens, $open);
        $name_range = argument($tokens, call_args($tokens, $open), 0, 'name');
        $name = $name_range !== null && $name_range[0] === $name_range[1] ? literal($tokens[$name_range[0]]) : null;

        $declares = false;
        $gated_by = [];
        for ($j = $open; $j <= $close; $j++) {
            $covered[$j] = true;
            if (literal($tokens[$j]) === 'min_profile' && ($tokens[next_significant($tokens, $j)][0] ?? 0) === T_DOUBLE_ARROW) {
                $declares = true;
            }
            $inner = called_function($tokens, $j);
            if ($inner !== null && substr($inner, (int) strrpos('\\' . $inner, '\\')) === 'require_profile') {
                $first = argument($tokens, call_args($tokens, next_significant($tokens, $j)), 0, 'ability_name');
                $gated_by[] = $first !== null && $first[0] === $first[1] ? literal($tokens[$first[0]]) : null;
            }
        }
        if (!$declares) {
            continue;
        }
        $label = $name ?? 'this ability';
        if ($gated_by === []) {
            $problems[] = [
                'line' => $token[2],
                'message' => "{$label} declares meta.safety.min_profile but its permission callback never calls Runtime\\require_profile('{$label}'); before WordPress 7.1 nothing else enforces it",
            ];
        } elseif (!in_array($name, $gated_by, true)) {
            $problems[] = [
                'line' => $token[2],
                'message' => "{$label} calls Runtime\\require_profile() with a name other than its own literal name",
            ];
        }
    }
    foreach ($tokens as $i => $token) {
        if (!isset($covered[$i]) && literal($token) === 'min_profile') {
            $problems[] = [
                'line' => $token[2],
                'message' => "'min_profile' outside a wp_register_ability() call: declare meta.safety inline so the require_profile() check can see it",
            ];
        }
    }
    return $problems;
}
