<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Ability: Set the Elementor document tree for a post.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/elementor-set-content', [
    'label' => __('Set Elementor Content', domain: 'wppilot'),
    'description' => __(
        'Writes a full Elementor document tree to a post, invalidates Elementor\'s internal CSS caches, and fires WordPress\'s `clean_post_cache` so third-party optimization plugins (Perfmatters, WP Rocket, LiteSpeed, etc.) that listen for post changes can purge their per-post caches. A CDN, server cache or optimiser that does not listen for post changes can still serve the old page, so check the live page before reporting the change as visible. Validation runs server-side on every call: unknown control IDs or invalid enum values abort the write (nothing is persisted) and return the compact content-only schemas of the affected widget types INLINE in the error, so you can correct and retry in a single roundtrip. wppilot/elementor-get-schema is an OPTIONAL discovery tool for learning what widgets exist and what their controls look like — it is NOT required before writing, validation is automatic. Shape rules: use only keys present in the widget\'s "controls" map; select/choose values MUST come from the control\'s "opts"; switchers use "rv" for on and "" for off; typography requires typography_typography="custom" first; dimensions are {unit,top,right,bottom,left,isLinked}; sliders are {size,unit}; colors are #RRGGBB; scalars for "arr: true" v3 controls are auto-wrapped into a one-element array; v3 controls carrying an "r" flag in the schema accept responsive overrides via the suffixed key `<key>_<breakpoint>` (e.g. typography_font_size_tablet, padding_mobile, _padding_widescreen) — `r:1` means every breakpoint is allowed, while `r:{min:<bp>}` / `r:{max:<bp>}` / `r:{min,max}` restrict the suffix to a closed window in the canonical size order (mobile < mobile_extra < tablet < tablet_extra < laptop < desktop < widescreen). Breakpoint names are the v4 ones listed in check-setup.kit.active_breakpoints and the same value shape applies to every variant; ATOMIC (v4) widgets accept ergonomic scalar values which the server auto-wraps into {"$$type": "<prop_key>", "value": <scalar>} — for both `settings` AND inside the `styles` map\'s `props` (e.g. color:"#FFFFFF", font-size:72, padding:{block-start:16,...}); or you can pass the wrapped shape directly; v4 atomic responsive uses the `styles` map\'s `variants[]` with `meta.breakpoint` (NOT suffixed keys); CONTENT FIELDS — single-line text controls (heading.title, button.text, icon-list[].text, image.caption, …) take plain text only; wysiwyg controls (text-editor.editor, testimonial.testimonial_content, …) take text-level HTML: paragraphs (`<p>`), lists (`<ul>`/`<ol>` with `<li>`), `<strong>`, `<em>`, `<a>`, `<br>`; NEVER put `style` attributes, wrapper `<div>`s or other layout markup in them — alignment goes in `align`, font sizing/weight/family in `typography_*`, max-width in `_element_custom_width`, spacing in `_padding`/`_margin` (all responsive via the suffix shape above); the dedicated `html` widget IS the place for arbitrary HTML; do not emit controls whose "if" condition is not satisfied.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The WordPress post ID.',
            ],
            'content' => [
                'type' => 'array',
                'description' => 'Top-level Elementor elements (containers/sections). Each element uses either `element_type` (consistent with add-element: "container", "widget", "e-flexbox", "e-div-block", or the classic "section" / "column" that elementor-get-content returns for pre-container pages) or the native Elementor `elType` key — both are accepted. Widget elements also accept `widget_type` or `widgetType`. Settings and optional nested `elements` children complete each node. IDs are auto-generated where missing.',
                'items' => ['type' => 'object'],
            ],
            'template_type' => [
                'type' => 'string',
                'description' => 'Elementor template type. Omit it to keep the document\'s current type (a header stays a header); a post without one becomes "wp-page".',
            ],
        ],
        'required' => ['post_id', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'post_id' => ['type' => 'integer'],
            'edit_url' => ['type' => 'string'],
            'view_url' => ['type' => 'string', 'description' => 'The public page. Open it and look at what you built. Empty until the page is published — use preview_url for a draft.'],
            'preview_url' => ['type' => 'string', 'description' => 'Viewable even while the page is a draft.'],
            'assigned_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Element IDs that were auto-generated for elements that did not provide one. Empty when every element carried its own id.',
            ],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_set_content',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => 'Each element accepts either the add-element-style keys (element_type + widget_type) or the native Elementor keys (elType + widgetType) — both work. Top-level containers use element_type="container" or elType="container". Widgets use element_type="widget" + widget_type="heading" (or elType="widget" + widgetType="heading"). Children go in the "elements" array. Settings shape depends on the widget/container type — use wppilot/elementor-get-schema to inspect it. IDs are 7-char hex; provide your own or let them be auto-generated. Atomic v4 widgets auto-wrap scalar values into the $$type envelope — both for settings and inside per-element styles\' variant props.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
/**
 * Normalize and validate a document, without writing it.
 *
 * Split out of elementor_set_content so a dry run can reach the same verdict
 * the real write reaches. It could not before: build-page's dry run stopped
 * after parsing its own node descriptions, which checks that each node is
 * shaped like an element and nothing about whether the styles on it are
 * values Elementor will accept. A description carrying a flat CSS map instead
 * of style entries passed the dry run and was then refused, in full, by the
 * write — so the one call whose entire purpose is "tell me before I commit"
 * was the call that could not tell you.
 *
 * @param  list<array<string, mixed>> $content
 * @return array{success: bool, tree?: list<array<string, mixed>>, assigned?: array<string, mixed>, error?: string}
 */
function elementor_prepare_content(array $content, string $on_invalid = 'refuse'): array
{
    $assigned = [];
    $normalized = el_normalize_tree($content, $assigned);

    // Run the same universal atomic pipeline add-element does, so a
    // page written from scratch via set-content gets the same wrapping,
    // CSS-id sanitization, breakpoint normalization, and classes sync.
    // V3-only steps (boxed splitter, v3 settings translator) deliberately
    // do NOT run here — set-content is the "I already have v4 atomic"
    // surface and must not silently rewrite legitimate v4 values.
    $dynamic_tag_errors = [];
    $normalized = el_finalize_atomic_forest($normalized, $dynamic_tag_errors);

    $validation = el_validate_tree($normalized);

    // The validator has already produced a usable tree: it drops the keys it
    // could not accept and coerces what it could. Refusing the document is a
    // policy on top of that, and for hand-written content it is the right one -
    // somebody typed a key and should hear that it was wrong.
    //
    // For generated content it is the wrong one, and expensively so. A single
    // `alt` on an image the schema does not declare took down a seventy-four
    // element page; one typography key a widget had never heard of took down
    // three hundred and fifty. Losing one property beats losing the page, so a
    // caller that generated the tree can ask for the coerced version and the
    // errors as warnings instead.
    if ($on_invalid === 'drop' && $dynamic_tag_errors === [] && !$validation['ok']) {
        return [
            'success' => true,
            'tree' => $validation['tree'],
            'assigned' => $assigned,
            'dropped' => $validation['errors'],
        ];
    }

    if ($dynamic_tag_errors !== [] || !$validation['ok']) {
        return build_tree_validation_error_response($validation, $dynamic_tag_errors);
    }

    return ['success' => true, 'tree' => $validation['tree'], 'assigned' => $assigned];
}

function elementor_set_content(array $input): array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['success' => false, 'error' => 'Elementor is not active.'];
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return ['success' => false, 'error' => "Post {$post_id} not found."];
    }

    /** @var list<array<string, mixed>> $content */
    $content = is_array($input['content'] ?? null) ? array_values($input['content']) : [];
    // Without an explicit type the document keeps its own: a Theme Builder header rewritten as
    // `wp-page` stops being a header, and its display conditions stop placing it anywhere.
    $template_type = is_string($input['template_type'] ?? null) && $input['template_type'] !== '' ? $input['template_type'] : null;

    $prepared = elementor_prepare_content(
        $content,
        (string) ($input['on_invalid'] ?? 'refuse'),
    );
    if (($prepared['success'] ?? false) !== true) {
        return $prepared;
    }
    /** @var list<array<string, mixed>> $tree */
    $tree = $prepared['tree'];
    /** @var array<string, mixed> $assigned */
    $assigned = $prepared['assigned'];

    $result = el_write_page($post_id, $tree, $template_type);
    if (is_wp_error($result)) {
        return ['success' => false, 'error' => $result->get_error_message()];
    }

    // array_merge, not spreads: unpacking string keys is a fatal on PHP 8.0,
    // and it would land after the page was already written.
    return array_merge(
        [
            'success' => true,
            'post_id' => $post_id,
            'assigned_ids' => $assigned,
        ],
        // What the document was written without. Reported rather than logged,
        // because a page that built with a property missing and said nothing is
        // the same silence this whole change exists to remove - the caller has
        // to be able to see it and decide whether it mattered.
        ($prepared['dropped'] ?? []) !== [] ? ['dropped' => $prepared['dropped']] : [],
        el_look_at_it($post_id),
    );
}
