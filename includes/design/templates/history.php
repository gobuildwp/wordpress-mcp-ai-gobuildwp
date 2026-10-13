<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary. Reads are type-checked and escaped on output.

use WPPilot\Design\Contract;
use WPPilot\Design\Parser;
use WPPilot\Design\Revisions;

if (!defined('ABSPATH')) {
    exit();
}

/** @var WP_Post $post */
/** @var bool $is_active */
/** @var string $action_url */

$history = Revisions\history($post);
$history_enabled = wp_revisions_enabled($post);
$date_format = (string) get_option('date_format') . ' ' . (string) get_option('time_format');
?>
<section class="wppilot-detail-block wppilot-history">
    <h2><?php esc_html_e('History', domain: 'wppilot'); ?><?php if ($history !== []): ?>
        <span class="wppilot-detail-count"><?php echo esc_html((string) count($history)); ?></span>
    <?php endif; ?></h2>
    <p class="wppilot-history-intro"><?php esc_html_e(
        'GoBuildWP keeps at most five snapshots. Restoring one creates a new current version, so the change remains reversible.',
        domain: 'wppilot',
    ); ?></p>

    <?php if (!$history_enabled): ?>
        <p class="wppilot-history-empty"><?php esc_html_e(
            'Revision history is disabled by the WordPress configuration.',
            domain: 'wppilot',
        ); ?></p>
    <?php endif; ?>
    <?php if ($history_enabled && $history === []): ?>
        <p class="wppilot-history-empty"><?php esc_html_e('No previous versions yet.', domain: 'wppilot'); ?></p>
    <?php endif; ?>
    <?php if ($history !== []): ?>
        <ol class="wppilot-history-list">
            <?php foreach ($history as $revision):
                $inspection = Contract\inspect($revision->post_content);
                $ready = $inspection['readiness']['ready'];
                $can_restore = !$is_active || $ready;
                $timestamp = strtotime($revision->post_modified_gmt . ' UTC');
                $date = $timestamp !== false ? (string) wp_date($date_format, $timestamp) : $revision->post_modified;
                $author = get_userdata((int) $revision->post_author);
                $author_name = $author instanceof WP_User ? $author->display_name : __('System', domain: 'wppilot');
                $parsed = Parser\parse($revision->post_content);
                ?>
                <li class="wppilot-history-item">
                    <div class="wppilot-history-copy">
                        <strong class="wppilot-history-date"><?php echo esc_html($date); ?></strong>
                        <span class="wppilot-history-meta"><?php echo
                            esc_html(sprintf(
                                /* translators: 1: design name, 2: revision author */
                                __('%1$s · by %2$s', domain: 'wppilot'),
                                $parsed['name'] !== '' ? $parsed['name'] : $post->post_title,
                                $author_name,
                            ))
                        ; ?></span>
                    </div>
                    <span class="wppilot-history-state <?php echo $ready ? 'is-ready' : 'is-incomplete'; ?>"><?php echo
                        esc_html($ready ? __('Ready', domain: 'wppilot') : __('Incomplete', domain: 'wppilot'))
                    ; ?></span>
                    <form method="post" action="<?php echo
                        esc_url($action_url)
                    ; ?>" onsubmit="return confirm('<?php echo
                        esc_js(__('Restore this design revision?', domain: 'wppilot'))
                    ; ?>');">
                        <?php wp_nonce_field('wppilot_design_restore_' . $revision->ID); ?>
                        <input type="hidden" name="action" value="wppilot_design_restore" />
                        <input type="hidden" name="design_id" value="<?php echo (int) $post->ID; ?>" />
                        <input type="hidden" name="revision_id" value="<?php echo (int) $revision->ID; ?>" />
                        <button type="submit" class="button" <?php disabled(!$can_restore); ?> title="<?php echo
                            esc_attr(
                                $can_restore
                                    ? __('Restore this revision', domain: 'wppilot')
                                    : Contract\activation_error($inspection),
                            )
                        ; ?>"><?php esc_html_e('Restore', domain: 'wppilot'); ?></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
