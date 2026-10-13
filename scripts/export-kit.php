<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Export kits into another plugin, rewritten under that plugin's own names.
 *
 *   php scripts/export-kit.php --kits=changes-export[,more] [--pro-src=../wppilot-pro] \
 *       --namespace="Acme\Kits" --prefix=acme --text-domain=acme-plugin --out=../acme/kits \
 *       [--copyright="2026 Acme <dev@acme.test>"]
 *
 * THE EMITTED TREE IS VENDORED, NOT FORKED. The receiving plugin requires `<out>/load.php`
 * once and never edits anything under <out> except config.php. A change to a kit is made here,
 * in the kit's source, and re-exported; a re-export replaces every emitted file, so an edit made
 * in the copy is lost the next time anyone runs this, silently.
 *
 * Why a rewrite and not runtime prefixing: two plugins that each carry a copy of the same kit
 * must not share one PHP symbol, option, hook, cron event or ability name, or the second one to
 * load either fatals on a redeclaration or quietly reads and writes the first one's data. Kit
 * source therefore keeps GoBuildWP's literal names — the verifiers and the website count literal
 * `wp_register_ability('wppilot/…')` calls — and this script renames them on the way out:
 *
 *   PHP namespace   WPPilot\Kits\…                  → --namespace\…   (names, `use`, strings)
 *   ability names   'wppilot/<name>'                 → '<prefix>/<name>'
 *   host abilities  wppilot/list-changes, rollback-change → <prefix>/kit-list-changes, kit-rollback-change
 *                   (the standalone host's equivalents; a skill pointing at GoBuildWP's own
 *                   ledger abilities would otherwise name something that does not exist there)
 *   storage names   wppilot_kit_…, _wppilot_kit_…    → <prefix>_kit_…, _<prefix>_kit_…
 *   handles, REST   wppilot-kit…                     → <prefix>-kit…
 *   text domain     'wppilot' / 'wppilot-pro' as the domain argument of __(), _e(), _x(), _n(),
 *                   esc_html__() and friends          → --text-domain
 *   categories      a slug a kit declares in kit.json `categories` → <prefix>-<slug>, in kit.json
 *                   and wherever kit PHP passes it as `'category' =>` or to a category function.
 *                   Inside GoBuildWP a kit reuses the host's category; exported, it must not
 *                   register GoBuildWP's `changes` before GoBuildWP does, which makes GoBuildWP's own
 *                   registration fail with a notice on every request.
 *   tests only      WPPilot\Tests\Unit\Kits → --namespace\Tests, and the source paths
 *                   /includes/kits/_runtime/ and /includes/kits/ → /runtime/ and /kits/. Tests
 *                   keep their relative location (tests/Unit/Kits/<Kit>/, tests/kits/<slug>.php)
 *                   so their dirname(__DIR__, n) still lands on the export root.
 *
 * PHP is rewritten token by token (token_get_all), so a rule only ever touches the kind of token
 * it is about. JSON, Markdown, JS and CSS are rewritten as text with rules anchored on word
 * boundaries; kit.json is decoded, rewritten field by field, and re-encoded.
 *
 * Comments: rewritten by the same rules; a PHP comment that still names GoBuildWP afterwards (a
 * docblock explaining how the GoBuildWP host differs, say) is removed whole and replaced by as many
 * newlines as it spanned, so every line of emitted code keeps the line number it has in the
 * source and a stack trace from the copy points at the right line here. Markdown has no such
 * fallback: wrap GoBuildWP-only prose in `<!-- kit-export:omit -->` … `<!-- /kit-export:omit -->`
 * and it is dropped; anything left over fails the export. JS and CSS comments are not touched,
 * so the same applies to them. The one deliberate exception is the SPDX-FileCopyrightText line
 * of each PHP file: it is a copyright notice, kept as written unless --copyright replaces it, and
 * it is the only line the leftover scan allows to name GoBuildWP.
 *
 * Output under --out:
 *   load.php      the entry the receiving plugin requires once (generated here)
 *   config.php    its settings; written only when absent, never overwritten or hashed
 *   EXPORT.json   source git SHAs, kit versions, runtime API_VERSION, the rewrite map and the
 *                 sha256 of every emitted file, keys sorted
 *   runtime/      includes/kits/_runtime without hosts/wppilot.php (GoBuildWP-only glue)
 *   kits/<slug>/  each kit, minus any tests/ folder inside it, plus every kit a requested one
 *                 names in kit.json `requires.kits` (transitively), reported on stdout
 *   tests/        the kit's tests from this repo (kit.json `tests`, else tests/Unit/Kits/<Kit>)
 *                 and, for Pro kits, <pro-src>/tests/kits/<slug>.php. They need a PHPUnit
 *                 harness with WordPress doubles in the receiving plugin to run.
 *
 * Nothing in the output carries a timestamp, and every listing is sorted, so exporting the same
 * source twice produces byte-identical trees and a re-export diffs to exactly what changed.
 *
 * Before anything is written, every kit must pass scripts/check-kit-boundaries.php, and the
 * emitted tree must pass: php -l on every PHP file; no symbol declared twice; no leftover
 * `wppilot` anywhere (file:line reported); every `<prefix>/name` it mentions registered by the
 * export; ability names and category slugs within WordPress's own patterns; option names within
 * 191 characters, transients within 172 and hook or cron names within 64 once prefixed. The
 * previous export in --out is only replaced when all of that passes.
 *
 * Development-only: package.sh excludes scripts/.
 */

namespace WPPilot\Scripts\Kits\Export;

use function WPPilot\Scripts\Kits\argument;
use function WPPilot\Scripts\Kits\call_args;
use function WPPilot\Scripts\Kits\called_function;
use function WPPilot\Scripts\Kits\files_under;
use function WPPilot\Scripts\Kits\git_state;
use function WPPilot\Scripts\Kits\lint_file;
use function WPPilot\Scripts\Kits\literal;
use function WPPilot\Scripts\Kits\next_significant;
use function WPPilot\Scripts\Kits\prev_significant;
use function WPPilot\Scripts\Kits\registered_ability_literals;
use function WPPilot\Scripts\Kits\relative;
use function WPPilot\Scripts\Kits\static_string;
use function WPPilot\Scripts\Kits\string_constants;
use function WPPilot\Scripts\Kits\tokens;
use function WPPilot\Scripts\Kits\top_level_declarations;

use const WPPilot\Scripts\Kits\ABILITY_NAME_PATTERN;
use const WPPilot\Scripts\Kits\CATEGORY_SLUG_PATTERN;

require_once __DIR__ . '/lib/kit-tools.php';

/** GoBuildWP's ledger abilities, and what the standalone host calls its equivalent. */
const HOST_EQUIVALENTS = ['list-changes' => 'kit-list-changes', 'rollback-change' => 'kit-rollback-change'];

/** The runtime file that is GoBuildWP's glue and never leaves this repository. */
const WPPILOT_ONLY = 'hosts/wppilot.php';

/**
 * Whether text names the source plugin: its code name (wppilot, in every identifier a kit is
 * rewritten from) or its product name, GoBuildWP. Either one left in an export ties the copy to
 * this plugin.
 */
function names_source_plugin(string $text): bool
{
    return stripos($text, 'wppilot') !== false || stripos($text, 'gobuildwp') !== false;
}

/** Longest suffix the runtime appends to a key prefix (Runner: `lease_` + a UUID). */
const KEY_TAIL = 42;

const OPTION_MAX = 191;
const TRANSIENT_MAX = 172;
const SITE_TRANSIENT_MAX = 167;
const HOOK_MAX = 64;

const I18N_FUNCTIONS = [
    '__', '_e', '_x', '_ex', '_n', '_nx', '_n_noop', '_nx_noop', 'translate', 'esc_html__', 'esc_html_e',
    'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x', 'load_plugin_textdomain', 'wp_set_script_translations',
];

const CATEGORY_FUNCTIONS = [
    'wp_register_ability_category', 'wp_has_ability_category', 'wp_get_ability_category', 'wp_unregister_ability_category',
];

final class ExportError extends \RuntimeException
{
}

final class Rewriter
{
    /**
     * @param array<string, string> $categories Declared kit category slug => exported slug.
     */
    public function __construct(
        public string $namespace,
        public string $prefix,
        public string $domain,
        public ?string $copyright,
        public array $categories,
    ) {
    }

    /** @return array<string, mixed> */
    public function map(): array
    {
        $host = [];
        foreach (HOST_EQUIVALENTS as $from => $to) {
            $host['wppilot/' . $from] = $this->prefix . '/' . $to;
        }
        return [
            'namespace' => ['WPPilot\\Kits' => $this->namespace, 'WPPilot\\Tests\\Unit\\Kits' => $this->namespace . '\\Tests'],
            'ability_prefix' => ['wppilot/' => $this->prefix . '/'],
            'host_abilities' => $host,
            'names' => [
                'wppilot_kit_' => $this->prefix . '_kit_',
                '_wppilot_kit_' => '_' . $this->prefix . '_kit_',
                'wppilot-kit' => $this->prefix . '-kit',
            ],
            'text_domain' => ['wppilot' => $this->domain, 'wppilot-pro' => $this->domain],
            'categories' => $this->categories,
            'test_paths' => ['/includes/kits/_runtime/' => '/runtime/', '/includes/kits/' => '/kits/'],
            'comments' => 'rewritten; a PHP comment still naming the source plugin is blanked, line count kept',
            'copyright' => $this->copyright ?? 'kept as written',
        ];
    }

    /**
     * Rewrite names inside one piece of text: string contents, a comment, a Markdown line.
     */
    public function text(string $text, bool $test = false): string
    {
        $namespace = $this->namespace;
        if ($test) {
            $text = (string) preg_replace_callback(
                '/WPPilot(\\\\{1,2})Tests\1Unit\1Kits(?![A-Za-z0-9_])/',
                static fn(array $m): string => str_replace('\\', $m[1], $namespace) . $m[1] . 'Tests',
                $text,
            );
            $text = str_replace(['/includes/kits/_runtime/', '/includes/kits/'], ['/runtime/', '/kits/'], $text);
        }
        $text = (string) preg_replace_callback(
            '/WPPilot(\\\\{1,2})Kits(?![A-Za-z0-9_])/',
            static fn(array $m): string => str_replace('\\', $m[1], $namespace),
            $text,
        );
        $prefix = $this->prefix;
        $text = (string) preg_replace_callback(
            '/(?<![A-Za-z0-9_.\/-])wppilot\/([a-z0-9][a-z0-9-]*)/',
            static fn(array $m): string => $prefix . '/' . (HOST_EQUIVALENTS[$m[1]] ?? $m[1]),
            $text,
        );
        $text = (string) preg_replace('/(?<![A-Za-z0-9])wppilot_kit_/', $prefix . '_kit_', $text);
        return (string) preg_replace('/(?<![A-Za-z0-9_])wppilot-kit/', $prefix . '-kit', $text);
    }

    public function php(string $source, bool $test): string
    {
        $tokens = tokens($source);
        $replace = [];
        $quoted = static fn(string $value): string => "'" . $value . "'";

        foreach ($tokens as $i => $token) {
            $called = called_function($tokens, $i);
            if ($called !== null) {
                $short = substr($called, (int) strrpos('\\' . $called, '\\'));
                $open = next_significant($tokens, $i);
                if (in_array($short, I18N_FUNCTIONS, true)) {
                    foreach (call_args($tokens, $open) as [$first, $last]) {
                        // The whole argument is the literal, positionally or as `domain: '…'`.
                        $colon = next_significant($tokens, $first);
                        $alone = $first === $last
                            || ($tokens[$first][0] === T_STRING && $tokens[$colon][1] === ':' && next_significant($tokens, $colon) === $last);
                        if ($alone && in_array(literal($tokens[$last]), ['wppilot', 'wppilot-pro'], true)) {
                            $replace[$last] = $quoted($this->domain);
                        }
                    }
                }
                if (in_array($short, CATEGORY_FUNCTIONS, true)) {
                    $slug = argument($tokens, call_args($tokens, $open), 0, 'slug');
                    if ($slug !== null && $slug[0] === $slug[1] && isset($this->categories[(string) literal($tokens[$slug[0]])])) {
                        $replace[$slug[0]] = $quoted($this->categories[(string) literal($tokens[$slug[0]])]);
                    }
                }
                continue;
            }
            $value = literal($token);
            if ($value !== null && isset($this->categories[$value])) {
                $arrow = prev_significant($tokens, $i);
                $key = $arrow === -1 ? -1 : prev_significant($tokens, $arrow);
                if ($arrow !== -1 && $tokens[$arrow][0] === T_DOUBLE_ARROW && $key !== -1 && literal($tokens[$key]) === 'category') {
                    $replace[$i] = $quoted($this->categories[$value]);
                }
            }
        }

        $out = [];
        foreach ($tokens as $i => [$id, $text]) {
            if (isset($replace[$i])) {
                $out[] = $replace[$i];
                continue;
            }
            switch ($id) {
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                case T_NAME_RELATIVE:
                    $out[] = $this->name($text, $test);
                    break;
                case T_STRING:
                case T_CONSTANT_ENCAPSED_STRING:
                case T_ENCAPSED_AND_WHITESPACE:
                    $out[] = $this->text($text, $test);
                    break;
                case T_COMMENT:
                case T_DOC_COMMENT:
                    $comment = $this->comment($text, $test);
                    if ($comment === null) {
                        // Blank the comment but keep its lines, and drop the indentation that
                        // led up to it so the copy has no trailing whitespace.
                        $last = count($out) - 1;
                        if ($last >= 0 && trim($out[$last], " \t\r\n") === '') {
                            $cut = strrpos($out[$last], "\n");
                            $out[$last] = $cut === false ? '' : substr($out[$last], 0, $cut + 1);
                        }
                        $comment = str_repeat("\n", substr_count($text, "\n"));
                    }
                    $out[] = $comment;
                    break;
                default:
                    $out[] = $text;
            }
        }
        return implode('', $out);
    }

    private function name(string $name, bool $test): string
    {
        $namespace = $this->namespace;
        if ($test) {
            $name = (string) preg_replace_callback(
                '/^(\\\\?)WPPilot\\\\Tests\\\\Unit\\\\Kits(?=\\\\|$)/',
                static fn(array $m): string => $m[1] . $namespace . '\\Tests',
                $name,
            );
        }
        return (string) preg_replace_callback(
            '/^(\\\\?)WPPilot\\\\Kits(?=\\\\|$)/',
            static fn(array $m): string => $m[1] . $namespace,
            $name,
        );
    }

    /** The rewritten comment, or null when it still names GoBuildWP and has to go. */
    private function comment(string $text, bool $test): ?string
    {
        if (preg_match('/^(\/\/\s*SPDX-FileCopyrightText:)/', $text, $m) === 1) {
            return $this->copyright === null ? $text : $m[1] . ' ' . $this->copyright;
        }
        $rewritten = $this->text($text, $test);
        return names_source_plugin($rewritten) ? null : $rewritten;
    }

    public function markdown(string $text): string
    {
        $kept = (string) preg_replace('/^[ \t]*<!-- kit-export:omit -->.*?^[ \t]*<!-- \/kit-export:omit -->[ \t]*\R?/ms', '', $text);
        if (str_contains($kept, 'kit-export:omit')) {
            throw new ExportError('an unbalanced <!-- kit-export:omit --> marker');
        }
        // Omitting a block can leave two blank lines where it stood.
        return $this->text((string) preg_replace("/\n{3,}/", "\n\n", $kept));
    }

    public function manifest(string $json): string
    {
        $manifest = json_decode($json, true);
        if (!is_array($manifest)) {
            throw new ExportError('kit.json is not valid JSON');
        }
        if (is_array($manifest['categories'] ?? null)) {
            $renamed = [];
            foreach ($manifest['categories'] as $slug => $category) {
                $renamed[$this->categories[$slug] ?? $slug] = $category;
            }
            $manifest['categories'] = $renamed;
        }
        $walk = function (mixed $value) use (&$walk): mixed {
            if (is_string($value)) {
                return $this->text($value);
            }
            if (is_array($value)) {
                $rewritten = [];
                foreach ($value as $key => $item) {
                    $rewritten[is_string($key) ? $this->text($key) : $key] = $walk($item);
                }
                return $rewritten;
            }
            return $value;
        };
        return json_encode($walk($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

/**
 * @return array<string, string>
 */
function options(array $argv): array
{
    $parsed = getopt('', ['kits:', 'pro-src:', 'namespace:', 'prefix:', 'text-domain:', 'out:', 'copyright:', 'help']);
    if ($parsed === false || isset($parsed['help'])) {
        throw new ExportError('usage: php scripts/export-kit.php --kits=a[,b] [--pro-src=DIR] --namespace="Vendor\\Kits" --prefix=vendor --text-domain=vendor-plugin --out=DIR [--copyright="2026 Vendor <dev@vendor.test>"]');
    }
    foreach (['kits', 'namespace', 'prefix', 'text-domain', 'out'] as $required) {
        if (!is_string($parsed[$required] ?? null) || $parsed[$required] === '') {
            throw new ExportError("--{$required} is required");
        }
    }
    $namespace = trim((string) $parsed['namespace'], '\\');
    if (preg_match('/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*$/', $namespace) !== 1) {
        throw new ExportError('--namespace must be a PHP namespace such as "Acme\\Kits"');
    }
    if (names_source_plugin($namespace)) {
        throw new ExportError('--namespace must not name GoBuildWP: the point of an export is that it shares nothing with it');
    }
    // Letters and digits only: the prefix becomes an ability namespace (no `_`), an option and
    // hook prefix (no `-` wanted in an identifier-like name) and a category slug prefix.
    if (preg_match('/^[a-z][a-z0-9]{1,31}$/', (string) $parsed['prefix']) !== 1 || names_source_plugin((string) $parsed['prefix'])) {
        throw new ExportError('--prefix must be 2-32 lower-case letters and digits, starting with a letter, and not wppilot');
    }
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $parsed['text-domain']) !== 1 || names_source_plugin((string) $parsed['text-domain'])) {
        throw new ExportError('--text-domain must be a lower-case slug, and not GoBuildWP\'s (wppilot)');
    }
    $copyright = isset($parsed['copyright']) ? trim((string) $parsed['copyright']) : null;
    if ($copyright !== null && ($copyright === '' || str_contains($copyright, "\n"))) {
        throw new ExportError('--copyright must be one line');
    }
    return [
        'kits' => (string) $parsed['kits'],
        'pro' => isset($parsed['pro-src']) ? (string) $parsed['pro-src'] : '',
        'namespace' => $namespace,
        'prefix' => (string) $parsed['prefix'],
        'domain' => (string) $parsed['text-domain'],
        'out' => (string) $parsed['out'],
        'copyright' => $copyright ?? '',
    ];
}

function absolute(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $absolute = preg_match('#^([A-Za-z]:)?/#', $path) === 1 ? $path : str_replace('\\', '/', (string) getcwd()) . '/' . $path;
    $parts = [];
    foreach (explode('/', $absolute) as $index => $part) {
        if ($part === '..') {
            array_pop($parts);
        } elseif ($part !== '.' && ($part !== '' || $index === 0)) {
            $parts[] = $part;
        }
    }
    return implode('/', $parts);
}

function remove_tree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach ((array) scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            remove_tree($path . '/' . $entry);
        }
    }
    rmdir($path);
}

function write_file(string $path, string $contents): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        throw new ExportError('cannot create ' . dirname($path));
    }
    if (file_put_contents($path, $contents) === false) {
        throw new ExportError('cannot write ' . $path);
    }
}

function studly(string $slug): string
{
    return str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
}

/** @param array<mixed> $value */
function sort_keys(array $value): array
{
    if (!array_is_list_compat($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = sort_keys($item);
        }
    }
    return $value;
}

/** array_is_list() without needing PHP 8.1. */
function array_is_list_compat(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

/**
 * Names the standalone host registers from its id, read from its source: `$this->id . '/kit-…'`.
 *
 * @return list<string>
 */
function standalone_ability_suffixes(string $runtime): array
{
    $tokens = tokens((string) file_get_contents($runtime . '/hosts/standalone.php'));
    $found = [];
    foreach ($tokens as $i => $token) {
        $value = literal($token);
        $prev = prev_significant($tokens, $i);
        if ($value !== null && preg_match('/^\/([a-z0-9-]+)$/', $value, $m) === 1 && $prev !== -1 && $tokens[$prev][1] === '.') {
            $found[$m[1]] = true;
        }
    }
    $found = array_keys($found);
    sort($found, SORT_STRING);
    return $found;
}

/**
 * @param array<string, string> $emitted relative path => contents
 * @return list<string> Problems, as "path:line: message".
 */
function validate(array $emitted, Rewriter $rewriter, array $abilities, array $categories, string $staged): array
{
    $problems = [];
    $prefix = $rewriter->prefix;

    foreach ($abilities as $name) {
        if (preg_match(ABILITY_NAME_PATTERN, $name) !== 1) {
            $problems[] = "ability name {$name} does not match WordPress's " . ABILITY_NAME_PATTERN;
        }
    }
    foreach ($categories as $slug) {
        if (preg_match(CATEGORY_SLUG_PATTERN, $slug) !== 1) {
            $problems[] = "category slug {$slug} does not match WordPress's " . CATEGORY_SLUG_PATTERN;
        }
    }

    $declared = [];
    foreach ($emitted as $path => $contents) {
        if ($path === 'EXPORT.json') {
            continue;
        }
        // Leftovers: nothing emitted may still name GoBuildWP, bar the copyright notices.
        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if (!names_source_plugin($line)) {
                continue;
            }
            if ($rewriter->copyright === null && preg_match('/^\s*\/\/\s*SPDX-FileCopyrightText:/', $line) === 1) {
                continue;
            }
            $problems[] = sprintf('%s:%d: still names GoBuildWP: %s', $path, $index + 1, trim($line));
        }
        // Every ability the copy mentions has to exist in the copy.
        if (preg_match_all('/(?<![A-Za-z0-9_.\/-])' . preg_quote($prefix, '/') . '\/([a-z0-9][a-z0-9-]*)/', $contents, $mentions, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($mentions[0] as $index => [$mention, $offset]) {
                if (str_ends_with($mention, '-') || in_array($mention, $abilities, true)) {
                    continue;
                }
                $problems[] = sprintf(
                    '%s:%d: mentions %s, which nothing in this export registers',
                    $path,
                    substr_count(substr($contents, 0, $offset), "\n") + 1,
                    $mention,
                );
            }
        }
        if (!str_ends_with($path, '.php')) {
            continue;
        }

        $error = lint_file($staged . '/' . $path);
        if ($error !== '') {
            $problems[] = "{$path}: php -l: {$error}";
        }

        $tokens = tokens($contents);
        foreach ($tokens as $i => $token) {
            if ($token[0] === T_NAMESPACE) {
                $name = next_significant($tokens, $i);
                $namespace = $name === -1 ? '' : $tokens[$name][1];
                if (in_array($tokens[$name][0] ?? 0, [T_STRING, T_NAME_QUALIFIED], true)
                    && $namespace !== $rewriter->namespace && !str_starts_with($namespace, $rewriter->namespace . '\\')) {
                    $problems[] = sprintf('%s:%d: namespace %s is outside %s', $path, $token[2], $namespace, $rewriter->namespace);
                }
            }
        }
        foreach (top_level_declarations($contents) as $declaration) {
            $declared[$declaration['name']][] = $path . ':' . $declaration['line'];
        }

        // Name lengths, once prefixed. A key ending in `_` is a prefix the runtime completes.
        $constants = string_constants($tokens);
        $transients = [];
        $site_transients = [];
        $hooks = [];
        foreach ($tokens as $i => $token) {
            $called = called_function($tokens, $i);
            if ($called === null) {
                continue;
            }
            $args = call_args($tokens, next_significant($tokens, $i));
            $first = static_string($tokens, argument($tokens, $args, 0, 'x'), $constants);
            if (in_array($called, ['get_transient', 'set_transient', 'delete_transient'], true) && $first !== null) {
                $transients[$first] = true;
            } elseif (in_array($called, ['get_site_transient', 'set_site_transient', 'delete_site_transient'], true) && $first !== null) {
                $site_transients[$first] = true;
            } elseif (in_array($called, ['add_action', 'add_filter', 'do_action', 'apply_filters', 'has_action', 'has_filter', 'remove_action', 'remove_filter', 'wp_next_scheduled', 'wp_clear_scheduled_hook'], true) && $first !== null) {
                $hooks[$first] = true;
            } elseif (in_array($called, ['wp_schedule_single_event', 'wp_schedule_event'], true)) {
                $hook = static_string($tokens, argument($tokens, $args, $called === 'wp_schedule_event' ? 2 : 1, 'hook'), $constants);
                if ($hook !== null) {
                    $hooks[$hook] = true;
                }
            }
        }
        $pattern = '/^_?' . preg_quote($prefix, '/') . '_kit_/';
        foreach ($tokens as $token) {
            $value = literal($token);
            if ($value === null || preg_match($pattern, $value) !== 1) {
                continue;
            }
            $length = strlen($value) + (str_ends_with($value, '_') ? KEY_TAIL : 0);
            $limit = OPTION_MAX;
            $kind = 'an option name';
            if (isset($site_transients[$value])) {
                [$limit, $kind] = [SITE_TRANSIENT_MAX, 'a site transient key'];
            } elseif (isset($transients[$value])) {
                [$limit, $kind] = [TRANSIENT_MAX, 'a transient key'];
            } elseif (isset($hooks[$value])) {
                [$limit, $kind] = [HOOK_MAX, 'a hook or cron event name'];
            }
            if ($length > $limit) {
                $problems[] = sprintf('%s:%d: %s is %d characters as %s (limit %d)', $path, $token[2], $value, $length, $kind, $limit);
            }
        }
    }
    foreach ($declared as $name => $where) {
        if (count($where) > 1) {
            $problems[] = sprintf('%s is declared more than once: %s', $name, implode(', ', $where));
        }
    }
    return $problems;
}

function load_php(string $namespace, string $prefix): string
{
    return <<<PHP
<?php

// SPDX-License-Identifier: GPL-2.0-or-later
//
// Generated by the kit exporter. Vendored: never edit anything in this folder except
// config.php. Change the kit at its source and export it again; a re-export replaces every
// other file here.

declare(strict_types=1);

namespace {$namespace};

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/runtime/runtime.php';
require_once __DIR__ . '/runtime/hosts/standalone.php';

(static function (): void {
    // Required twice by mistake, the second copy of the host would register its abilities twice.
    if (Runtime\\has_host()) {
        return;
    }
    /** @var mixed \$config */
    \$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
    Runtime\\host(new Runtime\\Hosts\\StandaloneHost('{$prefix}', is_array(\$config) ? \$config : []));
    Runtime\\PostPartial\\register(Runtime\\host()->ledger());

    // After every plugin file is loaded, so a kit's `requires.classes` sees the plugins it
    // needs; before init, so kit abilities are queued before the Abilities API collects them.
    \$load = static function (): void {
        Runtime\\load_kits(__DIR__ . '/kits');
    };
    if (did_action('plugins_loaded') > 0) {
        \$load();
    } else {
        add_action('plugins_loaded', \$load, 20);
    }
})();

PHP;
}

function config_php(string $domain): string
{
    return <<<PHP
<?php

// Settings for the vendored kits. The exporter writes this file once and never touches it
// again, so it is the one file in this folder that is yours to edit.

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

return [
    // Master switch: false keeps every kit ability registered but refused.
    'enabled' => true,
    // Who may run kit abilities.
    'capability' => 'manage_options',
    // readonly, production or developer; an ability whose meta.safety.min_profile is above
    // this is refused.
    'safety_profile' => 'production',
    // The admin menu kit screens hang under.
    'admin_parent_slug' => 'tools.php',
    'text_domain' => '{$domain}',
];

PHP;
}

function main(array $argv): int
{
    $options = options($argv);
    $free = absolute(dirname(__DIR__));
    $pro = $options['pro'] !== '' ? absolute($options['pro']) : '';
    $out = absolute($options['out']);
    $runtime = $free . '/includes/kits/_runtime';

    if ($pro !== '' && !is_dir($pro . '/includes/kits')) {
        throw new ExportError("--pro-src {$pro} has no includes/kits");
    }
    foreach (array_filter([$free, $pro]) as $source) {
        if ($out === $source || str_starts_with($out . '/', $source . '/includes/') || str_starts_with($source . '/', $out . '/')) {
            throw new ExportError("--out {$out} overlaps the source tree {$source}");
        }
    }

    // A kit that breaks the boundary rules would carry that break into the other plugin.
    $lint = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/check-kit-boundaries.php')
        . ($pro !== '' ? ' --pro-src=' . escapeshellarg($pro) : '') . ' 2>&1';
    exec($lint, $lint_output, $lint_code);
    if ($lint_code !== 0) {
        throw new ExportError("the kits fail scripts/check-kit-boundaries.php:\n" . implode("\n", $lint_output));
    }

    // Resolve every requested kit before touching anything, with the kits each one declares in
    // kit.json `requires.kits`: a kit that runs another kit's abilities (routines runs the audits)
    // would otherwise export cleanly and then name abilities its copy does not have.
    $kits = [];
    $requested = array_values(array_unique(array_filter(array_map('trim', explode(',', $options['kits'])))));
    $slugs = $requested;
    for ($i = 0; $i < count($slugs); $i++) {
        $slug = $slugs[$i];
        foreach ([$free, $pro] as $root) {
            $file = "{$root}/includes/kits/{$slug}/kit.json";
            if ($root === '' || !is_file($file)) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($file), true);
            $requires = is_array($manifest) && is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
            foreach (is_array($requires['kits'] ?? null) ? $requires['kits'] : [] as $required) {
                if (is_string($required) && !in_array($required, $slugs, true)) {
                    $slugs[] = $required;
                }
            }
        }
    }
    $added = array_values(array_diff($slugs, $requested));
    if ($added !== []) {
        fwrite(STDOUT, 'export-kit: also exporting ' . implode(', ', $added) . ", which the requested kits require\n");
    }
    sort($slugs, SORT_STRING);
    foreach ($slugs as $slug) {
        $candidates = array_filter([
            'free' => is_file("{$free}/includes/kits/{$slug}/kit.json") ? "{$free}/includes/kits/{$slug}" : '',
            'pro' => $pro !== '' && is_file("{$pro}/includes/kits/{$slug}/kit.json") ? "{$pro}/includes/kits/{$slug}" : '',
        ]);
        if ($candidates === []) {
            throw new ExportError("no kit {$slug} in {$free}/includes/kits" . ($pro !== '' ? " or {$pro}/includes/kits" : ''));
        }
        if (count($candidates) > 1) {
            throw new ExportError("kit {$slug} exists in both Free and Pro; rename one");
        }
        $origin = (string) array_key_first($candidates);
        $manifest = json_decode((string) file_get_contents($candidates[$origin] . '/kit.json'), true);
        if (!is_array($manifest)) {
            throw new ExportError("{$slug}/kit.json is not valid JSON");
        }
        $kits[$slug] = ['origin' => $origin, 'dir' => $candidates[$origin], 'root' => $origin === 'free' ? $free : $pro, 'manifest' => $manifest];
    }

    $categories = [];
    foreach ($kits as $kit) {
        foreach (array_keys(is_array($kit['manifest']['categories'] ?? null) ? $kit['manifest']['categories'] : []) as $slug) {
            $categories[(string) $slug] = $options['prefix'] . '-' . $slug;
        }
    }
    ksort($categories, SORT_STRING);
    $rewriter = new Rewriter(
        $options['namespace'],
        $options['prefix'],
        $options['domain'],
        $options['copyright'] !== '' ? $options['copyright'] : null,
        $categories,
    );

    $suffixes = standalone_ability_suffixes($runtime);
    foreach (HOST_EQUIVALENTS as $from => $to) {
        if (!in_array($to, $suffixes, true)) {
            throw new ExportError("the standalone host no longer registers <id>/{$to}, which the exporter maps wppilot/{$from} to");
        }
    }

    /** @var array<string, string> $emitted */
    $emitted = [];
    $transform = static function (string $path, string $contents, bool $test, bool $manifest) use ($rewriter): string {
        try {
            if ($manifest) {
                return $rewriter->manifest($contents);
            }
            return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'php' => $rewriter->php($contents, $test),
                'md' => $rewriter->markdown($contents),
                'json', 'js', 'mjs', 'css', 'txt', 'html', 'svg', 'xml', 'yml', 'yaml' => $rewriter->text($contents, $test),
                default => $contents,
            };
        } catch (ExportError $error) {
            throw new ExportError($path . ': ' . $error->getMessage());
        }
    };

    foreach (files_under($runtime) as $file) {
        $relative = relative($runtime, $file);
        if ($relative !== WPPILOT_ONLY) {
            $emitted['runtime/' . $relative] = $transform('runtime/' . $relative, (string) file_get_contents($file), false, false);
        }
    }

    $abilities = [];
    foreach ($suffixes as $suffix) {
        $abilities[] = $options['prefix'] . '/' . $suffix;
    }
    $kit_versions = [];
    foreach ($kits as $slug => $kit) {
        foreach (files_under($kit['dir']) as $file) {
            $relative = relative($kit['dir'], $file);
            if (str_starts_with($relative, 'tests/')) {
                continue;
            }
            $target = "kits/{$slug}/{$relative}";
            $emitted[$target] = $transform($target, (string) file_get_contents($file), false, $relative === 'kit.json');
        }
        $manifest = json_decode($emitted["kits/{$slug}/kit.json"], true);
        foreach (is_array($manifest['abilities'] ?? null) ? $manifest['abilities'] : [] as $ability) {
            $abilities[] = (string) ($ability['name'] ?? '');
        }

        $tests = [];
        if ($kit['origin'] === 'pro') {
            $tests[] = "tests/kits/{$slug}.php";
        } else {
            $declared = is_array($kit['manifest']['tests'] ?? null) ? $kit['manifest']['tests'] : [];
            $tests = $declared !== [] ? array_map('strval', $declared) : ['tests/Unit/Kits/' . studly($slug)];
        }
        foreach ($tests as $test) {
            $path = $kit['root'] . '/' . trim($test, '/');
            foreach (is_dir($path) ? files_under($path) : (is_file($path) ? [$path] : []) as $file) {
                $target = relative($kit['root'], $file);
                $emitted[$target] = $transform($target, (string) file_get_contents($file), true, false);
            }
        }
        $kit_versions[$slug] = [
            'source' => $kit['origin'],
            'tier' => (string) ($kit['manifest']['tier'] ?? ''),
            'version' => (string) ($kit['manifest']['version'] ?? ''),
        ];
    }
    $abilities = array_values(array_unique($abilities));
    sort($abilities, SORT_STRING);

    $emitted['load.php'] = load_php($options['namespace'], $options['prefix']);
    ksort($emitted, SORT_STRING);

    // Stage, validate, and only then replace the previous export.
    $staged = str_replace('\\', '/', sys_get_temp_dir()) . '/kit-export-' . bin2hex(random_bytes(6));
    try {
        foreach ($emitted as $path => $contents) {
            write_file($staged . '/' . $path, $contents);
        }
        $problems = validate($emitted, $rewriter, $abilities, array_values($categories) + ['host' => $options['prefix'] . '-changes'], $staged);
        if ($problems !== []) {
            throw new ExportError("the exported tree is not safe to ship:\n  - " . implode("\n  - ", $problems));
        }

        $versions = string_constants(tokens((string) file_get_contents($runtime . '/runtime.php')));
        $sources = ['free' => git_state($free, ['includes/kits', 'tests/Unit/Kits'])];
        if (in_array('pro', array_column($kits, 'origin'), true)) {
            $sources['pro'] = git_state($pro, ['includes/kits', 'tests/kits']);
        }
        $hashes = [];
        foreach ($emitted as $path => $contents) {
            $hashes[$path] = hash('sha256', $contents);
        }
        $record = sort_keys([
            'abilities' => $abilities,
            'files' => $hashes,
            'kits' => $kit_versions,
            'prefix' => $options['prefix'],
            'rewrite' => $rewriter->map(),
            'runtime_api_version' => $versions['API_VERSION'] ?? 'unknown',
            'sources' => $sources,
        ]);
        $export_json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        if (is_dir($out)) {
            $existing = array_values(array_diff((array) scandir($out), ['.', '..', 'config.php']));
            if ($existing !== [] && !is_file($out . '/EXPORT.json')) {
                throw new ExportError("--out {$out} holds files that are not a previous export; refusing to replace them");
            }
            foreach (['runtime', 'kits', 'tests', 'load.php', 'EXPORT.json'] as $previous) {
                remove_tree($out . '/' . $previous);
            }
        }
        foreach ($emitted as $path => $contents) {
            write_file($out . '/' . $path, $contents);
        }
        write_file($out . '/EXPORT.json', $export_json);
        $config = 'kept';
        if (!is_file($out . '/config.php')) {
            write_file($out . '/config.php', config_php($options['domain']));
            $config = 'written';
        }
    } finally {
        remove_tree($staged);
    }

    printf(
        "Exported %s as %s/ (%s) into %s: %d files, %d abilities; config.php %s.\n",
        implode(', ', array_keys($kits)),
        $options['prefix'],
        $options['namespace'],
        $out,
        count($emitted) + 1,
        count($abilities),
        $config,
    );
    return 0;
}

try {
    exit(main($argv));
} catch (ExportError $error) {
    fwrite(STDERR, 'export-kit: ' . $error->getMessage() . "\n");
    exit(1);
}
