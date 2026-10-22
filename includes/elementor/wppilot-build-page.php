<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor: build a whole page from a compact description.
 *
 * Everything here is reachable through add-element already — this exists because
 * the shape of the call matters. Composing a landing page one element at a time
 * is a dozen round trips, each carrying its own parent id and position, and the
 * agent has to hold the layout in its head between them. A single nested
 * description is how a person would say it, and the failure mode is better too:
 * the whole page is validated and written once, so a rejected widget leaves no
 * half-built page behind.
 *
 * The input is deliberately looser than Elementor's own document format. Each
 * node is {type, settings, styles, children} — where `type` is a widget name or
 * a container type — and this file translates that into the element shape
 * set-content already knows how to validate. Anyone who wants the real format
 * still has set-content.
 *
 * Section layouts (`grammar`) and design-spec roles (`role`) are GoBuildWP Pro.
 * Pro supplies them through two filters, so this file never needs to know how
 * either works:
 *
 * - `wppilot_elementor_build_page_node` receives each top-level node before it
 *   is built and resolves the `role` names inside it to class ids.
 * - `wppilot_elementor_build_page_grammar` receives a built section and the
 *   grammar it named, and returns the section with that composition applied.
 *
 * Without a callback on either, a node that asks for one is refused by name.
 * Dropping it would build a page that looks finished and is missing the layout
 * or styling the agent asked for.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/elementor-build-page', [
    'label' => __('Build Elementor Page', domain: 'wppilot'),
    'description' => __(
        'Builds a complete Elementor page from one nested description, in a single call. Not for the site header: use wppilot/build-site-header. Each node is {type, settings, styles, children}: type is a widget name (heading, image, e-heading) or a container type (container, e-flexbox, e-div-block), settings are that element\'s controls, and children nest the same shape. Ids are generated throughout. By default the page is replaced, which is what "build me a landing page" means; pass replace=false to append instead. The whole document is validated before anything is written, so a rejected widget aborts the build and returns that widget\'s compact schema inline rather than leaving a half-finished page. For a single element use wppilot/elementor-add-element; for an exact Elementor document use wppilot/elementor-set-content.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Page to build.'],
            'content' => [
                'type' => 'array',
                'description' => 'Top-level nodes, each {type, settings, styles, children}. With GoBuildWP Pro a node may also carry `grammar`, the name of a section composition from wppilot/list-layout-grammars, and nodes may carry `role`, a role from a compiled design spec (wppilot/elementor-compile-spec). Without Pro, a node naming either is refused.',
                'items' => ['type' => 'object'],
            ],
            'replace' => [
                'type' => 'boolean',
                'description' => 'Replace the existing content. Defaults to true.',
            ],
            'dry_run' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Validate the whole description and report what would be written, without touching the page. Use it before a large build: every node is checked, and the first malformed one names itself, so a page is never left half-written by a description that was wrong at element ninety.',
            ],
        ],
        'required' => ['post_id', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'created' => ['type' => 'integer', 'description' => 'Elements written, including nested ones.'],
            'dry_run' => ['type' => 'boolean', 'description' => 'True when nothing was written.'],
            'would_create' => ['type' => 'integer', 'description' => 'Elements a real run would write, including nested ones.'],
            'top_level' => ['type' => 'integer', 'description' => 'Top-level nodes in the description.'],
            'view_url' => ['type' => 'string', 'description' => 'The public page. Open it and look at what you built before reporting the job done.'],
            'preview_url' => ['type' => 'string', 'description' => 'Viewable even while the page is a draft.'],
            'edit_url' => ['type' => 'string'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => __NAMESPACE__ . '\\elementor_build_page',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        // The default replaces the page's existing content.
        'annotations' => [
            'instructions' => 'Open view_url (or preview_url on a draft) and look at the rendered page before telling anyone the build is finished. Reading back the element tree confirms what you wrote, not what it looks like: a page can carry every element you intended and still land with two sections in the same rhythm, a hero that collapses at one breakpoint, or an accent nobody can read. Judge the result, then fix it.',
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
function elementor_build_page(array $input): array
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $replace = ($input['replace'] ?? true) !== false;
    $dry_run = ($input['dry_run'] ?? false) === true;

    /** @var mixed $content */
    $content = $input['content'] ?? null;
    if (!is_array($content) || $content === []) {
        return ['success' => false, 'error' => 'Parameter "content" must list at least one element.'];
    }

    $created = 0;
    $nodes = [];
    /** @var mixed $node */
    foreach ($content as $node) {
        if (!is_array($node)) {
            return ['success' => false, 'error' => 'Every entry in "content" must be an object.'];
        }

        // Roles resolve before the node is built, because the builder drops
        // keys it does not recognise and a role read afterwards is a role that
        // has already been thrown away.
        if (has_filter('wppilot_elementor_build_page_node')) {
            /** @var mixed $resolved */
            $resolved = apply_filters('wppilot_elementor_build_page_node', $node);
            if ($resolved instanceof \WP_Error) {
                return ['success' => false, 'error' => $resolved->get_error_message()];
            }
            if (is_array($resolved)) {
                $node = $resolved;
            }
        } elseif (el_build_node_has_key($node, 'role')) {
            return [
                'success' => false,
                'error' => 'A node names a design-spec "role", which needs GoBuildWP Pro and a compiled spec (wppilot/elementor-compile-spec). Style the node with explicit settings and styles instead.',
            ];
        }

        $built = el_build_node($node, $created);
        if (is_string($built)) {
            return ['success' => false, 'error' => $built];
        }

        // Applying a grammar creates its global classes in the kit, so a dry
        // run - which promises to write nothing - checks the tree without them.
        $grammar = trim((string) ($node['grammar'] ?? ''));
        if ($grammar !== '') {
            if (!has_filter('wppilot_elementor_build_page_grammar')) {
                return [
                    'success' => false,
                    'error' => 'Section grammars need GoBuildWP Pro and Elementor global classes. Build the section with explicit containers instead.',
                ];
            }
            if (!$dry_run) {
                /** @var mixed $with_grammar */
                $with_grammar = apply_filters('wppilot_elementor_build_page_grammar', $built, $grammar);
                if ($with_grammar instanceof \WP_Error) {
                    return ['success' => false, 'error' => $with_grammar->get_error_message()];
                }
                if (is_array($with_grammar)) {
                    $built = $with_grammar;
                }
            }
        }

        // The same v3 normalisation wppilot/elementor-add-element runs, so the
        // two abilities answer the same description the same way. A container
        // carrying `boxed_width` is split into the full-bleed outer and
        // width-constrained inner pair v4 needs.
        $built = el_split_boxed_containers($built);
        $built = el_translate_v3_container_settings($built);

        $nodes[] = $built;
    }

    // A dry run answers with the verdict the write would reach, which means
    // running the write's own validation and stopping short of the disk.
    // Parsing the node descriptions is not enough: it proves each node looks
    // like an element and says nothing about whether the styles on it are
    // values Elementor accepts.
    if ($dry_run) {
        $existing_for_check = [];
        if (!$replace) {
            [$current] = el_read_page($post_id);
            $existing_for_check = is_array($current) ? $current : [];
        }

        $checked = elementor_prepare_content(
            [...$existing_for_check, ...$nodes],
            (string) ($input['on_invalid'] ?? 'refuse'),
        );
        if (($checked['success'] ?? false) !== true) {
            return array_merge($checked, ['dry_run' => true]);
        }

        return [
            'success' => true,
            'dry_run' => true,
            'would_create' => $created,
            'top_level' => count($nodes),
        ];
    }

    // Routed through set-content rather than repeated inserts: it validates the
    // whole document server-side and returns the offending widget's schema
    // inline, which is the behaviour that makes a failed build correctable in
    // one more call instead of leaving elements already written.
    $existing = [];
    if (!$replace) {
        [$current, $error] = el_read_page($post_id);
        if ($current === null) {
            return ['success' => false, 'error' => $error ?? 'Unknown error.'];
        }
        $existing = $current;
    }

    // set-content names the document "content", not "elements". Passing the
    // wrong key is silently accepted as an empty document.
    /** @var array{success: bool, error?: string} $result */
    $result = elementor_set_content([
        'post_id' => $post_id,
        'content' => [...$existing, ...$nodes],
        'on_invalid' => (string) ($input['on_invalid'] ?? 'refuse'),
    ]);

    if (($result['success'] ?? false) === true) {
        $result['created'] = $created;
        $result = array_merge($result, el_look_at_it($post_id));
    }

    return $result;
}

/**
 * Whether a description node, or any node under it, carries a key.
 *
 * @param array<string, mixed> $node
 */
function el_build_node_has_key(array $node, string $key): bool
{
    if (array_key_exists($key, $node)) {
        return true;
    }
    /** @var mixed $children */
    $children = $node['children'] ?? null;
    if (!is_array($children)) {
        return false;
    }
    /** @var mixed $child */
    foreach ($children as $child) {
        if (is_array($child) && el_build_node_has_key($child, $key)) {
            return true;
        }
    }
    return false;
}

/**
 * Translate one description node into an Elementor element.
 *
 * Returns an error string rather than throwing, so a malformed node names itself
 * in the response instead of surfacing as a generic failure several layers up.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>|string
 */
function el_build_node(array $node, int &$created): array|string
{
    $type = (string) ($node['type'] ?? '');
    if ($type === '') {
        return 'Every node needs a "type": a widget name, or container / e-flexbox / e-div-block.';
    }

    /** @var array<string, mixed> $settings */
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
    /** @var array<string, mixed> $styles */
    $styles = is_array($node['styles'] ?? null) ? $node['styles'] : [];

    $is_container = $type === 'container' || in_array($type, WPPILOT_ATOMIC_CONTAINER_TYPES, strict: true);

    $element = [
        'id' => el_generate_id(),
        'elType' => $is_container ? $type : 'widget',
        'settings' => $settings,
        'elements' => [],
    ];
    if (!$is_container) {
        $element['widgetType'] = $type;
    }
    if (is_string($node['element_id'] ?? null) && $node['element_id'] !== '') {
        $element['id'] = (string) $node['element_id'];
    }
    // edit-element takes the flat `{color, font-size, ...}` map, and an agent
    // that learned the shape there writes it here too. Passed through raw, every
    // property came back as its own `style_entry_not_object` error naming a
    // style id the agent never typed. Wrapping it the way edit-element does
    // makes the two abilities answer the same description the same way.
    if ($styles !== [] && el_looks_like_ergonomic_styles_map($styles)) {
        $checked = el_validate_style_props($styles);
        if (!array_key_exists('props', $checked)) {
            $details = array_merge(
                $checked['unknown_properties'] ?? [],
                array_keys($checked['invalid_values'] ?? []),
            );
            return sprintf(
                'Styles on the "%s" node (%s): %s%s',
                $type,
                (string) $element['id'],
                (string) ($checked['error'] ?? 'invalid styles'),
                $details === [] ? '' : ' Properties: ' . implode(', ', $details) . '.',
            );
        }
        $styles = el_wrap_ergonomic_styles_as_style_def((string) $element['id'], $checked['props']);
    }
    if ($styles !== []) {
        $element['styles'] = $styles;
    }

    $created++;

    /** @var mixed $children */
    $children = $node['children'] ?? null;
    if (is_array($children)) {
        $built_children = [];
        /** @var mixed $child */
        foreach ($children as $child) {
            if (!is_array($child)) {
                return 'Every entry in "children" must be an object.';
            }
            $built = el_build_node($child, $created);
            if (is_string($built)) {
                return $built;
            }
            $built_children[] = $built;
        }
        $element['elements'] = $built_children;
    }

    return $element;
}
