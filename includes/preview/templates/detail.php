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

/** @var array<string, mixed> $record Supplied by render_page(). */
$id = (string) ($record['preview_id'] ?? '');
$status = (string) ($record['status'] ?? '');
$target = is_array($record['target'] ?? null) ? $record['target'] : [];
$diff = is_array($record['diff'] ?? null) ? $record['diff'] : [];
$agent = is_array($record['agent'] ?? null) ? $record['agent'] : [];
$created_by = is_array($record['created_by'] ?? null) ? $record['created_by'] : [];
$entries = is_array($diff['entries'] ?? null) ? $diff['entries'] : [];
$unpredicted = is_array($diff['unpredicted'] ?? null) ? $diff['unpredicted'] : [];
$side_effects = is_array($record['side_effects'] ?? null) ? $record['side_effects'] : [];
$warnings = is_array($record['warnings'] ?? null) ? $record['warnings'] : [];
$is_pending = $status === Store\STATUS_PENDING;

?>
<?php \wppilot_render_admin_header(); ?>
<div class="wrap wppilot-wrap">
    <h1><?php esc_html_e('Review a proposed change', domain: 'wppilot'); ?></h1>

    <p>
        <a href="<?php echo esc_url(add_query_arg(['page' => PAGE_SLUG], admin_url('admin.php'))); ?>">
            &larr; <?php esc_html_e('All previews', domain: 'wppilot'); ?>
        </a>
    </p>

    <div class="wppilot-panel">
        <h2>
            <?php echo esc_html((string) ($target['label'] ?? __('Untitled', domain: 'wppilot'))); ?>
            <span class="wppilot-status wppilot-status--<?php echo esc_attr($status); ?>">
                <?php echo esc_html(status_label($status)); ?>
            </span>
        </h2>

        <table class="wppilot-preview-meta">
            <tr>
                <th scope="row"><?php esc_html_e('Ability', domain: 'wppilot'); ?></th>
                <td><code><?php echo esc_html((string) ($record['ability'] ?? '')); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Proposed by', domain: 'wppilot'); ?></th>
                <td>
                    <?php
                    $client = trim((string) ($agent['label'] ?? ''));
                    $version = trim((string) ($agent['client_version'] ?? ''));
                    echo esc_html($client !== '' ? $client : __('Unknown client', domain: 'wppilot'));
                    if ($version !== '') {
                        echo ' ' . esc_html($version);
                    }
                    ?>
                    <span class="wppilot-muted">
                        <?php
                        printf(
                            /* translators: 1: WordPress user login, 2: authentication method */
                            esc_html__('as %1$s, via %2$s', domain: 'wppilot'),
                            esc_html((string) ($created_by['login'] ?? '')),
                            esc_html((string) ($agent['method'] ?? 'direct')),
                        );
                        ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Created', domain: 'wppilot'); ?></th>
                <td><?php echo esc_html(relative_time((string) ($record['created_at'] ?? ''))); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Expires', domain: 'wppilot'); ?></th>
                <td><?php echo esc_html(relative_time((string) ($record['expires_at'] ?? ''))); ?></td>
            </tr>
        </table>
    </div>

    <?php foreach ($warnings as $warning) { ?>
        <div class="notice notice-warning inline"><p><?php echo esc_html((string) $warning); ?></p></div>
    <?php } ?>

    <?php if (($diff['destroys'] ?? false) === true) { ?>
        <div class="notice notice-error inline">
            <p><strong><?php esc_html_e('This removes data.', domain: 'wppilot'); ?></strong>
            <?php esc_html_e('The fields below marked "removed" will no longer exist after this is applied.', domain: 'wppilot'); ?></p>
        </div>
    <?php } ?>

    <div class="wppilot-panel">
        <h2>
            <?php
            $count = (int) ($diff['changed_count'] ?? 0);
            echo esc_html(sprintf(
                /* translators: %d: number of changed fields */
                _n('%d field would change', '%d fields would change', $count, 'wppilot'),
                $count,
            ));
            ?>
        </h2>

        <?php if ($entries === []) { ?>
            <p class="wppilot-muted"><?php esc_html_e('Nothing would change. The values this call sends already match what is stored.', domain: 'wppilot'); ?></p>
        <?php } else { ?>
            <div class="wppilot-diff-scroll">
                <table class="widefat striped wppilot-diff">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Field', domain: 'wppilot'); ?></th>
                            <th scope="col"><?php esc_html_e('Now', domain: 'wppilot'); ?></th>
                            <th scope="col"><?php esc_html_e('After', domain: 'wppilot'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry) {
                            $op = (string) ($entry['op'] ?? 'changed');
                            ?>
                            <tr class="wppilot-diff--<?php echo esc_attr($op); ?>">
                                <td>
                                    <code><?php echo esc_html((string) ($entry['path_label'] ?? '')); ?></code>
                                    <span class="wppilot-badge wppilot-badge--<?php echo esc_attr($op); ?>"><?php echo esc_html($op); ?></span>
                                    <?php if (($entry['value_truncated'] ?? false) === true) { ?>
                                        <span class="wppilot-muted"><?php esc_html_e('(shortened for display)', domain: 'wppilot'); ?></span>
                                    <?php } ?>
                                </td>
                                <td><pre class="wppilot-diff-value wppilot-diff-value--before"><?php echo esc_html((string) ($entry['before'] ?? '')); ?></pre></td>
                                <td><pre class="wppilot-diff-value wppilot-diff-value--after"><?php echo esc_html((string) ($entry['after'] ?? '')); ?></pre></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (($diff['truncated'] ?? false) === true) { ?>
                <p class="wppilot-muted">
                    <?php
                    printf(
                        /* translators: %d: number of additional changed fields not shown */
                        esc_html__('%d more changed field(s) are not shown here. They will still be written.', domain: 'wppilot'),
                        (int) ($diff['dropped_count'] ?? 0),
                    );
                    ?>
                </p>
            <?php } ?>
        <?php } ?>
    </div>

    <?php if ($unpredicted !== [] || $side_effects !== []) { ?>
        <div class="wppilot-panel">
            <h2><?php esc_html_e('What this diff cannot show', domain: 'wppilot'); ?></h2>
            <ul class="wppilot-list">
                <?php foreach ($unpredicted as $note) { ?>
                    <li>
                        <code><?php echo esc_html((string) ($note['path_label'] ?? '')); ?></code>
                        &mdash; <?php echo esc_html((string) ($note['reason'] ?? '')); ?>
                    </li>
                <?php } ?>
                <?php foreach ($side_effects as $effect) { ?>
                    <li><?php echo esc_html((string) $effect); ?></li>
                <?php } ?>
            </ul>
        </div>
    <?php } ?>

    <?php if ($is_pending) { ?>
        <div class="wppilot-panel wppilot-panel--actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppilot-inline-form">
                <input type="hidden" name="action" value="wppilot_preview_apply">
                <input type="hidden" name="preview_id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field('wppilot_preview_apply_' . $id); ?>
                <button type="submit" class="button button-primary"><?php esc_html_e('Apply this change', domain: 'wppilot'); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppilot-inline-form">
                <input type="hidden" name="action" value="wppilot_preview_discard">
                <input type="hidden" name="preview_id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field('wppilot_preview_discard_' . $id); ?>
                <button type="submit" class="button"><?php esc_html_e('Discard', domain: 'wppilot'); ?></button>
            </form>

            <p class="wppilot-muted">
                <?php esc_html_e('If the target has changed since this preview was made, applying is refused rather than overwriting the newer version.', domain: 'wppilot'); ?>
            </p>
        </div>
    <?php } elseif ($status === Store\STATUS_CONFLICTED) { ?>
        <div class="notice notice-error inline">
            <p><?php esc_html_e('The target changed after this preview was created, so it can no longer be applied. Nothing was written. Ask the agent to run the preview again against the current state.', domain: 'wppilot'); ?></p>
        </div>
    <?php } ?>
</div>
