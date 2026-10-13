<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Admin\Changes;

if (!defined('ABSPATH')) {
    exit();
}

if (!current_user_can_manage()) {
    wp_die(esc_html__('Not allowed.', domain: 'wppilot'), title: '', args: ['response' => 403]);
}

/** @var array<string, string|int> $filters */
// Counted and paged by the ledger itself: the table can hold ten thousand rows with their
// before-images, and only one page of them is ever shown.
$total = \wppilot_count_change_log($filters);
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination of a read.
$paged = max(1, (int) ($_GET['paged'] ?? 1));
$pages = max(1, (int) ceil($total / PER_PAGE));
$paged = min($paged, $pages);
$visible = \wppilot_query_change_log($filters, PER_PAGE, ($paged - 1) * PER_PAGE);
$status_filter = (string) ($filters['status'] ?? '');
$undoable = match ($status_filter) {
    '' => \wppilot_count_change_log(array_merge($filters, ['status' => 'undoable'])),
    'undoable' => $total,
    default => 0,
};

// How many rows each group has in the whole log, so a row can say "1 of a batch of 40".
$group_sizes = \wppilot_change_group_sizes(array_values(array_unique(array_map(
    static fn(array $row): string => (string) ($row['group'] ?? ''),
    $visible,
))));
$datetime_format = \wppilot_get_datetime_format();
$active_group = (string) ($filters['group'] ?? '');
$active_session = (string) ($filters['session'] ?? '');

?>
<?php \wppilot_render_admin_header(); ?>
<div class="wrap wppilot-wrap wppilot-changes">
    <h1><?php echo esc_html(\wppilot_nav_label(PAGE_SLUG, fallback: __('Changes', domain: 'wppilot'))); ?></h1>
    <p class="wppilot-lede"><?php esc_html_e('Everything an agent changed through GoBuildWP, newest first, with the way back where one exists.', domain: 'wppilot'); ?></p>

    <div class="wppilot-panel">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="wppilot-changes__filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(PAGE_SLUG); ?>">
            <?php if ($active_group !== '') { ?>
                <input type="hidden" name="group" value="<?php echo esc_attr($active_group); ?>">
            <?php } ?>
            <?php if ($active_session !== '') { ?>
                <input type="hidden" name="session" value="<?php echo esc_attr($active_session); ?>">
            <?php } ?>
            <label>
                <span><?php esc_html_e('Ability', domain: 'wppilot'); ?></span>
                <input type="search" name="ability" placeholder="wppilot/update-" value="<?php echo esc_attr((string) ($filters['ability'] ?? '')); ?>">
            </label>
            <label>
                <span><?php esc_html_e('Agent', domain: 'wppilot'); ?></span>
                <input type="search" name="agent" list="wppilot-changes-agents" placeholder="<?php esc_attr_e('Connection or client name', domain: 'wppilot'); ?>" value="<?php echo esc_attr((string) ($filters['agent'] ?? '')); ?>">
                <?php // Every credential that has reached the MCP endpoint, access tokens included, so "what did the SEO token do" is a pick, not a guess at its name. ?>
                <datalist id="wppilot-changes-agents">
                    <?php foreach (agent_filter_options() as $credential => $label) { ?>
                        <option value="<?php echo esc_attr($credential); ?>"><?php echo esc_html($label); ?></option>
                    <?php } ?>
                </datalist>
            </label>
            <label>
                <span><?php esc_html_e('From', domain: 'wppilot'); ?></span>
                <input type="date" name="since" value="<?php echo esc_attr((string) ($filters['since'] ?? '')); ?>">
            </label>
            <label>
                <span><?php esc_html_e('To', domain: 'wppilot'); ?></span>
                <input type="date" name="until" value="<?php echo esc_attr((string) ($filters['until'] ?? '')); ?>">
            </label>
            <label>
                <span><?php esc_html_e('Status', domain: 'wppilot'); ?></span>
                <select name="status">
                    <option value=""><?php esc_html_e('Any', domain: 'wppilot'); ?></option>
                    <?php foreach (['undoable', 'rolled-back', 'not-reversible'] as $status) { ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php selected((string) ($filters['status'] ?? ''), $status); ?>><?php echo esc_html(status_label($status)); ?></option>
                    <?php } ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Kind', domain: 'wppilot'); ?></span>
                <select name="kind">
                    <option value=""><?php esc_html_e('Changes and audited reads', domain: 'wppilot'); ?></option>
                    <option value="change" <?php selected((string) ($filters['kind'] ?? ''), 'change'); ?>><?php esc_html_e('Changes only', domain: 'wppilot'); ?></option>
                    <option value="audit-read" <?php selected((string) ($filters['kind'] ?? ''), 'audit-read'); ?>><?php esc_html_e('Audited reads only', domain: 'wppilot'); ?></option>
                </select>
            </label>
            <span class="wppilot-changes__filter-actions">
                <button type="submit" class="button"><?php esc_html_e('Filter', domain: 'wppilot'); ?></button>
                <?php if ($filters !== []) { ?>
                    <a class="button-link" href="<?php echo esc_url(list_url()); ?>"><?php esc_html_e('Clear', domain: 'wppilot'); ?></a>
                <?php } ?>
            </span>
        </form>

        <?php if ($active_group !== '') { ?>
            <p class="wppilot-changes__scope">
                <?php echo esc_html(sprintf(
                    /* translators: %d: number of changes in the batch */
                    _n('Showing one bulk call: %d change.', 'Showing one bulk call: %d changes.', $total, 'wppilot'),
                    $total,
                )); ?>
                <a href="<?php echo esc_url(list_url(array_diff_key($filters, ['group' => true]))); ?>"><?php esc_html_e('Show everything', domain: 'wppilot'); ?></a>
            </p>
        <?php } ?>

        <?php if ($active_session !== '') {
            $session_detail = \wppilot_session_detail($active_session, limit: 1);
            ?>
            <div class="wppilot-changes__session" id="wppilot-session-panel">
                <p class="wppilot-changes__scope">
                    <?php echo esc_html(sprintf(
                        /* translators: 1: session id, 2: number of changes */
                        _n('Showing one agent session (%1$s): %2$d change.', 'Showing one agent session (%1$s): %2$d changes.', $total, 'wppilot'),
                        $active_session,
                        $total,
                    )); ?>
                    <a href="<?php echo esc_url(list_url(array_diff_key($filters, ['session' => true]))); ?>"><?php esc_html_e('Show everything', domain: 'wppilot'); ?></a>
                </p>
                <?php if ($session_detail instanceof \WP_Error) { ?>
                    <p class="wppilot-muted"><?php echo esc_html($session_detail->get_error_message()); ?></p>
                <?php } else {
                    $session_undo = $session_detail['undo'];
                    $session_redo = $session_detail['redo'];
                    ?>
                    <ul class="wppilot-changes__session-facts">
                        <li><?php echo esc_html(sprintf(
                            /* translators: 1: changes that can be undone, 2: already undone, 3: cannot be undone */
                            __('%1$d can be undone, %2$d already undone, %3$d cannot be undone.', domain: 'wppilot'),
                            $session_undo['can_undo'],
                            $session_undo['already_undone'],
                            count($session_undo['not_reversible']),
                        )); ?></li>
                        <?php foreach (array_slice($session_undo['conflicts'], 0, 5) as $conflict) { ?>
                            <li class="wppilot-changes__session-conflict"><?php echo esc_html(sprintf(
                                /* translators: 1: ability, 2: target, 3: changed fields */
                                __('Conflict: %1$s on %2$s was changed afterwards (%3$s).', domain: 'wppilot'),
                                (string) $conflict['ability'],
                                (string) $conflict['target'],
                                implode(', ', array_map('strval', (array) ($conflict['changed'] ?? []))),
                            )); ?></li>
                        <?php } ?>
                        <?php foreach (array_slice($session_undo['not_reversible'], 0, 5) as $blocked) { ?>
                            <li><?php echo esc_html(sprintf(
                                /* translators: 1: ability, 2: reason */
                                __('Cannot be undone: %1$s. %2$s', domain: 'wppilot'),
                                (string) $blocked['ability'],
                                (string) ($blocked['reason'] ?? ''),
                            )); ?></li>
                        <?php } ?>
                    </ul>
                    <div class="wppilot-changes__toolbar">
                        <?php if ($session_undo['can_undo'] > 0) { ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php echo esc_attr(sprintf(
                                /* translators: %d: number of changes */
                                _n('Undo %d change of this session, newest first? Each is verified; the run stops at the first that fails and can be redone.', 'Undo all %d changes of this session, newest first? Each is verified; the run stops at the first that fails and can be redone.', $session_undo['can_undo'], 'wppilot'),
                                $session_undo['can_undo'],
                            )); ?>">
                                <input type="hidden" name="action" value="wppilot_changes_undo_session">
                                <input type="hidden" name="session" value="<?php echo esc_attr($active_session); ?>">
                                <?php wp_nonce_field('wppilot_changes_undo_session'); ?>
                                <?php if ($session_undo['not_reversible'] !== []) { ?>
                                    <label><input type="checkbox" name="allow_partial" value="1"> <?php esc_html_e('Undo the rest and leave the ones that cannot be undone', domain: 'wppilot'); ?></label>
                                <?php } ?>
                                <button type="submit" class="button button-primary"><?php esc_html_e('Undo session', domain: 'wppilot'); ?></button>
                            </form>
                        <?php } ?>
                        <?php if ($session_redo['can_redo'] > 0) { ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php echo esc_attr(sprintf(
                                /* translators: %d: number of changes */
                                _n('Redo %d undone change of this session?', 'Redo all %d undone changes of this session, oldest first?', $session_redo['can_redo'], 'wppilot'),
                                $session_redo['can_redo'],
                            )); ?>">
                                <input type="hidden" name="action" value="wppilot_changes_redo_session">
                                <input type="hidden" name="session" value="<?php echo esc_attr($active_session); ?>">
                                <?php wp_nonce_field('wppilot_changes_redo_session'); ?>
                                <?php if ($session_redo['not_redoable'] !== []) { ?>
                                    <label><input type="checkbox" name="allow_partial" value="1"> <?php esc_html_e('Redo the rest and leave the ones that cannot be redone', domain: 'wppilot'); ?></label>
                                <?php } ?>
                                <button type="submit" class="button"><?php esc_html_e('Redo session', domain: 'wppilot'); ?></button>
                            </form>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        <?php } elseif ($filters === []) {
            $recent_sessions = \wppilot_list_change_sessions(5);
            if ($recent_sessions !== []) { ?>
                <div class="wppilot-changes__sessions">
                    <h2><?php esc_html_e('Recent agent sessions', domain: 'wppilot'); ?></h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Session', domain: 'wppilot'); ?></th>
                                <th scope="col"><?php esc_html_e('Agent', domain: 'wppilot'); ?></th>
                                <th scope="col"><?php esc_html_e('Last change', domain: 'wppilot'); ?></th>
                                <th scope="col"><?php esc_html_e('Changes', domain: 'wppilot'); ?></th>
                                <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', domain: 'wppilot'); ?></span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_sessions as $recent) {
                                $last = strtotime((string) $recent['last_change_at']);
                                $who = \wppilot_client_display((string) $recent['agent']['label'], (string) $recent['agent']['client']);
                                ?>
                                <tr>
                                    <td><code title="<?php echo esc_attr((string) $recent['session_id']); ?>"><?php echo esc_html(session_label((string) $recent['session_id'])); ?></code></td>
                                    <td><?php echo esc_html($who !== '' ? $who : (string) $recent['user']['login']); ?></td>
                                    <td><?php echo esc_html($last !== false ? wp_date($datetime_format, $last) : ''); ?></td>
                                    <td><?php echo esc_html(sprintf(
                                        /* translators: 1: total changes, 2: can be undone, 3: undone */
                                        __('%1$d (%2$d can be undone, %3$d undone)', domain: 'wppilot'),
                                        (int) $recent['changes'],
                                        (int) $recent['undoable'],
                                        (int) $recent['undone'],
                                    )); ?></td>
                                    <td class="wppilot-changes__actions">
                                        <a class="button button-small" href="<?php echo esc_url(list_url(['session' => (string) $recent['session_id']])); ?>"><?php esc_html_e('Review and undo', domain: 'wppilot'); ?></a>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php }
        } ?>

        <div class="wppilot-changes__toolbar">
            <span class="wppilot-muted">
                <?php echo esc_html(sprintf(
                    /* translators: 1: number of matching rows, 2: how many of them can be undone */
                    __('%1$d matching, %2$d can be undone.', domain: 'wppilot'),
                    $total,
                    $undoable,
                )); ?>
            </span>
            <?php if ($undoable > 0 && $filters !== []) { ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php echo esc_attr(sprintf(
                    /* translators: %d: number of changes to undo */
                    _n('Undo %d change? Each is restored and verified; any that fail are listed afterwards.', 'Undo %d changes? Each is restored and verified; any that fail are listed afterwards.', $undoable, 'wppilot'),
                    $undoable,
                )); ?>">
                    <input type="hidden" name="action" value="wppilot_changes_undo_matching">
                    <?php wp_nonce_field('wppilot_changes_undo_matching'); ?>
                    <?php render_filter_fields(array_diff_key($filters, ['status' => true])); ?>
                    <button type="submit" class="button" <?php disabled($undoable > UNDO_MATCHING_MAX); ?>>
                        <?php echo esc_html(sprintf(
                            /* translators: %d: number of changes */
                            _n('Undo the %d change shown', 'Undo all %d changes shown', $undoable, 'wppilot'),
                            $undoable,
                        )); ?>
                    </button>
                </form>
            <?php } ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppilot-changes__export">
                <input type="hidden" name="action" value="wppilot_changes_export">
                <?php wp_nonce_field('wppilot_changes_export'); ?>
                <?php render_filter_fields($filters); ?>
                <button type="submit" name="format" value="csv" class="button"><?php esc_html_e('Download CSV', domain: 'wppilot'); ?></button>
                <button type="submit" name="format" value="json" class="button"><?php esc_html_e('Download JSON', domain: 'wppilot'); ?></button>
            </form>
        </div>

        <?php if ($visible === []) { ?>
            <p class="wppilot-muted">
                <?php echo esc_html($filters === []
                    ? __('No changes yet. Every write an agent makes through GoBuildWP is recorded here, with a way to undo it when GoBuildWP kept a before-image.', domain: 'wppilot')
                    : __('Nothing matches those filters.', domain: 'wppilot')); ?>
            </p>
        <?php } else { ?>
            <table class="widefat striped wppilot-changes__table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('When', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Ability', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('By', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', domain: 'wppilot'); ?></th>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', domain: 'wppilot'); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visible as $entry) {
                        $id = (string) ($entry['id'] ?? '');
                        $status = \wppilot_change_status($entry);
                        $group = (string) ($entry['group'] ?? '');
                        $recorded = strtotime((string) ($entry['recorded_at'] ?? ''));
                        $is_audit = ($entry['kind'] ?? 'change') === 'audit-read';
                        ?>
                        <tr>
                            <td>
                                <?php echo esc_html($recorded !== false ? wp_date($datetime_format, $recorded) : ''); ?>
                            </td>
                            <td>
                                <code><?php echo esc_html((string) ($entry['ability'] ?? '')); ?></code>
                                <?php if ($is_audit) { ?>
                                    <span class="wppilot-badge"><?php esc_html_e('Audited read', domain: 'wppilot'); ?></span>
                                <?php } ?>
                                <?php if ($group !== '' && $active_group === '' && ($group_sizes[$group] ?? 0) > 1) { ?>
                                    <a class="wppilot-changes__batch" href="<?php echo esc_url(list_url(['group' => $group])); ?>">
                                        <?php echo esc_html(sprintf(
                                            /* translators: %d: number of changes in the batch */
                                            __('batch of %d', domain: 'wppilot'),
                                            $group_sizes[$group],
                                        )); ?>
                                    </a>
                                <?php } ?>
                            </td>
                            <td>
                                <?php $credential = (string) (is_array($entry['agent'] ?? null) ? ($entry['agent']['credential'] ?? '') : ''); ?>
                                <?php if ($credential !== '') { ?>
                                    <a href="<?php echo esc_url(list_url(['agent' => $credential])); ?>"><?php echo esc_html(actor_label($entry)); ?></a>
                                <?php } else { ?>
                                    <?php echo esc_html(actor_label($entry)); ?>
                                <?php } ?>
                                <?php $row_session = (string) ($entry['session'] ?? ''); ?>
                                <?php if ($row_session !== '' && $active_session === '') { ?>
                                    <a class="wppilot-changes__batch" title="<?php echo esc_attr($row_session); ?>" href="<?php echo esc_url(list_url(['session' => $row_session])); ?>"><?php esc_html_e('session', domain: 'wppilot'); ?></a>
                                <?php } ?>
                            </td>
                            <td>
                                <span class="wppilot-changes__status wppilot-changes__status--<?php echo esc_attr($status); ?>">
                                    <?php echo esc_html($is_audit ? __('Read', domain: 'wppilot') : status_label($status)); ?>
                                </span>
                            </td>
                            <td class="wppilot-changes__actions">
                                <a class="button button-small" href="<?php echo esc_url(detail_url($id)); ?>"><?php esc_html_e('Details', domain: 'wppilot'); ?></a>
                                <?php if ($status === 'undoable') { ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php esc_attr_e('Undo this change?', domain: 'wppilot'); ?>">
                                        <input type="hidden" name="action" value="wppilot_changes_undo">
                                        <input type="hidden" name="change_id" value="<?php echo esc_attr($id); ?>">
                                        <?php wp_nonce_field('wppilot_changes_undo'); ?>
                                        <button type="submit" class="button button-small"><?php esc_html_e('Undo', domain: 'wppilot'); ?></button>
                                    </form>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>

            <?php if ($pages > 1) { ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php echo wp_kses_post((string) paginate_links([
                        'base' => add_query_arg('paged', '%#%', list_url($filters)),
                        'format' => '',
                        'current' => $paged,
                        'total' => $pages,
                    ])); ?>
                </div></div>
            <?php } ?>
        <?php } ?>
    </div>
</div>
