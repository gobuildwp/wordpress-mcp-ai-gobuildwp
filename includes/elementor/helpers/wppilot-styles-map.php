<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor v4: the styles map on an atomic element.
 *
 * Parsing, labelling, and validating style definitions, including the
 * ergonomic shorthand that gets rewritten into a full style definition.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * List the CSS property names that live in the atomic Style Schema. Callers
 * use this set to recognize when a dropped `settings` key actually belongs
 * in the per-element `styles` map — turning an opaque "unknown key" error
 * into a targeted hint at the right channel.
 *
 * Cached within the request because `Style_Schema::get()` walks a fair bit
 * of Elementor registry state and this is called from every validation
 * error path on atomic widgets.
 *
 * @return list<string>
 */
function el_style_schema_property_names(): array
{
    /** @var list<string>|null $cache */
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    return $cache = el_compute_style_schema_property_names();
}

/**
 * Compute the list used by {@see el_style_schema_property_names()}. Extracted
 * so the caller's static-cache bookkeeping stays in a single line and the
 * analyzer stops flagging `$cache = []; return $cache;` as an inlineable
 * return.
 *
 * @return list<string>
 */
function el_compute_style_schema_property_names(): array
{
    if (!class_exists(\Elementor\Modules\AtomicWidgets\Styles\Style_Schema::class)) {
        return [];
    }

    /** @var mixed $raw */
    $raw = \Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get();
    if (!is_array($raw)) {
        return [];
    }

    $names = [];
    /** @var mixed $key */
    foreach (array_keys($raw) as $key) {
        if (!is_string($key)) {
            continue;
        }
        $names[] = $key;
    }
    return $names;
}

/**
 * Validate a per-element styles map using Elementor's `Style_Parser`.
 *
 * Every entry must be a full style object `{id, type, label, variants:[...]}`
 * — this is the shape `Atomic_Styles_Manager::group_by_breakpoint` iterates
 * at render time. Writing a flat `{css_prop: {$$type, value}}` map (the
 * Global Classes shape) triggers a fatal TypeError during CSS generation,
 * so we reject anything that does not round-trip through `Style_Parser`.
 *
 * Returns the sanitized styles on success or a list of per-entry errors
 * describing which style ID failed and why.
 *
 * @param mixed $styles
 * @return array{ok: bool, styles: array<string, mixed>, errors: list<array{style_id: string, reason: string, path: string}>}
 */
function el_validate_styles_map(mixed $styles): array
{
    /** @var list<array{style_id: string, reason: string, path: string}> $no_errors */
    $no_errors = [];

    if ($styles === null || $styles === '' || $styles === []) {
        return ['ok' => true, 'styles' => [], 'errors' => $no_errors];
    }
    if (!is_array($styles)) {
        return [
            'ok' => false,
            'styles' => [],
            'errors' => [['style_id' => '', 'reason' => 'styles_not_object', 'path' => '']],
        ];
    }

    /** @var array<string, mixed> $styles_map */
    $styles_map = $styles;

    if (
        !class_exists(\Elementor\Modules\AtomicWidgets\Parsers\Style_Parser::class)
        || !class_exists(\Elementor\Modules\AtomicWidgets\Styles\Style_Schema::class)
    ) {
        return ['ok' => true, 'styles' => $styles_map, 'errors' => $no_errors];
    }

    /** @var mixed $raw_schema */
    $raw_schema = \Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get();
    if (!is_array($raw_schema)) {
        return ['ok' => true, 'styles' => $styles_map, 'errors' => $no_errors];
    }

    // Auto-wrap scalar prop values inside each variant's `props` against the
    // Style Schema before parsing — mirrors the settings-side ergonomic
    // wrapper, so an agent can pass `color: "#FFFFFF"` instead of the
    // long-form `{$$type:"color", value:"#FFFFFF"}` everywhere in styles.
    $styles_map = el_normalize_styles_map_props($styles_map);

    // Coerce each entry's `label` to a CSS-class-safe kebab shape so the
    // Style_Parser rules (2–50 chars, no spaces, `[a-zA-Z0-9_-]+`, can't
    // start with a digit / `--` / `-<digit>`, can't be `container`) are
    // satisfied on natural inputs like "hero h1" or "Card Title".
    $styles_map = el_normalize_styles_map_labels($styles_map);

    $parser = \Elementor\Modules\AtomicWidgets\Parsers\Style_Parser::make($raw_schema);
    $resolved_schema = el_resolve_style_schema();

    /** @var list<array{style_id: string, reason: string, path: string}> $errors */
    $errors = [];
    /** @var array<string, mixed> $clean */
    $clean = [];
    foreach (array_keys($styles_map) as $style_id) {
        // Unknown sub-keys inside shape props (e.g. `flex.flex-wrap`) survive
        // `Object_Prop_Type::validate` because defaults pass, and the v4
        // renderer then ignores them — producing a silently-wrong style.
        // Reject them up front so the caller sees what was dropped.
        $subkey_errors = el_collect_dropped_subkey_errors($style_id, $styles_map[$style_id], $resolved_schema);
        if ($subkey_errors !== []) {
            $errors = [...$errors, ...$subkey_errors];
            continue;
        }
        [$parsed, $entry_errors] = el_parse_single_style($parser, $style_id, $styles_map[$style_id]);
        if ($entry_errors !== []) {
            $errors = [...$errors, ...$entry_errors];
            continue;
        }
        if ($parsed !== null) {
            $clean[$style_id] = $parsed;
        }
    }

    return ['ok' => $errors === [], 'styles' => $clean, 'errors' => $errors];
}

/**
 * Run Style_Parser against a single styles-map entry. Returns the parsed
 * style (or null when the entry wasn't a writable array) and any per-entry
 * errors surfaced by the parser. Isolated so the caller's loop stays
 * free of `mixed` narrowing noise.
 *
 * @return array{0: array<string, mixed>|null, 1: list<array{style_id: string, reason: string, path: string}>}
 */
function el_parse_single_style(
    \Elementor\Modules\AtomicWidgets\Parsers\Style_Parser $parser,
    string $style_id,
    mixed $style_entry,
): array {
    /** @var list<array{style_id: string, reason: string, path: string}> $no_errors */
    $no_errors = [];

    if (!is_array($style_entry)) {
        return [null, [['style_id' => $style_id, 'reason' => 'style_entry_not_object', 'path' => '']]];
    }

    $result = $parser->parse($style_entry);
    if (!$result->is_valid()) {
        /** @var list<array{key: string, error: string}> $raw_errors */
        $raw_errors = $result->errors()->all();
        return [null, el_collect_style_parse_errors($style_id, $raw_errors)];
    }

    /** @var mixed $parsed */
    $parsed = $result->unwrap();
    if (!is_array($parsed)) {
        return [null, $no_errors];
    }
    /** @var array<string, mixed> $typed_parsed */
    $typed_parsed = $parsed;
    return [$typed_parsed, $no_errors];
}

/**
 * Convert Style_Parser error entries into the validator's per-style error
 * shape. Each Elementor error entry is `{key, error}`; we thread the
 * owning style id through so the caller can report them keyed by style.
 *
 * @param list<array{key: string, error: string}> $raw_errors
 * @return list<array{style_id: string, reason: string, path: string}>
 */
function el_collect_style_parse_errors(string $style_id, array $raw_errors): array
{
    $out = [];
    foreach ($raw_errors as $err) {
        $out[] = [
            'style_id' => $style_id,
            'reason' => $err['error'],
            'path' => $err['key'],
        ];
    }
    return $out;
}

/**
 * Render up to $limit style parse errors as a compact inline summary so the
 * per-entry reasons survive an MCP wrapper that only passes the `error`
 * string through.
 *
 * @param list<array{style_id: string, reason: string, path: string}> $errors
 */
function el_summarize_style_errors(array $errors, int $limit): string
{
    if ($errors === []) {
        return '';
    }
    $picked = [];
    foreach ($errors as $entry) {
        if (count($picked) >= $limit) {
            break;
        }
        $picked[] = sprintf(
            'style_id="%s" reason=%s path=%s',
            $entry['style_id'],
            $entry['reason'],
            $entry['path'] === '' ? '(root)' : $entry['path'],
        );
    }
    return sprintf('First %d: %s.', count($picked), implode('; ', $picked));
}

/**
 * Walk a per-element styles map and auto-wrap scalar prop values inside each
 * variant's `props` against the v4 Style Schema. Mirrors the settings-side
 * auto-wrap so an agent can pass:
 *
 *   "color":     "#FFFFFF"   → {$$type:"color", value:"#FFFFFF"}
 *   "font-size": 72          → {$$type:"size",  value:{size:72, unit:"px"}}
 *   "padding":   {"block-start":16, "inline-end":32, ...}
 *                            → {$$type:"dimensions", value:{block-start:{$$type:"size",...}, ...}}
 *   "flex":      {"flexGrow":1, "flexShrink":1, "flexBasis":{"size":0,"unit":"%"}}
 *                            → {$$type:"flex", value:{flexGrow:{$$type:"number",value:1}, ...}}
 *
 * Pre-wrapped values pass through untouched. Object/array prop types still
 * recurse into the inner shape so a partially-wrapped value (outer envelope
 * present but inner sides scalar) is normalized too. Unknown props are left
 * alone — Style_Parser will report them downstream.
 *
 * @param array<string, mixed> $styles
 * @return array<string, mixed>
 */
function el_normalize_styles_map_props(array $styles): array
{
    $schema = el_resolve_style_schema();
    if ($schema === []) {
        return $styles;
    }
    foreach (array_keys($styles) as $sid) {
        /** @var mixed $entry */
        $entry = $styles[$sid];
        if (!is_array($entry) || !is_array($entry['variants'] ?? null)) {
            continue;
        }
        /** @var list<mixed> $variants */
        $variants = $entry['variants'];
        foreach (array_keys($variants) as $vi) {
            /** @var mixed $variant */
            $variant = $variants[$vi];
            if (!is_array($variant) || !is_array($variant['props'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $props */
            $props = $variant['props'];
            $variant['props'] = el_normalize_style_props($props, $schema);
            $variants[$vi] = $variant;
        }
        $entry['variants'] = $variants;
        $styles[$sid] = $entry;
    }
    return $styles;
}

/**
 * Coerce every entry's `label` into a form that satisfies the v4 Style_Parser
 * rules (mirrors `Style_Parser::validate_style_label`). Leaves already-valid
 * labels untouched so agent-picked kebab labels round-trip verbatim.
 *
 * @param array<string, mixed> $styles
 * @return array<string, mixed>
 */
function el_normalize_styles_map_labels(array $styles): array
{
    foreach (array_keys($styles) as $sid) {
        /** @var mixed $entry */
        $entry = $styles[$sid];
        if (!is_array($entry) || !is_string($entry['label'] ?? null)) {
            continue;
        }
        $entry['label'] = el_sanitize_style_label($entry['label']);
        $styles[$sid] = $entry;
    }
    return $styles;
}

/**
 * Coerce an arbitrary string into a label that passes Elementor's style-label
 * rules: 2–50 chars, `[a-zA-Z0-9_-]+`, can't start with a digit / `--` /
 * `-<digit>`, can't be `container`. Invalid chars (spaces, punctuation) become
 * hyphens; consecutive hyphens collapse; unsafe prefixes get an `e-` escape.
 * Already-valid labels come back unchanged, so `"hero-h1"` stays `"hero-h1"`
 * while `"hero h1"` becomes `"hero-h1"` and `"Card Title"` becomes
 * `"Card-Title"`.
 */
function el_sanitize_style_label(string $label): string
{
    if (el_is_valid_style_label($label)) {
        return $label;
    }

    $slug = preg_replace(pattern: '/[^a-zA-Z0-9_-]+/', replacement: '-', subject: $label) ?? '';
    $slug = preg_replace(pattern: '/-{2,}/', replacement: '-', subject: $slug) ?? '';
    $slug = trim(string: $slug, characters: '-');

    if ($slug !== '' && $slug[0] >= '0' && $slug[0] <= '9') {
        $slug = 'e-' . $slug;
    }

    if (strlen($slug) > 50) {
        $slug = rtrim(substr($slug, offset: 0, length: 50), characters: '-');
    }

    if (strlen($slug) < 2) {
        $slug = 'e-' . $slug;
    }

    if (strtolower($slug) === 'container') {
        $slug = 'e-' . $slug;
    }

    return $slug;
}

/**
 * Mirror of `Style_Parser::validate_style_label` — returns true when $label
 * already satisfies every rule, so the sanitizer can short-circuit and leave
 * agent-supplied kebab labels exactly as written.
 */
function el_is_valid_style_label(string $label): bool
{
    $len = strlen($label);
    if ($len < 2 || $len > 50) {
        return false;
    }
    if (strtolower($label) === 'container') {
        return false;
    }
    if (preg_match('/^[a-zA-Z0-9_-]+$/', $label) !== 1) {
        return false;
    }
    if (preg_match('/^[0-9]/', $label) === 1) {
        return false;
    }
    if (str_starts_with($label, '--')) {
        return false;
    }
    if (preg_match('/^-[0-9]/', $label) === 1) {
        return false;
    }
    return true;
}

/**
 * Resolve the v4 Style Schema as a CSS-property → Prop_Type map. Returns an
 * empty array when the atomic-widgets module is missing — callers must treat
 * that as a no-op and skip normalization.
 *
 * @return array<string, object>
 */
function el_resolve_style_schema(): array
{
    if (!class_exists(\Elementor\Modules\AtomicWidgets\Styles\Style_Schema::class)) {
        return [];
    }
    /** @var mixed $raw */
    $raw = \Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get();
    if (!is_array($raw)) {
        return [];
    }
    $clean = [];
    foreach (array_keys($raw) as $name) {
        /** @var mixed $prop */
        $prop = $raw[$name];
        if (!is_string($name) || !is_object($prop)) {
            continue;
        }
        $clean[$name] = $prop;
    }
    return $clean;
}

/**
 * Validate the element's `styles` map via Style_Parser and write the
 * sanitized shape back onto the element. Returns the (possibly updated)
 * element plus the list of per-entry errors — empty when the map is
 * missing, empty, or validates cleanly.
 *
 * @param array<string, mixed> $element
 * @return array{0: array<string, mixed>, 1: list<array{style_id: string, reason: string, path: string}>}
 */
function el_validate_atomic_element_styles(array $element): array
{
    if (!array_key_exists('styles', $element)) {
        return [$element, []];
    }

    $styles_result = el_validate_styles_map($element['styles']);
    if (!$styles_result['ok']) {
        return [$element, $styles_result['errors']];
    }

    $element['styles'] = $styles_result['styles'];
    return [$element, []];
}

/**
 * Detect whether a per-element styles map is in the ergonomic CSS-property
 * shape (`{"color": "#fff", "padding": 28, "background": {...}, ...}`)
 * rather than the verbose `{"<style_id>": {id, type, label, variants: [...]}}`
 * shape the v4 Style_Parser consumes.
 *
 * Treated as ergonomic when at least one top-level key matches a known v4
 * Style Schema CSS property AND no value carries a `variants` key
 * (`variants` is the marker of a Style_Definition record). The permissive
 * "at least one" rule means a typo in one CSS property name still routes
 * through the ergonomic path so the caller sees `unknown_properties` rather
 * than Style_Parser's `style_entry_not_object` noise. Empty maps are not
 * ergonomic — callers use `{}` with `replace:true` to clear styles, so
 * matching empty would break that contract.
 *
 * @param array<string, mixed> $styles
 */
function el_looks_like_ergonomic_styles_map(array $styles): bool
{
    if ($styles === []) {
        return false;
    }
    $schema_props = el_style_schema_property_names();
    if ($schema_props === []) {
        return false;
    }
    $schema_set = array_flip($schema_props);
    $has_schema_key = false;
    foreach (array_keys($styles) as $key) {
        /** @var mixed $value */
        $value = $styles[$key];
        if (is_array($value) && array_key_exists('variants', $value)) {
            return false;
        }
        if (array_key_exists($key, $schema_set)) {
            $has_schema_key = true;
        }
    }
    return $has_schema_key;
}

/**
 * Wrap a validated ergonomic props map (output of {@see el_validate_style_props})
 * into the single-variant Style_Definition record shape the Style_Parser /
 * renderer pipeline expects. The synthesized style id is deterministic per
 * target element (`s-<element_id>`), so repeated ergonomic calls against the
 * same element overwrite the same entry instead of piling up new styles in
 * merge mode. The id (and not the label) is what becomes the rendered CSS
 * class name; the label is fixed to "local" to match the editor convention
 * Elementor uses for every per-element style.
 *
 * @param array<string, mixed> $props
 * @return array<string, mixed>
 */
function el_wrap_ergonomic_styles_as_style_def(string $element_id, array $props): array
{
    $sid = 's-' . ($element_id !== '' ? $element_id : el_generate_id());
    return [
        $sid => [
            'id' => $sid,
            'type' => 'class',
            'label' => 'local',
            'variants' => [
                [
                    'meta' => ['breakpoint' => 'desktop', 'state' => null],
                    'props' => $props,
                ],
            ],
        ],
    ];
}

/**
 * Wrap a flat CSS map into the per-element style entry a write path accepts.
 *
 * Two style shapes exist in v4 and they are easy to confuse. A global class
 * takes `{property: value}`. A per-element style takes a keyed entry carrying an
 * id, a type, a label and a list of variants, and handing the first where the
 * second belongs is refused with one error per property — twenty-five of them
 * on a page of any size, none of which say "you used the wrong shape".
 *
 * That confusion has cost two builds in this codebase, in two different files,
 * written weeks apart. It is not a thing to remember; it is a thing to have a
 * function for.
 *
 * The id is derived from the properties, so two elements asking for the same
 * override share one entry rather than minting one each, and rebuilding a page
 * produces the same ids instead of a fresh set every time.
 *
 * States ride alongside the base properties. Elementor stores hover and focus
 * as extra variants on the same style rather than as separate styles, so a
 * caller asking for a lift on hover gets one entry with two variants and not
 * two entries fighting over the same element.
 *
 * The `$error` out-parameter exists because the silent version of this cost
 * real debugging time: a property the schema does not accept produced no
 * styling, no entry and no message, and the page simply came out unstyled with
 * nothing anywhere saying why. Callers that can surface a reason should pass it.
 *
 * @param  array<string, mixed>                      $flat
 * @param  array<string, array<string, mixed>>       $states Keyed by state name.
 * @param  string|null                               $error  Set to the validation reason on failure.
 * @return array<string, mixed> Empty when there is nothing to wrap or the
 *                              properties do not survive validation.
 */
function el_local_style_entry(array $flat, array $states = [], ?string &$error = null): array
{
    $error = null;
    if ($flat === [] && $states === []) {
        return [];
    }

    $variants = [];
    foreach ($states as $state => $props) {
        if (!is_array($props) || $props === []) {
            continue;
        }
        $variants[] = ['meta' => ['breakpoint' => 'desktop', 'state' => $state], 'styles' => $props];
    }

    // A style with no base properties is legal: a card that only changes on
    // hover has nothing to say at rest, and an empty base variant is what
    // Elementor stores for one. Inventing a resting property here would give
    // the element a display it never asked for.
    $assembled = gc_assemble_variants($flat, ['variants' => $variants]);
    if (array_key_exists('error', $assembled)) {
        $error = (string) $assembled['error'];

        return [];
    }

    $id = 's-' . substr(md5((string) wp_json_encode([$flat, $states])), offset: 0, length: 12);

    return [
        $id => [
            'id' => $id,
            'type' => 'class',
            // Elementor's own name for a style belonging to a single element.
            'label' => 'local',
            'variants' => $assembled['variants'] ?? [],
        ],
    ];
}
