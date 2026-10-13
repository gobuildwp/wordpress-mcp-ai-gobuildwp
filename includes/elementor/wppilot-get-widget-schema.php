<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Ability: Unified Elementor schema reader.
 *
 * One MCP tool, two actions:
 *   action=list  → enumerate every registered widget
 *   action=get   → describe one or more widgets in compact form (default)
 *
 * Progressive disclosure is layered on top of the compact default:
 *   include_styles  include style / advanced / visibility tab controls;
 *                   pair with control_names, tab, or section whenever possible
 *   verbose         attach labels / descriptions / widget metadata
 *   control_names   restrict the controls map to those names, always verbose
 *   tab             restrict controls to a single tab (content, style, advanced, ...)
 *   section         restrict controls to a single section id
 *
 * The compact output is the same `{t, opts, def, rv, if, arr, fields}` shape
 * used by `elementor-set-content` / `elementor-add-widget` /
 * `elementor-edit-element` during validation, so a caller that asks for a
 * schema can feed the result straight back into a write call.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/elementor-get-schema', [
    'label' => __('Get Elementor Widget Schema', domain: 'wppilot'),
    'description' => __(
        'Discovery and exploration tool for Elementor widgets (v3 and v4) and the container element. Use this to understand what widgets exist, what controls they expose, and what enum values they accept. NOT required before writing — set-content / add-element / edit-element validate server-side on every call and return the compact schema of the affected widget INLINE in any error response. Two actions: "list" (discover) and "get" (describe). LIST without filters returns a minimal overview: {categories (alphabetically sorted list of category names — use these as "category" filter values), sources (map of package → widget count — use these as "source" filter values)}. No widget names are returned in the overview — drill into a subset with filters. LIST with any filter (name_contains, is_atomic, source, category — combined with AND) returns a flat widget list with {name, title, is_atomic, categories, source} per entry. For v4 atomic widgets use is_atomic:true. For keyword search use name_contains. GET takes widget_types (a list of strings, "__container__" allowed) and returns compact schemas {t, opts?, def?, rv?, if?, arr?, fields?} by default. For style checks, prefer include_styles:true with control_names:["padding","margin",...] or tab/section narrowing; broad include_styles:true on common v3 widgets can be very large. Use verbose:true only when labels / widget metadata are needed. The compact output shape is exactly what set-content / add-element / edit-element expect, so feed it back verbatim when you do use it.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform. "list" discovers widgets: without filters it returns a grouped overview (categories, atomics, sources, counts) in a single call; with any filter set it returns a flat filtered widget list instead. "get" describes widgets in compact form.',
                'enum' => ['list', 'get'],
            ],
            'widget_types' => [
                'type' => 'array',
                'description' => 'Widget type names to describe. Required for action "get". Pass "__container__" to include the container element.',
                'items' => ['type' => 'string'],
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Filter "list" results to widgets that declare this Elementor category (e.g. "v4-elements", "basic", "dynamic-content-for-elementor-post"). Combines in AND with other filters.',
            ],
            'name_contains' => [
                'type' => 'string',
                'description' => 'Filter "list" results to widgets whose name contains this case-insensitive substring (e.g. "heading" → heading, e-heading, animated-headline).',
            ],
            'is_atomic' => [
                'type' => 'boolean',
                'description' => 'Filter "list" results to only atomic (v4) widgets when true, or only legacy (v3) widgets when false.',
            ],
            'source' => [
                'type' => 'string',
                'description' => 'Filter "list" results by the widget\'s source package. One of: "elementor" (core v3), "elementor-pro", "atomic" (any atomic v4 core widget), "dynamic-content-for-elementor", or the sanitized namespace of a third-party plugin.',
            ],
            'include_styles' => [
                'type' => 'boolean',
                'description' => 'For action "get": when true, also include controls from the style / advanced / visibility tabs. Defaults to false (content only). Prefer pairing this with control_names, tab, or section; broad style reads for v3 widgets can be very large.',
            ],
            'verbose' => [
                'type' => 'boolean',
                'description' => 'For action "get": when true, include widget metadata (icon, categories, keywords) and control labels / descriptions. Defaults to false.',
            ],
            'control_names' => [
                'type' => 'array',
                'description' => 'For action "get": restrict the controls map to the listed control IDs. When set, those controls are always returned in verbose form.',
                'items' => ['type' => 'string'],
            ],
            'tab' => [
                'type' => 'string',
                'description' => 'For action "get": restrict controls to a single tab (e.g. "content", "style", "advanced").',
            ],
            'section' => [
                'type' => 'string',
                'description' => 'For action "get": restrict controls to a single section id (e.g. "section_title").',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'categories' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'For action "list" without filters: alphabetically sorted list of every Elementor category name declared by a registered widget. Use these strings as the "category" filter value.',
            ],
            'sources' => [
                'type' => 'object',
                'description' => 'For action "list" without filters: map of source package identifier (elementor / elementor-pro / third-party namespace) to the count of widgets from that source. Use these exact strings as the "source" filter value.',
            ],
            'widgets' => [
                'description' => 'For action "list" WITH at least one filter: the filtered flat widget list, each entry containing {name, title, is_atomic, categories, source}. For action "get": a map of widget_type => schema.',
            ],
            'count' => [
                'type' => 'integer',
                'description' => 'For action "list" with filters: the number of widgets that matched.',
            ],
            'filters' => [
                'type' => 'object',
                'description' => 'For action "list" with filters: the normalized filters that were applied, echoed back so you can verify how the server interpreted them.',
            ],
            'missing' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'For action "get": widget types that were requested but could not be resolved.',
            ],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_get_schema',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Execute the unified get-schema ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function elementor_get_schema(array $input): array
{
    if (!class_exists(\Elementor\Plugin::class)) {
        return [
            'success' => false,
            'error' => 'Elementor is not active or not fully loaded.',
        ];
    }

    $action = (string) ($input['action'] ?? '');

    if ($action === 'list') {
        return schema_handle_list($input);
    }

    if ($action === 'get') {
        return schema_get_widgets($input);
    }

    return [
        'success' => false,
        'error' => sprintf('Unknown action "%s". Use "list" or "get".', $action),
    ];
}

/**
 * Internal widget names that should never appear in list output — they are
 * abstract base classes used by Elementor for inheritance rather than real
 * widgets the caller can instantiate.
 */
const WPPILOT_LIST_SKIP_WIDGETS = ['common', 'common-base', 'common-optimized', 'global'];

/**
 * Dispatch between the grouped overview (no filters) and the filtered flat
 * list (any filter set). Progressive disclosure: calling `list` once with no
 * filters gives the caller a complete, already-organized picture of every
 * available widget. Filters are a drill-down tool for narrow subsets.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function schema_handle_list(array $input): array
{
    $filters = schema_parse_list_filters($input);

    if ($filters !== []) {
        return schema_list_filtered($filters);
    }

    return schema_list_overview();
}

/**
 * Extract and normalize the list filters from the raw input.
 *
 * @param array<string, mixed> $input
 * @return array{category?: string, name_contains?: string, is_atomic?: bool, source?: string}
 */
function schema_parse_list_filters(array $input): array
{
    $filters = [];

    /** @var mixed $category */
    $category = $input['category'] ?? null;
    if (is_string($category) && $category !== '') {
        $filters['category'] = $category;
    }

    /** @var mixed $name_contains */
    $name_contains = $input['name_contains'] ?? null;
    if (is_string($name_contains) && $name_contains !== '') {
        $filters['name_contains'] = strtolower($name_contains);
    }

    if (array_key_exists('is_atomic', $input) && is_bool($input['is_atomic'])) {
        $filters['is_atomic'] = $input['is_atomic'];
    }

    /** @var mixed $source */
    $source = $input['source'] ?? null;
    if (is_string($source) && $source !== '') {
        $filters['source'] = $source;
    }

    return $filters;
}

/**
 * Build the minimal overview for action=list without filters. Returns only
 * what the caller needs to discover valid values for the `category` and
 * `source` filters — no widget names. For actual widget listings the caller
 * drills with a filter (name_contains / is_atomic / category / source).
 *
 * @return array<string, mixed>
 */
function schema_list_overview(): array
{
    $manager = \Elementor\Plugin::$instance->widgets_manager;
    /** @var array<string, \Elementor\Widget_Base> $types */
    $types = $manager->get_widget_types();

    /** @var array<string, bool> $categories_set */
    $categories_set = [];
    /** @var array<string, int> $sources */
    $sources = [];

    foreach ($types as $name => $widget) {
        if (!schema_widget_is_listable($name, $widget)) {
            continue;
        }

        $source = schema_detect_widget_source($widget);
        $sources[$source] = ($sources[$source] ?? 0) + 1;

        /** @var list<string> $categories */
        $categories = $widget->get_categories();
        if ($categories === []) {
            $categories = ['uncategorized'];
        }

        foreach ($categories as $category) {
            $categories_set[$category] = true;
        }
    }

    // Also include atomic container elements (e-flexbox, e-div-block).
    foreach (WPPILOT_ATOMIC_CONTAINER_TYPES as $atomic_type) {
        $schema = build_atomic_element_compact_schema($atomic_type);
        if ($schema === null) {
            continue;
        }
        $sources['elementor'] = ($sources['elementor'] ?? 0) + 1;
        $categories_set['v4-elements'] = true;
    }

    $categories_list = array_keys($categories_set);
    sort($categories_list);
    ksort($sources);

    return [
        'success' => true,
        'categories' => $categories_list,
        'sources' => $sources,
    ];
}

/**
 * Return a flat widget list after applying the filters. Every entry
 * includes the bits a caller usually needs to decide what to use next:
 * name, human title, atomic flag, its Elementor categories, and the
 * detected source package.
 *
 * @param array{category?: string, name_contains?: string, is_atomic?: bool, source?: string} $filters
 * @return array<string, mixed>
 */
function schema_list_filtered(array $filters): array
{
    $manager = \Elementor\Plugin::$instance->widgets_manager;
    /** @var array<string, \Elementor\Widget_Base> $types */
    $types = $manager->get_widget_types();

    $matched = [];
    foreach ($types as $name => $widget) {
        if (!schema_widget_is_listable($name, $widget)) {
            continue;
        }
        if (!schema_widget_matches_filters($name, $widget, $filters)) {
            continue;
        }

        /** @var list<string> $categories */
        $categories = $widget->get_categories();
        $matched[] = [
            'name' => $name,
            'title' => $widget->get_title(),
            'is_atomic' => is_atomic_widget($widget),
            'categories' => $categories,
            'source' => schema_detect_widget_source($widget),
        ];
    }

    // Also include atomic container elements when they match the filters.
    $matched = schema_append_atomic_containers($matched, $filters);

    return [
        'success' => true,
        'widgets' => $matched,
        'count' => count($matched),
        'filters' => $filters,
    ];
}

/**
 * Append atomic container elements (e-flexbox, e-div-block) to a filtered
 * widget list when they match the given filters.
 *
 * @param list<array<string, mixed>> $matched
 * @param array{category?: string, name_contains?: string, is_atomic?: bool, source?: string} $filters
 * @return list<array<string, mixed>>
 */
function schema_append_atomic_containers(array $matched, array $filters): array
{
    foreach (WPPILOT_ATOMIC_CONTAINER_TYPES as $atomic_type) {
        if (!schema_atomic_container_matches_filters($atomic_type, $filters)) {
            continue;
        }

        $schema = build_atomic_element_compact_schema($atomic_type);
        if ($schema === null) {
            continue;
        }

        $matched[] = [
            'name' => $atomic_type,
            'title' => $schema['label'],
            'is_atomic' => true,
            'categories' => ['v4-elements'],
            'source' => 'elementor',
        ];
    }

    return $matched;
}

/**
 * @param array{category?: string, name_contains?: string, is_atomic?: bool, source?: string} $filters
 */
function schema_atomic_container_matches_filters(string $name, array $filters): bool
{
    $name_contains = $filters['name_contains'] ?? null;
    if (is_string($name_contains) && !str_contains(strtolower($name), $name_contains)) {
        return false;
    }
    if (array_key_exists('is_atomic', $filters) && $filters['is_atomic'] !== true) {
        return false;
    }
    $category_filter = $filters['category'] ?? null;
    if (is_string($category_filter) && $category_filter !== 'v4-elements') {
        return false;
    }
    $source_filter = $filters['source'] ?? null;
    if (is_string($source_filter) && $source_filter !== 'elementor') {
        return false;
    }

    return true;
}

/**
 * Is a given widget worth exposing in a list response? Drops internal base
 * classes and widgets with empty titles (abstract / development-only).
 */
function schema_widget_is_listable(string $name, \Elementor\Widget_Base $widget): bool
{
    if (in_array($name, WPPILOT_LIST_SKIP_WIDGETS, strict: true)) {
        return false;
    }

    if (trim($widget->get_title()) === '') {
        return false;
    }

    return true;
}

/**
 * Apply the AND-combined filter set to a single widget.
 *
 * @param array{category?: string, name_contains?: string, is_atomic?: bool, source?: string} $filters
 */
function schema_widget_matches_filters(string $name, \Elementor\Widget_Base $widget, array $filters): bool
{
    $name_contains = $filters['name_contains'] ?? null;
    if (is_string($name_contains) && !str_contains(strtolower($name), $name_contains)) {
        return false;
    }

    if (array_key_exists('is_atomic', $filters) && is_atomic_widget($widget) !== $filters['is_atomic']) {
        return false;
    }

    $category_filter = $filters['category'] ?? null;
    if (is_string($category_filter)) {
        /** @var list<string> $categories */
        $categories = $widget->get_categories();
        if (!in_array($category_filter, $categories, strict: true)) {
            return false;
        }
    }

    $source_filter = $filters['source'] ?? null;
    if (is_string($source_filter) && schema_detect_widget_source($widget) !== $source_filter) {
        return false;
    }

    return true;
}

/**
 * Detect the source package of a widget from its PHP class namespace.
 * Returns a stable identifier the caller can feed back into a `source`
 * filter — the values are exactly what the grouped overview surfaces in
 * its `sources` field so the caller never has to guess.
 */
function schema_detect_widget_source(\Elementor\Widget_Base $widget): string
{
    $class = get_class($widget);

    if (str_starts_with($class, 'ElementorPro\\')) {
        return 'elementor-pro';
    }

    if (str_starts_with($class, 'Elementor\\')) {
        return 'elementor';
    }

    $parts = explode('\\', $class);
    if (count($parts) > 1) {
        return sanitize_title($parts[0]);
    }

    return 'unknown';
}

/**
 * Dispatch for action=get. Accepts one or many widget types and returns a
 * compact or verbose schema for each.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function schema_get_widgets(array $input): array
{
    /** @var mixed $raw_types */
    $raw_types = $input['widget_types'] ?? null;
    if (!is_array($raw_types) || $raw_types === []) {
        return [
            'success' => false,
            'error' => 'Parameter "widget_types" must be a non-empty array of widget type names when action is "get".',
        ];
    }

    $opts = schema_parse_get_options($input);

    $widgets = [];
    $missing = [];

    /** @var mixed $raw */
    foreach ($raw_types as $raw) {
        if (!is_string($raw) || $raw === '') {
            continue;
        }

        $schema = schema_build_single($raw, $opts);
        if ($schema === null) {
            $missing[] = $raw;
            continue;
        }

        $widgets[$raw] = $schema;
    }

    $response = [
        'success' => true,
        'widgets' => $widgets,
    ];

    if ($missing !== []) {
        $response['missing'] = $missing;
    }

    if ($opts['include_styles'] && $opts['control_names'] === [] && $opts['tab'] === '' && $opts['section'] === '') {
        $response['hint'] = 'Broad include_styles:true reads can be large for v3 widgets. Prefer control_names:["padding","margin",...] or tab / section when checking for specific styling controls.';
    }

    if (!array_key_exists('hint', $response) && !$opts['verbose'] && $opts['control_names'] === []) {
        $response['hint'] = 'Compact view. Pass control_names:["id",...] to zoom into specific controls, include_styles:true plus control_names / tab / section for style controls, or verbose:true only when labels / widget metadata are needed.';
    }

    return $response;
}

/**
 * Normalize the "get" input into an internal options struct used by every
 * downstream helper.
 *
 * @param array<string, mixed> $input
 * @return array{include_styles: bool, verbose: bool, control_names: list<string>, tab: string, section: string}
 */
function schema_parse_get_options(array $input): array
{
    /** @var list<string> $control_names */
    $control_names = [];
    if (array_key_exists('control_names', $input) && is_array($input['control_names'])) {
        /** @var mixed $name */
        foreach ($input['control_names'] as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $control_names[] = $name;
        }
    }

    return [
        'include_styles' => ($input['include_styles'] ?? false) === true,
        'verbose' => ($input['verbose'] ?? false) === true,
        'control_names' => $control_names,
        'tab' => (string) ($input['tab'] ?? ''),
        'section' => (string) ($input['section'] ?? ''),
    ];
}

/**
 * Build the schema for a single widget, applying progressive-disclosure
 * filters (tab, section, control_names, verbose).
 *
 * @param array{include_styles: bool, verbose: bool, control_names: list<string>, tab: string, section: string} $opts
 * @return array<string, mixed>|null
 */
function schema_build_single(string $widget_type, array $opts): ?array
{
    $extract_opts = ['include_styles' => $opts['include_styles']];
    $schema = resolve_compact_schema($widget_type, $extract_opts);
    if ($schema === null) {
        return null;
    }

    // The tab and section filters read fields that only the verbose pass
    // attaches, so they have to run after it. Filtering the bare compact shape
    // compared every control against a key it never carries and dropped the
    // lot: `tab=content` on a heading answered zero controls rather than the
    // handful that matter, which reads as "this widget has no content
    // settings" instead of "this filter is broken".
    $wants_meta_filter = $opts['tab'] !== '' || $opts['section'] !== '';
    $wants_verbose = $opts['verbose'] || $opts['control_names'] !== [];

    if ($wants_meta_filter || $wants_verbose) {
        $schema = schema_enrich_verbose($widget_type, $schema);
    }

    $schema = schema_apply_drilldown_filters($schema, $opts);

    // Enriched only to make the filter work? Put the compact shape back, so a
    // caller who asked to narrow the payload is not handed a wider one.
    if ($wants_meta_filter && !$wants_verbose) {
        $schema = schema_strip_verbose_fields($schema);
    }

    return $schema;
}

/**
 * Drop the verbose-only fields from every control in a schema.
 *
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function schema_strip_verbose_fields(array $schema): array
{
    /** @var mixed $controls */
    $controls = $schema['controls'] ?? null;
    if (!is_array($controls)) {
        return $schema;
    }

    /** @var array<string, mixed> $stripped */
    $stripped = [];
    /** @var mixed $entry */
    foreach ($controls as $cid => $entry) {
        if (!is_array($entry)) {
            $stripped[(string) $cid] = $entry;
            continue;
        }
        foreach (['label', 'description', 'placeholder', 'tab', 'section'] as $field) {
            unset($entry[$field]);
        }
        $stripped[(string) $cid] = $entry;
    }

    $schema['controls'] = $stripped;
    return $schema;
}

/**
 * Apply the tab / section / control_names filters to a compact schema's
 * controls map. Operates on the compact shape produced by the extractor.
 *
 * @param array<string, mixed> $schema
 * @param array{include_styles: bool, verbose: bool, control_names: list<string>, tab: string, section: string} $opts
 * @return array<string, mixed>
 */
function schema_apply_drilldown_filters(array $schema, array $opts): array
{
    if ($opts['control_names'] === [] && $opts['tab'] === '' && $opts['section'] === '') {
        return $schema;
    }

    /** @var array<string, array<string, mixed>> $controls */
    $controls = is_array($schema['controls'] ?? null) ? $schema['controls'] : [];

    $filtered = [];
    $name_filter = $opts['control_names'] === [] ? null : array_flip($opts['control_names']);

    foreach ($controls as $cid => $entry) {
        if ($name_filter !== null && !array_key_exists($cid, $name_filter)) {
            continue;
        }
        if ($opts['tab'] !== '' && ($entry['tab'] ?? '') !== $opts['tab']) {
            continue;
        }
        if ($opts['section'] !== '' && ($entry['section'] ?? '') !== $opts['section']) {
            continue;
        }
        $filtered[$cid] = $entry;
    }

    $schema['controls'] = $filtered;
    return $schema;
}

/**
 * Enrich a compact schema with verbose metadata: widget-level icon /
 * categories / keywords, and per-control label / description. The original
 * compact fields (t/opts/def/...) are preserved so downstream validators can
 * still consume the response.
 *
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function schema_enrich_verbose(string $widget_type, array $schema): array
{
    if ($widget_type === WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY) {
        $schema['is_atomic'] = false;
        return $schema;
    }

    // Atomic container elements — already enriched by build_atomic_element_compact_schema.
    if (in_array($widget_type, WPPILOT_ATOMIC_CONTAINER_TYPES, strict: true)) {
        $schema['categories'] = ['v4-elements'];
        return $schema;
    }

    $manager = \Elementor\Plugin::$instance->widgets_manager;
    $widget = $manager->get_widget_types($widget_type);
    if (!$widget instanceof \Elementor\Widget_Base) {
        return $schema;
    }

    $schema['icon'] = $widget->get_icon();
    /** @var list<string> $categories */
    $categories = $widget->get_categories();
    $schema['categories'] = $categories;
    /** @var list<string> $keywords */
    $keywords = $widget->get_keywords();
    $schema['keywords'] = $keywords;
    $schema['is_atomic'] = is_atomic_widget($widget);

    if (is_atomic_widget($widget)) {
        return $schema;
    }

    /** @var array<string, array<string, mixed>> $raw_controls */
    $raw_controls = $widget->get_controls();
    /** @var array<string, array<string, mixed>> $compact_controls */
    $compact_controls = is_array($schema['controls'] ?? null) ? $schema['controls'] : [];

    foreach ($compact_controls as $cid => $entry) {
        $raw = $raw_controls[$cid] ?? null;
        if (!is_array($raw)) {
            continue;
        }
        $compact_controls[$cid] = schema_attach_verbose_fields($entry, $raw);
    }

    $schema['controls'] = $compact_controls;
    return $schema;
}

/**
 * Merge the interesting verbose fields (label / description / placeholder /
 * tab / section) from a raw control definition into a compact entry.
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function schema_attach_verbose_fields(array $entry, array $raw): array
{
    foreach (['label', 'description', 'placeholder', 'tab', 'section'] as $field) {
        if (!array_key_exists($field, $raw)) {
            continue;
        }
        /** @var mixed $value */
        $value = $raw[$field];
        if ($value === '' || $value === null) {
            continue;
        }
        $entry[$field] = $value;
    }
    return $entry;
}

/**
 * Whether a widget instance is an atomic (v4) widget.
 */
function is_atomic_widget(\Elementor\Widget_Base $widget): bool
{
    if (!class_exists(\Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base::class)) {
        return false;
    }

    return $widget instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base;
}
