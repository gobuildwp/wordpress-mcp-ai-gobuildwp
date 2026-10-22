<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary.

use WPPilot\Design\Admin;
use WPPilot\Design\Contract;
use WPPilot\Design\Library;
use WPPilot\Design\Markdown;
use WPPilot\Design\Parser;
use WPPilot\Design\Store;
use WPPilot\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(esc_html__('You do not have permission to view this page.', domain: 'wppilot'));
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view: the slug only selects which design to display; changes post to nonce-checked handlers.
$view_param = is_string($_GET['view'] ?? null) ? sanitize_title(wp_unslash($_GET['view'])) : '';
$slug = Parser\normalize_slug($view_param);
$design = $slug !== '' ? Library\find($slug) : null;
if ($design === null) {
    wp_die(esc_html__('Design not found.', domain: 'wppilot'));
}
/** @var array{slug: string, name: string, description: string, content: string} $design */

$tokens = Tokens\extract($design['content']);
$inspection = Contract\inspect($design['content']);
$is_ready = $inspection['readiness']['ready'];
$dials = Tokens\dials($tokens);
$palette = Tokens\palette($design['content']);
$shadows = Tokens\prose_shadows($design['content'], $palette);
$animations = Tokens\prose_animations($design['content']);
$philosophy = Markdown\section($design['content'], [
    'overview',
    'philosophy',
    'design philosophy',
    'about',
    'introduction',
]);
$guidance = Markdown\guidance($design['content'], [
    "do's and don'ts",
    "dos and don'ts",
    "do's & don'ts",
    "dos & don'ts",
    "do and don't",
    'guidelines',
    'principles',
    'rules',
]);
$has_guidance = $guidance['dos'] !== [] || $guidance['donts'] !== [] || $guidance['rest'] !== '';
$vars_style = Tokens\css_vars_string($tokens);
$accent = Tokens\css_vars($tokens)['--wppilot-accent'] ?? '';
$active_slug = Store\get_active_slug();
$is_active = $design['slug'] === $active_slug;
$action_url = admin_url('admin-post.php');
$gallery_url = admin_url('admin.php?page=' . Admin\PAGE_SLUG);

$edit_url = '';
$post = Store\find_user_post($design['slug']);
if ($post instanceof \WP_Post) {
    $edit_url = add_query_arg(['page' => Admin\PAGE_SLUG, 'design' => $post->ID], admin_url('admin.php'));
}
$page_style = $accent !== '' ? '--ds-accent:' . $accent : '';
?>
<?php wppilot_render_admin_header(legend: __('Design', domain: 'wppilot')); ?>
<div class="wrap wppilot-design wppilot-design-detail" style="<?php echo esc_attr($page_style); ?>">
    <a class="wppilot-detail-back" href="<?php echo esc_url($gallery_url); ?>">&larr; <?php esc_html_e(
        'All designs',
        domain: 'wppilot',
    ); ?></a>

    <header class="wppilot-detail-head">
        <div class="wppilot-detail-headmain">
            <span class="wppilot-detail-kicker"><?php esc_html_e('Design', domain: 'wppilot'); ?></span>
            <h1 class="wppilot-detail-title"><?php echo esc_html($design['name']); ?></h1>
            <?php if ($design['description'] !== ''): ?>
                <p class="wppilot-detail-desc"><?php echo esc_html($design['description']); ?></p>
            <?php endif; ?>
            <?php if (!$is_ready): ?>
                <span class="wppilot-design-incomplete-badge"><?php esc_html_e(
                    'Incomplete',
                    domain: 'wppilot',
                ); ?></span>
            <?php endif; ?>
            <?php $waivers = WPPilot\Design\Preflight\waivers($design['content']); ?>
            <?php if ($waivers !== []): ?>
                <span class="wppilot-design-allows"><?php echo
                    esc_html(sprintf(
                        /* translators: %s: list of anti-slop rules this design waives */
                        __('Allows: %s', domain: 'wppilot'),
                        implode(' · ', $waivers),
                    ))
                ; ?><span class="wppilot-design-allows-help" title="<?php echo
                    esc_attr__(
                        'Anti-slop rules this design intentionally waives. GoBuildWP normally flags these AI tells; here they count as a deliberate house-style choice, not a mistake.',
                        domain: 'wppilot',
                    )
                ; ?>">?</span></span>
            <?php endif; ?>
        </div>
        <div class="wppilot-detail-actions">
            <?php if ($is_active): ?>
                <span class="wppilot-design-active-badge wppilot-detail-activebadge"><?php esc_html_e(
                    'Active',
                    domain: 'wppilot',
                ); ?></span>
            <?php endif; ?>
            <?php if (!$is_active && $is_ready): ?>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('wppilot_design_activate'); ?>
                    <input type="hidden" name="action" value="wppilot_design_activate" />
                    <input type="hidden" name="slug" value="<?php echo esc_attr($design['slug']); ?>" />
                    <button type="submit" class="button button-primary"><?php esc_html_e(
                        'Activate',
                        domain: 'wppilot',
                    ); ?></button>
                </form>
            <?php endif; ?>
            <?php if (!$is_active && !$is_ready): ?>
                <button type="button" class="button" disabled title="<?php echo
                    esc_attr(Contract\activation_error($inspection))
                ; ?>"><?php esc_html_e('Activate', domain: 'wppilot'); ?></button>
            <?php endif; ?>
            <?php if ($edit_url !== ''): ?>
                <a class="button" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e(
                    'Edit',
                    domain: 'wppilot',
                ); ?></a>
            <?php endif; ?>
            <button type="button" class="button" data-wppilot-copy-design><?php esc_html_e(
                'Copy DESIGN.md',
                domain: 'wppilot',
            ); ?></button>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <?php wp_nonce_field('wppilot_design_duplicate'); ?>
                <input type="hidden" name="action" value="wppilot_design_duplicate" />
                <input type="hidden" name="slug" value="<?php echo esc_attr($design['slug']); ?>" />
                <button type="submit" class="button"><?php esc_html_e('Duplicate', domain: 'wppilot'); ?></button>
            </form>
        </div>
    </header>

    <section class="wppilot-detail-stage">
        <?php require __DIR__ . '/preview.php'; ?>
    </section>

    <?php if ($philosophy !== ''): ?>
        <section class="wppilot-detail-block wppilot-detail-philosophy">
            <span class="wppilot-detail-eyebrow"><?php esc_html_e('Philosophy', domain: 'wppilot'); ?></span>
            <div class="wppilot-doc wppilot-doc-lead"><?php echo wp_kses_post($philosophy); ?></div>
        </section>
    <?php endif; ?>

    <div class="wppilot-detail-cols">
        <?php if ($palette !== []): ?>
            <section class="wppilot-detail-block">
                <h2><?php esc_html_e('Palette', domain: 'wppilot'); ?> <span class="wppilot-detail-count"><?php echo
                    esc_html((string) count($palette))
                ; ?></span></h2>
                <div class="wppilot-palette">
                    <?php foreach ($palette as $name => $hex): ?>
                        <div class="wppilot-palette-chip">
                            <span class="wppilot-palette-swatch" style="background:<?php echo
                                esc_attr(Tokens\css_value($hex))
                            ; ?>"></span>
                            <span class="wppilot-palette-name"><?php echo esc_html($name); ?></span>
                            <span class="wppilot-palette-hex"><?php echo esc_html($hex); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($tokens['rounded'] !== [] || $tokens['spacing'] !== []): ?>
            <section class="wppilot-detail-block">
                <h2><?php esc_html_e('Shape & spacing', domain: 'wppilot'); ?></h2>
                <div class="wppilot-shape" style="<?php echo esc_attr($vars_style); ?>">
                    <?php if ($tokens['rounded'] !== []): ?>
                        <div class="wppilot-shape-group">
                            <span class="wppilot-shape-group-label"><?php esc_html_e(
                                'Radius',
                                domain: 'wppilot',
                            ); ?></span>
                            <div class="wppilot-radius-row">
                                <?php foreach ($tokens['rounded'] as $k => $v):
                                    $radius = is_numeric($v) ? $v . 'px' : $v;
                                    ?>
                                    <div class="wppilot-radius-spec">
                                        <span class="wppilot-radius-box" style="border-radius:<?php echo
                                            esc_attr(Tokens\css_value($radius))
                                        ; ?>"></span>
                                        <span class="wppilot-shape-spec-label"><?php echo
                                            esc_html($k . ' · ' . $v)
                                        ; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($tokens['spacing'] as $k => $v):
                        $space_matches = [];
                        preg_match_all('/\d+(?:\.\d+)?/', $v, $space_matches);
                        $steps = $space_matches[0];
                        if ($steps === []) {
                            continue;
                        }
                        ?>
                        <div class="wppilot-shape-group">
                            <span class="wppilot-shape-group-label"><?php echo
                                esc_html(sprintf(
                                    /* translators: %s: spacing token name */
                                    __('Space %s', domain: 'wppilot'),
                                    $k,
                                ))
                            ; ?></span>
                            <div class="wppilot-space-rows">
                                <?php foreach ($steps as $step):
                                    $bar_width = is_numeric($step) ? min((float) $step, 320.0) : 0.0;
                                    ?>
                                    <div class="wppilot-space-row">
                                        <span class="wppilot-space-num"><?php echo esc_html($step); ?></span>
                                        <span class="wppilot-space-bar" style="width:<?php echo
                                            esc_attr((string) $bar_width)
                                        ; ?>px"></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <section class="wppilot-detail-block">
        <h2><?php esc_html_e('Composition', domain: 'wppilot'); ?></h2>
        <div class="wppilot-dials">
            <?php foreach (['variance', 'density', 'motion'] as $dial_key):
                $dial_pct = (int) round($dials[$dial_key] * 100);
                ?>
                <div class="wppilot-dial">
                    <div class="wppilot-dial-head">
                        <span class="wppilot-dial-label"><?php echo esc_html(ucfirst($dial_key)); ?></span>
                        <span class="wppilot-dial-value"><?php echo
                            esc_html(number_format($dials[$dial_key], decimals: 2))
                        ; ?></span>
                    </div>
                    <div class="wppilot-dial-track">
                        <span class="wppilot-dial-fill" style="width:<?php echo
                            esc_attr((string) $dial_pct)
                        ; ?>%"></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="wppilot-dials-note"><?php esc_html_e(
                '0 = symmetric / airy / static.  1 = asymmetric / packed / kinetic.',
                domain: 'wppilot',
            ); ?></p>
        </div>
    </section>

    <?php if ($tokens['typography'] !== []): ?>
        <section class="wppilot-detail-block">
            <h2><?php esc_html_e('Typography', domain: 'wppilot'); ?></h2>
            <div class="wppilot-typeset">
                <?php foreach ($tokens['typography'] as $role => $props):
                    $family = $props['fontFamily'] ?? '';
                    $weight = $props['fontWeight'] ?? '';
                    $size = $props['fontSize'] ?? '';
                    $display = Tokens\display_px($size);
                    $sample_style = 'font-family:' . Tokens\css_value($family);
                    if ($weight !== '') {
                        $sample_style .= ';font-weight:' . Tokens\css_value($weight);
                    }
                    if ($display !== '') {
                        $sample_style .= ';font-size:' . $display . ';line-height:1.15';
                    }
                    ?>
                    <div class="wppilot-typespec">
                        <div class="wppilot-typespec-meta">
                            <code><?php echo esc_html($role); ?></code>
                            <span class="wppilot-typespec-family"><?php echo
                                esc_html($family !== '' ? $family : '—')
                            ; ?></span>
                            <?php if (trim($weight . ' ' . $size) !== ''): ?>
                                <span class="wppilot-typespec-num"><?php echo
                                    esc_html(trim($weight . ' ' . $size))
                                ; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="wppilot-typespec-sample" style="<?php echo
                            esc_attr($sample_style)
                        ; ?>">Ag · The quick brown fox</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="wppilot-detail-block">
        <h2><?php esc_html_e('Components', domain: 'wppilot'); ?></h2>
        <div class="wppilot-components" style="<?php echo esc_attr($vars_style); ?>">
            <div class="wppilot-comp-row">
                <button type="button" class="wppilot-comp-btn wppilot-comp-primary"><?php esc_html_e(
                    'Primary',
                    domain: 'wppilot',
                ); ?></button>
                <button type="button" class="wppilot-comp-btn wppilot-comp-secondary"><?php esc_html_e(
                    'Secondary',
                    domain: 'wppilot',
                ); ?></button>
                <button type="button" class="wppilot-comp-btn wppilot-comp-ghost"><?php esc_html_e(
                    'Ghost',
                    domain: 'wppilot',
                ); ?></button>
                <span class="wppilot-comp-badge"><?php esc_html_e('Badge', domain: 'wppilot'); ?></span>
            </div>
            <input class="wppilot-comp-input" type="text" placeholder="<?php esc_attr_e(
                'Input field',
                domain: 'wppilot',
            ); ?>" />
            <div class="wppilot-comp-card">
                <strong><?php esc_html_e('Card title', domain: 'wppilot'); ?></strong>
                <span><?php esc_html_e('A small surface, rendered in this design system.', domain: 'wppilot'); ?></span>
            </div>
        </div>
    </section>

    <?php if ($shadows !== []): ?>
        <section class="wppilot-detail-block">
            <h2><?php esc_html_e('Elevation', domain: 'wppilot'); ?> <span class="wppilot-detail-count"><?php echo
                esc_html((string) count($shadows))
            ; ?></span></h2>
            <div class="wppilot-shadows" style="<?php echo esc_attr($vars_style); ?>">
                <?php foreach ($shadows as $shadow_name => $shadow): ?>
                    <div class="wppilot-shadow">
                        <span class="wppilot-shadow-swatch" style="box-shadow:<?php echo
                            esc_attr($shadow['css'])
                        ; ?>"></span>
                        <span class="wppilot-shadow-name"><?php echo esc_html($shadow_name); ?></span>
                        <span class="wppilot-shadow-spec"><?php echo esc_html($shadow['spec']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($has_guidance): ?>
        <section class="wppilot-detail-block wppilot-detail-doc">
            <h2><?php esc_html_e('Do\'s &amp; Don\'ts', domain: 'wppilot'); ?></h2>
            <?php if ($guidance['rest'] !== ''): ?>
                <div class="wppilot-doc"><?php echo wp_kses_post($guidance['rest']); ?></div>
            <?php endif; ?>
            <?php if ($guidance['dos'] !== [] || $guidance['donts'] !== []): ?>
                <div class="wppilot-doc-split">
                    <?php if ($guidance['dos'] !== []): ?>
                        <div class="wppilot-doc-col">
                            <span class="wppilot-doc-col-label wppilot-doc-col-label--do"><?php esc_html_e(
                                'Do',
                                domain: 'wppilot',
                            ); ?></span>
                            <ul class="wppilot-doc wppilot-doc-list">
                                <?php foreach ($guidance['dos'] as $item): ?>
                                    <li class="wppilot-doc-do"><?php echo wp_kses_post($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if ($guidance['donts'] !== []): ?>
                        <div class="wppilot-doc-col">
                            <span class="wppilot-doc-col-label wppilot-doc-col-label--dont"><?php esc_html_e(
                                'Don\'t',
                                domain: 'wppilot',
                            ); ?></span>
                            <ul class="wppilot-doc wppilot-doc-list">
                                <?php foreach ($guidance['donts'] as $item): ?>
                                    <li class="wppilot-doc-dont"><?php echo wp_kses_post($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($animations !== []): ?>
        <section class="wppilot-detail-block">
            <h2><?php esc_html_e('Animations', domain: 'wppilot'); ?> <span class="wppilot-detail-count"><?php echo
                esc_html((string) count($animations))
            ; ?></span></h2>
            <div class="wppilot-anims">
                <?php foreach ($animations as $anim_name => $anim_desc): ?>
                    <div class="wppilot-anim">
                        <span class="wppilot-anim-name"><?php echo esc_html($anim_name); ?></span>
                        <span class="wppilot-anim-desc"><?php echo esc_html($anim_desc); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="wppilot-detail-block">
        <details class="wppilot-detail-raw">
            <summary><?php esc_html_e('Raw DESIGN.md', domain: 'wppilot'); ?></summary>
            <pre class="wppilot-design-md wppilot-detail-md"><?php echo esc_html($design['content']); ?></pre>
        </details>
    </section>

    <?php if ($post instanceof \WP_Post): ?>
        <?php require __DIR__ . '/history.php'; ?>
    <?php endif; ?>
</div>
