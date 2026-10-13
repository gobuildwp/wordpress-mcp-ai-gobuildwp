<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Runtime;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The kit runtime: the host registry and the loader every kit goes through.
 *
 * A kit is a folder — kit.json, bootstrap.php, src/, skills/ — that carries one feature and can
 * be copied into another plugin by scripts/export-kit.php. This file and its siblings are the
 * only code a kit may lean on besides WordPress itself. They are exported with the kits, under
 * the target plugin's namespace, so two plugins each carrying a copy never share a symbol.
 *
 * API_VERSION is the contract kits are written against. A kit declares the major it needs in
 * kit.json (`"runtime": "^1.0"`); a host skips a kit whose major differs and reports why, rather
 * than loading code written against a different contract.
 *
 * 1.1 added require_profile() and the ProfileGate host interface.
 * 1.2 added unclaimed(), for kits that carry an ability another plugin may already register, and
 * kit.json `requires.any`, for kits that serve whichever of several plugins is active, and the
 * SessionLedger host interface, for kits whose restore types a session undo should check and redo.
 */
const API_VERSION = '1.2';

require_once __DIR__ . '/host.php';
require_once __DIR__ . '/ledger/post-partial.php';
require_once __DIR__ . '/ledger/MiniLedger.php';
require_once __DIR__ . '/jobs/Runner.php';
require_once __DIR__ . '/http/Page.php';

/**
 * The host the kits are running inside. Set once, by the plugin that boots the runtime.
 */
function host(?Host $set = null): Host
{
    /** @var Host|null $host */
    static $host = null;
    if ($set !== null) {
        $host = $set;
    }
    if ($host === null) {
        throw new \LogicException('No kit host has been set; boot the runtime before loading kits.');
    }
    return $host;
}

function has_host(): bool
{
    try {
        host();
        return true;
    } catch (\LogicException) {
        return false;
    }
}

/**
 * Whether this copy may register an ability under this name: false when something registered it
 * first.
 *
 * Some kits carry abilities an older release of a companion plugin still registers itself under
 * the same name — GoBuildWP 1.16.0 took the WooCommerce reads, the backup and security status
 * reads, the basic per-post SEO and form reads and the scheduled audits from Pro 1.10.0, which
 * keeps registering its own copies until its next release, and a richer Pro version of a write
 * keeps its name on purpose. Registering a name twice is refused by core with a doing_it_wrong
 * notice on every request, so the kit checks first and stands aside: the first registration
 * wins, and on a GoBuildWP site that is Pro's, which loads its integrations at
 * wp_abilities_api_init priority 10, ahead of the kit loader's 20.
 *
 * A kit that stands aside must also leave the name's ledger capture alone, since the ability that
 * runs is not its own: call Ledger::capture_for() only inside the same check.
 */
function unclaimed(string $ability_name): bool
{
    return !wp_has_ability($ability_name);
}

/**
 * Whether a kit's `runtime` constraint accepts this runtime: same major, and a minor at least
 * the one it names. `^1.0` accepts 1.0 through 1.x.
 */
function runtime_satisfies(string $constraint): bool
{
    if (preg_match('/^\^(\d+)\.(\d+)$/', trim($constraint), $want) !== 1) {
        return false;
    }
    [$major, $minor] = array_map('intval', explode('.', API_VERSION));
    return (int) $want[1] === $major && (int) $want[2] <= $minor;
}

/**
 * The shared confirmation check; see Host::confirm_guard().
 *
 * @param array<string, mixed> $input
 */
function confirm_guard(string $ability_name, array $input): bool|WP_Error
{
    return host()->confirm_guard($ability_name, $input);
}

/**
 * Permission callback every kit ability uses as its base: the host's switch and capability.
 */
function can_run(): bool
{
    $host = host();
    return $host->is_enabled() && $host->can_manage();
}

/**
 * Refuse an ability whose `meta.safety.min_profile` is above the host's safety profile.
 *
 * Every kit ability that declares min_profile calls this from its permission callback, with its
 * own literal name (scripts/check-kit-boundaries.php fails the build otherwise). The meta key on
 * its own is only a declaration: the `wp_ability_permission_result` filter that could enforce it
 * for every caller exists from WordPress 7.1, and kits run on 6.9, where a Developer-only read
 * would otherwise run under Production Safe for any caller that does not go through the host's
 * own gate (core REST, WP-CLI, another MCP adapter).
 *
 * Returns true or a WP_Error, never false; `bool` only because a `true` type needs PHP 8.2.
 */
function require_profile(string $ability_name): bool|WP_Error
{
    $host = host();
    if ($host instanceof ProfileGate) {
        return $host->profile_allows($ability_name);
    }
    return profile_check($ability_name, $host->safety_profile());
}

/**
 * The min_profile comparison itself, for a host that keeps its profile as a plain string.
 *
 * Fails closed: an ability whose policy cannot be read is refused rather than assumed harmless.
 */
function profile_check(string $ability_name, string $profile): bool|WP_Error
{
    $ability = wp_get_ability($ability_name);
    if (!$ability instanceof \WP_Ability) {
        return new WP_Error(
            'kit_ability_unknown',
            sprintf('Ability "%s" is not registered, so its safety policy cannot be checked.', $ability_name),
            ['status' => 403],
        );
    }
    $meta = $ability->get_meta();
    $safety = is_array($meta['safety'] ?? null) ? $meta['safety'] : [];
    $rank = ['readonly' => 0, 'production' => 1, 'developer' => 2];
    $needed = is_string($safety['min_profile'] ?? null) ? $safety['min_profile'] : '';
    if ($needed === '' || !isset($rank[$needed])) {
        return true;
    }
    if (($rank[$profile] ?? $rank['production']) >= $rank[$needed]) {
        return true;
    }
    return new WP_Error(
        'kit_safety_profile_blocked',
        sprintf('Ability "%1$s" needs the %2$s safety profile; this site runs %3$s.', $ability_name, $needed, $profile),
        ['status' => 403, 'ability' => $ability_name, 'min_profile' => $needed, 'profile' => $profile],
    );
}

/**
 * Run another ability from inside a kit ability, through the same controls a direct call meets.
 *
 * Calling `$ability->execute()` straight away would skip everything the host enforces before
 * execute(): inside GoBuildWP the safety profile, the confirmation contract, the rate limit and the
 * design and preview gates; standalone the confirm flag. A kit that runs abilities on an agent's
 * behalf (a network kit running one on another site) would then be a way around all of them.
 *
 * The richer host offers its runner as the `ability-runner` extension point: a callable taking
 * the ability and its input and returning what execute() returns, or the refusal. Standalone
 * there is none, so the confirm guard runs here. Either way execute() still runs the ability's
 * own permission callback and the ledger's before/after hooks.
 *
 * @param mixed $input The inner ability's input, including its own `confirm` when it needs one.
 * @return mixed The ability's result, or a WP_Error explaining the refusal.
 */
function run_ability(\WP_Ability $ability, mixed $input): mixed
{
    /** @var mixed $runner */
    $runner = host()->extension('ability-runner');
    if (is_callable($runner)) {
        return $runner($ability, $input);
    }

    $values = is_array($input) ? $input : [];
    $confirmed = host()->confirm_guard($ability->get_name(), $values);
    if ($confirmed instanceof WP_Error) {
        return $confirmed;
    }
    if (is_array($input) && array_key_exists('confirm', $input)) {
        $properties = $ability->get_input_schema()['properties'] ?? [];
        // `confirm` is a transport control; an ability whose schema does not declare it would
        // reject the whole call as having an unknown property.
        if (!is_array($properties) || !array_key_exists('confirm', $properties)) {
            unset($input['confirm']);
        }
    }
    return $ability->execute(empty_input_for($ability, $input));
}

/**
 * The input to hand execute() when a caller passed nothing.
 *
 * An ability with no input schema rejects any input, `[]` included, so it must be given null;
 * one with an object schema rejects null. Passing one answer for both breaks half of them.
 */
function empty_input_for(\WP_Ability $ability, mixed $input): mixed
{
    if ($input !== [] && $input !== null) {
        return $input;
    }
    return $ability->get_input_schema() === [] ? null : [];
}

/**
 * Read kit.json for every kit folder under a directory.
 *
 * Folders whose names start with `_` are the runtime's own and are skipped.
 *
 * @return list<array<string, mixed>>
 */
function discover(string $kits_dir): array
{
    $found = [];
    $dirs = glob(rtrim($kits_dir, '/\\') . '/*', GLOB_ONLYDIR);
    foreach (is_array($dirs) ? $dirs : [] as $dir) {
        if (str_starts_with(basename($dir), '_') || !is_file($dir . '/kit.json')) {
            continue;
        }
        $manifest = json_decode((string) file_get_contents($dir . '/kit.json'), associative: true);
        if (!is_array($manifest) || !is_string($manifest['slug'] ?? null)) {
            // Reported rather than dropped: a kit that vanishes without a word reads as a
            // registration bug three layers away from the typo that caused it.
            $found[] = ['slug' => basename($dir), 'dir' => $dir, 'invalid' => 'kit.json is not valid JSON with a slug'];
            continue;
        }
        $manifest['dir'] = $dir;
        $found[] = $manifest;
    }
    // Pro-tier kits first, then by slug. A Pro kit can carry a richer copy of an ability a Free kit
    // also carries (the SEO plugins' per-post edits), and the Free copy stands aside through
    // unclaimed() for whichever registered first. Inside GoBuildWP, Pro registers ahead of the kit
    // loader anyway; an export that carries both tiers gets the same winner this way.
    usort($found, static fn(array $a, array $b): int => [($a['tier'] ?? '') === 'pro' ? 0 : 1, (string) $a['slug']]
        <=> [($b['tier'] ?? '') === 'pro' ? 0 : 1, (string) $b['slug']]);
    return $found;
}

/**
 * Why a kit cannot load here, or '' when it can.
 *
 * @param array<string, mixed> $manifest
 */
function incompatibility(array $manifest): string
{
    if (is_string($manifest['invalid'] ?? null)) {
        return $manifest['invalid'];
    }
    if (!runtime_satisfies((string) ($manifest['runtime'] ?? ''))) {
        return sprintf('needs kit runtime %s; this is %s', (string) ($manifest['runtime'] ?? '?'), API_VERSION);
    }
    $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
    $php = (string) ($requires['php'] ?? '');
    if ($php !== '' && version_compare(PHP_VERSION, $php, '<')) {
        return sprintf('needs PHP %s', $php);
    }
    $wp = (string) ($requires['wp'] ?? '');
    if ($wp !== '' && version_compare((string) get_bloginfo('version'), $wp, '<')) {
        return sprintf('needs WordPress %s', $wp);
    }
    foreach (is_array($requires['classes'] ?? null) ? $requires['classes'] : [] as $class) {
        if (!class_exists((string) $class)) {
            return sprintf('needs %s, which is not active', (string) $class);
        }
    }
    foreach (is_array($requires['functions'] ?? null) ? $requires['functions'] : [] as $function) {
        if (!function_exists((string) $function)) {
            return sprintf('needs %s(), which is not available', (string) $function);
        }
    }
    // `any`: a kit that serves whichever of several plugins is active (the SEO, form, backup and
    // security kits) needs one of them, named by a constant, class or function it defines.
    $any = is_array($requires['any'] ?? null) ? $requires['any'] : [];
    if ($any !== []) {
        $named = [];
        foreach (['constants' => 'defined', 'classes' => 'class_exists', 'functions' => 'function_exists'] as $kind => $check) {
            foreach (is_array($any[$kind] ?? null) ? $any[$kind] : [] as $symbol) {
                if ($check((string) $symbol)) {
                    return '';
                }
                $named[] = (string) $symbol;
            }
        }
        return sprintf('needs one of %s, and none is active', implode(', ', $named));
    }
    return '';
}

/**
 * Load every compatible kit under a directory: boot each, and queue its ability files for
 * wp_abilities_api_init.
 *
 * A kit never hooks wp_abilities_api_init itself. A registration outside that action registers
 * nothing, silently, and a kit that hooked it would register again in every plugin that carries
 * a copy; the loader owns the hook, once per runtime.
 *
 * @param list<string> $only Slugs to load; empty loads every kit found.
 * @return array{loaded: list<string>, skipped: array<string, string>}
 */
function load_kits(string $kits_dir, array $only = []): array
{
    $report = ['loaded' => [], 'skipped' => []];
    foreach (discover($kits_dir) as $manifest) {
        $slug = (string) $manifest['slug'];
        if ($only !== [] && !in_array($slug, $only, strict: true)) {
            continue;
        }
        $reason = incompatibility($manifest);
        if ($reason !== '') {
            $report['skipped'][$slug] = $reason;
            continue;
        }
        $bootstrap = (string) $manifest['dir'] . '/bootstrap.php';
        /** @var mixed $descriptor */
        $descriptor = is_file($bootstrap) ? require $bootstrap : null;
        if (!is_array($descriptor)) {
            $report['skipped'][$slug] = 'bootstrap.php did not describe the kit';
            continue;
        }
        // A condition kit.json cannot express — "this is a multisite network" is a runtime fact,
        // and is_multisite() exists on every install — is the bootstrap's to state. Reported like
        // any other skip, so integration health says why the kit's abilities are absent.
        $skip = $descriptor['skip'] ?? null;
        if (is_string($skip) && $skip !== '') {
            $report['skipped'][$slug] = $skip;
            continue;
        }
        if (is_callable($descriptor['boot'] ?? null)) {
            ($descriptor['boot'])(host());
        }
        queue($manifest, is_array($descriptor['ability_files'] ?? null) ? $descriptor['ability_files'] : []);
        $report['loaded'][] = $slug;
    }
    registry($report);
    return $report;
}

/**
 * Every kit this runtime has seen, for integration health.
 *
 * @param array{loaded: list<string>, skipped: array<string, string>}|null $add
 * @return array{loaded: list<string>, skipped: array<string, string>}
 */
function registry(?array $add = null): array
{
    /** @var array{loaded: list<string>, skipped: array<string, string>} $seen */
    static $seen = ['loaded' => [], 'skipped' => []];
    if ($add !== null) {
        $seen['loaded'] = array_values(array_unique(array_merge($seen['loaded'], $add['loaded'])));
        $seen['skipped'] = array_merge($seen['skipped'], $add['skipped']);
    }
    return $seen;
}

/**
 * @param array<string, mixed> $manifest
 * @param list<mixed> $files
 */
function queue(array $manifest, array $files): void
{
    pending_kits($manifest, array_values(array_filter($files, 'is_string')));
    if (!has_action('wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities')) {
        add_action('wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_categories', priority: 20);
        add_action('wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities', priority: 20);
    }
}

/**
 * @param array<string, mixed>|null $manifest
 * @param list<string> $files
 * @return list<array{manifest: array<string, mixed>, files: list<string>}>
 */
function pending_kits(?array $manifest = null, array $files = []): array
{
    /** @var list<array{manifest: array<string, mixed>, files: list<string>}> $pending */
    static $pending = [];
    if ($manifest !== null) {
        $pending[] = ['manifest' => $manifest, 'files' => $files];
    }
    return $pending;
}

/**
 * Register the categories kits declare, where the host has not already.
 *
 * kit.json `categories` maps a slug to its label and description. Inside GoBuildWP most kits reuse
 * a category the host registers (`changes`); standalone that category does not exist, and an
 * ability whose category is missing is refused by core.
 */
function register_categories(): void
{
    foreach (pending_kits() as $kit) {
        $categories = $kit['manifest']['categories'] ?? [];
        foreach (is_array($categories) ? $categories : [] as $slug => $category) {
            if (!is_string($slug) || !is_array($category) || wp_has_ability_category($slug)) {
                continue;
            }
            wp_register_ability_category($slug, [
                'label' => (string) ($category['label'] ?? $slug),
                'description' => (string) ($category['description'] ?? ''),
            ]);
        }
    }
}

function register_abilities(): void
{
    foreach (pending_kits() as $kit) {
        foreach ($kit['files'] as $file) {
            require_once $file;
        }
    }
}
