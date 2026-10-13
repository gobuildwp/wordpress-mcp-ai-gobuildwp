<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Prove an exported copy of the kits can run on the same site as GoBuildWP without either one
 * touching the other.
 *
 *   php scripts/test-kit-coexistence.php [--pro-src=../wppilot-pro]
 *
 * The failure this exists for is quiet. Two plugins carrying the same kit under names that did
 * not get rewritten do not fatal — unless a symbol clashes — they share: one option holds both
 * ledgers, one cron hook runs both job queues, the second registration of an ability or category
 * is refused with a notice, and every one of those looks like the other plugin's bug.
 *
 * It exports every Free kit (and Pro's, with --pro-src) as prefix `kitprobe`, namespace
 * `KitProbe\Kits`, into a temp directory. Then, in one child PHP process on recording WordPress
 * doubles (scripts/lib/coexistence-doubles.php, over tests/doubles/wordpress.php), it boots
 * GoBuildWP's side exactly as the plugin does — includes/kits/loader.php on its real GoBuildWP host,
 * with the few wppilot_* functions that host calls stubbed — and requires the export's load.php
 * beside it, fires plugins_loaded and the Abilities API init actions, runs every read-only
 * ability each copy registered, writes a ledger row and a background job through each copy's
 * runtime, and ticks each copy's cron hook.
 *
 * It fails when the process fatals (a redeclared symbol), when WordPress would have refused or
 * complained about a registration, when ticking one copy's cron runs the other copy's job, or
 * when both copies name the same ability, ability category, option, transient, cron event, REST
 * route, post type, script/style handle, nonce action or admin page — recorded at run time and
 * read statically from each tree's source. Hooks are compared too, but only the ones a kit owns:
 * one it fires itself or one named after a kit. Both copies listening to plugins_loaded is the
 * point, not a collision.
 *
 * --installed-pro=<unpacked Pro release> checks the other coexistence: this Free tree beside a Pro
 * release that is already on people's sites and still carries its own copies of abilities Free
 * has since taken over (Pro 1.10.0 beside Free 1.16.0). GoBuildWP's side then loads that release's
 * kits the way its specialization engine does - categories and abilities at priority 10 of the
 * Abilities API init actions, ahead of Free's kit loader at 20, and the routines kit's early boot
 * on plugins_loaded 25 - in place of --pro-src, which still decides what the export carries. On
 * top of every check above it fails unless each ability name both trees carry was registered
 * once, by the release, with Free's copy standing aside.
 *
 * Development-only: package.sh excludes scripts/.
 */

namespace WPPilot\Scripts\Kits\Coexistence;

use function WPPilot\Scripts\Kits\argument;
use function WPPilot\Scripts\Kits\call_args;
use function WPPilot\Scripts\Kits\called_function;
use function WPPilot\Scripts\Kits\files_under;
use function WPPilot\Scripts\Kits\kit_dirs;
use function WPPilot\Scripts\Kits\next_significant;
use function WPPilot\Scripts\Kits\static_string;
use function WPPilot\Scripts\Kits\string_constants;
use function WPPilot\Scripts\Kits\tokens;

require_once __DIR__ . '/lib/kit-tools.php';

const PREFIX = 'kitprobe';

/**
 * Names WordPress itself owns. Both copies reading or taking core's cron lock is the point, not a
 * collision: it is how each one stays out of the way of wp-cron.
 */
const CORE_NAMES = ['transient' => ['doing_cron' => true]];
const NS = 'KitProbe\\Kits';

/** Call => [kind, argument position, argument name]. */
const NAMING_CALLS = [
    'get_option' => ['option', 0, 'option'], 'add_option' => ['option', 0, 'option'],
    'update_option' => ['option', 0, 'option'], 'delete_option' => ['option', 0, 'option'],
    'get_site_option' => ['option', 0, 'option'], 'update_site_option' => ['option', 0, 'option'],
    'get_transient' => ['transient', 0, 'transient'], 'set_transient' => ['transient', 0, 'transient'],
    'delete_transient' => ['transient', 0, 'transient'], 'get_site_transient' => ['transient', 0, 'transient'],
    'set_site_transient' => ['transient', 0, 'transient'],
    'add_action' => ['hook', 0, 'hook_name'], 'add_filter' => ['hook', 0, 'hook_name'],
    'do_action' => ['fired', 0, 'hook_name'], 'apply_filters' => ['fired', 0, 'hook_name'],
    'wp_next_scheduled' => ['cron', 0, 'hook'], 'wp_clear_scheduled_hook' => ['cron', 0, 'hook'],
    'wp_schedule_single_event' => ['cron', 1, 'hook'], 'wp_schedule_event' => ['cron', 2, 'hook'],
    'register_rest_route' => ['rest_namespace', 0, 'route_namespace'],
    'register_post_type' => ['post_type', 0, 'post_type'],
    'wp_register_script' => ['handle', 0, 'handle'], 'wp_enqueue_script' => ['handle', 0, 'handle'],
    'wp_register_style' => ['handle', 0, 'handle'], 'wp_enqueue_style' => ['handle', 0, 'handle'],
    'wp_create_nonce' => ['nonce', 0, 'action'], 'wp_verify_nonce' => ['nonce', 1, 'action'],
    'check_ajax_referer' => ['nonce', 0, 'action'], 'check_admin_referer' => ['nonce', 0, 'action'],
    'add_menu_page' => ['admin_page', 3, 'menu_slug'], 'add_submenu_page' => ['admin_page', 4, 'menu_slug'],
    'wp_register_ability' => ['ability', 0, 'name'], 'wp_register_ability_category' => ['category', 0, 'slug'],
];

/**
 * WordPress's own options. Every plugin reads them (which page is the front page, the site name),
 * so two copies of a kit both naming one is the site being read, not a clash between the copies.
 */
const CORE_OPTIONS = ['show_on_front', 'page_on_front', 'page_for_posts', 'blogname', 'blogdescription', 'home', 'siteurl', 'permalink_structure', 'blog_public', 'gmt_offset'];

/**
 * Storage kinds a kit.json `vendor_storage` may declare, and the kind this gate files each under.
 * Meta keys are compared as options: the static read files a constant named *META* or *KEY*
 * under `option`, whichever table the key lives in.
 */
const VENDOR_STORAGE_KINDS = ['option' => 'option', 'meta' => 'option', 'transient' => 'transient', 'cron' => 'cron'];

/**
 * Vendor-owned names Pro's kits use but do not declare in kit.json yet. Transitional: every run
 * lists the entries no kit.json declares, and the list goes once Pro's kits declare them all.
 */
const UNDECLARED_VENDOR_NAMES = [
    'option' => ['backwpup_cfg_logfolder', '_wds_focus-keywords'],
    'cron' => ['backwpup_cron', 'updraft_backup', 'updraft_backup_database'],
];

/**
 * Names that belong to another plugin (Elementor's page data, Slim SEO's per-post array, a backup
 * plugin's cron event), declared by the kits that read or write them in kit.json:
 *
 *   "vendor_storage": {"option": [...], "meta": [...], "transient": [...], "cron": [...]}
 *
 * Both copies naming one of these are both talking to that plugin, not clashing with each other,
 * and renaming them in an export would break the kit. Keyed by this gate's kind.
 *
 * @param list<string> $kit_jsons
 * @return array<string, array<string, true>>
 */
function vendor_names(array $kit_jsons): array
{
    $names = [];
    foreach ($kit_jsons as $file) {
        $manifest = json_decode((string) file_get_contents($file), true);
        $declared = is_array($manifest['vendor_storage'] ?? null) ? $manifest['vendor_storage'] : [];
        foreach (VENDOR_STORAGE_KINDS as $key => $kind) {
            foreach (is_array($declared[$key] ?? null) ? $declared[$key] : [] as $name) {
                if (is_string($name) && $name !== '') {
                    $names[$kind][$name] = true;
                }
            }
        }
    }
    return $names;
}

/**
 * Names each kind of shared resource a tree's PHP source spells out, statically.
 *
 * @param list<string> $files
 * @return array<string, array<string, true>>
 */
function static_names(array $files): array
{
    $names = [];
    foreach ($files as $file) {
        if (basename($file) === 'kit.json') {
            // A category a kit declares is registered only when the host has not already, so at
            // run time the second copy just skips it — and GoBuildWP's own unconditional
            // registration of the same slug is what then fails. Compare the declarations.
            $manifest = json_decode((string) file_get_contents($file), true);
            foreach (array_keys(is_array($manifest['categories'] ?? null) ? $manifest['categories'] : []) as $slug) {
                $names['category'][(string) $slug] = true;
            }
            continue;
        }
        if (!str_ends_with($file, '.php')) {
            continue;
        }
        $tokens = tokens((string) file_get_contents($file));
        $constants = string_constants($tokens);
        foreach ($constants as $name => $value) {
            // Storage keys the runtime completes at run time (OPTION_PREFIX . $id) are compared
            // as prefixes below.
            // WordPress's own keys (_wp_attachment_image_alt, _wp_attached_file) are shared by
            // every plugin that edits media; both copies writing them is the point, not a clash.
            if (str_starts_with($value, '_wp_')) {
                continue;
            }
            if (preg_match('/OPTION|TRANSIENT|META|KEY/', $name) === 1) {
                $names['option'][$value] = true;
            } elseif (preg_match('/HOOK|EVENT/', $name) === 1) {
                $names['cron'][$value] = true;
            }
        }
        foreach ($tokens as $i => $token) {
            $called = called_function($tokens, $i);
            if ($called === null || !isset(NAMING_CALLS[$called])) {
                continue;
            }
            [$kind, $position, $parameter] = NAMING_CALLS[$called];
            $value = static_string($tokens, argument($tokens, call_args($tokens, next_significant($tokens, $i)), $position, $parameter), $constants);
            if ($value !== null) {
                $names[$kind][$value] = true;
            }
        }
    }
    return $names;
}

/**
 * Load an installed Pro release's kits the way its specialization engine does.
 *
 * Pro loads a kit inside the Abilities API init actions at priority 10 (Kits\load(): bootstrap,
 * boot, then the ability files required directly), and registers the kit's first category there
 * when nobody has. Its routines kit is also booted early, from plugins_loaded 25, by
 * WPPilot\Pro\Kits\register_routines(), which is how Free's scheduled-audits kit knows to stand
 * aside; that function is defined here under the same name so Free sees what a licensed site
 * shows it. The rest of Pro (licence, its non-kit integrations) is not loaded; a live install
 * covers those.
 */
function boot_installed_pro(string $installed): void
{
    $kits = [];
    foreach (glob($installed . '/includes/kits/*/kit.json') ?: [] as $kit_json) {
        $manifest = json_decode((string) file_get_contents($kit_json), true);
        if (is_array($manifest)) {
            $manifest['dir'] = dirname($kit_json);
            $kits[(string) $manifest['slug']] = $manifest;
        }
    }
    $boot = static function (array $manifest, bool $abilities): void {
        /** @var array<string, array<string, mixed>> $booted */
        static $booted = [];
        $slug = (string) $manifest['slug'];
        $reason = \WPPilot\Kits\Runtime\incompatibility($manifest);
        if ($reason !== '') {
            \WPPilot\Kits\Runtime\registry(['loaded' => [], 'skipped' => [$slug => $reason]]);
            return;
        }
        if (!isset($booted[$slug])) {
            /** @var mixed $descriptor */
            $descriptor = require $manifest['dir'] . '/bootstrap.php';
            $booted[$slug] = is_array($descriptor) ? $descriptor : [];
            if (is_callable($booted[$slug]['boot'] ?? null)) {
                ($booted[$slug]['boot'])(\WPPilot\Kits\Runtime\host());
            }
        }
        if ($abilities) {
            foreach ((array) ($booted[$slug]['ability_files'] ?? []) as $file) {
                require_once (string) $file;
            }
            \WPPilot\Kits\Runtime\pending_kits($manifest, []);
            \WPPilot\Kits\Runtime\registry(['loaded' => [$slug], 'skipped' => []]);
        }
    };
    $GLOBALS['kit_coexistence_installed'] = ['kits' => $kits, 'boot' => $boot];
    if (isset($kits['routines']) && !function_exists('WPPilot\Pro\Kits\register_routines')) {
        eval('namespace WPPilot\Pro\Kits; function register_routines(): void { $i = $GLOBALS["kit_coexistence_installed"]; ($i["boot"])($i["kits"]["routines"], false); }');
        add_action('plugins_loaded', 'WPPilot\Pro\Kits\register_routines', 25);
    }
    add_action('wp_abilities_api_categories_init', static function () use ($kits): void {
        foreach ($kits as $manifest) {
            foreach ((array) ($manifest['categories'] ?? []) as $slug => $category) {
                if (!wp_has_ability_category((string) $slug)) {
                    wp_register_ability_category((string) $slug, [
                        'label' => (string) ($category['label'] ?? $slug),
                        'description' => (string) ($category['description'] ?? ''),
                    ]);
                }
                break;
            }
        }
    }, 10);
    add_action('wp_abilities_api_init', static function () use ($kits, $boot): void {
        foreach ($kits as $manifest) {
            $boot($manifest, true);
        }
    }, 10);
}

/**
 * Whether the installed release's kits declare every ability a Free kit declares.
 */
function installed_pro_covers(string $kit_dir, string $installed): bool
{
    $manifest = json_decode((string) @file_get_contents($kit_dir . '/kit.json'), true);
    $ours = array_map(static fn(array $a): string => (string) ($a['name'] ?? ''), (array) ($manifest['abilities'] ?? []));
    if ($ours === []) {
        return false;
    }
    $theirs = [];
    foreach (glob($installed . '/includes/kits/*/kit.json') ?: [] as $kit_json) {
        $other = json_decode((string) file_get_contents($kit_json), true);
        foreach ((array) ($other['abilities'] ?? []) as $ability) {
            $theirs[(string) ($ability['name'] ?? '')] = true;
        }
    }
    return array_diff($ours, array_keys($theirs)) === [];
}

/**
 * Every ability name both Free's kits and the installed release's kits carry must have been
 * registered once, by the release.
 *
 * @return list<string>
 */
function installed_pro_problems(string $free, string $installed): array
{
    $declared = static function (string $root): array {
        $names = [];
        foreach (glob($root . '/includes/kits/*/kit.json') ?: [] as $kit_json) {
            $manifest = json_decode((string) file_get_contents($kit_json), true);
            foreach ((array) ($manifest['abilities'] ?? []) as $ability) {
                $names[(string) ($ability['name'] ?? '')] = true;
            }
        }
        return $names;
    };
    $shared = array_keys(array_intersect_key($declared($free), $declared($installed)));
    sort($shared);
    $problems = [];
    foreach ($shared as $name) {
        $owners = array_keys(\Kit_Coexistence::$names['ability'][$name] ?? []);
        if ($owners !== ['wppilot-pro-installed']) {
            $problems[] = sprintf('%s was registered by %s; expected the installed Pro release alone', $name, $owners === [] ? 'nobody' : implode(' and ', $owners));
        }
    }
    echo 'Installed Pro registered, and Free stood aside for: ', $shared === [] ? '(no shared names)' : implode(', ', $shared), "\n";
    return $problems;
}

function run_export(string $free, string $pro, string $out): void
{
    $kits = array_keys(kit_dirs($free . '/includes/kits'));
    if ($pro !== '') {
        $kits = array_merge($kits, array_keys(kit_dirs($pro . '/includes/kits')));
    }
    if ($kits === []) {
        throw new \RuntimeException('there are no kits to export');
    }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/export-kit.php')
        . ' --kits=' . escapeshellarg(implode(',', $kits))
        . ($pro !== '' ? ' --pro-src=' . escapeshellarg($pro) : '')
        . ' --namespace=' . escapeshellarg(NS) . ' --prefix=' . PREFIX . ' --text-domain=' . PREFIX
        . ' --out=' . escapeshellarg($out) . ' 2>&1';
    exec($command, $output, $code);
    if ($code !== 0) {
        throw new \RuntimeException("export-kit.php failed:\n" . implode("\n", $output));
    }
}

function remove_tree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (is_dir($path) ? (array) scandir($path) : [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            remove_tree($path . '/' . $entry);
        }
    }
    if (is_dir($path)) {
        rmdir($path);
    }
}

/**
 * Whether an ability's meta asks for a safety profile above Production Safe.
 *
 * @param array<string, mixed> $meta
 */
function min_profile_above_production(array $meta): bool
{
    $safety = is_array($meta['safety'] ?? null) ? $meta['safety'] : [];
    return ($safety['min_profile'] ?? '') === 'developer';
}

/**
 * The child process: both copies, one PHP runtime.
 *
 * @return list<string> Problems.
 */
function load_both(string $free, string $pro, string $export, string $installed = ''): array
{
    define('ABSPATH', $free . '/');
    define('WPPILOT_CHANGE_BULK_SNAPSHOT_BUDGET_BYTES', 1_048_576);
    require_once __DIR__ . '/lib/coexistence-doubles.php';
    require_once $free . '/tests/doubles/wordpress.php';
    // A network: a kit that skips itself on a single site (multisite) must load here, or its
    // names are never compared.
    \WPPilot_Test_State::$multisite = true;

    // What WPPilotHost calls; the rest of GoBuildWP is not loaded here.
    $stubs = [
        'wppilot_permission_callback' => static fn(): bool => true,
        'wppilot_get_safety_profile' => static fn(): string => 'production',
        'wppilot_register_rollback_strategy' => static fn(): bool => true,
        'wppilot_ledger_record_items' => static fn(string $ability, array $items, ?string $group = null): array => ['group' => (string) $group, 'change_ids' => [], 'without_before_image' => 0],
        'wppilot_query_change_log' => static fn(array $filters = [], int $limit = 0, int $offset = 0): array => [],
        'wppilot_count_change_log' => static fn(array $filters = []): int => 0,
        'wppilot_change_export_row' => static fn(array $entry): array => $entry,
        // Production Safe, as wppilot_get_safety_profile() above: a Developer-only ability is refused.
        'wppilot_safety_check_ability' => static fn(\WP_Ability $ability): bool|\WP_Error => min_profile_above_production($ability->get_meta())
            ? new \WP_Error('wppilot_safety_profile_blocked', 'blocked by the stubbed Production Safe profile')
            : true,
    ];
    $GLOBALS['kit_coexistence_stubs'] = $stubs;
    foreach (array_keys($stubs) as $function) {
        if (!function_exists($function)) {
            eval("function {$function}(...\$args) { return (\$GLOBALS['kit_coexistence_stubs']['{$function}'])(...\$args); }");
        }
    }
    foreach (['esc_html__', 'esc_attr__', '_x', 'esc_html_x', 'esc_attr_x'] as $function) {
        if (!function_exists($function)) {
            eval("function {$function}(string \$text, ...\$rest): string { return \$text; }");
        }
    }

    // A kit that needs a vendor plugin (woo-reports: WooCommerce) would be skipped here, and a
    // skipped kit proves nothing about clashes. Its required classes and functions get empty
    // stand-ins so it loads and registers; its abilities are not executed below, because the
    // stand-ins have nothing behind them.
    $vendor_bound = [];
    foreach (array_merge(glob($free . '/includes/kits/*/kit.json') ?: [], $pro !== '' ? (glob($pro . '/includes/kits/*/kit.json') ?: []) : [], $installed !== '' ? (glob($installed . '/includes/kits/*/kit.json') ?: []) : []) as $kit_json) {
        $kit = json_decode((string) file_get_contents($kit_json), true);
        $requires = is_array($kit['requires'] ?? null) ? $kit['requires'] : [];
        $any = is_array($requires['any'] ?? null) ? $requires['any'] : [];
        $classes = array_values(array_filter(array_merge((array) ($requires['classes'] ?? []), (array) ($any['classes'] ?? [])), 'is_string'));
        $functions = array_values(array_filter(array_merge((array) ($requires['functions'] ?? []), (array) ($any['functions'] ?? [])), 'is_string'));
        $constants = array_values(array_filter((array) ($any['constants'] ?? []), 'is_string'));
        if ($classes === [] && $functions === [] && $constants === []) {
            continue;
        }
        // A kit that serves one of several plugins (requires.any) gets every one of them, so all
        // of its vendor files load and register here. A version constant reads as a high version,
        // so no vendor floor turns the kit away.
        foreach ($constants as $constant) {
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $constant) === 1 && !defined($constant)) {
                define($constant, '99.0.0');
            }
        }
        foreach ($classes as $class) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class) === 1 && !class_exists($class)) {
                $at = strrpos($class, '\\');
                eval($at === false ? "class {$class} {}" : 'namespace ' . substr($class, 0, $at) . '; class ' . substr($class, $at + 1) . ' {}');
            }
        }
        foreach ($functions as $function) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $function) === 1 && !function_exists($function)) {
                eval("function {$function}(...\$args) { return null; }");
            }
        }
        foreach ((array) ($kit['abilities'] ?? []) as $ability) {
            $name = (string) ($ability['name'] ?? '');
            $vendor_bound[$name] = true;
            $vendor_bound[PREFIX . substr($name, strlen('wppilot'))] = true;
        }
    }

    \Kit_Coexistence::$owners = ['wppilot' => $free . '/includes/kits/', 'export' => $export . '/'];
    if ($pro !== '') {
        \Kit_Coexistence::$owners['wppilot-pro'] = $pro . '/includes/kits/';
    }

    // GoBuildWP's side, as wppilot.php boots it; then the export's, as the other plugin does.
    require_once $free . '/includes/kits/loader.php';
    if ($installed !== '') {
        \Kit_Coexistence::$owners['wppilot-pro-installed'] = $installed . '/includes/kits/';
        boot_installed_pro($installed);
        do_action('plugins_loaded');
    } elseif ($pro !== '') {
        // As Pro's specialization engine loads them: inside the Abilities API init actions at
        // priority 10, ahead of Free's kit loader at 20, so a richer Pro copy of a name wins.
        boot_installed_pro($pro);
        do_action('plugins_loaded');
    }
    require_once $export . '/load.php';
    if ($pro === '') {
        do_action('plugins_loaded');
    }
    do_action('wp_abilities_api_categories_init');
    do_action('wp_abilities_api_init');
    do_action('init');
    do_action('rest_api_init');
    do_action('admin_menu');

    $problems = [];
    $wppilot_host = \WPPilot\Kits\Runtime\host();
    $export_host = ('\\' . NS . '\\Runtime\\host')();
    if ($wppilot_host->id() !== 'wppilot' || $export_host->id() !== PREFIX) {
        $problems[] = sprintf('hosts are %s and %s; expected wppilot and %s', $wppilot_host->id(), $export_host->id(), PREFIX);
    }
    $wppilot_report = \WPPilot\Kits\Runtime\registry();
    $export_report = ('\\' . NS . '\\Runtime\\registry')();
    foreach (['GoBuildWP' => $wppilot_report, 'the export' => $export_report] as $side => $report) {
        foreach ($report['skipped'] as $slug => $reason) {
            // Beside an installed release, a Free kit whose every ability that release carries
            // itself (scheduled-audits beside Pro 1.10.0's routines) is meant to stay off; the
            // stand-aside check below proves the release registered them instead.
            if ($side === 'GoBuildWP' && $installed !== '' && installed_pro_covers($free . '/includes/kits/' . $slug, $installed)) {
                echo "GoBuildWP stood aside with kit {$slug}: {$reason}\n";
                continue;
            }
            $problems[] = "{$side} skipped kit {$slug}: {$reason}";
        }
    }
    if ($installed === '' && count($export_report['loaded']) !== count($wppilot_report['loaded'])) {
        $problems[] = sprintf('GoBuildWP loaded %d kits, the export %d', count($wppilot_report['loaded']), count($export_report['loaded']));
    }

    // Run what is safe to run: every read-only ability, as an agent would.
    foreach (\Kit_Coexistence::$abilities as $name => $args) {
        $annotations = $args['meta']['annotations'] ?? [];
        if (($annotations['readonly'] ?? false) !== true || isset($vendor_bound[$name])) {
            continue;
        }
        if (min_profile_above_production(is_array($args['meta'] ?? null) ? $args['meta'] : [])) {
            // Both hosts run Production Safe here, so this ability must be refused by its own
            // permission callback: before WordPress 7.1 that callback is the only enforcement.
            if (($args['permission_callback'])([]) === true) {
                $problems[] = "{$name}: declares a min_profile above Production Safe, but its permission callback allowed it there";
            }
            continue;
        }
        if (($args['permission_callback'])([]) !== true) {
            $problems[] = "{$name}: permission refused for an administrator";
            continue;
        }
        $result = ($args['execute_callback'])([]);
        // A read that needs input (a search string, a job id, an attachment) refuses an empty call
        // with a 4xx WP_Error; that still proves it loaded and ran on its own runtime. Anything
        // else (a 5xx, a non-array) is a real failure.
        $data = $result instanceof \WP_Error ? $result->get_error_data() : null;
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        $refused_input = $result instanceof \WP_Error && ($status === 0 || ($status >= 400 && $status < 500));
        if (!is_array($result) && !$refused_input) {
            $problems[] = "{$name}: execute returned " . get_debug_type($result);
        }
    }

    // Each runtime's own storage: a ledger row and a background job.
    $wppilot_ledger = new \WPPilot\Kits\Runtime\MiniLedger();
    $export_ledger_class = '\\' . NS . '\\Runtime\\MiniLedger';
    $export_ledger = new $export_ledger_class();
    $wppilot_ledger->record_items('wppilot/coexistence-probe', [['input' => ['side' => 'wppilot']]]);
    $export_ledger->record_items(PREFIX . '/coexistence-probe', [['input' => ['side' => 'export']]]);
    if (count($wppilot_ledger->all()) !== 1 || count($export_ledger->all()) !== 1) {
        $problems[] = sprintf('ledgers share storage: GoBuildWP sees %d rows, the export %d', count($wppilot_ledger->all()), count($export_ledger->all()));
    }

    $step = static fn(array $payload, array $state): array => ['state' => [], 'done' => true];
    $wppilot_host->jobs()->register('coexistence-probe', $step);
    $export_host->jobs()->register('coexistence-probe', $step);
    $wppilot_job = $wppilot_host->jobs()->enqueue('coexistence-probe', []);
    $export_job = $export_host->jobs()->enqueue('coexistence-probe', []);
    $export_runner = '\\' . NS . '\\Runtime\\Runner';
    if (\WPPilot\Kits\Runtime\Runner::CRON_HOOK === $export_runner::CRON_HOOK) {
        $problems[] = 'both runners tick on ' . $export_runner::CRON_HOOK;
    }
    do_action(\WPPilot\Kits\Runtime\Runner::CRON_HOOK);
    $wppilot_status = is_string($wppilot_job) ? ($wppilot_host->jobs()->get($wppilot_job)['status'] ?? 'missing') : 'not queued';
    $export_status = is_string($export_job) ? ($export_host->jobs()->get($export_job)['status'] ?? 'missing') : 'not queued';
    if ($wppilot_status !== 'done' || $export_status !== 'queued') {
        $problems[] = "ticking GoBuildWP's job runner left GoBuildWP's job {$wppilot_status} and the export's {$export_status}; expected done and queued";
    }
    do_action($export_runner::CRON_HOOK);
    $export_status = is_string($export_job) ? ($export_host->jobs()->get($export_job)['status'] ?? 'missing') : 'not queued';
    if ($export_status !== 'done') {
        $problems[] = "ticking the export's job runner left its job {$export_status}";
    }

    foreach (array_unique(\Kit_Coexistence::$notices) as $notice) {
        $problems[] = 'WordPress would complain: ' . $notice;
    }

    // Overlaps: what each copy named at run time, plus what its source names statically.
    $wppilot_files = files_under($free . '/includes/kits');
    $wppilot_pro = $installed !== '' ? $installed : $pro;
    if ($wppilot_pro !== '') {
        $wppilot_files = array_merge($wppilot_files, files_under($wppilot_pro . '/includes/kits'));
    }
    $export_files = array_merge(files_under($export . '/runtime'), files_under($export . '/kits'), [$export . '/load.php']);
    $sides = ['wppilot' => static_names($wppilot_files), 'export' => static_names($export_files)];
    foreach (\Kit_Coexistence::$names as $kind => $names) {
        foreach ($names as $name => $owners) {
            foreach (array_keys($owners) as $owner) {
                $side = in_array($owner, ['wppilot-pro', 'wppilot-pro-installed'], true) ? 'wppilot' : $owner;
                if (isset($sides[$side])) {
                    $sides[$side][$kind][(string) $name] = true;
                }
            }
        }
    }

    $vendor = vendor_names(array_merge(
        glob($free . '/includes/kits/*/kit.json') ?: [],
        $pro !== '' ? (glob($pro . '/includes/kits/*/kit.json') ?: []) : [],
        $installed !== '' ? (glob($installed . '/includes/kits/*/kit.json') ?: []) : [],
    ));
    $undeclared = [];
    foreach ($pro !== '' || $installed !== '' ? UNDECLARED_VENDOR_NAMES : [] as $kind => $list) {
        foreach ($list as $name) {
            if (!isset($vendor[$kind][$name])) {
                $vendor[$kind][$name] = true;
                $undeclared[] = "{$kind} {$name}";
            }
        }
    }

    $fired = ($sides['wppilot']['fired'] ?? []) + ($sides['export']['fired'] ?? []);
    foreach (['ability', 'category', 'option', 'transient', 'cron', 'rest_namespace', 'rest_route', 'post_type', 'handle', 'nonce', 'admin_page', 'hook'] as $kind) {
        $ours = $sides['wppilot'][$kind] ?? [];
        $theirs = $sides['export'][$kind] ?? [];
        foreach (array_keys($ours) as $name) {
            $name = (string) $name;
            $clash = isset($theirs[$name]) && !isset(CORE_NAMES[$kind][$name]) && !isset($vendor[$kind][$name]);
            if (!$clash && $kind === 'option' && str_ends_with($name, '_')) {
                foreach (array_keys($theirs) as $other) {
                    $clash = $clash || str_starts_with((string) $other, $name);
                }
            }
            if (!$clash || ($kind === 'option' && in_array($name, CORE_OPTIONS, true))) {
                continue;
            }
            if ($kind === 'hook' && !isset($fired[$name]) && preg_match('/wppilot|' . PREFIX . '|kit/i', $name) !== 1) {
                continue;
            }
            $problems[] = "both copies use the {$kind} {$name}";
        }
    }

    if ($installed !== '') {
        $problems = array_merge($problems, installed_pro_problems($free, $installed));
    }

    $counts = [];
    foreach (['ability', 'option', 'cron', 'hook'] as $kind) {
        $counts[] = sprintf('%s %d/%d', $kind, count($sides['wppilot'][$kind] ?? []), count($sides['export'][$kind] ?? []));
    }
    echo 'Compared (GoBuildWP/export): ', implode(', ', $counts), "\n";
    if ($undeclared !== []) {
        echo 'Vendor names no kit.json declares yet, allowed by the transitional list (declare them in vendor_storage): ', implode(', ', $undeclared), "\n";
    }
    echo 'Abilities registered: ', implode(', ', array_keys(\Kit_Coexistence::$abilities)), "\n";
    return $problems;
}

$options = getopt('', ['pro-src:', 'phase:', 'export:', 'installed-pro:']);
$options = is_array($options) ? $options : [];
$free = str_replace('\\', '/', dirname(__DIR__));
$pro = isset($options['pro-src']) ? rtrim(str_replace('\\', '/', (string) realpath((string) $options['pro-src'])), '/') : '';
if (isset($options['pro-src']) && ($pro === '' || !is_dir($pro . '/includes/kits'))) {
    fwrite(STDERR, "--pro-src has no includes/kits\n");
    exit(1);
}
$installed = isset($options['installed-pro']) ? rtrim(str_replace('\\', '/', (string) realpath((string) $options['installed-pro'])), '/') : '';
if (isset($options['installed-pro']) && ($installed === '' || !is_dir($installed . '/includes/kits'))) {
    fwrite(STDERR, "--installed-pro has no includes/kits\n");
    exit(1);
}

if (($options['phase'] ?? '') === 'load') {
    $problems = load_both($free, $pro, str_replace('\\', '/', (string) $options['export']), $installed);
    if ($problems !== []) {
        fwrite(STDERR, "The exported kits do not coexist with GoBuildWP's:\n\n  - " . implode("\n  - ", $problems) . "\n");
        exit(1);
    }
    echo "COEXIST-OK\n";
    exit(0);
}

$export = str_replace('\\', '/', sys_get_temp_dir()) . '/kit-coexistence-' . bin2hex(random_bytes(6));
try {
    run_export($free, $pro, $export);
    $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d error_reporting=-1 ' . escapeshellarg(__FILE__)
        . ' --phase=load --export=' . escapeshellarg($export)
        . ($pro !== '' ? ' --pro-src=' . escapeshellarg($pro) : '')
        . ($installed !== '' ? ' --installed-pro=' . escapeshellarg($installed) : '') . ' 2>&1';
    exec($command, $output, $code);
    echo implode("\n", $output), "\n";
    // A notice or warning is a failure too: a real site logs it on every request.
    $noisy = preg_grep('/^(PHP )?(Fatal error|Warning|Notice|Deprecated)/m', $output);
    if ($code !== 0 || !in_array('COEXIST-OK', $output, true) || $noisy !== []) {
        fwrite(STDERR, "Kit coexistence failed.\n");
        exit(1);
    }
    echo "GoBuildWP's kits and an exported copy coexist.\n";
} catch (\RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    remove_tree($export);
}
