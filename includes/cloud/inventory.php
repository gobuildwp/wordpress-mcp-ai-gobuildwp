<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * What is installed on this site, for GoBuildWP Cloud's vulnerability matching.
 *
 * GET /gobuildwp/v1/cloud/inventory (rest.php) answers with the list built here;
 * the status answer carries only its hash, so the Cloud polls cheaply and fetches
 * the list again only when the hash moves.
 *
 * Read from WordPress's own APIs and the update transients its twice-daily check
 * fills: no file is opened here beyond the plugin and theme headers WordPress
 * reads itself, nothing is fetched, and no absolute path leaves the site - a
 * plugin is named by its file relative to the plugins folder, as WordPress does.
 *
 * The hash is cached in an option and forgotten on every event that changes what
 * is installed or active. Those hooks only delete the option; the next status call
 * recomputes it. The cache also expires on its own after an hour, for a plugin
 * that arrived over FTP or a deploy without any WordPress hook firing. On a
 * multisite network the cache is a network (site) option, so a plugin activated on
 * any subsite forgets the one hash the Cloud reads.
 *
 * On a network the inventory also lists up to 200 subsites with what each has
 * active, read with get_blog_option() and never switch_to_blog(). The environment
 * block reads wp_get_environment_type() and the presence of a few non-secret host
 * constants; PANTHEON_ENVIRONMENT is the only constant whose value is read.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Not autoloaded: read only by the Cloud's status call. {hash: string, at: int}. */
const GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION = 'gobuildwp_cloud_inventory_hash';

/** Seconds a cached hash is trusted without any invalidating hook. */
const GOBUILDWP_CLOUD_INVENTORY_HASH_TTL = 3600;

/** Most items one inventory carries. Core and active items are kept first. */
const GOBUILDWP_CLOUD_INVENTORY_MAX_ITEMS = 1000;

/** Most subsites one inventory lists on a multisite network. */
const GOBUILDWP_CLOUD_INVENTORY_MAX_SITES = 200;

/** Plugins that make a staging copy of the site, matched by slug when installed (active or not). */
const GOBUILDWP_CLOUD_INVENTORY_STAGING_PLUGINS = ['wp-staging', 'wp-staging-pro', 'wp-stagecoach', 'instawp-connect'];

/**
 * Managed hosts, each recognised by whether a constant it defines exists. Only
 * defined() is asked; none of these values is read.
 */
const GOBUILDWP_CLOUD_INVENTORY_HOST_CONSTANTS = [
    'IS_WPE' => 'wpengine',
    'KINSTA_CACHE_ZONE' => 'kinsta',
    'FLYWHEEL_PLUGIN_DIR' => 'flywheel',
    'WPCOMSH_VERSION' => 'wpcom',
];

/**
 * Hooks that change what is installed or active. Each only forgets the cached hash.
 *
 * Network activation and deactivation fire activated_plugin and deactivated_plugin
 * too (with $network_wide); the option hooks catch a list written directly.
 */
const GOBUILDWP_CLOUD_INVENTORY_HOOKS = [
    'upgrader_process_complete',
    'activated_plugin',
    'deactivated_plugin',
    'deleted_plugin',
    'switch_theme',
    'delete_theme',
    'deleted_theme',
    '_core_updated_successfully',
    'automatic_updates_complete',
    'update_option_active_plugins',
    'update_site_option_active_sitewide_plugins',
    // Multisite: a subsite created, deleted, or marked deleted / restored.
    'wp_initialize_site',
    'wp_delete_site',
    'activate_blog',
    'deactivate_blog',
];

function gobuildwp_cloud_register_inventory_hooks(): void
{
    foreach (GOBUILDWP_CLOUD_INVENTORY_HOOKS as $hook) {
        add_action($hook, callback: 'gobuildwp_cloud_inventory_forget', priority: 10, accepted_args: 0);
    }
}

/**
 * Forget the cached hash; the next status call recomputes it. On a network it is a
 * site option, so a hook firing on any subsite reaches it.
 */
function gobuildwp_cloud_inventory_forget(): void
{
    if (is_multisite()) {
        delete_site_option(GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION);

        return;
    }
    delete_option(GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION);
}

/**
 * @return array{
 *     generated_at: string,
 *     wp_version: string,
 *     php_version: string,
 *     multisite: bool,
 *     locale: string,
 *     total_items: int,
 *     truncated: bool,
 *     items: list<array{type: string, slug: string, file: string, name: string, version: string, active: bool, network_active: bool, parent: string|null, update_uri: string, new_version: string|null, wporg: bool}>,
 *     environment: array{type: string, staging_plugins: list<string>, host: string|null, host_env: string|null},
 *     network?: array{sites_total: int, sites: list<array{blog_id: int, url_host: string, path: string, active_plugins: list<string>, stylesheet: string, template: string, deleted: bool}>, truncated: bool}
 * }
 */
function gobuildwp_cloud_inventory(): array
{
    $all = gobuildwp_cloud_inventory_items();
    $items = gobuildwp_cloud_inventory_cap($all, GOBUILDWP_CLOUD_INVENTORY_MAX_ITEMS);
    $multisite = is_multisite();

    $inventory = [
        'generated_at' => gmdate('c'),
        'wp_version' => wppilot_cloud_short((string) get_bloginfo('version'), 64),
        'php_version' => PHP_VERSION,
        'multisite' => $multisite,
        'locale' => wppilot_cloud_short((string) get_locale(), 20),
        'total_items' => count($all),
        'truncated' => count($all) > count($items),
        'items' => $items,
        'environment' => gobuildwp_cloud_inventory_environment($all),
    ];
    if ($multisite) {
        $inventory['network'] = gobuildwp_cloud_inventory_network();
    }

    return $inventory;
}

/**
 * The environment block, from the live site.
 *
 * @param list<array{type: string, slug: string}> $items every item, uncapped
 * @return array{type: string, staging_plugins: list<string>, host: string|null, host_env: string|null}
 */
function gobuildwp_cloud_inventory_environment(array $items): array
{
    $plugin_slugs = [];
    foreach ($items as $item) {
        if ($item['type'] === 'plugin') {
            $plugin_slugs[] = $item['slug'];
        }
    }

    return gobuildwp_cloud_inventory_environment_of(
        function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'production',
        $plugin_slugs,
        static fn(string $name): bool => defined($name),
        static fn(string $name): mixed => constant($name),
    );
}

/**
 * The environment block from its inputs. $defined and $constant stand for PHP's
 * defined() and constant(); $constant is only asked for PANTHEON_ENVIRONMENT.
 *
 * @param list<string> $installed_plugin_slugs
 * @param callable(string): bool $defined
 * @param callable(string): mixed $constant
 * @return array{type: string, staging_plugins: list<string>, host: string|null, host_env: string|null}
 */
function gobuildwp_cloud_inventory_environment_of(string $type, array $installed_plugin_slugs, callable $defined, callable $constant): array
{
    $staging = array_values(array_intersect(GOBUILDWP_CLOUD_INVENTORY_STAGING_PLUGINS, $installed_plugin_slugs));

    $host = null;
    foreach (GOBUILDWP_CLOUD_INVENTORY_HOST_CONSTANTS as $name => $label) {
        if ($defined($name)) {
            $host = $label;
            break;
        }
    }

    $host_env = null;
    if ($defined('PANTHEON_ENVIRONMENT')) {
        $value = $constant('PANTHEON_ENVIRONMENT');
        if (is_string($value) && preg_match('/^[a-z0-9_-]{1,32}$/', $value) === 1) {
            $host_env = $value;
        }
    }

    $type = wppilot_cloud_short($type, max: 20);

    return [
        'type' => $type === '' ? 'production' : $type,
        'staging_plugins' => $staging,
        'host' => $host,
        'host_env' => $host_env,
    ];
}

/**
 * Up to 200 subsites of this network and what each has active, without switching
 * to any of them. Network-active plugins are not repeated per subsite; the items
 * carry them as network_active.
 *
 * @return array{sites_total: int, sites: list<array{blog_id: int, url_host: string, path: string, active_plugins: list<string>, stylesheet: string, template: string, deleted: bool}>, truncated: bool}
 */
function gobuildwp_cloud_inventory_network(): array
{
    $ids = get_sites(['number' => GOBUILDWP_CLOUD_INVENTORY_MAX_SITES, 'fields' => 'ids']);
    $total = (int) get_sites(['count' => true]);

    $sites = [];
    foreach (is_array($ids) ? $ids : [] as $id) {
        $id = (int) $id;
        $site = get_site($id);
        if (!is_object($site)) {
            continue;
        }
        $slugs = [];
        foreach ((array) get_blog_option($id, 'active_plugins', []) as $file) {
            if (is_string($file) && $file !== '') {
                $slugs[] = wppilot_cloud_short(gobuildwp_cloud_inventory_plugin_slug($file), max: 200);
            }
        }
        $slugs = array_values(array_unique($slugs));
        sort($slugs, SORT_STRING);
        $sites[] = [
            'blog_id' => $id,
            // Domain only: the subsite's host, never a full URL.
            'url_host' => wppilot_cloud_short(strtolower((string) ($site->domain ?? '')), max: 253),
            'path' => wppilot_cloud_short((string) ($site->path ?? '/'), max: 200),
            'active_plugins' => $slugs,
            'stylesheet' => wppilot_cloud_short((string) get_blog_option($id, 'stylesheet', ''), max: 200),
            'template' => wppilot_cloud_short((string) get_blog_option($id, 'template', ''), max: 200),
            'deleted' => (bool) (int) ($site->deleted ?? 0),
        ];
        if (count($sites) >= GOBUILDWP_CLOUD_INVENTORY_MAX_SITES) {
            break;
        }
    }

    return [
        'sites_total' => max($total, count($sites)),
        'sites' => $sites,
        'truncated' => $total > count($sites),
    ];
}

/**
 * sha256 over one sorted line per item, so the order WordPress lists things in
 * does not move it. Cached; see the file comment.
 */
function gobuildwp_cloud_inventory_hash(?int $now = null): string
{
    $now ??= time();
    /** @var mixed $stored */
    $stored = is_multisite()
        ? get_site_option(GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION, null)
        : get_option(GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION, default_value: null);
    if (
        is_array($stored)
        && is_string($stored['hash'] ?? null)
        && preg_match('/^[0-9a-f]{64}$/', $stored['hash']) === 1
        && is_int($stored['at'] ?? null)
        && $stored['at'] <= $now
        && $now - $stored['at'] < GOBUILDWP_CLOUD_INVENTORY_HASH_TTL
    ) {
        return $stored['hash'];
    }

    $inventory = gobuildwp_cloud_inventory();

    return gobuildwp_cloud_inventory_remember(gobuildwp_cloud_inventory_hash_of($inventory['items'], $inventory), $now);
}

/** Cache $hash as of $now; returns it. A site option on a network: see the file comment. */
function gobuildwp_cloud_inventory_remember(string $hash, ?int $now = null): string
{
    $value = ['hash' => $hash, 'at' => $now ?? time()];
    if (is_multisite()) {
        update_site_option(GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION, $value);
    } else {
        update_option(GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION, $value, autoload: false);
    }

    return $hash;
}

/**
 * Pass the whole inventory as $inventory so its environment and network blocks
 * count too; without it the hash covers the items alone.
 *
 * @param list<array{type: string, slug: string, version: string, active: bool, network_active: bool}> $items
 * @param array{environment?: array{type: string, host: string|null, host_env: string|null}, network?: array{sites_total: int, sites: list<array{blog_id: int, active_plugins: list<string>, stylesheet: string, template: string, deleted: bool}>}} $inventory
 */
function gobuildwp_cloud_inventory_hash_of(array $items, array $inventory = []): string
{
    $lines = [];
    if (isset($inventory['environment'])) {
        $environment = $inventory['environment'];
        $lines[] = sprintf('env:%s:%s:%s', $environment['type'], $environment['host'] ?? '', $environment['host_env'] ?? '');
    }
    if (isset($inventory['network'])) {
        $lines[] = gobuildwp_cloud_inventory_network_digest($inventory['network']);
    }
    foreach ($items as $item) {
        $lines[] = sprintf(
            '%s:%s@%s:%d:%d',
            $item['type'],
            $item['slug'],
            $item['version'],
            $item['active'] ? 1 : 0,
            $item['network_active'] ? 1 : 0,
        );
    }
    sort($lines, SORT_STRING);

    return hash('sha256', implode("\n", $lines));
}

/**
 * One line standing for the network block: each subsite's id, deleted flag, themes
 * and sorted active plugins, in blog id order, so neither listing order moves it.
 *
 * @param array{sites_total: int, sites: list<array{blog_id: int, active_plugins: list<string>, stylesheet: string, template: string, deleted: bool}>} $network
 */
function gobuildwp_cloud_inventory_network_digest(array $network): string
{
    $sites = [];
    foreach ($network['sites'] as $site) {
        $slugs = $site['active_plugins'];
        sort($slugs, SORT_STRING);
        $sites[$site['blog_id']] = sprintf(
            '%d:%d:%s:%s:%s',
            $site['blog_id'],
            $site['deleted'] ? 1 : 0,
            $site['stylesheet'],
            $site['template'],
            implode(',', $slugs),
        );
    }
    ksort($sites, SORT_NUMERIC);

    return sprintf('network:%d:%s', $network['sites_total'], hash('sha256', implode("\n", $sites)));
}

/**
 * Core first, then everything active, then the rest, each group in WordPress's order.
 *
 * @template T of array{type: string, active: bool}
 * @param list<T> $items
 * @return list<T>
 */
function gobuildwp_cloud_inventory_cap(array $items, int $max): array
{
    $core = [];
    $active = [];
    $rest = [];
    foreach ($items as $item) {
        match (true) {
            $item['type'] === 'core' => $core[] = $item,
            $item['active'] => $active[] = $item,
            default => $rest[] = $item,
        };
    }

    return array_slice(array_merge($core, $active, $rest), 0, max(0, $max));
}

/**
 * Every item, uncapped.
 *
 * @return list<array{type: string, slug: string, file: string, name: string, version: string, active: bool, network_active: bool, parent: string|null, update_uri: string, new_version: string|null, wporg: bool}>
 */
function gobuildwp_cloud_inventory_items(): array
{
    if (!function_exists('get_plugins') || !function_exists('get_mu_plugins') || !function_exists('get_dropins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $core_update = wppilot_cloud_core_update(get_site_transient('update_core'));
    $core = gobuildwp_cloud_inventory_item([
        'type' => 'core',
        'slug' => 'wordpress',
        'name' => 'WordPress',
        'version' => (string) get_bloginfo('version'),
        'active' => true,
        'new_version' => $core_update['new_version'] ?? null,
        'wporg' => true,
    ]);

    return array_merge(
        [$core],
        gobuildwp_cloud_inventory_plugins(),
        gobuildwp_cloud_inventory_themes(),
        gobuildwp_cloud_inventory_headers_only('mu_plugin', get_mu_plugins()),
        gobuildwp_cloud_inventory_headers_only('dropin', get_dropins()),
    );
}

/**
 * @return list<array{type: string, slug: string, file: string, name: string, version: string, active: bool, network_active: bool, parent: string|null, update_uri: string, new_version: string|null, wporg: bool}>
 */
function gobuildwp_cloud_inventory_plugins(): array
{
    $active = array_map('strval', (array) get_option('active_plugins', default_value: []));
    $sitewide = is_multisite()
        ? array_map('strval', array_keys((array) get_site_option('active_sitewide_plugins', default_value: [])))
        : [];
    $offers = gobuildwp_cloud_inventory_offers(get_site_transient('update_plugins'));

    $items = [];
    foreach (get_plugins() as $file => $headers) {
        $file = (string) $file;
        $headers = (array) $headers;
        $network = in_array($file, $sitewide, strict: true);
        $offer = $offers['response'][$file] ?? null;
        $known = $offer ?? $offers['no_update'][$file] ?? null;
        $items[] = gobuildwp_cloud_inventory_item([
            'type' => 'plugin',
            'slug' => gobuildwp_cloud_inventory_plugin_slug($file),
            'file' => $file,
            'name' => (string) ($headers['Name'] ?? ''),
            'version' => (string) ($headers['Version'] ?? ''),
            'active' => $network || in_array($file, $active, strict: true),
            'network_active' => $network,
            'update_uri' => (string) ($headers['UpdateURI'] ?? ''),
            'new_version' => gobuildwp_cloud_inventory_offer_field($offer, field: 'new_version'),
            // wordpress.org answers with ids like w.org/plugins/akismet; other updaters do not.
            'wporg' => str_starts_with(gobuildwp_cloud_inventory_offer_field($known, field: 'id'), 'w.org/'),
        ]);
    }

    return $items;
}

/**
 * @return list<array{type: string, slug: string, file: string, name: string, version: string, active: bool, network_active: bool, parent: string|null, update_uri: string, new_version: string|null, wporg: bool}>
 */
function gobuildwp_cloud_inventory_themes(): array
{
    $in_use = [(string) get_stylesheet(), (string) get_template()];
    $offers = gobuildwp_cloud_inventory_offers(get_site_transient('update_themes'));

    $items = [];
    foreach (wp_get_themes() as $slug => $theme) {
        $slug = (string) $slug;
        $parent = (string) $theme->get_template();
        $update_uri = (string) $theme->get('UpdateURI');
        $offer = $offers['response'][$slug] ?? null;
        $listed = $offer !== null || array_key_exists($slug, $offers['no_update']);
        $items[] = gobuildwp_cloud_inventory_item([
            'type' => 'theme',
            'slug' => $slug,
            'name' => (string) $theme->get('Name'),
            'version' => (string) $theme->get('Version'),
            'active' => in_array($slug, $in_use, strict: true),
            'parent' => $parent !== '' && $parent !== $slug ? $parent : null,
            'update_uri' => $update_uri,
            'new_version' => gobuildwp_cloud_inventory_offer_field($offer, field: 'new_version'),
            // A theme whose Update URI names another host is not checked against wordpress.org
            // by WordPress, so an entry for it in the transient came from somewhere else.
            'wporg' => $listed && gobuildwp_cloud_inventory_update_uri_is_wporg($update_uri),
        ]);
    }

    return $items;
}

/**
 * Must-use plugins and drop-ins: always loaded, never updated by WordPress.
 *
 * @param array<array-key, mixed> $files file => headers, as get_mu_plugins() and get_dropins() return them
 * @return list<array{type: string, slug: string, file: string, name: string, version: string, active: bool, network_active: bool, parent: string|null, update_uri: string, new_version: string|null, wporg: bool}>
 */
function gobuildwp_cloud_inventory_headers_only(string $type, array $files): array
{
    $items = [];
    foreach ($files as $file => $headers) {
        $file = (string) $file;
        $headers = (array) $headers;
        $items[] = gobuildwp_cloud_inventory_item([
            'type' => $type,
            'slug' => gobuildwp_cloud_inventory_plugin_slug($file),
            'file' => $file,
            'name' => (string) ($headers['Name'] ?? ''),
            'version' => (string) ($headers['Version'] ?? ''),
            'active' => true,
        ]);
    }

    return $items;
}

/**
 * One item, every field present and cut to length. Missing fields take their empty value.
 *
 * @param array{type: string, slug: string, file?: string, name?: string, version?: string, active?: bool, network_active?: bool, parent?: string|null, update_uri?: string, new_version?: string|null, wporg?: bool} $fields
 * @return array{type: string, slug: string, file: string, name: string, version: string, active: bool, network_active: bool, parent: string|null, update_uri: string, new_version: string|null, wporg: bool}
 */
function gobuildwp_cloud_inventory_item(array $fields): array
{
    $parent = $fields['parent'] ?? null;
    $new_version = wppilot_cloud_short((string) ($fields['new_version'] ?? ''), max: 64);

    return [
        'type' => $fields['type'],
        'slug' => wppilot_cloud_short($fields['slug'], max: 200),
        'file' => wppilot_cloud_short($fields['file'] ?? '', max: 300),
        'name' => wppilot_cloud_short($fields['name'] ?? '', max: 200),
        'version' => wppilot_cloud_short($fields['version'] ?? '', max: 64),
        'active' => $fields['active'] ?? false,
        'network_active' => $fields['network_active'] ?? false,
        'parent' => $parent === null ? null : wppilot_cloud_short($parent, max: 200),
        'update_uri' => wppilot_cloud_short($fields['update_uri'] ?? '', max: 300),
        'new_version' => $new_version === '' ? null : $new_version,
        'wporg' => $fields['wporg'] ?? false,
    ];
}

/**
 * The directory name of a plugin in a folder, or the file name without .php for
 * a single-file plugin ('hello.php' is 'hello'), matching wordpress.org slugs.
 */
function gobuildwp_cloud_inventory_plugin_slug(string $file): string
{
    $file = str_replace('\\', '/', $file);
    $slash = strpos($file, '/');
    if ($slash !== false) {
        return substr($file, 0, $slash);
    }

    return (string) preg_replace('/\.php$/i', '', $file);
}

/**
 * The response and no_update lists of an update transient, keyed as WordPress keys them.
 *
 * @return array{response: array<string, mixed>, no_update: array<string, mixed>}
 */
function gobuildwp_cloud_inventory_offers(mixed $transient): array
{
    $lists = ['response' => [], 'no_update' => []];
    if (!is_object($transient)) {
        return $lists;
    }
    foreach (['response', 'no_update'] as $key) {
        if (isset($transient->{$key}) && is_array($transient->{$key})) {
            foreach ($transient->{$key} as $name => $entry) {
                $lists[$key][(string) $name] = $entry;
            }
        }
    }

    return $lists;
}

/** One field of a transient entry: plugins store objects, themes arrays. */
function gobuildwp_cloud_inventory_offer_field(mixed $entry, string $field): string
{
    if (is_object($entry) && isset($entry->{$field}) && is_scalar($entry->{$field})) {
        return (string) $entry->{$field};
    }
    if (is_array($entry) && isset($entry[$field]) && is_scalar($entry[$field])) {
        return (string) $entry[$field];
    }

    return '';
}

/** No Update URI, or one on wordpress.org / w.org, leaves WordPress checking wordpress.org. */
function gobuildwp_cloud_inventory_update_uri_is_wporg(string $update_uri): bool
{
    if ($update_uri === '') {
        return true;
    }
    $host = wp_parse_url($update_uri, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        // WordPress treats a bare value as the host.
        $host = $update_uri;
    }

    return in_array(strtolower($host), ['wordpress.org', 'w.org'], strict: true);
}
