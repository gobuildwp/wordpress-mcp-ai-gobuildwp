<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\VisualRuntime;

use WP_Error;
use WP_REST_Request;
use WPPilot\Design\Capture;

/**
 * Capturing a page from a browser tab the site already has.
 *
 * `wppilot/get-page-view-link` covers the client that has its own browser. A
 * great many do not - an access-token caller, a cron job, a client whose only
 * tool is the MCP connection - and for those the site has to take the picture
 * itself. GoBuildWP bundles no renderer, so the browser it uses is a logged-in
 * wp-admin tab, exactly as the Block Editor Queue already does for blocks.
 *
 * The capture is a **re-render, not a screenshot**. The tab loads the page in a
 * same-origin iframe, walks its DOM into an SVG `foreignObject` with the
 * computed styles inlined, and rasterises that through a canvas. That is a real
 * picture of a real render, and it is not what a browser's screenshot button
 * would produce: anything painted by a script after capture is missed, a
 * cross-origin image cannot be inlined, and a webfont served from another
 * origin falls back. Every one of those is reported with the capture rather
 * than left for somebody to discover by trusting it.
 *
 * The alternative was bundling a rasteriser library. This way there is no
 * third-party JavaScript in the plugin and no CDN at runtime, and the honest
 * limits are the ones the technique actually has.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** The option holding queued and finished capture jobs. */
const JOBS_OPTION = 'wppilot_visual_jobs';

/** How many jobs to keep. Small: a job is a request, not a record. */
const MAX_JOBS = 30;

/** A job nobody claimed in this long is stale, and claimable again. */
const CLAIM_SECONDS = 180;

/** The admin screen that does the capturing. */
const PAGE_SLUG = 'wppilot-visual-runtime';

/** Transient recording that a runtime tab is open. */
const HEARTBEAT_TRANSIENT = 'wppilot_visual_runtime_seen';

const HEARTBEAT_STALE_SECONDS = 45;

/**
 * Register the screen, its assets and its routes.
 */
function boot(): void
{
    add_action('admin_menu', __NAMESPACE__ . '\\register_page');
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets');
    add_action('rest_api_init', __NAMESPACE__ . '\\register_routes');
}

/**
 * A hidden screen: it is opened from a link in an ability's instruction rather
 * than browsed to, and a menu entry for a page that does nothing on its own
 * would be a menu entry nobody can explain.
 */
function register_page(): void
{
    add_submenu_page(
        parent_slug: '',
        page_title: __('GoBuildWP Visual Runtime', domain: 'wppilot'),
        menu_title: '',
        capability: 'edit_posts',
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

/**
 * Whether this request is the runtime screen.
 */
function is_runtime_request(): bool
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only: compares the admin page slug; nothing is changed.
    return is_admin() && isset($_GET['page']) && is_string($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === PAGE_SLUG;
}

/**
 * The runtime screen's URL.
 */
function page_url(): string
{
    return add_query_arg(['page' => PAGE_SLUG], admin_url('admin.php'));
}

/**
 * Render the screen.
 */
function render_page(): void
{
    if (!current_user_can('edit_posts')) {
        wp_die(esc_html__('You cannot capture pages on this site.', domain: 'wppilot'));
    }

    echo '<div class="wrap"><h1>' . esc_html__('GoBuildWP Visual Runtime', domain: 'wppilot') . '</h1>';
    echo '<p>' . esc_html__(
        'Leave this tab open. It takes the screenshots an agent asked for, one page at a time, by loading each page and rendering what it sees. Closing the tab stops the captures; nothing else on the site is affected.',
        domain: 'wppilot',
    ) . '</p>';
    echo '<p><strong>' . esc_html__('Status:', domain: 'wppilot') . '</strong> <span id="wppilot-visual-status">'
        . esc_html__('starting…', domain: 'wppilot') . '</span></p>';
    echo '<ol id="wppilot-visual-log"></ol>';
    // The iframe each page is loaded into. Off to the side rather than hidden:
    // a display:none iframe does not lay out, so nothing would render in it.
    echo '<div style="position:fixed;left:-10000px;top:0;width:1px;height:1px;overflow:hidden;">'
        . '<iframe id="wppilot-visual-frame" referrerpolicy="same-origin"></iframe></div>';
    echo '</div>';
}

/**
 * Load the runtime on its own screen only.
 */
function enqueue_assets(string $hook_suffix): void
{
    unset($hook_suffix);

    if (!is_runtime_request()) {
        return;
    }

    // phpcs:disable WordPress.WP.EnqueuedResourceParameters.NotInFooter -- `args: true` IS the in_footer flag.
    wp_register_script(
        handle: 'wppilot-visual-runtime',
        src: WPPILOT_PLUGIN_URL . 'includes/assets/visual-runtime.js',
        deps: ['wp-api-fetch'],
        ver: defined('WPPILOT_VERSION') ? WPPILOT_VERSION : '1',
        args: true,
    );
    // phpcs:enable WordPress.WP.EnqueuedResourceParameters.NotInFooter

    $config = wp_json_encode(['nonce' => wp_create_nonce('wp_rest')]);
    if (is_string($config)) {
        wp_add_inline_script('wppilot-visual-runtime', 'window.wppilotVisualRuntime = ' . $config . ';', 'before');
    }
    wp_enqueue_script('wppilot-visual-runtime');
}

/**
 * The routes the tab talks to.
 *
 * All three require a logged-in user who can edit posts: the tab is captured
 * content from pages that user can already see, and an unauthenticated capture
 * endpoint would be a way to have the site render private pages for a stranger.
 */
function register_routes(): void
{
    // Completing a job stores an upload and can prune older captures, so the
    // runtime needs upload rights as well as editing rights; per-job checks
    // below also require edit access to the post the job is about.
    $permission = static fn(): bool => current_user_can('edit_posts') && current_user_can('upload_files');

    register_rest_route('wppilot/v1', '/visual/claim', [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\rest_claim',
        'permission_callback' => $permission,
    ]);

    register_rest_route('wppilot/v1', '/visual/complete', [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\rest_complete',
        'permission_callback' => $permission,
    ]);

    register_rest_route('wppilot/v1', '/visual/fail', [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\rest_fail',
        'permission_callback' => $permission,
    ]);
}

/**
 * Every job, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function jobs(): array
{
    /** @var mixed $stored */
    $stored = get_option(JOBS_OPTION, []);

    return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
}

/**
 * Replace the job list.
 *
 * @param list<array<string, mixed>> $jobs
 */
function save_jobs(array $jobs): void
{
    update_option(JOBS_OPTION, array_slice($jobs, -MAX_JOBS), autoload: false);
}

/**
 * Queue a capture.
 *
 * @param list<int> $viewports
 * @return array<string, mixed>
 */
function enqueue_job(int $post_id, string $url, array $viewports, string $label): array
{
    $job = [
        'id' => wp_generate_uuid4(),
        'post_id' => $post_id,
        'url' => $url,
        'viewports' => array_values(array_unique(array_map('intval', $viewports))),
        'label' => $label,
        'status' => 'queued',
        'claimed_at' => 0,
        'created_at' => time(),
        'captures' => [],
        'errors' => [],
    ];

    $jobs = jobs();
    $jobs[] = $job;
    save_jobs($jobs);

    return $job;
}

/**
 * One job by id.
 *
 * @return array<string, mixed>|null
 */
function job(string $id): ?array
{
    foreach (jobs() as $job) {
        if (($job['id'] ?? '') === $id) {
            return $job;
        }
    }

    return null;
}

/**
 * Write one job back.
 *
 * @param array<string, mixed> $updated
 */
function update_job(array $updated): void
{
    $jobs = jobs();
    foreach ($jobs as $index => $job) {
        if (($job['id'] ?? '') === ($updated['id'] ?? '')) {
            $jobs[$index] = $updated;
            save_jobs($jobs);

            return;
        }
    }
}

/**
 * Whether a runtime tab is open right now.
 *
 * @return array<string, mixed>
 */
function runtime_status(): array
{
    /** @var mixed $seen */
    $seen = get_transient(HEARTBEAT_TRANSIENT);
    $last = is_numeric($seen) ? (int) $seen : 0;
    $online = $last > 0 && (time() - $last) < HEARTBEAT_STALE_SECONDS;

    return [
        'online' => $online,
        'last_seen_at' => $last > 0 ? gmdate('c', $last) : null,
        'page_url' => page_url(),
        'instruction' => $online
            ? __('A capture tab is open and will pick this up within a few seconds.', domain: 'wppilot')
            : __(
                'No capture tab is open, so this job will sit queued. Ask the person you are working with to open the Visual Runtime page in wp-admin and leave it open; the URL is in page_url.',
                domain: 'wppilot',
            ),
    ];
}

/**
 * Hand the tab the next job, and record that a tab is alive.
 *
 * @return array<string, mixed>
 */
function rest_claim(WP_REST_Request $request): array
{
    unset($request);

    set_transient(HEARTBEAT_TRANSIENT, time(), HEARTBEAT_STALE_SECONDS * 4);

    $now = time();
    foreach (jobs() as $job) {
        $status = (string) ($job['status'] ?? '');
        $claimed = (int) ($job['claimed_at'] ?? 0);
        if (!job_is_mine_to_run($job)) {
            continue;
        }

        // A job claimed by a tab that then went away - closed, navigated off,
        // throttled by the browser - would otherwise stay "running" forever.
        $stale = $status === 'running' && ($now - $claimed) > CLAIM_SECONDS;
        if ($status !== 'queued' && !$stale) {
            continue;
        }

        $job['status'] = 'running';
        $job['claimed_at'] = $now;
        update_job($job);

        return ['job' => $job, 'viewports' => $job['viewports']];
    }

    return ['job' => null];
}

/**
 * Whether the current user may work this job: edit rights on the post it
 * captures, when it captures a post.
 *
 * @param array<string, mixed> $job
 */
function job_is_mine_to_run(array $job): bool
{
    $post_id = (int) ($job['post_id'] ?? 0);

    return $post_id <= 0 || current_user_can('edit_post', $post_id);
}

/**
 * Store one rasterised viewport.
 *
 * @return array<string, mixed>|WP_Error
 */
function rest_complete(WP_REST_Request $request): array|WP_Error
{
    $id = (string) $request->get_param('job_id');
    $job = job($id);
    if ($job === null) {
        return new WP_Error('wppilot_visual_no_job', __('No such capture job.', domain: 'wppilot'), ['status' => 404]);
    }
    if (!job_is_mine_to_run($job) || (string) ($job['status'] ?? '') !== 'running') {
        return new WP_Error('wppilot_visual_not_running', __('That capture job is not running for you.', domain: 'wppilot'), ['status' => 403]);
    }

    $viewport = (int) $request->get_param('viewport');
    $image = (string) $request->get_param('image');

    $attachment_id = store_png($image, $job, $viewport);
    if ($attachment_id instanceof WP_Error) {
        return $attachment_id;
    }

    /** @var list<string> $notes */
    $notes = (array) ($request->get_param('notes') ?? []);

    Capture\record($attachment_id, [
        'post_id' => (int) ($job['post_id'] ?? 0),
        'url' => (string) ($job['url'] ?? ''),
        'viewport' => $viewport,
        'label' => (string) ($job['label'] ?? ''),
        'job_id' => $id,
    ], owned: true);

    $job['captures'][] = ['viewport' => $viewport, 'attachment_id' => $attachment_id, 'notes' => $notes];
    if (count($job['captures']) >= count($job['viewports'])) {
        $job['status'] = 'done';
    }
    update_job($job);

    return ['attachment_id' => $attachment_id, 'status' => $job['status']];
}

/**
 * Record that a job could not be captured.
 *
 * @return array<string, mixed>|WP_Error
 */
function rest_fail(WP_REST_Request $request): array|WP_Error
{
    $id = (string) $request->get_param('job_id');
    $job = job($id);
    if ($job === null) {
        return new WP_Error('wppilot_visual_no_job', __('No such capture job.', domain: 'wppilot'), ['status' => 404]);
    }
    if (!job_is_mine_to_run($job)) {
        return new WP_Error('wppilot_visual_not_running', __('That capture job is not running for you.', domain: 'wppilot'), ['status' => 403]);
    }

    $job['status'] = 'failed';
    $job['errors'][] = (string) $request->get_param('message');
    update_job($job);

    return ['status' => 'failed'];
}

/**
 * Turn a data URL into an attachment.
 *
 * @param array<string, mixed> $job
 * @return int|WP_Error
 */
function store_png(string $data_url, array $job, int $viewport): int|WP_Error
{
    if (preg_match('#\Adata:image/png;base64,#', $data_url) !== 1) {
        return new WP_Error(
            'wppilot_visual_bad_image',
            __('The capture was not a PNG data URL.', domain: 'wppilot'),
            ['status' => 422],
        );
    }

    $binary = base64_decode(substr($data_url, strlen('data:image/png;base64,')), strict: true);
    if ($binary === false || $binary === '') {
        return new WP_Error(
            'wppilot_visual_bad_image',
            __('The capture could not be decoded.', domain: 'wppilot'),
            ['status' => 422],
        );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $name = sprintf('wppilot-capture-%d-%dpx-%s.png', (int) ($job['post_id'] ?? 0), $viewport, substr((string) $job['id'], 0, 8));
    $upload = wp_upload_bits($name, null, $binary);
    if (!is_array($upload) || ($upload['error'] ?? false) !== false) {
        return new WP_Error(
            'wppilot_visual_upload_failed',
            is_array($upload) ? (string) $upload['error'] : __('The capture could not be written to uploads.', domain: 'wppilot'),
            ['status' => 500],
        );
    }

    $attachment_id = wp_insert_attachment([
        'post_mime_type' => 'image/png',
        'post_title' => $name,
        'post_status' => 'inherit',
    ], $upload['file']);

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }

    $attachment_id = (int) $attachment_id;
    wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));

    return $attachment_id;
}
