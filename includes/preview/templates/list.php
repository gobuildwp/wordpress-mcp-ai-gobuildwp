<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file is require()d inside render_page() in includes/preview/admin.php, so its variables are local to that function, not globals.

namespace WPPilot\Preview\Admin;

use WPPilot\Preview\Store;

if (!defined('ABSPATH')) {
    exit();
}

if (!current_user_can_manage()) {
    wp_die(esc_html__('Not allowed.', domain: 'wppilot'), title: '', args: ['response' => 403]);
}

$records = Store\all();
$pending = array_values(array_filter(
    $records,
    static fn(array $record): bool => ($record['status'] ?? '') === Store\STATUS_PENDING,
));
$resolved = array_values(array_filter(
    $records,
    static fn(array $record): bool => ($record['status'] ?? '') !== Store\STATUS_PENDING,
));

?>
<?php \wppilot_render_admin_header(); ?>
<div class="wrap wppilot-wrap">
    <h1><?php echo esc_html(\wppilot_nav_label(PAGE_SLUG, fallback: __('Preview', domain: 'wppilot'))); ?></h1>
    <p class="wppilot-lede"><?php esc_html_e('Changes an agent has proposed but not made. Nothing here is written until you apply it.', domain: 'wppilot'); ?></p>

    <div class="wppilot-panel">
        <h2><?php esc_html_e('Waiting for review', domain: 'wppilot'); ?></h2>

        <?php if ($pending === []) { ?>
            <p class="wppilot-muted">
                <?php esc_html_e('Nothing is waiting. When an agent calls GoBuildWP Preview before a write, the proposed change appears here with its diff, and nothing is written until you apply it.', domain: 'wppilot'); ?>
            </p>
        <?php } else { ?>
            <table class="widefat striped wppilot-preview-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Target', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Ability', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Changes', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Proposed by', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Age', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Review', domain: 'wppilot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $record) {
                        $id = (string) ($record['preview_id'] ?? '');
                        $target = is_array($record['target'] ?? null) ? $record['target'] : [];
                        $diff = is_array($record['diff'] ?? null) ? $record['diff'] : [];
                        $agent = is_array($record['agent'] ?? null) ? $record['agent'] : [];
                        $destroys = ($diff['destroys'] ?? false) === true;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html((string) ($target['label'] ?? '')); ?></strong>
                                <?php if ((int) ($target['id'] ?? 0) > 0) { ?>
                                    <span class="wppilot-muted">
                                        <?php echo esc_html(sprintf('#%d %s', (int) $target['id'], (string) ($target['post_type'] ?? ''))); ?>
                                    </span>
                                <?php } ?>
                            </td>
                            <td><code><?php echo esc_html((string) ($record['ability'] ?? '')); ?></code></td>
                            <td>
                                <?php
                                $count = (int) ($diff['changed_count'] ?? 0);
                                echo esc_html(sprintf(
                                    /* translators: %d: number of changed fields */
                                    _n('%d field', '%d fields', $count, 'wppilot'),
                                    $count,
                                ));
                                ?>
                                <?php if ($destroys) { ?>
                                    <span class="wppilot-badge wppilot-badge--destructive">
                                        <?php esc_html_e('Deletes data', domain: 'wppilot'); ?>
                                    </span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php
                                $client = trim((string) ($agent['label'] ?? ''));
                                echo esc_html($client !== '' ? $client : __('Unknown client', domain: 'wppilot'));
                                ?>
                                <span class="wppilot-muted">
                                    <?php echo esc_html((string) (is_array($record['created_by'] ?? null) ? ($record['created_by']['login'] ?? '') : '')); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(relative_time((string) ($record['created_at'] ?? ''))); ?></td>
                            <td>
                                <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => PAGE_SLUG, 'preview' => $id], admin_url('admin.php'))); ?>">
                                    <?php esc_html_e('Review', domain: 'wppilot'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>

    <?php if ($resolved !== []) { ?>
        <div class="wppilot-panel">
            <h2><?php esc_html_e('Recently resolved', domain: 'wppilot'); ?></h2>
            <table class="widefat striped wppilot-preview-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Target', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Ability', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Outcome', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Age', domain: 'wppilot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($resolved, offset: 0, length: 20) as $record) {
                        $target = is_array($record['target'] ?? null) ? $record['target'] : [];
                        $status = (string) ($record['status'] ?? '');
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) ($target['label'] ?? '')); ?></td>
                            <td><code><?php echo esc_html((string) ($record['ability'] ?? '')); ?></code></td>
                            <td><span class="wppilot-status wppilot-status--<?php echo esc_attr($status); ?>"><?php echo esc_html(status_label($status)); ?></span></td>
                            <td><?php echo esc_html(relative_time((string) ($record['created_at'] ?? ''))); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</div>
