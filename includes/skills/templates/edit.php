<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary. Reads are type-checked and escaped on output.

use WPPilot\Skills\Admin;
use WPPilot\Skills\Cpt;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(esc_html__('You do not have permission to edit skills.', domain: 'wppilot'));
}

$skill_param = $_GET['skill'] ?? 'new';
$id_or_new = is_string($skill_param) ? $skill_param : 'new';
$is_new = $id_or_new === 'new';

$title = '';
$description = '';
$content = '';
$enable_prompt = true;
$enable_agentic = true;
$enabled = true;
$post_id = 0;

if (!$is_new) {
    /** @var mixed $maybe_post */
    $maybe_post = get_post((int) $id_or_new);
    if (!$maybe_post instanceof \WP_Post || $maybe_post->post_type !== Cpt\POST_TYPE) {
        wp_die(esc_html__('Skill not found.', domain: 'wppilot'));
    }

    /** @var \WP_Post $post */
    $post = $maybe_post;
    $post_id = (int) $post->ID;
    $title = $post->post_name !== '' ? $post->post_name : $post->post_title;
    $description = $post->post_excerpt;
    $content = $post->post_content;
    $enable_prompt = boolval(get_post_meta($post_id, Cpt\META_ENABLE_PROMPT, single: true));
    $enable_agentic = boolval(get_post_meta($post_id, Cpt\META_ENABLE_AGENTIC, single: true));
    $enabled = $post->post_status === 'publish';
}

$list_url = admin_url('admin.php?page=' . Admin\PAGE_SLUG);
$action_url = admin_url('admin-post.php');
$nonce_action = $is_new ? 'wppilot_skill_create' : 'wppilot_skill_update_' . $post_id;
$form_action = $is_new ? 'wppilot_skill_create' : 'wppilot_skill_update';

$heading_title = match (true) {
    $is_new => __('New skill', domain: 'wppilot'),
    $title !== '' => $title,
    default => __('Untitled', domain: 'wppilot'),
};
?>
<?php wppilot_render_admin_header(); ?>
<div class="wrap wppilot-skills-edit">
    <h1>
        <a href="<?php echo esc_url($list_url); ?>">← <?php esc_html_e('Skills', domain: 'wppilot'); ?></a>
        / <?php echo esc_html($heading_title); ?>
    </h1>

    <form method="post" action="<?php echo esc_url($action_url); ?>">
        <?php wp_nonce_field($nonce_action); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr($form_action); ?>" />
        <?php if ($post_id > 0): ?>
            <input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
        <?php endif; ?>

        <div class="wppilot-skills-edit-grid">
            <div class="wppilot-skills-edit-main">
                <div class="wppilot-skills-title-field">
                    <input
                        type="text"
                        name="title"
                        value="<?php echo esc_attr($title); ?>"
                        required
                        placeholder="<?php esc_attr_e('untitled-skill', domain: 'wppilot'); ?>"
                        class="wppilot-skills-title-input"
                        aria-label="<?php esc_attr_e('Title', domain: 'wppilot'); ?>"
                    />
                </div>
                <div class="wppilot-skills-field">
                    <label
                        for="wppilot-skills-description"
                        class="wppilot-skills-field-label"
                    ><?php esc_html_e('Description', domain: 'wppilot'); ?></label>
                    <textarea
                        name="description"
                        id="wppilot-skills-description"
                        rows="2"
                        required
                        class="large-text"
                        placeholder="<?php esc_attr_e(
                            'e.g. Builds a landing page from a brief, following the site\'s design system.',
                            domain: 'wppilot',
                        ); ?>"
                    ><?php echo esc_textarea($description); ?></textarea>
                    <div class="wppilot-skills-field-help">
                        <p><?php

                        printf(
                            /* translators: %s: emphasised word "when" */
                            esc_html__('Describe %s to use this skill, not what it does or how.', domain: 'wppilot'),
                            '<strong>' . esc_html__('when', domain: 'wppilot') . '</strong>',
                        );
                        ?></p>
                        <ul class="wppilot-skills-field-examples">
                            <li>
                                <span class="wppilot-skills-example-label"><?php

                                esc_html_e('Too vague', domain: 'wppilot');
                                ?></span>
                                <span class="wppilot-skills-example-text"><?php esc_html_e(
                                    'Helps with content.',
                                    domain: 'wppilot',
                                ); ?></span>
                            </li>
                            <li>
                                <span class="wppilot-skills-example-label is-better"><?php

                                esc_html_e('Better', domain: 'wppilot');
                                ?></span>
                                <span class="wppilot-skills-example-text"><?php esc_html_e(
                                    'Builds a landing page from a brief, following the site\'s design system.',
                                    domain: 'wppilot',
                                ); ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="wppilot-skills-field">
                    <label
                        for="wppilot-skills-content"
                        class="wppilot-skills-field-label"
                    ><?php esc_html_e('Body', domain: 'wppilot'); ?></label>
                    <div class="wppilot-skills-md-toolbar" role="toolbar" aria-label="<?php

                    esc_attr_e('Markdown formatting', domain: 'wppilot');
                    ?>">
                        <button type="button" data-md="bold" title="<?php

                        esc_attr_e('Bold', domain: 'wppilot');
                        ?>"><strong>B</strong></button>
                        <button type="button" data-md="italic" title="<?php

                        esc_attr_e('Italic', domain: 'wppilot');
                        ?>"><em>I</em></button>
                        <button type="button" data-md="heading" title="<?php

                        esc_attr_e('Heading', domain: 'wppilot');
                        ?>">H</button>
                        <button type="button" data-md="list" title="<?php

                        esc_attr_e('Bulleted list', domain: 'wppilot');
                        ?>">•</button>
                        <button type="button" data-md="code" title="<?php

                        esc_attr_e('Inline code', domain: 'wppilot');
                        ?>"><code>&lt;/&gt;</code></button>
                        <button type="button" data-md="link" title="<?php

                        esc_attr_e('Link', domain: 'wppilot');
                        ?>">🔗</button>
                    </div>
                    <div class="wppilot-skills-body-wrap">
                        <textarea
                            name="content"
                            id="wppilot-skills-content"
                            rows="20"
                            class="large-text code"
                        ><?php echo esc_textarea($content); ?></textarea>
                        <?php if ($is_new): ?>
                            <div
                                class="wppilot-skills-body-hint"
                                data-wppilot-skills-body-hint
                                aria-hidden="true"
                            >
                                <p>
                                    <span class="wppilot-skills-body-hint-emoji">🤖</span>
                                    <strong><?php esc_html_e(
                                        'Pssst, you don\'t have to write this by hand.',
                                        domain: 'wppilot',
                                    ); ?></strong>
                                </p>
                                <p><?php

                                printf(
                                    /* translators: %s: example natural-language prompt */
                                    esc_html__('Just say to your AI: %s', domain: 'wppilot'),
                                    '<em>“'
                                    . esc_html__(
                                        'Create a GoBuildWP skill that builds landing pages from a brief.',
                                        domain: 'wppilot',
                                    )
                                    . '”</em>',
                                );
                                ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="wppilot-skills-edit-sidebar">
                <h2><?php esc_html_e('Settings', domain: 'wppilot'); ?></h2>
                <p class="wppilot-skills-checkbox-row">
                    <label>
                        <input type="checkbox" name="enable_agentic" value="1" <?php checked($enable_agentic); ?> />
                        <strong><?php esc_html_e('AI uses it automatically', domain: 'wppilot'); ?></strong>
                    </label>
                    <small class="description"><?php esc_html_e(
                        'The AI discovers this skill from its description and runs it when the task matches.',
                        domain: 'wppilot',
                    ); ?></small>
                </p>
                <p class="wppilot-skills-checkbox-row">
                    <label>
                        <input type="checkbox" name="enable_prompt" value="1" <?php checked($enable_prompt); ?> />
                        <strong><?php esc_html_e('You can invoke it manually', domain: 'wppilot'); ?></strong>
                    </label>
                    <small class="description"><?php esc_html_e(
                        'Lets you call this skill directly from your AI client, instead of waiting for the AI to pick it.',
                        domain: 'wppilot',
                    ); ?></small>
                </p>
                <div class="wppilot-skills-save-row">
                    <label for="wppilot-skills-status" class="screen-reader-text"><?php

                    esc_html_e('Status', domain: 'wppilot');
                    ?></label>
                    <select id="wppilot-skills-status" name="status">
                        <option value="publish" <?php selected($enabled, current: true); ?>><?php

                        esc_html_e('Enabled', domain: 'wppilot');
                        ?></option>
                        <option value="draft" <?php selected($enabled, current: false); ?>><?php

                        esc_html_e('Disabled', domain: 'wppilot');
                        ?></option>
                    </select>
                    <button type="submit" class="button button-primary"><?php esc_html_e(
                        'Save',
                        domain: 'wppilot',
                    ); ?></button>
                </div>
            </div>
        </div>
    </form>
</div>
<script>
(function () {
    // Body placeholder — visible only when content is empty.
    function wireBodyHint(cm) {
        var hint = document.querySelector('[data-wppilot-skills-body-hint]');
        if (!hint || !cm) {
            return;
        }
        var update = function () {
            if (cm.getValue().length > 0) {
                hint.classList.add('is-hidden');
            } else {
                hint.classList.remove('is-hidden');
            }
        };
        cm.on('change', update);
        update();
    }

    // Title normalisation (on blur + submit).
    var titleInput = document.querySelector('input[name="title"]');
    if (titleInput) {
        var normalize = function (raw) {
            return raw
                .toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9_-]/g, '')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');
        };
        var applyTitle = function () {
            var raw = titleInput.value;
            var normalized = normalize(raw);
            if (normalized !== raw) {
                titleInput.value = normalized;
            }
        };
        titleInput.addEventListener('blur', applyTitle);
        var form = titleInput.form;
        if (form) {
            form.addEventListener('submit', applyTitle);
        }
    }

    // Markdown toolbar — wraps the current selection (or inserts a
    // template) into the CodeMirror instance exposed as
    // window.wppilotSkillsEditor.
    function wireToolbar(cm) {
        var toolbar = document.querySelector('.wppilot-skills-md-toolbar');
        if (!toolbar || !cm) {
            return;
        }
        toolbar.addEventListener('click', function (event) {
            var btn = event.target.closest('button[data-md]');
            if (!btn) {
                return;
            }
            event.preventDefault();
            var action = btn.getAttribute('data-md');
            var selection = cm.getSelection();
            var hasSelection = selection.length > 0;
            var cursor = cm.getCursor();
            switch (action) {
                case 'bold':
                    cm.replaceSelection('**' + (hasSelection ? selection : 'bold text') + '**');
                    break;
                case 'italic':
                    cm.replaceSelection('*' + (hasSelection ? selection : 'italic text') + '*');
                    break;
                case 'code':
                    cm.replaceSelection('`' + (hasSelection ? selection : 'code') + '`');
                    break;
                case 'link':
                    cm.replaceSelection('[' + (hasSelection ? selection : 'text') + '](https://)');
                    break;
                case 'heading': {
                    var lineNo = cursor.line;
                    var line = cm.getLine(lineNo);
                    var match = line.match(/^(#{1,5})\s/);
                    var newLine;
                    if (match) {
                        newLine = '#'.repeat(match[1].length + 1) + ' ' + line.slice(match[0].length);
                        if (match[1].length >= 5) {
                            newLine = line.replace(/^#+\s/, '');
                        }
                    } else {
                        newLine = '# ' + line;
                    }
                    cm.replaceRange(newLine, { line: lineNo, ch: 0 }, { line: lineNo, ch: line.length });
                    break;
                }
                case 'list': {
                    var lineNo2 = cursor.line;
                    var line2 = cm.getLine(lineNo2);
                    var newLine2 = /^- /.test(line2) ? line2.slice(2) : '- ' + line2;
                    cm.replaceRange(newLine2, { line: lineNo2, ch: 0 }, { line: lineNo2, ch: line2.length });
                    break;
                }
            }
            cm.focus();
        });
    }

    function wireBodyExtras(cm) {
        wireToolbar(cm);
        wireBodyHint(cm);
    }

    if (window.wppilotSkillsEditor) {
        wireBodyExtras(window.wppilotSkillsEditor);
    } else {
        window.addEventListener('wppilot-skills-editor-ready', function () {
            wireBodyExtras(window.wppilotSkillsEditor);
        });
    }
})();
</script>
