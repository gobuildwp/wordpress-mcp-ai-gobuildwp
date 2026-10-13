<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor add-element: building the error response.
 *
 * A rejected tree can carry many independent problems. These summarize them
 * into one actionable message — what was dropped, what was invalid, and the
 * likely fix — instead of a wall of raw validator output.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Shape a fail-hard error response for tree-mode inserts. Combines standard
 * subtree validation errors with malformed v3 dynamic tag strings so the
 * caller can correct the converted subtree and retry.
 *
 * @param array{ok: bool, tree: list<array<string, mixed>>, errors: list<array<string, mixed>>, schemas: array<string, array<string, mixed>>} $validation
 * @param list<array{element_id: string, widget_type: string, setting_name: string, tag: mixed, reason: string}> $dynamic_tag_errors
 * @return array<string, mixed>
 */
function build_tree_validation_error_response(array $validation, array $dynamic_tag_errors): array
{
    $errors = $validation['errors'];
    $schemas = $validation['schemas'];

    $counts = el_count_tree_issues($errors);
    $message = el_build_tree_error_message($counts, count($dynamic_tag_errors));

    // Inline the first few concrete failures into the message itself so the
    // MCP wrapper's error-only unwrap path still surfaces the reasons.
    $summary = el_summarize_tree_errors($errors, $dynamic_tag_errors, limit: 3);
    if ($summary !== '') {
        $message .= ' ' . $summary;
    }

    $response = [
        'success' => false,
        'error' => $message,
    ];

    if ($errors !== []) {
        $response['errors'] = $errors;
        $response['schemas'] = $schemas;
    }

    if ($dynamic_tag_errors !== []) {
        $response['dynamic_tag_errors'] = $dynamic_tag_errors;
    }

    return $response;
}

/**
 * Build a fail-hard error response for a single-element write that failed
 * validation. Embeds the element's CONTENT-ONLY compact schema inline so
 * the caller can correct the settings without a second schema lookup and
 * without bloating the error payload with every style/advanced control.
 *
 * @param array{ok: bool, settings: array<string, mixed>, dropped: list<string>, invalid: list<array{key: string, value: mixed, opts: list<mixed>}>} $validation
 * @return array<string, mixed>
 */
function build_single_element_error_response(string $element_type, string $widget_type, array $validation): array
{
    $dropped = $validation['dropped'];
    $invalid = $validation['invalid'];

    $content_schema = resolve_compact_schema($widget_type, ['include_styles' => false]);
    $is_atomic = is_array($content_schema) && $content_schema['is_atomic'] === true;

    $misplaced_style_props = $is_atomic ? el_misplaced_style_props_from_dropped($dropped) : [];
    $label = $element_type === 'container' ? 'container' : sprintf('widget "%s"', $widget_type);

    $message = sprintf(
        'Invalid settings for %s: %d unknown key(s) and %d invalid value(s). Use the "schema" field to correct your payload and retry — do not invent control IDs or enum values.',
        $label,
        count($dropped),
        count($invalid),
    );
    $summary = el_summarize_single_element_errors($dropped, $invalid, limit: 3);
    if ($summary !== '') {
        $message .= ' ' . $summary;
    }
    if ($misplaced_style_props !== []) {
        $message .= sprintf(
            ' Note: [%s] are CSS properties from the atomic Style Schema, not controls — they belong in the element\'s `styles` map (see `styling_hint`).',
            implode(', ', $misplaced_style_props),
        );
    }

    $response = [
        'success' => false,
        'error' => $message,
        'element_type' => $element_type,
        'dropped_keys' => $dropped,
        'invalid_values' => $invalid,
    ];

    if ($element_type === 'widget') {
        $response['widget_type'] = $widget_type;
    }

    if ($content_schema !== null) {
        $response['schema'] = $content_schema;
    }

    if ($misplaced_style_props !== []) {
        $response['styling_hint'] = el_build_styling_hint($misplaced_style_props);
    }

    return $response;
}

/**
 * Tally the per-category error counts across the tree validation error list.
 * Unknown widget types are counted separately from dropped keys so the
 * headline can name each category — an agent that only reads the headline
 * otherwise misses whole rejected subtrees when a widget's type itself is
 * unknown and thus has no dropped_keys to flag.
 *
 * @param list<array<string, mixed>> $errors
 * @return array{dropped: int, invalid: int, style: int, unknown_widgets: int}
 */
function el_count_tree_issues(array $errors): array
{
    $dropped = 0;
    $invalid = 0;
    $style = 0;
    $unknown_widgets = 0;
    foreach ($errors as $error) {
        if (is_array($error['dropped_keys'] ?? null)) {
            $dropped += count($error['dropped_keys']);
        }
        if (is_array($error['invalid_values'] ?? null)) {
            $invalid += count($error['invalid_values']);
        }
        if (is_array($error['style_errors'] ?? null)) {
            $style += count($error['style_errors']);
        }
        if (($error['unknown_widget_type'] ?? false) === true) {
            $unknown_widgets++;
        }
    }
    return ['dropped' => $dropped, 'invalid' => $invalid, 'style' => $style, 'unknown_widgets' => $unknown_widgets];
}

/**
 * Render the headline string from the per-category counts. Unknown widget
 * types lead because they are the most severe class of rejection — a
 * subtree whose widget type doesn't exist can't be recovered by just
 * fixing a few keys.
 *
 * @param array{dropped: int, invalid: int, style: int, unknown_widgets: int} $counts
 */
function el_build_tree_error_message(array $counts, int $dynamic_tag_errors): string
{
    $parts = [];
    if ($dynamic_tag_errors > 0) {
        $parts[] = sprintf('%d dynamic tag parse error(s)', $dynamic_tag_errors);
    }
    if ($counts['unknown_widgets'] > 0) {
        $parts[] = sprintf('%d unknown widget type(s)', $counts['unknown_widgets']);
    }
    if ($counts['dropped'] > 0) {
        $parts[] = sprintf('%d unknown key(s)', $counts['dropped']);
    }
    if ($counts['invalid'] > 0) {
        $parts[] = sprintf('%d invalid value(s)', $counts['invalid']);
    }
    if ($counts['style'] > 0) {
        $parts[] = sprintf('%d style entry error(s)', $counts['style']);
    }

    if ($parts === []) {
        return 'Tree validation failed.';
    }
    return sprintf('Tree validation failed: %s. Fix the reported subtree issues and retry.', implode(', ', $parts));
}

/**
 * Render up to $limit concrete failures as a compact inline summary so the
 * reasons survive even when an MCP wrapper strips every response key except
 * `error`. Walks the tree error records in priority order (unknown widget,
 * style, dropped, invalid) and finally the dynamic tag parse errors.
 *
 * @param list<array<string, mixed>> $errors
 * @param list<array{element_id: string, widget_type: string, setting_name: string, tag: mixed, reason: string}> $dynamic_tag_errors
 */
function el_summarize_tree_errors(array $errors, array $dynamic_tag_errors, int $limit): string
{
    $picked = [];
    foreach ($errors as $error) {
        if (count($picked) >= $limit) {
            break;
        }
        el_append_tree_error_summary($error, $limit, $picked);
    }

    foreach ($dynamic_tag_errors as $dt_error) {
        if (count($picked) >= $limit) {
            break;
        }
        $picked[] = sprintf(
            'dynamic_tag widget="%s" setting="%s" reason=%s',
            $dt_error['widget_type'],
            $dt_error['setting_name'],
            $dt_error['reason'],
        );
    }

    if ($picked === []) {
        return '';
    }

    return sprintf('First %d: %s.', count($picked), implode('; ', $picked));
}

/**
 * Append up to $limit concrete failure strings extracted from a single tree
 * error record into $picked. Split from `el_summarize_tree_errors` to keep
 * each function's branching below the cyclomatic-complexity gate.
 *
 * @param array<string, mixed> $error
 * @param list<string> $picked
 */
function el_append_tree_error_summary(array $error, int $limit, array &$picked): void
{
    $widget_type = is_string($error['widget_type'] ?? null) ? $error['widget_type'] : '';

    if (($error['unknown_widget_type'] ?? false) === true) {
        $element_id = is_string($error['element_id'] ?? null) ? $error['element_id'] : '';
        $picked[] = sprintf('unknown widget_type="%s" element_id="%s"', $widget_type, $element_id);
        return;
    }

    if (is_array($error['style_errors'] ?? null)) {
        /** @var list<array{style_id: string, reason: string, path: string}> $style_errors */
        $style_errors = $error['style_errors'];
        el_append_style_error_summaries($widget_type, $style_errors, $limit, $picked);
    }

    if (is_array($error['dropped_keys'] ?? null)) {
        /** @var list<string> $dropped */
        $dropped = $error['dropped_keys'];
        el_append_dropped_key_summaries($widget_type, $dropped, $limit, $picked);
    }

    if (is_array($error['invalid_values'] ?? null)) {
        /** @var list<array{key: string, value: mixed, opts: list<mixed>}> $invalid */
        $invalid = $error['invalid_values'];
        el_append_invalid_value_summaries($widget_type, $invalid, $limit, $picked);
    }
}

/**
 * @param list<string> $dropped
 * @param list<string> $picked
 */
function el_append_dropped_key_summaries(string $widget_type, array $dropped, int $limit, array &$picked): void
{
    foreach ($dropped as $key) {
        if (count($picked) >= $limit) {
            return;
        }
        $picked[] = sprintf('unknown_key widget="%s" key="%s"', $widget_type, $key);
    }
}

/**
 * @param list<array{key: string, value: mixed, opts: list<mixed>}> $invalid
 * @param list<string> $picked
 */
function el_append_invalid_value_summaries(string $widget_type, array $invalid, int $limit, array &$picked): void
{
    foreach ($invalid as $entry) {
        if (count($picked) >= $limit) {
            return;
        }
        $picked[] = sprintf(
            'invalid_value widget="%s" key="%s" value=%s',
            $widget_type,
            $entry['key'],
            el_short_json($entry['value']),
        );
    }
}

/**
 * @param list<array{style_id: string, reason: string, path: string}> $style_errors
 * @param list<string> $picked
 */
function el_append_style_error_summaries(string $widget_type, array $style_errors, int $limit, array &$picked): void
{
    foreach ($style_errors as $style_error) {
        if (count($picked) >= $limit) {
            return;
        }
        $picked[] = sprintf(
            'style widget="%s" style_id="%s" reason=%s path=%s',
            $widget_type,
            $style_error['style_id'],
            $style_error['reason'],
            $style_error['path'] === '' ? '(root)' : $style_error['path'],
        );
    }
}

/**
 * Render up to $limit concrete key-level failures from a single-element
 * validation result so the reasons are visible even when only the `error`
 * string is passed through.
 *
 * @param list<string> $dropped
 * @param list<array{key: string, value: mixed, opts: list<mixed>}> $invalid
 */
function el_summarize_single_element_errors(array $dropped, array $invalid, int $limit): string
{
    $picked = [];
    foreach ($dropped as $key) {
        if (count($picked) >= $limit) {
            break;
        }
        $picked[] = sprintf('unknown_key key="%s"', $key);
    }
    foreach ($invalid as $entry) {
        if (count($picked) >= $limit) {
            break;
        }
        $picked[] = sprintf('invalid_value key="%s" value=%s', $entry['key'], el_short_json($entry['value']));
    }

    if ($picked === []) {
        return '';
    }

    return sprintf('First %d: %s.', count($picked), implode('; ', $picked));
}

/**
 * JSON-encode a value for inclusion in an error message, trimming long output
 * so the inline summary stays readable.
 */
function el_short_json(mixed $value): string
{
    $encoded = json_encode($value);
    if (!is_string($encoded)) {
        return '<unencodable>';
    }
    if (strlen($encoded) > 60) {
        return substr($encoded, offset: 0, length: 57) . '...';
    }
    return $encoded;
}

/**
 * Pick the subset of dropped setting keys that are actually CSS properties in
 * the atomic Style Schema. These are the ones an agent almost certainly meant
 * to send through the `styles` map instead of via `settings`, so the caller
 * can fix the shape in a single retry. Returns the empty list when nothing
 * dropped matches a known style prop.
 *
 * @param list<string> $dropped
 * @return list<string>
 */
function el_misplaced_style_props_from_dropped(array $dropped): array
{
    if ($dropped === []) {
        return [];
    }
    $style_props = el_style_schema_property_names();
    if ($style_props === []) {
        return [];
    }
    $style_set = array_flip($style_props);
    $out = [];
    foreach ($dropped as $key) {
        if (!array_key_exists($key, $style_set)) {
            continue;
        }
        $out[] = $key;
    }
    return $out;
}

/**
 * Build the inline `styling_hint` payload that teaches the caller the shape
 * the `styles` map expects. Ships a minimal example wrapping the first
 * misplaced prop so the retry is copy-paste-able.
 *
 * @param list<string> $misplaced_style_props
 * @return array{message: string, example: array<string, mixed>, reference: string}
 */
function el_build_styling_hint(array $misplaced_style_props): array
{
    // Caller guarantees at least one entry; `?? 'gap'` would be dead code
    // and the analyzer flags it.
    $first = $misplaced_style_props[0];
    $message = sprintf(
        'On atomic (v4) elements, layout/spacing/typography live in the `styles` map on the element, not in `settings`. Move [%s] into a per-element style like the example, then retry. Call wppilot/elementor-get-style-schema properties:["%s"] for the exact value shape.',
        implode(', ', $misplaced_style_props),
        $first,
    );

    $example = [
        'styles' => [
            's-1' => [
                'id' => 's-1',
                'type' => 'class',
                'label' => 's-1',
                'variants' => [
                    [
                        'meta' => ['breakpoint' => 'desktop', 'state' => null],
                        'props' => [
                            $first => ['$$type' => '<see get-style-schema>', 'value' => '<see get-style-schema>'],
                        ],
                    ],
                ],
            ],
        ],
    ];

    return [
        'message' => $message,
        'example' => $example,
        'reference' => 'wppilot/elementor-get-style-schema',
    ];
}
