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

/** @var array<string, mixed> $entry */
$id = (string) ($entry['id'] ?? '');
$status = \wppilot_change_status($entry);
$rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
$group = (string) ($entry['group'] ?? '');
$recorded = strtotime((string) ($entry['recorded_at'] ?? ''));
$is_audit = ($entry['kind'] ?? 'change') === 'audit-read';
$group_size = $group !== '' ? \wppilot_count_change_log(['group' => $group]) : 0;
$group_undoable = $group !== '' ? \wppilot_count_change_log(['group' => $group, 'status' => 'undoable']) : 0;
$pretty = static fn(mixed $value): string => (string) wp_json_encode(
    $value,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
);

?>
<?php \wppilot_render_admin_header(); ?>
<div class="wrap wppilot-wrap wppilot-changes">
    <h1><?php esc_html_e('Change details', domain: 'wppilot'); ?></h1>

    <p><a href="<?php echo esc_url(list_url()); ?>">&larr; <?php esc_html_e('All changes', domain: 'wppilot'); ?></a></p>

    <div class="wppilot-panel">
        <h2><code><?php echo esc_html((string) ($entry['ability'] ?? '')); ?></code></h2>

        <table class="form-table wppilot-changes__facts" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('When', domain: 'wppilot'); ?></th>
                <td><?php echo esc_html($recorded !== false ? wp_date(\wppilot_get_datetime_format(), $recorded) : ''); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('By', domain: 'wppilot'); ?></th>
                <td><?php echo esc_html(actor_label($entry)); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Risk', domain: 'wppilot'); ?></th>
                <td><?php echo esc_html((string) ($entry['risk'] ?? '')); ?></td>
            </tr>
            <?php
            $confirmation = is_array($entry['confirmation'] ?? null) ? $entry['confirmation'] : [];
            $confirmed_via = (string) ($confirmation['method'] ?? '');
            if ($confirmed_via !== '' && $confirmed_via !== 'not-required') {
                $approver = (int) ($confirmation['approved_by'] ?? 0);
                ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Confirmed by', domain: 'wppilot'); ?></th>
                    <td><?php echo esc_html(match ($confirmed_via) {
                        'argument' => __('The agent (confirm=true)', domain: 'wppilot'),
                        'elicitation' => __('The user, in their MCP client', domain: 'wppilot'),
                        'approval-url' => $approver > 0
                            /* translators: %d: WordPress user id */
                            ? sprintf(__('A person, on the wp-admin approval link (user #%d)', domain: 'wppilot'), $approver)
                            : __('A person, on the wp-admin approval link', domain: 'wppilot'),
                        'chat' => __('A person, in GoBuildWP Chat', domain: 'wppilot'),
                        'approval-queue' => __('A person, in the approval queue', domain: 'wppilot'),
                        default => $confirmed_via,
                    }); ?></td>
                </tr>
            <?php } ?>
            <tr>
                <th scope="row"><?php esc_html_e('Status', domain: 'wppilot'); ?></th>
                <td>
                    <?php if ($is_audit) {
                        esc_html_e('A read, recorded for audit. Nothing was changed.', domain: 'wppilot');
                    } else { ?>
                        <span class="wppilot-changes__status wppilot-changes__status--<?php echo esc_attr($status); ?>"><?php echo esc_html(status_label($status)); ?></span>
                        <?php if ($status === 'not-reversible' && (string) ($rollback['reason'] ?? '') !== '') { ?>
                            <p class="description"><?php echo esc_html((string) $rollback['reason']); ?></p>
                        <?php } ?>
                        <?php if ($status === 'rolled-back') { ?>
                            <p class="description"><?php echo esc_html((string) ($entry['rolled_back_at'] ?? '')); ?></p>
                        <?php } ?>
                    <?php } ?>
                </td>
            </tr>
            <?php $session = (string) ($entry['session'] ?? ''); ?>
            <?php if ($session !== '') { ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Agent session', domain: 'wppilot'); ?></th>
                    <td>
                        <a href="<?php echo esc_url(list_url(['session' => $session])); ?>"><code><?php echo esc_html($session); ?></code></a>
                        <p class="description"><?php esc_html_e('Every change this agent run made, with Undo session and Redo session.', domain: 'wppilot'); ?></p>
                    </td>
                </tr>
            <?php } ?>
            <?php if ($group !== '' && $group_size > 1) { ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Batch', domain: 'wppilot'); ?></th>
                    <td>
                        <a href="<?php echo esc_url(list_url(['group' => $group])); ?>">
                            <?php echo esc_html(sprintf(
                                /* translators: %d: number of changes in the batch */
                                __('One of %d changes made by the same bulk call', domain: 'wppilot'),
                                $group_size,
                            )); ?>
                        </a>
                    </td>
                </tr>
            <?php } ?>
        </table>

        <div class="wppilot-changes__detail-actions">
            <?php if ($status === 'undoable' && !$is_audit) { ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php esc_attr_e('Undo this change?', domain: 'wppilot'); ?>">
                    <input type="hidden" name="action" value="wppilot_changes_undo">
                    <input type="hidden" name="change_id" value="<?php echo esc_attr($id); ?>">
                    <?php wp_nonce_field('wppilot_changes_undo'); ?>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Undo this change', domain: 'wppilot'); ?></button>
                </form>
            <?php } ?>
            <?php if ($group_undoable > 1) { ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php echo esc_attr(sprintf(
                    /* translators: %d: number of changes */
                    __('Undo all %d changes in this batch that can still be undone?', domain: 'wppilot'),
                    $group_undoable,
                )); ?>">
                    <input type="hidden" name="action" value="wppilot_changes_undo_group">
                    <input type="hidden" name="group" value="<?php echo esc_attr($group); ?>">
                    <?php wp_nonce_field('wppilot_changes_undo_group'); ?>
                    <button type="submit" class="button"><?php echo esc_html(sprintf(
                        /* translators: %d: number of changes */
                        __('Undo the whole batch (%d)', domain: 'wppilot'),
                        $group_undoable,
                    )); ?></button>
                </form>
            <?php } ?>
        </div>
    </div>

    <div class="wppilot-panel">
        <h2><?php esc_html_e('Input', domain: 'wppilot'); ?></h2>
        <p class="description"><?php esc_html_e('As the agent sent it, with secrets redacted and email addresses masked.', domain: 'wppilot'); ?></p>
        <pre class="wppilot-changes__json"><?php echo esc_html($pretty($entry['input'] ?? [])); ?></pre>

        <h2><?php esc_html_e('Result', domain: 'wppilot'); ?></h2>
        <pre class="wppilot-changes__json"><?php echo esc_html($pretty($entry['result'] ?? [])); ?></pre>

        <?php if (is_array($entry['design'] ?? null) && $entry['design'] !== []) { ?>
            <h2><?php esc_html_e('Design check', domain: 'wppilot'); ?></h2>
            <pre class="wppilot-changes__json"><?php echo esc_html($pretty($entry['design'])); ?></pre>
        <?php } ?>

        <?php if (is_array($entry['rollback_result'] ?? null)) { ?>
            <h2><?php esc_html_e('Undo verification', domain: 'wppilot'); ?></h2>
            <pre class="wppilot-changes__json"><?php echo esc_html($pretty($entry['rollback_result'])); ?></pre>
        <?php } ?>
    </div>
</div>
