<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary. Reads are type-checked and escaped on output.

use WPPilot\Design\Admin;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(esc_html__('You do not have permission to import designs.', domain: 'wppilot'));
}

$gallery_url = admin_url('admin.php?page=' . Admin\PAGE_SLUG);
$action_url = admin_url('admin-post.php');
?>
<?php wppilot_render_admin_header(legend: __('Design', domain: 'wppilot')); ?>
<div class="wrap wppilot-design">
    <a class="wppilot-detail-back" href="<?php echo esc_url($gallery_url); ?>">&larr; <?php esc_html_e(
        'All designs',
        domain: 'wppilot',
    ); ?></a>

    <h1><?php esc_html_e('Import a DESIGN.md', domain: 'wppilot'); ?></h1>
    <p class="wppilot-design-intro"><?php esc_html_e(
        'Bring a design direction from another site or project. The name comes from the front matter or the first heading.',
        domain: 'wppilot',
    ); ?></p>
    <p class="wppilot-design-import-trust"><?php esc_html_e(
        'Your AI agent reads this DESIGN.md, and it has full access to this site. A file from a source you do not control could hide instructions meant to steer the agent beyond design work. Import only files you trust.',
        domain: 'wppilot',
    ); ?></p>

    <form
        method="post"
        action="<?php echo esc_url($action_url); ?>"
        enctype="multipart/form-data"
        class="wppilot-design-import"
        onsubmit="return confirm('<?php echo
            esc_js(__(
                'Your AI agent reads this DESIGN.md, and it has full access to this site. A file from a source you do not control could hide instructions meant to steer the agent beyond design work. Import only files you trust. Continue?',
                domain: 'wppilot',
            ))
        ; ?>');"
    >
        <?php wp_nonce_field('wppilot_design_import'); ?>
        <input type="hidden" name="action" value="wppilot_design_import" />
        <p>
            <label><?php esc_html_e('Upload a .md file:', domain: 'wppilot'); ?>
                <input type="file" name="design_file" accept=".md" />
            </label>
        </p>
        <p><?php esc_html_e('…or paste the DESIGN.md below:', domain: 'wppilot'); ?></p>
        <p>
            <textarea
                name="design_content"
                rows="14"
                class="large-text code"
                placeholder="---&#10;name: My Design&#10;---&#10;## Overview&#10;…"
            ></textarea>
        </p>
        <p>
            <label><input type="checkbox" name="activate" value="1" />
                <?php esc_html_e('Activate after import', domain: 'wppilot'); ?></label>
        </p>
        <p>
            <button type="submit" class="button button-primary"><?php esc_html_e(
                'Import',
                domain: 'wppilot',
            ); ?></button>
            <a href="<?php echo esc_url($gallery_url); ?>" class="button"><?php esc_html_e(
                'Cancel',
                domain: 'wppilot',
            ); ?></a>
        </p>
    </form>
</div>
