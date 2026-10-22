<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Admin\Routines;

use WPPilot\Kits;
use WPPilot\Kits\ScheduledAudits;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * GoBuildWP's side of the scheduled-audits kit: who owns routines on this site, and the Routines
 * screen.
 *
 * Scheduled audits came from GoBuildWP Pro 1.10.0's routines kit, which keeps running its own copy
 * over the same options and cron hooks until Pro's next release. That release boots its copy
 * early, from a `plugins_loaded` 25 hook it adds while its own file loads, so by the time the kit
 * loader boots kits (`plugins_loaded` 20) the hook tells us Pro will run routines on this request.
 * Two copies would both answer every tick — each audit run twice, each email sent twice — and
 * show two Routines screens, so the kit stands aside while that hook is there and this file
 * registers nothing else.
 *
 * The screen lives here rather than in the kit because it joins GoBuildWP's admin shell (its
 * header, its navigation map), which a kit may not name.
 */

const PAGE_SLUG = 'wppilot-routines';

/** Pro 1.10.0's screen, which links in emails already sent still point at. */
const LEGACY_PAGE_SLUG = 'wppilot-pro-routines';

const RUN_NOW_ACTION = 'wppilot_routines_run_now';

/**
 * Answer the kit's `routines-elsewhere` extension point: why this site's routines run elsewhere,
 * or the value unchanged when they do not.
 *
 * @param mixed $value
 * @return mixed
 */
function routines_elsewhere(mixed $value, mixed $point): mixed
{
    if ($point !== 'routines-elsewhere') {
        return $value;
    }
    if (has_action('plugins_loaded', 'WPPilot\\Pro\\Kits\\register_routines') !== false) {
        return 'GoBuildWP Pro runs this site\'s routines with its own routines kit; the scheduled audits here take over once Pro no longer carries it.';
    }
    return $value;
}

/** Whether this copy runs routines on this request: the kit loaded rather than standing aside. */
function active(): bool
{
    return in_array('scheduled-audits', Kits\report()['loaded'], true)
        && function_exists('WPPilot\\Kits\\ScheduledAudits\\queue_run_now');
}

/** After the kit loader (plugins_loaded 20) has decided. */
function register(): void
{
    if (!active()) {
        return;
    }
    add_filter('wppilot_kit_routines_report_url', __NAMESPACE__ . '\\report_url', 10, 2);
    if (is_admin()) {
        add_action('admin_menu', __NAMESPACE__ . '\\register_menu', priority: 37);
        add_filter('wppilot_nav_map', __NAMESPACE__ . '\\register_nav');
        add_action('admin_post_' . RUN_NOW_ACTION, __NAMESPACE__ . '\\handle_run_now');
        add_action('admin_page_access_denied', __NAMESPACE__ . '\\redirect_legacy_page');
    }
}

/**
 * @param mixed $url
 */
function report_url(mixed $url, mixed $id): string
{
    return admin_url('admin.php?page=' . PAGE_SLUG . '&routine=' . rawurlencode((string) $id));
}

function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'wppilot-connect',
        page_title: \wppilot_nav_label(PAGE_SLUG, fallback: __('Routines', domain: 'wppilot')),
        menu_title: \wppilot_nav_label(PAGE_SLUG, fallback: __('Routines', domain: 'wppilot')),
        capability: \wppilot_manage_capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render',
    );
}

/**
 * @param mixed $map
 * @return mixed
 */
function register_nav(mixed $map): mixed
{
    if (!is_array($map)) {
        return $map;
    }
    $map[PAGE_SLUG] = ['label' => __('Routines', domain: 'wppilot'), 'group' => 'activity'];
    return $map;
}

/**
 * Report links in emails Pro 1.10.0 sent name its page, which no longer exists once Free runs
 * the routines. WordPress refuses an unknown page before admin_init, so this runs on the refusal.
 */
function redirect_legacy_page(): void
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only: an old bookmarked page slug is redirected to the current page; nothing is changed.
    $page = sanitize_key((string) wp_unslash($_GET['page'] ?? ''));
    if ($page !== LEGACY_PAGE_SLUG || !\wppilot_current_user_can_manage()) {
        return;
    }
    $args = ['page' => PAGE_SLUG];
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Carried into the redirect only, to keep the same routine selected.
    $routine = sanitize_key((string) wp_unslash($_GET['routine'] ?? ''));
    if ($routine !== '') {
        $args['routine'] = $routine;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit();
}

function handle_run_now(): void
{
    if (!\wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to run GoBuildWP routines.', domain: 'wppilot'), '', ['response' => 403]);
    }
    $id = sanitize_key((string) wp_unslash($_POST['routine'] ?? ''));
    check_admin_referer(RUN_NOW_ACTION . '_' . $id);

    $queued = ScheduledAudits\queue_run_now($id);
    $args = ['page' => PAGE_SLUG, 'routine' => $id];
    if ($queued instanceof \WP_Error) {
        $args['routines_error'] = $queued->get_error_code();
    } else {
        $args['routines_queued'] = '1';
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit();
}

/**
 * The message for a refused run-now, by error code; the code is all the redirect carries.
 */
function error_message(string $code): string
{
    return match ($code) {
        'kit_routines_running' => __('A run of this routine is already in progress.', domain: 'wppilot'),
        'kit_routines_already_queued' => __('A run of this routine is already queued.', domain: 'wppilot'),
        'kit_routines_cooldown' => __('This routine was run by hand less than 10 minutes ago. Try again later.', domain: 'wppilot'),
        'kit_routines_not_found' => __('That routine no longer exists.', domain: 'wppilot'),
        default => __('The run could not be queued.', domain: 'wppilot'),
    };
}

function render(): void
{
    if (!\wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to view GoBuildWP routines.', domain: 'wppilot'));
    }
    $routines = ScheduledAudits\all_routines();
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only: the selected routine and the outcome codes from this page's own run-now redirect; running a routine is the nonce-checked POST in handle_run_now().
    $selected = sanitize_key((string) wp_unslash($_GET['routine'] ?? ''));
    $queued = isset($_GET['routines_queued']);
    $error = sanitize_key((string) wp_unslash($_GET['routines_error'] ?? ''));
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    \wppilot_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(\wppilot_nav_label(PAGE_SLUG, fallback: __('Routines', domain: 'wppilot'))); ?></h1>
        <p class="wppilot-lede"><?php esc_html_e(
            'Audits this site runs by itself on a schedule, with no agent connected. They only read the site. An agent creates and edits them with wppilot/routines-save.',
            domain: 'wppilot',
        ); ?></p>
        <?php if ($queued) { ?>
            <div class="notice notice-success"><p><?php esc_html_e('Run queued. It starts on the next WP-Cron tick; reload this page to see it finish.', domain: 'wppilot'); ?></p></div>
        <?php } ?>
        <?php if ($error !== '') { ?>
            <div class="notice notice-error"><p><?php echo esc_html(error_message($error)); ?></p></div>
        <?php } ?>

        <?php if ($routines === []) { ?>
            <p><?php esc_html_e('No routines yet. Ask your agent to set one up, for example a weekly accessibility and content audit.', domain: 'wppilot'); ?></p>
        <?php } else { ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Routine', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Audits', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Schedule', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Next run', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Last result', domain: 'wppilot'); ?></th>
                        <th><span class="screen-reader-text"><?php esc_html_e('Actions', domain: 'wppilot'); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($routines as $id => $routine) {
                    $view = ScheduledAudits\view_routine((string) $id, $routine);
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url(report_url(null, (string) $id)); ?>"><?php echo esc_html($view['label']); ?></a></strong>
                            <?php if ($view['enabled'] !== true) { ?><br><em><?php esc_html_e('Paused', domain: 'wppilot'); ?></em><?php } ?>
                        </td>
                        <td><?php echo esc_html(implode(', ', array_map(
                            static fn(array $audit): string => ScheduledAudits\step_label(ScheduledAudits\AUDITS[$audit['ability']] ?? '', $audit),
                            $view['audits'],
                        ))); ?></td>
                        <td><?php echo esc_html($view['schedule']['description']); ?></td>
                        <td><?php echo esc_html(is_array($view['next_run']) ? $view['next_run']['local'] : '—'); ?></td>
                        <td><?php echo esc_html(last_line($view)); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr(RUN_NOW_ACTION); ?>">
                                <input type="hidden" name="routine" value="<?php echo esc_attr((string) $id); ?>">
                                <?php wp_nonce_field(RUN_NOW_ACTION . '_' . (string) $id); ?>
                                <button type="submit" class="button"<?php disabled($view['running'] !== null || $view['run_now_queued']); ?>><?php esc_html_e('Run now', domain: 'wppilot'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>

        <?php if ($selected !== '' && isset($routines[$selected])) {
            render_report($selected);
        } ?>
    </div>
    <?php
}

/**
 * @param array<string, mixed> $view
 */
function last_line(array $view): string
{
    if ($view['running'] !== null) {
        return __('Running now', domain: 'wppilot');
    }
    if ($view['run_now_queued'] === true) {
        return __('Queued', domain: 'wppilot');
    }
    $last = $view['last_run'];
    if (!is_array($last)) {
        return __('Not run yet', domain: 'wppilot');
    }
    $line = sprintf(
        /* translators: 1: run status, 2: time, 3: number of open issues */
        __('%1$s %2$s: %3$d open', domain: 'wppilot'),
        ucfirst((string) $last['status']),
        (string) ($last['finished']['local'] ?? ''),
        (int) ($last['totals']['issues'] ?? 0),
    );
    if (($last['new'] ?? null) !== null) {
        $line .= sprintf(
            /* translators: 1: new issues, 2: resolved issues */
            __(', %1$d new, %2$d resolved', domain: 'wppilot'),
            (int) $last['new'],
            (int) $last['resolved'],
        );
    }
    return $line;
}

function render_report(string $id): void
{
    $report = ScheduledAudits\report_view($id, 5, false);
    if ($report instanceof \WP_Error) {
        return;
    }
    ?>
    <h2><?php echo esc_html(sprintf(
        /* translators: %s: routine label */
        __('Recent runs of %s', domain: 'wppilot'),
        $report['label'],
    )); ?></h2>
    <?php if ($report['runs'] === []) { ?>
        <p><?php esc_html_e('No runs yet.', domain: 'wppilot'); ?></p>
    <?php } ?>
    <?php foreach ($report['runs'] as $run) { ?>
        <div class="card" style="max-width:none">
            <h3><?php echo esc_html(sprintf(
                '%s — %s (%s)',
                (string) ($run['finished']['local'] ?? ''),
                ucfirst((string) $run['status']),
                $run['trigger'] === 'manual' ? __('run on request', domain: 'wppilot') : __('scheduled', domain: 'wppilot'),
            )); ?></h3>
            <ul>
                <?php foreach ($run['audits'] as $step) { ?>
                    <li><strong><?php echo esc_html((string) $step['label']); ?>:</strong> <?php echo esc_html(ScheduledAudits\step_line($step)); ?></li>
                <?php } ?>
            </ul>
            <?php if (is_array($run['diff'])) {
                $diff = $run['diff'];
                ?>
                <p><?php echo esc_html(sprintf(
                    /* translators: 1: new, 2: resolved, 3: changed */
                    __('Since the run before: %1$d new, %2$d resolved, %3$d changed in count.', domain: 'wppilot'),
                    (int) $diff['new_count'],
                    (int) $diff['resolved_count'],
                    (int) $diff['changed_count'],
                )); ?><?php if ($diff['approximate'] === true) { ?> <em><?php esc_html_e('(approximate)', domain: 'wppilot'); ?></em><?php } ?></p>
                <?php foreach (['new' => __('New', domain: 'wppilot'), 'resolved' => __('Resolved', domain: 'wppilot')] as $key => $heading) {
                    if ($diff[$key] === []) {
                        continue;
                    }
                    ?>
                    <p><strong><?php echo esc_html($heading); ?></strong></p>
                    <ul>
                        <?php foreach ($diff[$key] as $issue) { ?>
                            <li><?php echo esc_html(sprintf('%s — %s (%s) at %s', (string) $issue['audit'], (string) $issue['what'], (string) $issue['severity'], (string) $issue['where'])); ?></li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            <?php } else { ?>
                <p><?php esc_html_e('First run: the next one will be compared with it.', domain: 'wppilot'); ?></p>
            <?php } ?>
        </div>
    <?php } ?>
    <?php
}

// Answered before the kit loader boots kits (plugins_loaded 20), on every request: a WP-Cron
// request must stand the kit aside too, or it would tick alongside Pro's copy.
add_filter('wppilot_kit_extension', __NAMESPACE__ . '\\routines_elsewhere', 10, 2);
add_action('plugins_loaded', __NAMESPACE__ . '\\register', 21);
