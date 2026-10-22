<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

/**
 * Load bundled Composer dependencies and report the common source-ZIP install mistake clearly.
 *
 * @return WP_Error|null
 */
function wppilot_load_bundled_dependencies()
{
    if (!file_exists(WPPILOT_VENDOR_AUTOLOAD)) {
        return new WP_Error('wppilot_missing_vendor', __(
            'GoBuildWP is installed without its bundled vendor directory. This usually means the GitHub/source ZIP was installed instead of the GoBuildWP release build ZIP. The MCP Adapter cannot load, so GoBuildWP will not register an MCP endpoint. Install the GoBuildWP release build ZIP before using GoBuildWP.',
            domain: 'wppilot',
        ));
    }

    // On a debug site, let the Jetpack autoloader report a competing loader by
    // name. Its conflict check is compiled in but inert without this constant, so
    // the only symptom of a namespace collision is a fatal naming a hashed class -
    // which reads as a GoBuildWP bug rather than as two plugins colliding. Defined
    // before the autoloader is required, because it is read during its init.
    if (defined('WP_DEBUG') && WP_DEBUG && !defined('JETPACK_AUTOLOAD_DEBUG_CONFLICTING_LOADERS')) {
        define('JETPACK_AUTOLOAD_DEBUG_CONFLICTING_LOADERS', true);
    }

    try {
        require_once WPPILOT_VENDOR_AUTOLOAD;
    } catch (\Throwable $e) {
        return new WP_Error('wppilot_autoload_failed', sprintf(
            /* translators: %s: the autoloader error message */
            __(
                'GoBuildWP could not load its bundled Composer dependencies. The MCP Adapter cannot load, so GoBuildWP will not register an MCP endpoint. Reinstall the GoBuildWP release build ZIP. Error: %s',
                domain: 'wppilot',
            ),
            $e->getMessage(),
        ));
    }

    if (!class_exists(WPPILOT_MCP_ADAPTER_CLASS)) {
        return new WP_Error('wppilot_mcp_adapter_missing', sprintf(
            /* translators: %s: fully qualified MCP Adapter class name */
            __(
                'GoBuildWP loaded its Composer autoloader, but the MCP Adapter class (%s) is not available. GoBuildWP will not register an MCP endpoint. Reinstall the GoBuildWP release build ZIP.',
                domain: 'wppilot',
            ),
            WPPILOT_MCP_ADAPTER_CLASS,
        ));
    }

    return null;
}

/**
 * Store a runtime MCP dependency error.
 */
function wppilot_set_mcp_dependency_error(WP_Error $error): void
{
    wppilot_mcp_dependency_error($error);
}

/**
 * Return the current MCP dependency error, if any.
 *
 * @return WP_Error|null
 */
function wppilot_get_mcp_dependency_error()
{
    return wppilot_mcp_dependency_error();
}

/**
 * Shared storage for the current MCP dependency error.
 *
 * @return WP_Error|null
 */
function wppilot_mcp_dependency_error(?WP_Error $error = null)
{
    static $current = null;

    if ($error !== null) {
        $current = $error;
    }

    return $current;
}

/**
 * Whether the bundled MCP Adapter is available for GoBuildWP to initialize.
 */
function wppilot_is_mcp_adapter_available(): bool
{
    return wppilot_get_mcp_dependency_error() === null && class_exists(WPPILOT_MCP_ADAPTER_CLASS);
}

/**
 * Show a persistent admin error when GoBuildWP cannot expose MCP.
 */
function wppilot_render_mcp_dependency_notice(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }

    // Only compared against GoBuildWP's own screen slugs to decide where the notice shows; no state changes.
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (
        is_string($page)
        && in_array($page, ['wppilot-connect', WPPILOT_SETUP_PAGE, 'wppilot-abilities', 'wppilot-chat', 'wppilot-sandbox'], strict: true)
    ) {
        return;
    }

    $error = wppilot_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    wp_admin_notice(esc_html($error->get_error_message()), [
        'type' => 'error',
        'dismissible' => false,
    ]);
}

/**
 * Return a clear REST error at the MCP endpoint when the adapter cannot register its own route.
 */
function wppilot_register_missing_mcp_endpoint(): void
{
    $error = wppilot_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    $routes = rest_get_server()->get_routes();
    $callback = static fn() => new WP_Error('wppilot_mcp_adapter_unavailable', $error->get_error_message(), [
        'status' => 500,
    ]);

    foreach (['wppilot', 'mcp-adapter-default-server'] as $route_slug) {
        if (array_key_exists('/mcp/' . $route_slug, $routes)) {
            continue;
        }
        register_rest_route('mcp', '/' . $route_slug, [
            'methods' => WP_REST_Server::ALLMETHODS,
            'callback' => $callback,
            'permission_callback' => '__return_true',
        ]);
    }
}

/**
 * Initialize the MCP Adapter and convert runtime failures into visible admin notices.
 */
function wppilot_initialize_mcp_adapter(): bool
{
    if (!wppilot_is_mcp_adapter_available()) {
        return false;
    }

    try {
        \WP\MCP\Core\McpAdapter::instance();
        return true;
    } catch (\Throwable $e) {
        wppilot_set_mcp_dependency_error(
            new WP_Error('wppilot_mcp_adapter_init_failed', sprintf(
                /* translators: %s: the adapter initialization error message */
                __(
                    'GoBuildWP found the MCP Adapter, but it failed during initialization. GoBuildWP will not register an MCP endpoint. Error: %s',
                    domain: 'wppilot',
                ),
                $e->getMessage(),
            )),
        );
        return false;
    }
}

function wppilot_load_gutenberg_runtime(): void
{
    require_once dirname(__DIR__) . '/includes/abilities/gutenberg/bootstrap.php';
    require_once dirname(__DIR__) . '/includes/abilities/gutenberg/runtime.php';
    require_once dirname(__DIR__) . '/includes/abilities/gutenberg/rest.php';
    require_once dirname(__DIR__) . '/includes/admin/gutenberg-finalizer.php';
    \WPPilot\GutenbergFinalizer\boot_gutenberg_finalizer_admin();
}

function wppilot_load_gutenberg_abilities(): void
{
    $gutenberg_dir = dirname(__DIR__) . '/includes/abilities/gutenberg/';
    require_once $gutenberg_dir . 'bootstrap.php';
    require_once $gutenberg_dir . 'runtime.php';
    require_once $gutenberg_dir . 'get-finalizer-runtime.php';
    require_once $gutenberg_dir . 'get-content.php';
    require_once $gutenberg_dir . 'write-content.php';
    require_once $gutenberg_dir . 'create-pending-batch.php';
    require_once $gutenberg_dir . 'add-pending-change.php';
    require_once $gutenberg_dir . 'enable-batch-finalization.php';
    require_once $gutenberg_dir . 'get-pending-batch.php';
    require_once $gutenberg_dir . 'list-pending-batches.php';
    require_once $gutenberg_dir . 'delete-pending-batch.php';
    require_once $gutenberg_dir . 'delete-pending-change.php';
    require_once $gutenberg_dir . 'get-finalization-url.php';
}

function wppilot_inject_custom_instructions(mixed $instructions): mixed
{
    if (!is_string($instructions)) {
        return $instructions;
    }

    // Stay out while a GoBuildWP Pro that still manages custom instructions is
    // active: it injects its own copy (priority 5), so the base must not add a
    // second one. The base takes over once that Pro is gone or updated.
    if (\WPPilot\Context\legacy_pro_context_loaded()) {
        return $instructions;
    }

    if (\WPPilot\Context\instructions_custom_injection_suppressed()) {
        return $instructions;
    }

    if (!\WPPilot\Context\instructions_is_enabled()) {
        return $instructions;
    }

    $custom = \WPPilot\Context\instructions_get_content();
    if (trim($custom) === '') {
        return $instructions;
    }

    if (str_starts_with($instructions, $custom . "\n\n")) {
        return $instructions;
    }

    return $custom . "\n\n" . $instructions;
}

/**
 * Add the GoBuildWP AI Abilities status and toggle to the WordPress admin bar.
 */
function wppilot_register_admin_bar_toggle(\WP_Admin_Bar $wp_admin_bar): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }

    $dependency_error = wppilot_get_mcp_dependency_error();
    $configured_enabled = wppilot_is_enabled();
    $active = $configured_enabled && $dependency_error === null;
    $can_enable = $configured_enabled || $dependency_error === null;
    $target = $configured_enabled ? 'off' : 'on';
    $toggle_url = wp_nonce_url(
        admin_url('admin-post.php?action=wppilot_toggle_ai_abilities&wppilot_target=' . $target),
        action: 'wppilot_toggle_ai_abilities',
    );

    $wp_admin_bar->add_node([
        'id' => 'wppilot-mcp-status',
        // A dot carries the state; the label stays "GoBuildWP". A red block reading
        // "GoBuildWP ON" sat in the toolbar of every screen of a healthy site and
        // looked like an alarm.
        'title' => '<span class="wppilot-ab-dot" aria-hidden="true"></span>'
            . esc_html__('GoBuildWP', domain: 'wppilot')
            . '<span class="screen-reader-text"> ' . match (true) {
                $active => esc_html__('(agents can act)', domain: 'wppilot'),
                $configured_enabled => esc_html__('(agents blocked by an error)', domain: 'wppilot'),
                default => esc_html__('(agents off)', domain: 'wppilot'),
            } . '</span>',
        'href' => admin_url('admin.php?page=wppilot-connect'),
        'meta' => [
            'class' => match (true) {
                $active => 'wppilot-mcp-on',
                $configured_enabled => 'wppilot-mcp-error',
                default => 'wppilot-mcp-off',
            },
        ],
    ]);

    $wp_admin_bar->add_node([
        'id' => 'wppilot-mcp-status-label',
        'parent' => 'wppilot-mcp-status',
        'title' => match (true) {
            $active => esc_html__('Agents can act on this site', domain: 'wppilot'),
            $configured_enabled => esc_html__('Agents blocked: see Diagnostics', domain: 'wppilot'),
            default => esc_html__('Agents are off', domain: 'wppilot'),
        },
    ]);

    if (!$can_enable) {
        $wp_admin_bar->add_node([
            'id' => 'wppilot-mcp-unavailable',
            'parent' => 'wppilot-mcp-status',
            'title' => esc_html__('Agent access unavailable', domain: 'wppilot'),
            'href' => admin_url('admin.php?page=wppilot-troubleshoot'),
        ]);
    }

    if ($can_enable) {
        $wp_admin_bar->add_node([
            'id' => 'wppilot-mcp-toggle',
            'parent' => 'wppilot-mcp-status',
            'title' => $configured_enabled
                ? esc_html__('Turn off agent access', domain: 'wppilot')
                : esc_html__('Turn on agent access', domain: 'wppilot'),
            'href' => $toggle_url,
            'meta' => [
                'class' => $configured_enabled ? 'wppilot-mcp-toggle-off' : 'wppilot-mcp-toggle-on',
            ],
        ]);
    }

    $wp_admin_bar->add_node([
        'id' => 'wppilot-mcp-config',
        'parent' => 'wppilot-mcp-status',
        'title' => esc_html__('Dashboard', domain: 'wppilot'),
        'href' => admin_url('admin.php?page=wppilot-connect'),
    ]);

    $wp_admin_bar->add_node([
        'id' => 'wppilot-mcp-changes',
        'parent' => 'wppilot-mcp-status',
        'title' => esc_html__('Recent changes', domain: 'wppilot'),
        'href' => admin_url('admin.php?page=wppilot-changes'),
    ]);
}

/**
 * Style the admin-bar status chip and require confirmation before enabling from the dropdown.
 *
 * Hooked to admin_enqueue_scripts and wp_enqueue_scripts: the chip is in the admin bar on
 * both sides of the site.
 */
function wppilot_enqueue_admin_bar_toggle_assets(): void
{
    if (!wppilot_current_user_can_manage() || !is_admin_bar_showing()) {
        return;
    }

    $looks_production = wppilot_looks_like_production();
    $confirm_message = $looks_production
        ? __(
            'This looks like a production site. AI Abilities are intended for staging or development sites. Continue anyway?',
            domain: 'wppilot',
        )
        : (wppilot_is_wordpress_org_edition()
            ? __('AI agents will be able to act on this site within the safety profile you have chosen. Continue?', domain: 'wppilot')
            : __('AI agents will be able to execute PHP code and access the filesystem. Continue?', domain: 'wppilot'));

    wp_enqueue_style(
        'wppilot-admin-bar',
        (string) WPPILOT_PLUGIN_URL . 'includes/assets/admin-bar.css',
        [],
        WPPILOT_VERSION,
    );
    // phpcs:disable WordPress.WP.EnqueuedResourceParameters.NotInFooter -- `args: true` IS the in_footer flag; the sniff cannot read PHP named arguments.
    wp_enqueue_script(
        'wppilot-admin-bar',
        (string) WPPILOT_PLUGIN_URL . 'includes/assets/admin-bar.js',
        [],
        WPPILOT_VERSION,
        args: true,
    );
    // phpcs:enable WordPress.WP.EnqueuedResourceParameters.NotInFooter
    $data = wp_json_encode(['confirmEnable' => $confirm_message], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (is_string($data)) {
        wp_add_inline_script('wppilot-admin-bar', 'window.wppilotAdminBar = ' . $data . ';', 'before');
    }
}

/**
 * Register a legacy alias of the canonical GoBuildWP MCP server at the pre-rename slug.
 *
 * The canonical server is registered under `/mcp/wppilot`. Older client configs may still
 * point at `/mcp/mcp-adapter-default-server` from before the rename - this alias keeps them
 * working with identical behavior (same tools, same auto-discovered resources and prompts).
 */
function wppilot_register_legacy_mcp_server(mixed $adapter): void
{
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }

    if ($adapter->get_server('wppilot') === null) {
        return;
    }

    wppilot_create_mirror_mcp_server(
        $adapter,
        server_id: 'mcp-adapter-default-server',
        route: 'mcp-adapter-default-server',
        name: 'GoBuildWP (legacy alias)',
        description: 'Legacy alias for the GoBuildWP MCP server. New client configurations should use /wp-json/mcp/wppilot.',
    );
}

/**
 * Register GoBuildWP's canonical MCP server when the adapter belongs to someone else.
 *
 * When GoBuildWP's own copy of the adapter is loaded, the canonical server is the
 * adapter's default server, renamed through `mcp_adapter_default_server_config`.
 * When another plugin's copy won the Jetpack version arbitration, that default
 * server is theirs and is left alone - so `/wp-json/mcp/wppilot` has to be
 * created explicitly, or every existing GoBuildWP client configuration on the site
 * would 404 the moment the other plugin was installed.
 *
 * The tools and auto-discovered resources are identical either way, because the
 * Ability registry is site-wide; only the ownership of the default route differs.
 */
function wppilot_register_canonical_mcp_server(mixed $adapter): void
{
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }

    wppilot_create_mirror_mcp_server(
        $adapter,
        server_id: 'wppilot',
        route: 'wppilot',
        name: 'GoBuildWP',
        description: 'GoBuildWP MCP endpoint.',
    );
}

/**
 * Register the OAuth-authenticated GoBuildWP MCP server at `/mcp/wppilot-oauth`.
 *
 * The OAuth Bearer flow lives on this dedicated route so the canonical `/mcp/wppilot` endpoint -
 * used by the existing Application Password installs - is never seen by the OAuth challenge
 * middleware (see includes/oauth/middleware.php::is_mcp_route). Registered only when the OAuth
 * transport is permitted, mirroring includes/oauth/bootstrap.php so the endpoint never exists
 * without the token/authorize endpoints that make it usable.
 */
function wppilot_register_oauth_mcp_server(mixed $adapter): void
{
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }

    if (!wppilot_oauth_transport_allowed()) {
        return;
    }

    if ($adapter->get_server('wppilot') === null) {
        return;
    }

    wppilot_create_mirror_mcp_server(
        $adapter,
        server_id: 'wppilot-oauth',
        route: 'wppilot-oauth',
        name: 'GoBuildWP (OAuth)',
        description: 'OAuth-authenticated GoBuildWP MCP endpoint. Application Password clients use /wp-json/mcp/wppilot.',
    );
}

/**
 * Create an MCP server that mirrors the canonical GoBuildWP server - same tools, resources, and
 * prompts - under a different id and route. Shared by the legacy alias and the OAuth endpoint so
 * neither drifts from the default server's exposed abilities.
 */
function wppilot_create_mirror_mcp_server(
    \WP\MCP\Core\McpAdapter $adapter,
    string $server_id,
    string $route,
    string $name,
    string $description,
): void {
    // Another plugin may already hold this id - two copies of GoBuildWP on disk, or
    // a slug collision with a third-party server. The adapter throws on a
    // duplicate, and a throw here happens during `mcp_adapter_init`, which would
    // take down every server on the site rather than skip one registration.
    if ($adapter->get_server($server_id) !== null) {
        return;
    }

    $adapter->create_server(
        $server_id,
        'mcp',
        $route,
        $name,
        $description,
        // Derived, not hardcoded: this is the version legacy clients read from
        // initialize's serverInfo, and it silently drifted from the plugin
        // header once already.
        'v' . WPPILOT_VERSION,
        [\WP\MCP\Transport\HttpTransport::class],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        [
            'mcp-adapter/discover-abilities',
            'mcp-adapter/get-ability-info',
            'mcp-adapter/execute-ability',
        ],
        wppilot_discover_public_abilities('resource'),
        wppilot_discover_public_abilities('prompt'),
        // Without this the adapter's default applies: any logged-in user with `read`.
        'WPPilot\Mcp\Legacy\transport_permission',
    );
}

/**
 * Replicate DefaultServerFactory::discover_abilities_by_type for reuse on the legacy alias.
 *
 * @return list<string>
 */
function wppilot_discover_public_abilities(string $type): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $abilities = wp_get_abilities();
    $filtered = [];
    foreach ($abilities as $ability) {
        $meta = wppilot_ability_meta($ability);
        if (!wppilot_ability_is_exposed($meta) || wppilot_ability_mcp_type($meta) !== $type) {
            continue;
        }
        $filtered[] = $ability->get_name();
    }

    return $filtered;
}
