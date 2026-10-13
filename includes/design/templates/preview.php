<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary. Reads are type-checked and escaped on output.

if (!defined('ABSPATH')) {
    exit();
}

/** @var string $vars_style  Inline CSS custom-property string (already sanitized). */
$vars_style ??= '';
?>
<div class="wppilot-preview" data-wppilot-preview style="<?php echo esc_attr($vars_style); ?>">
    <div class="wppilot-preview-screen">
        <header class="wppilot-preview-nav">
            <span class="wppilot-preview-brand"><span class="wppilot-preview-mark" aria-hidden="true"></span><?php echo
                esc_html(get_bloginfo('name'))
            ; ?></span>
            <nav class="wppilot-preview-links" aria-hidden="true">
                <span><?php esc_html_e('Work', domain: 'wppilot'); ?></span>
                <span><?php esc_html_e('Studio', domain: 'wppilot'); ?></span>
                <span><?php esc_html_e('Journal', domain: 'wppilot'); ?></span>
            </nav>
            <span class="wppilot-preview-pill"><?php esc_html_e('Get started', domain: 'wppilot'); ?></span>
        </header>

        <section class="wppilot-preview-hero">
            <span class="wppilot-preview-tag"><?php esc_html_e('Preview', domain: 'wppilot'); ?></span>
            <h3 class="wppilot-preview-title"><?php esc_html_e(
                'A heading in this design system.',
                domain: 'wppilot',
            ); ?></h3>
            <p class="wppilot-preview-lede"><?php esc_html_e(
                'Sample body copy: type, colour, spacing and shape applied to real content.',
                domain: 'wppilot',
            ); ?></p>
            <div class="wppilot-preview-actions">
                <span class="wppilot-preview-btn wppilot-preview-btn-primary"><?php esc_html_e(
                    'Primary action',
                    domain: 'wppilot',
                ); ?></span>
                <span class="wppilot-preview-btn wppilot-preview-btn-ghost"><?php esc_html_e(
                    'Learn more',
                    domain: 'wppilot',
                ); ?> <span class="wppilot-preview-arrow" aria-hidden="true">&rarr;</span></span>
            </div>
        </section>

        <section class="wppilot-preview-grid" aria-hidden="true">
            <div class="wppilot-preview-tile">
                <span class="wppilot-preview-num">01</span>
                <strong><?php esc_html_e('Typography', domain: 'wppilot'); ?></strong>
                <span class="wppilot-preview-tile-sub"><?php esc_html_e('Scale & weight', domain: 'wppilot'); ?></span>
            </div>
            <div class="wppilot-preview-tile">
                <span class="wppilot-preview-num">02</span>
                <strong><?php esc_html_e('Colour', domain: 'wppilot'); ?></strong>
                <span class="wppilot-preview-tile-sub"><?php esc_html_e(
                    'Palette & accent',
                    domain: 'wppilot',
                ); ?></span>
            </div>
            <div class="wppilot-preview-tile">
                <span class="wppilot-preview-num">03</span>
                <strong><?php esc_html_e('Spacing', domain: 'wppilot'); ?></strong>
                <span class="wppilot-preview-tile-sub"><?php esc_html_e(
                    'Rhythm & density',
                    domain: 'wppilot',
                ); ?></span>
            </div>
        </section>
    </div>
</div>
