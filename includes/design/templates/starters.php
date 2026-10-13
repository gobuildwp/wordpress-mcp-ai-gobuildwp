<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file: require()d inside a namespaced render function, so every variable is function-scoped. Reads are type-checked and escaped on output.

/**
 * Starter kits.
 *
 * A shelf of finished directions to begin from, shown the way a palette is
 * actually understood: as colour, at a size you can read across the room,
 * rather than as a list of hex values nobody parses.
 *
 * Choosing one copies it into the site's own library and opens it for editing.
 * It is never applied straight to the site and never activated by the click,
 * because a palette adopted unchanged is exactly the generic result the rest of
 * this module exists to prevent. The button says so.
 */

use WPPilot\Design\Contrast;
use WPPilot\Design\Examples;
use WPPilot\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

$starters = Examples\all();
if ($starters === []) {
    return;
}

/** @var string $action_url */
$existing = [];
foreach ($library as $owned) {
    $existing[$owned['slug']] = true;
}
?>
<div class="wppilot-starters">
    <div class="wppilot-starters-head">
        <h2><?php esc_html_e('Starter kits', domain: 'wppilot'); ?></h2>
        <p><?php esc_html_e(
            'Finished directions to begin from, each one already checked for readable contrast. Choosing one copies it into your designs so you can change the colours and rewrite the reasoning for this business. Nothing is applied to your site until you activate it, and a starter left unchanged will look like a starter.',
            domain: 'wppilot',
        ); ?></p>
    </div>

    <div class="wppilot-starters-grid">
        <?php foreach ($starters as $starter):
            $tokens = Tokens\extract($starter['content']);
            $vars = Tokens\css_vars($tokens);
            $bg = $vars['--wppilot-bg'] ?? '#ffffff';
            $ink = $vars['--wppilot-ink'] ?? '#000000';
            $accent = $vars['--wppilot-accent'] ?? '#000000';
            $heading_font = $vars['--wppilot-font-heading'] ?? '';
            $body_font = $vars['--wppilot-font-body'] ?? '';

            // The whole declared palette, in the order the design wrote it, so
            // the swatch band shows the direction rather than three roles.
            $swatches = [];
            foreach ($tokens['colors'] as $role => $value) {
                $hex = \WPPilot\Design\Preflight\normalize_hex((string) $value);
                if ($hex !== '') {
                    $swatches[(string) $role] = $hex;
                }
            }
            $ratio = Contrast\ratio($ink, $bg);
            $taken = isset($existing[$starter['slug']]);
            ?>
            <article class="wppilot-starter" style="--st-bg:<?php echo esc_attr($bg); ?>;--st-ink:<?php
                echo esc_attr($ink);
            ?>;--st-accent:<?php echo esc_attr($accent); ?>">

                <div class="wppilot-starter-swatches" aria-hidden="true">
                    <?php foreach (array_slice($swatches, offset: 0, length: 6) as $role => $hex): ?>
                        <span
                            class="wppilot-starter-swatch"
                            style="background:<?php echo esc_attr($hex); ?>"
                            title="<?php echo esc_attr($role . ' ' . $hex); ?>"
                        ><em><?php echo esc_html($hex); ?></em></span>
                    <?php endforeach; ?>
                </div>

                <div class="wppilot-starter-specimen">
                    <p class="wppilot-starter-eyebrow"><?php echo esc_html(
                        sprintf(
                            /* translators: 1: heading typeface, 2: body typeface. */
                            __('%1$s / %2$s', domain: 'wppilot'),
                            $heading_font !== '' ? $heading_font : __('unset', domain: 'wppilot'),
                            $body_font !== '' ? $body_font : __('unset', domain: 'wppilot'),
                        ),
                    ); ?></p>
                    <p class="wppilot-starter-display" style="font-family:<?php echo
                        esc_attr($heading_font !== '' ? '"' . $heading_font . '", sans-serif' : 'inherit');
                    ?>"><?php echo esc_html($starter['name']); ?></p>
                    <p class="wppilot-starter-body" style="font-family:<?php echo
                        esc_attr($body_font !== '' ? '"' . $body_font . '", sans-serif' : 'inherit');
                    ?>"><?php esc_html_e(
                        'Body text on this background, at the size a visitor actually reads.',
                        domain: 'wppilot',
                    ); ?></p>
                    <span class="wppilot-starter-button"><?php esc_html_e('Primary action', domain: 'wppilot'); ?></span>
                </div>

                <div class="wppilot-starter-body-col">
                    <h3><?php echo esc_html($starter['name']); ?></h3>
                    <p class="wppilot-starter-desc"><?php echo esc_html($starter['description']); ?></p>
                    <p class="wppilot-starter-contrast"><?php echo esc_html(sprintf(
                        /* translators: %s: contrast ratio, e.g. 12.1. */
                        __('Text contrast %s:1 — passes AAA', domain: 'wppilot'),
                        number_format($ratio, decimals: 1),
                    )); ?></p>

                    <div class="wppilot-starter-actions">
                        <form method="post" action="<?php echo esc_url($action_url); ?>">
                            <?php wp_nonce_field('wppilot_design_use_starter'); ?>
                            <input type="hidden" name="action" value="wppilot_design_use_starter" />
                            <input type="hidden" name="slug" value="<?php echo esc_attr($starter['slug']); ?>" />
                            <button type="submit" class="button"><?php
                                echo $taken
                                    ? esc_html__('Copy again', domain: 'wppilot')
                                    : esc_html__('Start from this', domain: 'wppilot');
                            ?></button>
                        </form>
                        <?php if ($taken): ?>
                            <span class="wppilot-starter-taken"><?php esc_html_e(
                                'Already copied',
                                domain: 'wppilot',
                            ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
