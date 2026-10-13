<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file: require()d inside a namespaced render function, so every variable is function-scoped. Reads are type-checked and escaped on output.

/**
 * The active design, at the top of the screen.
 *
 * The first question anybody opening this page has is "what is my site set to
 * right now" — and before this the answer was somewhere in a list of cards, told
 * in hex codes. It is answered here in the design's own colours and faces, at a
 * size you read rather than parse, with the few facts that decide whether it is
 * usable: is it activated, can its palette carry text, how many tokens it holds.
 *
 * When nothing is active this becomes the empty state, because a person with no
 * design needs a route in far more than they need a status panel.
 */

use WPPilot\Design\Admin;
use WPPilot\Design\Contrast;
use WPPilot\Design\Contract;
use WPPilot\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/** @var array{slug: string, name: string, description: string, content: string}|null $active */
if ($active === null) {
    ?>
    <div class="wppilot-hero wppilot-hero-empty">
        <div class="wppilot-hero-empty-body">
            <h2><?php esc_html_e('No design is active yet', domain: 'wppilot'); ?></h2>
            <p><?php esc_html_e(
                'A design is the one document your AI builds within: your colours, your typefaces, and the things this site should never do. Until one is active, every page is built on whatever the model reaches for by default.',
                domain: 'wppilot',
            ); ?></p>
            <p class="wppilot-hero-empty-routes"><?php esc_html_e(
                'Start from a kit below, or ask your agent: "read this site and save a design from what it already looks like".',
                domain: 'wppilot',
            ); ?></p>
        </div>
    </div>
    <?php
    return;
}

$hero_tokens = Tokens\extract($active['content']);
$hero_vars = Tokens\css_vars($hero_tokens);
$hero_inspection = Contract\inspect($active['content']);
$hero_style = Tokens\css_vars_string($hero_tokens);

$hero_bg = $hero_vars['--wppilot-bg'] ?? '#ffffff';
$hero_ink = $hero_vars['--wppilot-ink'] ?? '#000000';
$hero_ratio = Contrast\ratio($hero_ink, $hero_bg);
$hero_grade = $hero_ratio >= Contrast\AAA_NORMAL
    ? __('AAA', domain: 'wppilot')
    : ($hero_ratio >= Contrast\AA_NORMAL ? __('AA', domain: 'wppilot') : __('below AA', domain: 'wppilot'));
$hero_grade_ok = $hero_ratio >= Contrast\AA_NORMAL;

$hero_colors = count($hero_tokens['colors']);
$hero_faces = count($hero_tokens['typography']);
$hero_donts = count(WPPilot\Design\Preflight\context($active['content'])['donts']);

$hero_edit = '';
$hero_post = WPPilot\Design\Store\find_user_post($active['slug']);
if ($hero_post instanceof \WP_Post) {
    $hero_edit = add_query_arg(['page' => Admin\PAGE_SLUG, 'design' => $hero_post->ID], admin_url('admin.php'));
}
$hero_view = add_query_arg(['page' => Admin\PAGE_SLUG, 'view' => $active['slug']], admin_url('admin.php'));
?>
<div class="wppilot-hero" style="<?php echo esc_attr($hero_style); ?>">
    <div class="wppilot-hero-specimen" style="background:<?php echo esc_attr($hero_bg); ?>;color:<?php
        echo esc_attr($hero_ink);
    ?>">
        <p class="wppilot-hero-kicker"><?php esc_html_e('Active design', domain: 'wppilot'); ?></p>
        <p class="wppilot-hero-display" style="font-family:<?php echo esc_attr(
            ($hero_vars['--wppilot-font-heading'] ?? '') !== ''
                ? '"' . $hero_vars['--wppilot-font-heading'] . '", sans-serif'
                : 'inherit',
        ); ?>"><?php echo esc_html($active['name']); ?></p>
        <p class="wppilot-hero-sample" style="font-family:<?php echo esc_attr(
            ($hero_vars['--wppilot-font-body'] ?? '') !== ''
                ? '"' . $hero_vars['--wppilot-font-body'] . '", sans-serif'
                : 'inherit',
        ); ?>"><?php esc_html_e(
            'Body text, at the size a visitor actually reads it.',
            domain: 'wppilot',
        ); ?></p>
        <span class="wppilot-hero-cta"><?php esc_html_e('Primary action', domain: 'wppilot'); ?></span>

        <div class="wppilot-hero-swatches" aria-hidden="true">
            <?php foreach (array_slice($hero_tokens['colors'], offset: 0, length: 8) as $role => $value):
                $swatch = WPPilot\Design\Preflight\normalize_hex((string) $value);
                if ($swatch === '') {
                    continue;
                } ?>
                <span
                    class="wppilot-hero-swatch"
                    style="background:<?php echo esc_attr($swatch); ?>"
                    title="<?php echo esc_attr($role . ' ' . $swatch); ?>"
                ></span>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="wppilot-hero-facts">
        <h2><?php echo esc_html($active['name']); ?></h2>
        <?php if ($active['description'] !== ''): ?>
            <p class="wppilot-hero-desc"><?php echo esc_html($active['description']); ?></p>
        <?php endif; ?>

        <ul class="wppilot-hero-chips">
            <li class="is-<?php echo $hero_inspection['readiness']['ready'] ? 'ok' : 'warn'; ?>"><?php
                echo esc_html($hero_inspection['readiness']['ready']
                    ? __('Ready to build with', domain: 'wppilot')
                    : __('Incomplete', domain: 'wppilot'));
            ?></li>
            <li class="is-<?php echo $hero_grade_ok ? 'ok' : 'warn'; ?>"><?php echo esc_html(sprintf(
                /* translators: 1: contrast ratio, 2: WCAG grade. */
                __('Text %1$s:1 · %2$s', domain: 'wppilot'),
                number_format($hero_ratio, decimals: 1),
                $hero_grade,
            )); ?></li>
            <li><?php echo esc_html(sprintf(
                /* translators: %d: number of colours. */
                _n('%d colour', '%d colours', $hero_colors, 'wppilot'),
                $hero_colors,
            )); ?></li>
            <li><?php echo esc_html(sprintf(
                /* translators: %d: number of typefaces. */
                _n('%d typeface', '%d typefaces', $hero_faces, 'wppilot'),
                $hero_faces,
            )); ?></li>
            <li class="is-<?php echo $hero_donts > 0 ? 'ok' : 'warn'; ?>"><?php echo esc_html(sprintf(
                /* translators: %d: number of enforceable rules. */
                _n('%d enforced rule', '%d enforced rules', $hero_donts, 'wppilot'),
                $hero_donts,
            )); ?></li>
        </ul>

        <?php
        /**
         * Extra status a paid module can add, such as whether the design has
         * actually been written into the site's builders.
         *
         * @param string $slug The active design's slug.
         */
        do_action('wppilot_design_hero_status', $active['slug']);
        ?>

        <div class="wppilot-hero-actions">
            <?php if ($hero_edit !== ''): ?>
                <a class="button button-primary" href="<?php echo esc_url($hero_edit); ?>"><?php esc_html_e(
                    'Edit this design',
                    domain: 'wppilot',
                ); ?></a>
            <?php endif; ?>
            <a class="button" href="<?php echo esc_url($hero_view); ?>"><?php esc_html_e(
                'View details',
                domain: 'wppilot',
            ); ?></a>
        </div>
    </div>
</div>
