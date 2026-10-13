<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Spec;

use WP_Error;
use WPPilot\Design\Contrast;
use WPPilot\Design\Preflight;
use WPPilot\Design\Store;

/**
 * A reproduction spec: the measurements of a design somebody is asking for.
 *
 * DESIGN.md is a direction. It names a palette, two faces and a set of dials,
 * deliberately leaves layout open, and asks "is this page in the spirit of the
 * brand". That is the right shape for inventing a site and the wrong shape
 * entirely for "build the page in this screenshot", because nothing in it can
 * carry the one thing reproduction needs: the actual numbers, in order.
 *
 * Asked to match a reference without them, a model infers. It gets the palette
 * right, because colour is the part a screenshot gives away, and it invents the
 * rest: the section sequence, the container width, the type scale, the weight.
 * The result shares a palette with the target and matches nothing else, and
 * there is no check anywhere that notices, because every existing rule asks
 * whether the page is any good rather than whether it is the page that was
 * asked for.
 *
 * So a spec is measurements, and it is measurements the caller supplies. The
 * plugin deliberately does not try to see the design: a screenshot, a Figma
 * file and a rendered URL are three different extraction problems, all of them
 * better solved by the agent that already has vision and a browser than by PHP
 * guessing at a JPEG. What the plugin owns is the part an agent is bad at —
 * holding the numbers exactly, turning them into real builder primitives with
 * no hand-made classes, and saying afterwards how far off the result landed.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Where a spec lives, alongside the design document it belongs to. */
const META_KEY = '_wppilot_design_spec';

/** How a spec was arrived at, recorded because it changes how much to trust it. */
const SOURCES = ['url', 'image', 'figma', 'prompt'];

/**
 * The layouts a section can use, and the columns each one means.
 *
 * Named rather than free-form so a spec stays comparable: two specs both saying
 * `split-2` can be checked against each other, where two specs saying
 * `1.04fr 1fr` and `1fr 1fr` cannot. The set covers what real marketing pages
 * are actually built from, including the three-column row that the layout
 * grammars have no equivalent for.
 *
 * @return array<string, string>
 */
/**
 * Sentinel values for the two layouts that are a flex flow rather than a grid.
 *
 * `layouts()` maps a name to its `grid-template-columns`, which is exactly the
 * right shape for the eight column layouts and no shape at all for a row whose
 * children keep their own widths. These mark the two that are laid out the
 * other way, so the one map still enumerates every layout a spec may name.
 */
const FLOW = ':flow';
const FLOW_BASELINE = ':flow-baseline';

/**
 * Sentinels for the compositions that are not a row and not a column either.
 *
 * These are the four grammars `grammars.php` has described since the beginning
 * and that no spec could ever ask for. That module names the shapes a page can
 * be built from — a panel crossing a section boundary, layers on a common
 * ground, a column that stays put while its neighbour scrolls, an element
 * escaping the page edge — and `wppilot/list-layout-grammars` returns all of it
 * as prose. Nothing consumed it. Every page this pipeline built was a stack of
 * bands, because a stack of bands was the only thing the vocabulary could say,
 * and the grammar most worth having was the one described as "the one thing a
 * stack of bands can never do".
 */
const BLEED = ':bleed';
const LAYER = ':layer';
const INDEX_DETAIL = ':index-detail';
const OVERLAP = ':overlap';

function layouts(): array
{
    return [
        'stack' => '',
        // One column is a real layout, not a degenerate one. A card set of a
        // single item used to fall through to a wrapper with no class at all,
        // so it lost the gap and the width the grid was carrying.
        'split-1' => '1fr',
        'split-2' => '1fr 1fr',
        'split-3' => '1fr 1fr 1fr',
        'split-4' => '1fr 1fr 1fr 1fr',
        'split-5' => '1fr 1fr 1fr 1fr 1fr',
        'split-6' => '1fr 1fr 1fr 1fr 1fr 1fr',
        'split-wide-left' => '7fr 5fr',
        'split-wide-right' => '5fr 7fr',
        'split-major-left' => '65fr 35fr',
        'split-minor-left' => '35fr 65fr',
        // Inline flow rather than a grid, so children keep their own widths.
        // A price is the case that needs it: 45 at sixty pixels and /month at
        // fourteen, sharing one baseline, is a shape a grid cannot make and one
        // that every pricing page in existence has.
        'row' => FLOW,
        'row-baseline' => FLOW_BASELINE,
        // An element that escapes the container and runs to both page edges.
        // Full-bleed is what makes a photograph carry a page rather than sit on
        // one, and every reference this pipeline has been graded against uses it
        // for the hero.
        'bleed' => BLEED,
        // Children share one grid cell, so they stack. Layering without
        // absolute positioning: nothing needs coordinates, nothing falls out of
        // flow, and the tallest child still sets the height.
        'layer' => LAYER,
        // A column that stays put while the one beside it scrolls. Changes the
        // rhythm of a long page without changing how any of it looks.
        'index-detail' => INDEX_DETAIL,
        // A block pulled up across the boundary above it. Two grounds meeting
        // behind one panel is the most recognisable sign a person composed the
        // page.
        'overlap' => OVERLAP,
    ];
}

/**
 * Roles a layout attaches to its children rather than to itself.
 *
 * Most compositions are entirely a property of the parent: a grid decides where
 * its children go and the children need to know nothing. Two here are not.
 * Stacking needs every child in the same cell, and a sticky index needs the
 * first child to stick — neither is expressible from the parent alone.
 *
 * @return array<string, array{child: string, first_only: bool}>
 */
/**
 * Whether a name is a two-column split written as its own ratio.
 *
 * The named splits cover the ratios worth having an opinion about — sevens to
 * fives, sixty-five to thirty-five — and a real page measured off a reference
 * does not land on them. gobuildwp.com alone uses 46:54, 36:64 and 54:46, none of
 * which is in the list, and rounding each to the nearest named split is how a
 * reproduction quietly becomes an approximation.
 *
 * So a ratio may be written directly: `split-46-54`. The named ones stay,
 * because `split-major-left` says what it is for and `split-65-35` does not.
 */
function is_ratio_layout(string $name): bool
{
    return preg_match('/^split-([1-9][0-9]?)-([1-9][0-9]?)$/', $name) === 1;
}

/**
 * Every ratio layout a spec actually names, so roles are generated for those
 * and only those.
 *
 * @param  array<string, mixed> $spec
 * @return list<string>
 */
function ratio_layouts(array $spec): array
{
    $found = [];

    $walk = static function (array $blocks) use (&$walk, &$found): void {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $layout = (string) ($block['layout'] ?? '');
            if (is_ratio_layout($layout)) {
                $found[$layout] = true;
            }
            if (is_array($block['blocks'] ?? null)) {
                $walk($block['blocks']);
            }
            foreach ((is_array($block['items'] ?? null) ? $block['items'] : []) as $item) {
                if (is_array($item['blocks'] ?? null)) {
                    $walk($item['blocks']);
                }
            }
        }
    };

    foreach ((is_array($spec['sections'] ?? null) ? $spec['sections'] : []) as $section) {
        if (!is_array($section)) {
            continue;
        }
        $layout = (string) ($section['layout'] ?? '');
        if (is_ratio_layout($layout)) {
            $found[$layout] = true;
        }
        $walk(is_array($section['blocks'] ?? null) ? $section['blocks'] : []);
    }

    return array_keys($found);
}

function layout_child_roles(): array
{
    return [
        'layer' => ['child' => 'layer-item', 'first_only' => false],
        'index-detail' => ['child' => 'index-sticky', 'first_only' => true],
    ];
}

/**
 * Type roles a spec may declare. A role is a job on the page, not a size, so a
 * design with no separate card heading simply omits `card`.
 *
 * @return list<string>
 */
function type_roles(): array
{
    return ['display', 'h1', 'h2', 'h3', 'card', 'body', 'small', 'label'];
}

/**
 * Validate and normalize a raw spec.
 *
 * Fails rather than repairs. A reproduction spec with a guessed value in it is
 * worse than no spec, because the guess is then enforced and measured against
 * as though somebody had chosen it.
 *
 * @param  array<string, mixed> $raw
 * @return array<string, mixed>|WP_Error
 */
function normalize(array $raw): array|WP_Error
{
    $source = strtolower(trim((string) ($raw['source'] ?? 'prompt')));
    if (!in_array($source, SOURCES, strict: true)) {
        return new WP_Error(
            'wppilot_spec_source',
            sprintf(
                /* translators: 1: the supplied source, 2: comma-separated valid sources */
                __('"%1$s" is not a spec source. Use one of: %2$s.', domain: 'wppilot'),
                $source,
                implode(', ', SOURCES),
            ),
            ['status' => 422],
        );
    }

    $surfaces = [];
    /** @var mixed $raw_surfaces */
    $raw_surfaces = $raw['surfaces'] ?? [];
    if (!is_array($raw_surfaces) || $raw_surfaces === []) {
        return new WP_Error(
            'wppilot_spec_surfaces',
            __(
                'A spec needs at least one surface: the named backgrounds its sections sit on, as name => hex. The sequence of these is most of what makes a page recognisable.',
                domain: 'wppilot',
            ),
            ['status' => 422],
        );
    }
    $gradients = [];
    foreach ($raw_surfaces as $name => $value) {
        // A surface may be a gradient rather than a flat colour. A page that
        // has one and a spec that cannot say so produces a flat band where the
        // reference has a wash, and there is no way to describe the difference
        // in a palette of hex values.
        if (is_array($value)) {
            $stops = [];
            /** @var mixed $raw_stops */
            $raw_stops = $value['stops'] ?? [];
            if (is_array($raw_stops)) {
                foreach (array_values($raw_stops) as $index => $stop) {
                    $stop_hex = Preflight\normalize_hex((string) (is_array($stop) ? ($stop['color'] ?? '') : $stop));
                    if ($stop_hex === '') {
                        continue;
                    }
                    $stops[] = [
                        'color' => $stop_hex,
                        'offset' => is_array($stop) && array_key_exists('offset', $stop)
                            ? max(0, min(100, (int) $stop['offset']))
                            : (int) ($index === 0 ? 0 : 100),
                    ];
                }
            }
            if (count($stops) < 2) {
                return new WP_Error(
                    'wppilot_spec_gradient_stops',
                    sprintf(
                        /* translators: %s: surface name */
                        __('Gradient surface "%s" needs at least two colour stops.', domain: 'wppilot'),
                        (string) $name,
                    ),
                    ['status' => 422],
                );
            }
            $key = sanitize_key((string) $name);
            $gradients[$key] = [
                'type' => (string) ($value['type'] ?? 'linear') === 'radial' ? 'radial' : 'linear',
                'angle' => (int) ($value['angle'] ?? 180),
                'stops' => $stops,
            ];
            // The first stop doubles as the flat colour, so every rule that
            // reasons about a surface — contrast, readable text, the grader —
            // keeps working without learning about gradients.
            $surfaces[$key] = $stops[0]['color'];
            continue;
        }

        $hex = Preflight\normalize_hex((string) $value);
        if ($hex === '') {
            return new WP_Error(
                'wppilot_spec_surface_color',
                sprintf(
                    /* translators: 1: surface name, 2: the unusable value */
                    __('Surface "%1$s" is set to "%2$s", which is not a colour.', domain: 'wppilot'),
                    (string) $name,
                    (string) $value,
                ),
                ['status' => 422],
            );
        }
        $surfaces[sanitize_key((string) $name)] = $hex;
    }

    $type = normalize_type(is_array($raw['type'] ?? null) ? $raw['type'] : []);
    if ($type instanceof WP_Error) {
        return $type;
    }

    $sections = normalize_sections(
        is_array($raw['sections'] ?? null) ? $raw['sections'] : [],
        array_keys($surfaces),
    );
    if ($sections instanceof WP_Error) {
        return $sections;
    }

    $radius = [];
    /** @var mixed $raw_radius */
    $raw_radius = $raw['radius'] ?? [];
    if (is_array($raw_radius)) {
        foreach ($raw_radius as $name => $value) {
            $radius[sanitize_key((string) $name)] = max(0.0, (float) $value);
        }
    }

    return [
        'source' => $source,
        'reference' => esc_url_raw((string) ($raw['reference'] ?? '')),
        'container' => max(0.0, (float) ($raw['container'] ?? 1200)),
        'section_padding' => max(0.0, (float) ($raw['section_padding'] ?? 96)),
        'section_padding_mobile' => max(0.0, (float) ($raw['section_padding_mobile'] ?? 0)),
        'gap' => max(0.0, (float) ($raw['gap'] ?? 32)),
        'surfaces' => $surfaces,
        'gradients' => $gradients,
        'radius' => $radius,
        'type' => $type,
        'sections' => $sections,
    ];
}

/**
 * @param  array<string, mixed> $raw
 * @return array<string, array<string, mixed>>|WP_Error
 */
/**
 * A DESIGN.md typography map, as a spec type scale.
 *
 * Two vocabularies describe the same eight decisions and neither is wrong for
 * its own job. A design document is written to be read, so it speaks CSS —
 * `fontSize: "87px"`, `letterSpacing: "-0.035em"` — and every token consumer
 * in this plugin reads it that way. A spec is written to be measured against,
 * so it holds numbers with the units stripped: a comparison cannot subtract
 * "87px" from "86px".
 *
 * Without something in between, generating a design and then writing a spec
 * from it meant retyping forty numbers by hand, which is exactly the step that
 * produces a spec agreeing with its own design about nothing. This is that
 * step, done once and correctly.
 *
 * Roles the spec does not know are dropped rather than refused: a design may
 * legitimately name type roles for its own purposes, and failing the whole
 * conversion over one is worse than converting the seven that map.
 *
 * @param  array<string, mixed> $typography A DESIGN.md `typography` token map.
 * @return array<string, array<string, mixed>>
 */
function from_tokens(array $typography): array
{
    $known = type_roles();
    $out = [];

    foreach ($typography as $role => $props) {
        $role = sanitize_key((string) $role);
        if (!is_array($props) || !in_array($role, $known, strict: true)) {
            continue;
        }

        $size = Preflight\size_to_px((string) ($props['fontSize'] ?? ''));
        if ($size === null || $size <= 0.0) {
            continue;
        }

        $entry = [
            'family' => trim((string) ($props['fontFamily'] ?? '')),
            'size' => $size,
            'weight' => (string) ($props['fontWeight'] ?? '400'),
            'line_height' => (float) ($props['lineHeight'] ?? 1.4),
            'tracking' => tracking_em((string) ($props['letterSpacing'] ?? '0')),
        ];

        // Measure is optional in a design and meaningful only on the roles that
        // carry running text, so an absent one stays absent rather than
        // becoming a zero the spec would then enforce.
        $measure = (float) ($props['measure'] ?? 0);
        if ($measure > 0.0) {
            $entry['measure'] = $measure;
        }

        $out[$role] = $entry;
    }

    return $out;
}

/**
 * A letter-spacing value as em.
 *
 * Em is the only unit that survives a size change, and a spec is applied at more
 * than one size the moment it has a mobile variant. A px tracking converted
 * without its size would be a guess, so it is refused into zero rather than
 * silently rescaled into something nobody chose.
 */
function tracking_em(string $value): float
{
    $value = trim($value);
    if ($value === '' || $value === '0' || $value === 'normal') {
        return 0.0;
    }

    return str_ends_with($value, 'em') ? (float) $value : 0.0;
}

function normalize_type(array $raw): array|WP_Error
{
    if ($raw === []) {
        return new WP_Error(
            'wppilot_spec_type',
            sprintf(
                /* translators: %s: comma-separated role names */
                __(
                    'A spec needs a type scale. Declare at least the roles the page uses, from: %s. Weight and tracking matter as much as size; a scale copied at the right sizes and the wrong weight does not read as the same design.',
                    domain: 'wppilot',
                ),
                implode(', ', type_roles()),
            ),
            ['status' => 422],
        );
    }

    $out = [];
    foreach ($raw as $role => $props) {
        $role = sanitize_key((string) $role);
        if (!in_array($role, type_roles(), strict: true)) {
            return new WP_Error(
                'wppilot_spec_type_role',
                sprintf(
                    /* translators: 1: the supplied role, 2: comma-separated valid roles */
                    __('"%1$s" is not a type role. Use one of: %2$s.', domain: 'wppilot'),
                    $role,
                    implode(', ', type_roles()),
                ),
                ['status' => 422],
            );
        }
        if (!is_array($props)) {
            continue;
        }

        $size = (float) ($props['size'] ?? 0);
        if ($size <= 0) {
            return new WP_Error(
                'wppilot_spec_type_size',
                sprintf(
                    /* translators: %s: the type role */
                    __('Type role "%s" has no size.', domain: 'wppilot'),
                    $role,
                ),
                ['status' => 422],
            );
        }

        $entry = [
            'family' => trim((string) ($props['family'] ?? '')),
            'size' => $size,
            'size_mobile' => max(0.0, (float) ($props['size_mobile'] ?? 0)),
            'weight' => (string) ($props['weight'] ?? '400'),
            'line_height' => (float) ($props['line_height'] ?? 1.4),
            // Tracking is stored in em because that is the only unit that
            // survives a size change, and a spec is applied at more than one
            // size the moment it has a mobile variant.
            'tracking' => (float) ($props['tracking'] ?? 0),
            'color' => sanitize_key((string) ($props['color'] ?? 'ink')),
            'measure' => max(0.0, (float) ($props['measure'] ?? 0)),
        ];
        $out[$role] = $entry;
    }

    return $out;
}

/**
 * @param  list<mixed>  $raw
 * @param  list<string> $surface_names
 * @return list<array<string, mixed>>|WP_Error
 */
function normalize_sections(array $raw, array $surface_names): array|WP_Error
{
    if ($raw === []) {
        return new WP_Error(
            'wppilot_spec_sections',
            __(
                'A spec needs its sections in order. The sequence of surfaces down a page is the thing a reader recognises before any single section, and it is the part that gets invented when it is not stated.',
                domain: 'wppilot',
            ),
            ['status' => 422],
        );
    }

    $known = layouts();
    $out = [];
    foreach (array_values($raw) as $index => $section) {
        if (!is_array($section)) {
            continue;
        }
        $surface = sanitize_key((string) ($section['surface'] ?? ''));
        if (!in_array($surface, $surface_names, strict: true)) {
            return new WP_Error(
                'wppilot_spec_section_surface',
                sprintf(
                    /* translators: 1: section index, 2: the surface named, 3: comma-separated declared surfaces */
                    __('Section %1$d names surface "%2$s", which the spec does not declare. Declared: %3$s.', domain: 'wppilot'),
                    $index,
                    $surface,
                    implode(', ', $surface_names),
                ),
                ['status' => 422],
            );
        }

        $layout = sanitize_key((string) ($section['layout'] ?? 'stack'));
        if (!array_key_exists($layout, $known) && !is_ratio_layout($layout)) {
            return new WP_Error(
                'wppilot_spec_section_layout',
                sprintf(
                    /* translators: 1: section index, 2: the layout named, 3: comma-separated valid layouts */
                    __('Section %1$d names layout "%2$s". Use one of: %3$s.', domain: 'wppilot'),
                    $index,
                    $layout,
                    implode(', ', array_keys($known)),
                ),
                ['status' => 422],
            );
        }

        $blocks = normalize_blocks(is_array($section['blocks'] ?? null) ? $section['blocks'] : [], $index);
        if ($blocks instanceof WP_Error) {
            return $blocks;
        }

        $out[] = [
            'surface' => $surface,
            'layout' => $layout,
            'gap' => max(0.0, (float) ($section['gap'] ?? 0)),
            'inset' => (bool) ($section['inset'] ?? false),
            'styles' => is_array($section['styles'] ?? null) ? $section['styles'] : [],
            'note' => trim((string) ($section['note'] ?? '')),
            'blocks' => $blocks,
        ];
    }

    return $out;
}

/**
 * The blocks a section can be made of.
 *
 * Surfaces and a type scale describe how a page is coloured and set. They do
 * not describe what is on it, and a spec that stops there produces a page with
 * the right palette, the right sizes, and none of the things the reference
 * actually contains: no console panel in the hero, no seven-item brief grid, no
 * accordion, no stat row. That page matches on every measurement and looks
 * nothing like the target, which is the trap in grading a reproduction by
 * tokens alone.
 *
 * So the vocabulary is components, not markup. A caller says "a card grid of
 * seven, each with an eyebrow, a title and a line" and the builder decides what
 * a card is on its side. Naming the component rather than the element tree is
 * what keeps a spec portable between builders, and what stops a page
 * description from being a hand-built tree that only its author can maintain.
 *
 * @param  list<mixed> $raw
 * @return list<array<string, mixed>>|WP_Error
 */
/**
 * The interaction states a block or an item may declare.
 *
 * Two, and deliberately not more. Hover is what makes a card feel like a
 * surface rather than a picture of one, and focus is what keeps that legible to
 * anyone arriving by keyboard — leaving focus out is how a design acquires a
 * hover affordance that half its users cannot see. Active and visited are real
 * states and neither carries design weight worth the vocabulary.
 *
 * @return list<string>
 */
function states(): array
{
    return ['hover', 'focus'];
}

/**
 * A states map, keyed by state, each holding a flat CSS map.
 *
 * Unknown states are dropped rather than refused. A state this file has not
 * heard of is a caller reaching for something the design does not express, and
 * failing the whole page over it would trade a missing hover for no page.
 *
 * @param  mixed $raw
 * @return array<string, array<string, mixed>>
 */
/**
 * Heading tags a block may name, separately from the size it asks for.
 *
 * A type role is a size and, until now, silently also an outline level: asking
 * for `display` or `h1` produced an `<h1>`, so a page with a hero and a loud
 * closing band shipped two of them, and a price set at heading size shipped one
 * per tier. Choosing how big something looks should not choose what it means to
 * a screen reader, and the two were the same field.
 *
 * @return list<string>
 */
function heading_tags(): array
{
    return ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p'];
}

function normalize_states(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    foreach (states() as $state) {
        $props = $raw[$state] ?? null;
        if (is_array($props) && $props !== []) {
            $out[$state] = $props;
        }
    }

    return $out;
}

function normalize_blocks(array $raw, int $section_index, int $depth = 0): array|WP_Error
{
    // Groups nest, and a spec that nests without limit is a spec that can hang
    // the builder. Six levels rather than four because an item is a level now:
    // section to cards to item to group to group spends four before anything
    // unusual has happened, and the old ceiling would have refused ordinary
    // pages rather than runaway ones.
    if ($depth > 6) {
        return new WP_Error(
            'wppilot_spec_block_depth',
            sprintf(
                /* translators: %d: section index */
                __('Section %d nests blocks more than six deep.', domain: 'wppilot'),
                $section_index,
            ),
            ['status' => 422],
        );
    }

    $known = block_types();
    $out = [];
    foreach (array_values($raw) as $position => $block) {
        if (!is_array($block)) {
            continue;
        }
        $type = sanitize_key((string) ($block['type'] ?? ''));
        if (!in_array($type, $known, strict: true)) {
            return new WP_Error(
                'wppilot_spec_block_type',
                sprintf(
                    /* translators: 1: section index, 2: block position, 3: the type named, 4: comma-separated valid types */
                    __('Section %1$d block %2$d is type "%3$s". Use one of: %4$s.', domain: 'wppilot'),
                    $section_index,
                    $position,
                    $type,
                    implode(', ', $known),
                ),
                ['status' => 422],
            );
        }

        if ($type === 'widget' && sanitize_key((string) ($block['widget'] ?? '')) === '') {
            return new WP_Error(
                'wppilot_spec_widget_name',
                sprintf(
                    /* translators: 1: section index, 2: block position */
                    __(
                        'Section %1$d block %2$d is a widget and names none. Set "widget" to an Elementor widget type; wppilot/elementor-get-schema with action "list" enumerates them.',
                        domain: 'wppilot',
                    ),
                    $section_index,
                    $position,
                ),
                ['status' => 422],
            );
        }

        // Sections have always had their layout checked against the list and
        // blocks never did, so a misspelled block layout was accepted at save
        // time and quietly degraded to a stack at build time. That was survivable
        // while every layout was a grid; with a baseline row in the vocabulary a
        // typo turns a price into two lines and nothing anywhere says why.
        $layout = sanitize_key((string) ($block['layout'] ?? 'stack'));
        if (!array_key_exists($layout, layouts()) && !is_ratio_layout($layout)) {
            return new WP_Error(
                'wppilot_spec_block_layout',
                sprintf(
                    /* translators: 1: section index, 2: block position, 3: the layout named, 4: comma-separated valid layouts */
                    __('Section %1$d block %2$d names layout "%3$s". Use one of: %4$s.', domain: 'wppilot'),
                    $section_index,
                    $position,
                    $layout,
                    implode(', ', array_keys(layouts())),
                ),
                ['status' => 422],
            );
        }

        // The outline level, when it should not follow the size. A price at
        // heading size is not a document heading, and a second loud band is not
        // a second title.
        $tag = sanitize_key((string) ($block['tag'] ?? ''));
        if ($tag !== '' && !in_array($tag, heading_tags(), strict: true)) {
            return new WP_Error(
                'wppilot_spec_block_tag',
                sprintf(
                    /* translators: 1: section index, 2: block position, 3: the tag named, 4: comma-separated valid tags */
                    __('Section %1$d block %2$d names tag "%3$s". Use one of: %4$s.', domain: 'wppilot'),
                    $section_index,
                    $position,
                    $tag,
                    implode(', ', heading_tags()),
                ),
                ['status' => 422],
            );
        }

        // What the members of this set may differ by. The plugin ships no
        // variant names: `featured` is the caller's word and is checked only for
        // existence. That is the whole point — a set whose members are identical
        // is a table with padding, and naming the difference once is what lets a
        // spec say "this tier is the chosen one" without restating the map on
        // every sibling.
        /** @var array<string, array<string, mixed>> $variants */
        $variants = [];
        if (is_array($block['variants'] ?? null)) {
            foreach ($block['variants'] as $variant_name => $variant) {
                $variant_name = sanitize_key((string) $variant_name);
                if ($variant_name === '' || !is_array($variant)) {
                    continue;
                }
                $variants[$variant_name] = [
                    'styles' => is_array($variant['styles'] ?? null) ? $variant['styles'] : [],
                    'states' => normalize_states($variant['states'] ?? null),
                    'surface' => sanitize_key((string) ($variant['surface'] ?? '')),
                ];
            }
        }

        // An item was a flat record of eight strings, and that shape is most of
        // why every card this pipeline built looked like every other one. A
        // real pricing tier is a ribbon, a name, a muted eligibility line, a
        // price row where the figure and the suffix sit on one baseline at
        // different sizes, a feature list, a full-width button, and one tier of
        // the set inverted and lifted. None of that is icon-eyebrow-title-text,
        // so the model wrote heading-plus-paragraph — not because it lacked the
        // taste, but because that was the only sentence this vocabulary could
        // say. Items nest now, so the taste has somewhere to go.
        //
        // The flat keys stay. They are correct and much terser for the cards
        // that really are a title and a line, and making every simple card
        // declare a block list would be a tax on the common case.
        /** @var list<array<string, mixed>> $items */
        $items = [];
        /** @var mixed $raw_items */
        $raw_items = $block['items'] ?? [];
        if (is_array($raw_items)) {
            foreach (array_values($raw_items) as $item) {
                if (is_string($item)) {
                    $item = ['text' => $item];
                }
                if (!is_array($item)) {
                    continue;
                }

                // Nested through the same recursion and the same ceiling groups
                // use, so an item cannot buy depth a group would be refused.
                $item_blocks = normalize_blocks(
                    is_array($item['blocks'] ?? null) ? $item['blocks'] : [],
                    $section_index,
                    $depth + 1,
                );
                if ($item_blocks instanceof WP_Error) {
                    return $item_blocks;
                }

                // The variant this member takes, which must be one the block
                // declared. Silently ignoring an undeclared name is how a
                // featured tier ships looking exactly like its neighbours with
                // nothing anywhere reporting a problem.
                $variant = sanitize_key((string) ($item['variant'] ?? ''));
                if ($variant !== '' && !array_key_exists($variant, $variants)) {
                    return new WP_Error(
                        'wppilot_spec_item_variant',
                        sprintf(
                            /* translators: 1: section index, 2: the variant named, 3: comma-separated declared variants */
                            __(
                                'Section %1$d has an item with variant "%2$s", which its block does not declare. Declared: %3$s.',
                                domain: 'wppilot',
                            ),
                            $section_index,
                            $variant,
                            $variants === [] ? __('none', domain: 'wppilot') : implode(', ', array_keys($variants)),
                        ),
                        ['status' => 422],
                    );
                }

                // Shorthand or authored, never both. A hybrid would need an
                // ordering rule nobody can predict from the schema — does the
                // title come before the blocks or after them? — and this file
                // fails rather than repairs, because a guessed answer becomes
                // the thing every later page is measured against.
                if ($item_blocks !== []) {
                    foreach (['icon', 'src', 'eyebrow', 'title', 'text', 'value'] as $shorthand) {
                        if (trim((string) ($item[$shorthand] ?? '')) === '') {
                            continue;
                        }

                        return new WP_Error(
                            'wppilot_spec_item_hybrid',
                            sprintf(
                                /* translators: 1: section index, 2: the shorthand key named */
                                __(
                                    'Section %1$d has an item that declares both "blocks" and "%2$s". An item is either shorthand or composed, not both: move the text into a block, or drop the blocks.',
                                    domain: 'wppilot',
                                ),
                                $section_index,
                                $shorthand,
                            ),
                            ['status' => 422],
                        );
                    }
                }

                $items[] = [
                    'icon' => trim((string) ($item['icon'] ?? '')),
                    'src' => esc_url_raw((string) ($item['src'] ?? '')),
                    'eyebrow' => trim((string) ($item['eyebrow'] ?? '')),
                    'title' => trim((string) ($item['title'] ?? '')),
                    'text' => trim((string) ($item['text'] ?? '')),
                    'value' => trim((string) ($item['value'] ?? '')),
                    'surface' => sanitize_key((string) ($item['surface'] ?? '')),
                    'variant' => $variant,
                    'blocks' => $item_blocks,
                    'styles' => is_array($item['styles'] ?? null) ? $item['styles'] : [],
                    'states' => normalize_states($item['states'] ?? null),
                ];
            }
        }

        // Anything the vocabulary does not name. A closed component list keeps a
        // spec portable and gradeable, and on its own it also makes every
        // section that is not one of eleven shapes impossible to express, which
        // is the complaint a closed list always earns. Styles ride alongside:
        // the component decides the structure, and this decides everything
        // else, so a caller is never stuck asking for a shape nobody
        // anticipated. Values are passed to the builder as-is and validated
        // there against the real style schema, so a wrong one is refused with a
        // reason rather than silently dropped.
        /** @var array<string, mixed> $styles */
        $styles = is_array($block['styles'] ?? null) ? $block['styles'] : [];

        // A group holds other blocks and carries its own layout, fill and
        // styling. It is what turns a fixed component list into a composable
        // one: a chat panel, a tabbed preview, a bordered feature row and a
        // hundred shapes nobody has thought of are all a group with children,
        // so the vocabulary no longer has to grow a type every time a reference
        // contains something new. Without it the only sections that could be
        // built were the ones this file happened to anticipate, which is the
        // real reason a matched page kept coming out as a column of paragraphs.
        $children = [];
        if ($type === 'group') {
            $nested = normalize_blocks(
                is_array($block['blocks'] ?? null) ? $block['blocks'] : [],
                $section_index,
                $depth + 1,
            );
            if ($nested instanceof WP_Error) {
                return $nested;
            }
            $children = $nested;
        }

        // Any Elementor widget, named and configured directly. The component
        // list covers what most sections are made of and there are a hundred
        // and ninety-two widgets on this install, so a closed list will always
        // be missing the one a particular reference needs: a slider, a counter,
        // a form, a gallery, a widget an addon pack installed last week. The
        // named components stay because they are portable and gradeable; this
        // is the door out of them, and its settings are validated against the
        // real widget schema at build time rather than here, where nothing
        // knows what a widget is.
        /** @var array<string, mixed> $widget_settings */
        $widget_settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];

        $out[] = [
            'type' => $type,
            'widget' => sanitize_key((string) ($block['widget'] ?? '')),
            'settings' => $widget_settings,
            'blocks' => $children,
            'layout' => $layout,
            'styles' => $styles,
            'src' => esc_url_raw((string) ($block['src'] ?? '')),
            'query' => trim((string) ($block['query'] ?? '')),
            'alt' => trim((string) ($block['alt'] ?? '')),
            'align' => sanitize_key((string) ($block['align'] ?? '')),
            // Which column of a multi-column section this block belongs to.
            // Without it a section's blocks fall into the grid in reading order
            // and a hero of heading, text, buttons and a panel comes out as two
            // columns of two, which is never what the reference did.
            'column' => max(0, (int) ($block['column'] ?? 0)),
            'role' => sanitize_key((string) ($block['role'] ?? '')),
            // Empty means "derive it from the role", which is what every spec
            // written before this did and still gets.
            'tag' => $tag,
            'text' => trim((string) ($block['text'] ?? '')),
            'surface' => sanitize_key((string) ($block['surface'] ?? '')),
            'columns' => max(0, (int) ($block['columns'] ?? 0)),
            'states' => normalize_states($block['states'] ?? null),
            'variants' => $variants,
            'items' => $items,
        ];
    }

    return $out;
}

/**
 * A composition: a block subtree on its own, outside any section.
 *
 * This exists so composition knowledge can be captured rather than shipped.
 * Every other tool in this space solves the cold-start problem with a file per
 * industry — a dentist's homepage has these eight sections in this order — which
 * works the day it is written, is stale the month after, covers only the trades
 * somebody thought of, and hands every dentist in the country the same page. The
 * shape being prescribed at section level rather than pixel level does not stop
 * it being a template.
 *
 * So nothing is shipped. A composition is measured from a reference, adopted
 * from a site, or remembered from a build, and it is validated through the same
 * normalizer a spec's blocks go through — which means a captured composition is
 * expressible, buildable and gradeable by construction, rather than being a
 * second format that drifts from the first.
 *
 * Section index zero because a fragment has no position in a page yet. That is
 * the whole difference between this and {@see normalize_blocks}: a composition
 * is a shape, and where it goes is decided when it is used.
 *
 * @param  list<mixed> $raw
 * @return list<array<string, mixed>>|WP_Error
 */
function normalize_composition(array $raw): array|WP_Error
{
    return normalize_blocks($raw, section_index: 0);
}

/**
 * Component types a section may contain.
 *
 * Deliberately a closed list. An open one turns into free-form markup within a
 * week, and free-form markup is the thing that cannot be graded, cannot be
 * rebuilt on another builder, and cannot be changed centrally when the spec
 * changes.
 *
 * @return list<string>
 */
function block_types(): array
{
    return [
        'eyebrow',
        'heading',
        'text',
        'buttons',
        'cards',
        'panel',
        'list',
        'stats',
        'accordion',
        'logos',
        'media',
        'image',
        'quote',
        'divider',
        'spacer',
        'group',
        'tabs',
        'toggle',
        'widget',
    ];
}

/**
 * The style of every named role in a spec, as a flat CSS property map.
 *
 * This is the whole contract between the spec and a builder. A builder asks for
 * the roles, turns each one into whatever a class is on its side, and never has
 * to know what a spec is; the spec never has to know what Elementor is. It also
 * means the classes a page is built from are generated from the measurements
 * rather than typed out by hand once per page, which is what made two earlier
 * attempts unreproducible.
 *
 * @param  array<string, mixed> $spec
 * @return array<string, array<string, mixed>>
 */
/**
 * How far a rounded corner eats into its own box, as a fraction of the radius.
 *
 * The largest square that fits inside a quarter-circle of radius r is inset
 * from the corner by r(1 - 1/root 2). Below that, content is inside the arc and
 * clips; above it, the corner is clear. Exact, and about a third of the "pad by
 * the radius" rule of thumb it replaces.
 */
const CORNER_ENCROACHMENT = 0.2929;

/**
 * The corner a block nested inside a rounded one should take.
 *
 * Two curves look like one object when they share a centre, and share a centre
 * exactly when the inner radius is the outer one minus the gap between them.
 * Give a nested card the same radius as its parent and the shapes read as two
 * things that happen to be near each other — the inner corner looks too round
 * and nobody can say why, because the error is a few pixels and entirely
 * perceptual.
 *
 * Clamped at zero: once the padding exceeds the radius the inner block is
 * square, and a negative corner is not a tighter one.
 */
/**
 * The transition every interactive surface carries.
 *
 * Elementor's style schema already holds `transition`, `transform`, `filter`
 * and `opacity`, which is the whole of what interaction motion needs — so this
 * is vocabulary rather than a stylesheet bolted on beside one. A hover that
 * snaps reads as a page that was assembled; the same hover over 180ms reads as
 * a surface that responds, and the difference is one property.
 *
 * 180ms because it is the band where motion is perceived as causal rather than
 * as animation. Below about 100ms the eye reads it as an instant jump; past
 * roughly 300ms the interface starts waiting for itself.
 *
 * @return list<array<string, mixed>>
 */
function ease(int $ms = 180): array
{
    return [[
        '$$type' => 'selection-size',
        'value' => [
            'selection' => ['$$type' => 'key-value', 'value' => [
                'key' => ['$$type' => 'string', 'value' => 'All properties'],
                'value' => ['$$type' => 'string', 'value' => 'all'],
            ]],
            'size' => ['$$type' => 'size', 'value' => ['unit' => 'ms', 'size' => $ms]],
        ],
    ]];
}

function inner_radius(float $outer, float $padding): float
{
    return max(0.0, round($outer - $padding, precision: 2));
}

function roles(array $spec): array
{
    /** @var array<string, array<string, mixed>> $out */
    $out = [];

    /** @var array<string, string> $surfaces */
    $surfaces = $spec['surfaces'];
    $pad = (float) $spec['section_padding'];
    $pad_mobile = (float) $spec['section_padding_mobile'];
    $pad_mobile = $pad_mobile > 0 ? $pad_mobile : max(40.0, round($pad * 0.45));

    /** @var array<string, array<string, mixed>> $gradients */
    $gradients = is_array($spec['gradients'] ?? null) ? $spec['gradients'] : [];

    foreach ($surfaces as $name => $hex) {
        $background = array_key_exists($name, $gradients)
            ? ['color' => $hex, 'background-overlay' => [[
                '$$type' => 'background-gradient-overlay',
                'value' => [
                    'type' => $gradients[$name]['type'],
                    'angle' => $gradients[$name]['angle'],
                    'stops' => array_map(
                        static fn(array $stop): array => [
                            '$$type' => 'color-stop',
                            'value' => [
                                'color' => ['$$type' => 'color', 'value' => $stop['color']],
                                'offset' => ['$$type' => 'number', 'value' => $stop['offset']],
                            ],
                        ],
                        $gradients[$name]['stops'],
                    ),
                ],
            ]]]
            : ['color' => $hex];

        $out['surface-' . $name] = [
            'styles' => [
                'background' => $background,
                'padding' => [
                    'block-start' => $pad . 'px',
                    'block-end' => $pad . 'px',
                    'inline-start' => '24px',
                    'inline-end' => '24px',
                ],
            ],
            'mobile' => [
                'padding' => [
                    'block-start' => $pad_mobile . 'px',
                    'block-end' => $pad_mobile . 'px',
                    'inline-start' => '20px',
                    'inline-end' => '20px',
                ],
            ],
        ];
    }

    $out['container'] = [
        'styles' => [
            'max-width' => (float) $spec['container'] . 'px',
            'width' => '100%',
            'margin' => ['inline-start' => 'auto', 'inline-end' => 'auto'],
            'display' => 'flex',
            'flex-direction' => 'column',
            'gap' => (float) $spec['gap'] . 'px',
            'align-items' => 'flex-start',
        ],
        'mobile' => [],
    ];

    foreach (layouts() as $name => $columns) {
        if ($columns === '') {
            continue;
        }

        if ($columns === BLEED) {
            $out['layout-' . $name] = [
                'styles' => [
                    'width' => '100vw',
                    'max-width' => ['$$type' => 'size', 'value' => ['unit' => 'custom', 'size' => 'none']],
                    // Centred on the viewport rather than on the container, so
                    // it escapes symmetrically whatever the container is doing.
                    'margin' => [
                        'inline-start' => ['$$type' => 'size', 'value' => ['unit' => 'custom', 'size' => 'calc(50% - 50vw)']],
                        'inline-end' => ['$$type' => 'size', 'value' => ['unit' => 'custom', 'size' => 'calc(50% - 50vw)']],
                    ],
                ],
                'mobile' => [],
            ];
            continue;
        }

        if ($columns === LAYER) {
            $out['layout-' . $name] = [
                'styles' => [
                    'display' => 'grid',
                    'grid-template-columns' => '1fr',
                    'width' => '100%',
                    'align-items' => 'center',
                ],
                'mobile' => [],
            ];
            continue;
        }

        if ($columns === INDEX_DETAIL) {
            $out['layout-' . $name] = [
                'styles' => [
                    'display' => 'grid',
                    'grid-template-columns' => '35fr 65fr',
                    'gap' => (float) $spec['gap'] . 'px',
                    'width' => '100%',
                    // Start, not stretch: a sticky child cannot stick inside a
                    // cell that has already been stretched to the row height.
                    'align-items' => 'start',
                ],
                'mobile' => ['grid-template-columns' => '1fr'],
            ];
            continue;
        }

        if ($columns === OVERLAP) {
            // Pulled up by half the section's own padding, so the overlap is
            // proportional to the page's rhythm rather than a fixed number that
            // looks deliberate on one design and broken on the next.
            $pull = max(32.0, round($pad * 0.5));
            $out['layout-' . $name] = [
                'styles' => [
                    'position' => 'relative',
                    'z-index' => 2,
                    'width' => '100%',
                    'margin' => ['block-start' => '-' . $pull . 'px'],
                ],
                // On a phone the bands are shorter and the pull reads as a
                // collision rather than as a composition.
                'mobile' => ['margin' => ['block-start' => '0px']],
            ];
            continue;
        }

        if ($columns === FLOW || $columns === FLOW_BASELINE) {
            $out['layout-' . $name] = [
                'styles' => [
                    'display' => 'flex',
                    'flex-direction' => 'row',
                    'flex-wrap' => 'wrap',
                    'gap' => (float) $spec['gap'] . 'px',
                    // Two sizes centred against each other is the thing that
                    // makes a price look pasted on; sitting them on one line
                    // along the bottom is what makes it read as one figure.
                    //
                    // `flex-end` rather than `baseline`, and not by choice:
                    // Elementor's atomic style schema enumerates align-items as
                    // normal, stretch, center, start, end, flex-start, flex-end,
                    // self-start, self-end, anchor-center — baseline is not in
                    // it, and a class asking for one is refused at compile time
                    // rather than degrading. Flex-end aligns the line boxes
                    // instead of the baselines, so a suffix with generous
                    // leading sits a few pixels low; give it a line-height near
                    // 1 and the two agree. Still far closer to the intent than
                    // centring, which is the only other thing the schema allows.
                    'align-items' => $columns === FLOW_BASELINE ? 'flex-end' : 'center',
                ],
                // A row stays a row on a phone. Collapsing one would break the
                // price it was built for into two lines, which is the failure
                // the layout exists to prevent.
                'mobile' => [],
            ];
            continue;
        }

        $out['layout-' . $name] = [
            'styles' => [
                'display' => 'grid',
                'grid-template-columns' => $columns,
                'gap' => (float) $spec['gap'] . 'px',
                'width' => '100%',
                'align-items' => 'start',
            ],
            // Every multi-column layout becomes one column on a phone. A spec
            // that had to state this per section would state it wrongly once.
            'mobile' => ['grid-template-columns' => '1fr'],
        ];
    }

    // Ratios the spec named directly. Generated from what is used rather than
    // enumerated in advance, because the set of useful ratios is every ratio.
    foreach (ratio_layouts($spec) as $name) {
        preg_match('/^split-([0-9]+)-([0-9]+)$/', $name, $parts);
        $out['layout-' . $name] = [
            'styles' => [
                'display' => 'grid',
                'grid-template-columns' => $parts[1] . 'fr ' . $parts[2] . 'fr',
                'gap' => (float) $spec['gap'] . 'px',
                'width' => '100%',
                'align-items' => 'start',
            ],
            'mobile' => ['grid-template-columns' => '1fr'],
        ];
    }

    // The two roles a composition puts on its children rather than on itself.
    $out['layer-item'] = [
        'styles' => ['grid-column' => '1', 'grid-row' => '1'],
        'mobile' => [],
    ];
    $out['index-sticky'] = [
        'styles' => [
            'position' => 'sticky',
            'inset-block-start' => (float) $spec['gap'] . 'px',
            'align-self' => 'start',
        ],
        // Sticky on a single column is just a header that will not go away.
        'mobile' => ['position' => 'static'],
    ];

    /** @var array<string, array<string, mixed>> $type */
    $type = $spec['type'];
    foreach ($type as $role => $props) {
        foreach ($surfaces as $surface_name => $surface_hex) {
            // A role is declared once and needed in every colour the page sets
            // it in, so each one is emitted per surface with a readable
            // foreground rather than leaving the caller to invent one.
            $ink = readable_on($surface_hex, $surfaces);
            $key = $role . '-on-' . $surface_name;
            $out[$key] = ['styles' => type_styles($props, $ink), 'mobile' => type_mobile($props)];
        }
        $out[$role] = [
            'styles' => type_styles($props, named_color($surfaces, (string) $props['color'])),
            'mobile' => type_mobile($props),
        ];
    }

    // Components. A spec that stopped at type and surfaces could colour a page
    // correctly and still contain none of the things the reference is made of,
    // so the filled blocks a page is actually built from are generated here
    // from the same numbers rather than hand-written per page.
    $radius = is_array($spec['radius'] ?? null) ? $spec['radius'] : [];
    $card_radius = (float) ($radius['md'] ?? $radius['sm'] ?? 16);
    $pill = (float) ($radius['pill'] ?? 999);
    $gap = (float) $spec['gap'];

    // Card padding used to be 32px, its inner gap 12px and a button's padding
    // 18 by 32, written here and identical on every design this plugin has ever
    // produced. That is authored composition sitting inside the tool: two specs
    // that agree about nothing else still produced cards with the same inset,
    // which is a large part of why spec-built pages recognised each other.
    //
    // Derived from the spec's own gap instead, with a geometric floor.
    //
    // The floor is the corner's actual encroachment, not its radius. A square
    // inscribed in the arc is offset from the box edge by r(1 - 1/root 2),
    // about 0.29r, and that is the point where content starts running into the
    // curve. "Pad by at least the radius" is a common rule of thumb and roughly
    // three times too strict — applied literally it forces the padding to equal
    // or exceed the radius on every card, which makes every nested corner
    // exactly zero and the concentric rule below unreachable.
    $card_pad = max($gap, ceil($card_radius * CORNER_ENCROACHMENT));
    // The inner gap is a step below the outer one. A card whose children are
    // spaced like its siblings has no inside.
    $card_gap = max(8.0, round($gap * 0.375));

    // A button's proportions come from the button, not from the card it sits
    // near. Floored on the card inset, this produced 10px of height against
    // 40px of width on a design with large corners — a card's optical floor is
    // driven by its radius, and a button does not have that radius.
    $button_pad_y = max(10.0, round($gap * 0.5625));
    $button_pad_x = round($button_pad_y * 1.75);

    foreach ($surfaces as $name => $hex) {
        $ink = readable_on($hex, $surfaces);

        $out['card-' . $name] = [
            'styles' => [
                'background' => ['color' => $hex],
                'border-radius' => $card_radius . 'px',
                'padding' => [
                    'block-start' => $card_pad . 'px',
                    'block-end' => $card_pad . 'px',
                    'inline-start' => $card_pad . 'px',
                    'inline-end' => $card_pad . 'px',
                ],
                'display' => 'flex',
                'flex-direction' => 'column',
                'gap' => $card_gap . 'px',
                'transition' => ease(),
            ],
            'mobile' => [],
        ];

        // A filled block sitting inside a filled card. Its corner is the outer
        // one minus the padding between them, which is what makes two nested
        // curves look like one object rather than two: concentric corners share
        // a centre, and equal corners at different offsets visibly do not.
        $out['card-inner-' . $name] = [
            'styles' => [
                'background' => ['color' => $hex],
                'border-radius' => inner_radius($card_radius, $card_pad) . 'px',
                'padding' => [
                    'block-start' => $card_gap . 'px',
                    'block-end' => $card_gap . 'px',
                    'inline-start' => $card_gap . 'px',
                    'inline-end' => $card_gap . 'px',
                ],
                'display' => 'flex',
                'flex-direction' => 'column',
                'gap' => $card_gap . 'px',
            ],
            'mobile' => [],
        ];

        // A button on a ground is that ground's strongest counterpart filled
        // in, with the ground itself as the label. Derived rather than declared
        // because a spec that had to name a button colour per surface would
        // name one of them wrong.
        $out['button-' . $name] = [
            'styles' => [
                'background' => ['color' => $ink],
                'color' => $hex,
                'font-weight' => '600',
                // A button is wider than it is tall by roughly the same amount
                // on every design worth copying, so the ratio is fixed and the
                // size is not. The horizontal floor is the card's inset rather
                // than the corner radius: a pill declares a radius far larger
                // than the button, and CSS clamps that to half the height on
                // its own, so applying the optical rule literally would pad a
                // button to five hundred pixels wide.
                'padding' => [
                    'block-start' => $button_pad_y . 'px',
                    'block-end' => $button_pad_y . 'px',
                    'inline-start' => $button_pad_x . 'px',
                    'inline-end' => $button_pad_x . 'px',
                ],
                'border-radius' => $pill . 'px',
                'border-width' => '0px',
                'align-self' => 'flex-start',
                'cursor' => 'pointer',
                'transition' => ease(),
            ],
            'mobile' => [],
        ];
    }

    $out['row'] = [
        'styles' => [
            'display' => 'flex',
            'flex-direction' => 'row',
            'gap' => $card_gap . 'px',
            'flex-wrap' => 'wrap',
            'align-items' => 'center',
        ],
        'mobile' => [],
    ];

    $out['stack'] = [
        'styles' => [
            'display' => 'flex',
            'flex-direction' => 'column',
            'gap' => $gap . 'px',
            'align-items' => 'flex-start',
            'width' => '100%',
        ],
        'mobile' => [],
    ];

    return $out;
}

/**
 * @param  array<string, mixed> $props
 * @return array<string, mixed>
 */
function type_styles(array $props, string $color): array
{
    $styles = [
        'font-size' => (float) $props['size'] . 'px',
        'font-weight' => (string) $props['weight'],
        'line-height' => ['$$type' => 'size', 'value' => ['size' => (string) $props['line_height'], 'unit' => 'custom']],
        'color' => $color,
    ];
    if ((string) $props['family'] !== '') {
        $styles['font-family'] = (string) $props['family'];
    }
    if ((float) $props['tracking'] !== 0.0) {
        $styles['letter-spacing'] = (float) $props['tracking'] . 'em';
    }
    if ((float) $props['measure'] > 0) {
        $styles['max-width'] = (float) $props['measure'] . 'ch';
    }
    return $styles;
}

/**
 * @param  array<string, mixed> $props
 * @return array<string, mixed>
 */
function type_mobile(array $props): array
{
    $mobile = [];
    if ((float) $props['size_mobile'] > 0) {
        $mobile['font-size'] = (float) $props['size_mobile'] . 'px';
    }
    if ((float) $props['measure'] > 0) {
        $mobile['max-width'] = '100%';
    }
    return $mobile;
}

/** A named surface's hex, or the name itself when it is already one. */
function named_color(array $surfaces, string $name): string
{
    if (array_key_exists($name, $surfaces)) {
        return (string) $surfaces[$name];
    }
    $hex = Preflight\normalize_hex($name);
    return $hex !== '' ? $hex : '#000000';
}

/**
 * The declared surface that should carry text on a given ground.
 *
 * Picked from the spec's own surfaces rather than defaulting to black or white,
 * because a design that chose a near-black green and a warm off-white chose
 * them for each other, and dropping pure white onto its dark sections is the
 * detail that makes a faithful copy look slightly wrong.
 *
 * Not simply the highest contrast, which was the first answer and the wrong
 * one: on a near-black ground an acid lime out-contrasts the cream, so every
 * paragraph on every dark section came out in the accent. That is the single
 * loudest mistake available with a palette like this, and the spec would have
 * compiled it into forty classes. Among the surfaces that clear readability the
 * quietest wins, and contrast only decides it when nothing clears the bar.
 *
 * @param array<string, string> $surfaces
 */
function readable_on(string $ground, array $surfaces): string
{
    $readable = '';
    $readable_saturation = 2.0;
    $fallback = '';
    $fallback_ratio = 0.0;

    foreach ($surfaces as $hex) {
        $ratio = (float) (Contrast\ratio($hex, $ground) ?? 0.0);
        if ($ratio > $fallback_ratio) {
            $fallback_ratio = $ratio;
            $fallback = $hex;
        }
        if ($ratio < 4.5) {
            continue;
        }
        $hsl = Preflight\hex_to_hsl($hex);
        $saturation = $hsl === null ? 1.0 : (float) $hsl[1];
        if ($saturation < $readable_saturation) {
            $readable_saturation = $saturation;
            $readable = $hex;
        }
    }

    if ($readable !== '') {
        return $readable;
    }

    return $fallback !== '' ? $fallback : '#000000';
}

/**
 * Store a spec against a saved design.
 *
 * @param  array<string, mixed> $spec
 * @return array<string, mixed>|WP_Error
 */
function save(string $slug, array $spec): array|WP_Error
{
    $normalized = normalize($spec);
    if ($normalized instanceof WP_Error) {
        return $normalized;
    }

    $post = Store\find_user_post($slug);
    if ($post === null) {
        return new WP_Error(
            'wppilot_spec_no_design',
            sprintf(
                /* translators: %s: the slug asked for */
                __('No design "%s" exists to attach a spec to. Save the design first.', domain: 'wppilot'),
                $slug,
            ),
            ['status' => 404],
        );
    }

    update_post_meta($post->ID, META_KEY, wp_slash(wp_json_encode($normalized) ?: '{}'));

    return $normalized;
}

/**
 * Read a spec, or null when the design has none.
 *
 * @return array<string, mixed>|null
 */
function get(string $slug): ?array
{
    $post = Store\find_user_post($slug);
    if ($post === null) {
        return null;
    }
    /** @var mixed $raw */
    $raw = get_post_meta($post->ID, META_KEY, single: true);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    /** @var mixed $decoded */
    $decoded = json_decode($raw, associative: true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * How closely a built page matches the spec it was built from.
 *
 * The measurements come from the caller, because reading a rendered page means
 * a real browser resolving a real cascade, and PHP fetching HTML cannot do it.
 * What matters is that the comparison itself is not the caller's to make: an
 * agent asked to grade its own work grades it generously, and the whole reason
 * two earlier attempts shipped at a fifth of the target was that nothing
 * anywhere produced a number.
 *
 * Surfaces are weighted hardest. The sequence of grounds down a page is what a
 * person recognises before they read a word, and it is the first thing that
 * gets invented when a reference is described rather than measured.
 *
 * @param  array<string, mixed> $spec
 * @param  array<string, mixed> $actual
 * @return array{score: float, matched: int, checked: int, diffs: list<array<string, string>>}
 */
function compare(array $spec, array $actual): array
{
    /** @var list<array<string, string>> $diffs */
    $diffs = [];
    $weighted = 0.0;
    $total = 0.0;

    $check = static function (
        string $label,
        mixed $want,
        mixed $got,
        float $weight,
        float $tolerance = 0.0,
    ) use (&$diffs, &$weighted, &$total): void {
        $total += $weight;
        $ok = is_numeric($want) && is_numeric($got)
            ? abs((float) $want - (float) $got) <= $tolerance
            : (string) $want === (string) $got;
        if ($ok) {
            $weighted += $weight;
            return;
        }
        $diffs[] = [
            'property' => $label,
            'expected' => is_scalar($want) ? (string) $want : (string) (wp_json_encode($want) ?: ''),
            'actual' => is_scalar($got) ? (string) $got : (string) (wp_json_encode($got) ?: ''),
        ];
    };

    // The surface sequence, position by position. Compared as a sequence rather
    // than as a set: the same five grounds in a different order is a different
    // page, and a set comparison would call it a perfect match.
    /** @var list<array<string, mixed>> $sections */
    $sections = $spec['sections'];
    /** @var mixed $actual_surfaces */
    $actual_surfaces = $actual['surfaces'] ?? [];
    $actual_surfaces = is_array($actual_surfaces) ? array_values($actual_surfaces) : [];
    /** @var array<string, string> $surfaces */
    $surfaces = $spec['surfaces'];

    $check('section count', count($sections), count($actual_surfaces), weight: 3.0);
    foreach ($sections as $index => $section) {
        $want = (string) ($surfaces[(string) $section['surface']] ?? '');
        $got = Preflight\normalize_hex((string) ($actual_surfaces[$index] ?? ''));
        $check('section ' . $index . ' surface', $want, $got, weight: 3.0);
    }

    $check('container', $spec['container'], $actual['container'] ?? null, weight: 2.0, tolerance: 2.0);
    $check(
        'section padding',
        $spec['section_padding'],
        $actual['section_padding'] ?? null,
        weight: 2.0,
        tolerance: 2.0,
    );

    /** @var array<string, array<string, mixed>> $type */
    $type = $spec['type'];
    /** @var mixed $actual_type */
    $actual_type = $actual['type'] ?? [];
    $actual_type = is_array($actual_type) ? $actual_type : [];
    foreach ($type as $role => $props) {
        /** @var mixed $got */
        $got = $actual_type[$role] ?? [];
        $got = is_array($got) ? $got : [];
        // A point either way on size: a spec says 73 and a browser reports
        // 73.44 because the reference used a viewport-relative step.
        $check($role . ' size', $props['size'], $got['size'] ?? null, weight: 2.0, tolerance: 1.0);
        // Weight is unforgiving on purpose. It is the single property that
        // decides whether a page reads as the same design, and it is the one an
        // inference gets wrong most often.
        $check($role . ' weight', $props['weight'], $got['weight'] ?? null, weight: 2.0);
    }

    $score = $total > 0.0 ? round($weighted / $total, 3) : 0.0;

    return ['score' => $score, 'matched' => count($diffs) === 0 ? 1 : 0, 'checked' => (int) $total, 'diffs' => $diffs];
}

/**
 * A design's structure as one comparable string.
 *
 * Palette and type pairing already tell you whether two designs look alike.
 * They say nothing about whether they are the same page, and a site can change
 * every colour and every face while keeping the identical skeleton: paper hero,
 * raised card grid, ink band, lime call. That is the shape a reader recognises
 * before they read a word, and a distinctiveness check that ignores it will call
 * two builds of one template distinct because the second one is blue.
 *
 * The signature is the sequence of surface role and layout, not the colours
 * themselves. Two designs whose grounds differ but whose rhythm is identical
 * should still match, because it is the rhythm that repeats.
 */
/**
 * The shape of a block list, nesting included.
 *
 * This walked only the top level, which was survivable while nesting was rare
 * and became a real blind spot the moment items could hold blocks: a page of
 * six plain cards and a page of six composed pricing tiers both reduced to
 * "ca", so distinctiveness would have called them the same page and said
 * nothing. The structure that got deeper is exactly the structure that carries
 * the difference.
 *
 * Depth is written into the string rather than flattened away, so a page that
 * moves a shape one level down reads as changed rather than as identical.
 *
 * @param  list<array<string, mixed>> $blocks
 */
function block_signature(array $blocks, int $depth = 0): string
{
    // The same ceiling the normalizer enforces. A spec cannot nest deeper than
    // this, so a signature that stops here is not truncating anything real.
    if ($depth > 6) {
        return '';
    }

    $parts = [];
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $part = substr((string) ($block['type'] ?? ''), offset: 0, length: 2);

        $children = is_array($block['blocks'] ?? null) ? $block['blocks'] : [];
        if ($children !== []) {
            $part .= '(' . block_signature($children, $depth + 1) . ')';
        }

        // A set's members count structurally. Composed items are most of what
        // separates one card grid from another, and a variant is the thing that
        // stops a set reading as a table, so both belong in the signature.
        $items = is_array($block['items'] ?? null) ? $block['items'] : [];
        $members = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $inner = is_array($item['blocks'] ?? null) ? $item['blocks'] : [];
            $members[] = ((string) ($item['variant'] ?? '') !== '' ? '*' : '')
                . ($inner !== [] ? '[' . block_signature($inner, $depth + 1) . ']' : '.');
        }
        if ($members !== []) {
            $part .= '{' . implode('', $members) . '}';
        }

        $parts[] = $part;
    }

    return implode('', $parts);
}

function skeleton(array $spec): string
{
    /** @var list<array<string, mixed>> $sections */
    $sections = is_array($spec['sections'] ?? null) ? $spec['sections'] : [];
    if ($sections === []) {
        return '';
    }

    /** @var array<string, string> $surfaces */
    $surfaces = is_array($spec['surfaces'] ?? null) ? $spec['surfaces'] : [];

    $parts = [];
    foreach ($sections as $section) {
        $surface = (string) ($section['surface'] ?? '');
        // Grounds are compared as light or dark rather than by name, so two
        // designs that both open dark and follow it light match even when one
        // calls its dark surface "ink" and the other "night".
        $hex = (string) ($surfaces[$surface] ?? '');
        $hsl = $hex !== '' ? Preflight\hex_to_hsl($hex) : null;
        $tone = $hsl === null ? '?' : ((float) $hsl[2] < 0.5 ? 'd' : 'l');

        /** @var list<array<string, mixed>> $blocks */
        $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];

        $parts[] = $tone . ':' . (string) ($section['layout'] ?? 'stack') . ':' . block_signature($blocks);
    }

    return implode('|', $parts);
}

/**
 * How much two skeletons differ, 0 (identical) to 1 (nothing in common).
 *
 * Compared position by position rather than as a set, because the same sections
 * in a different order is a different page and a set comparison would call it a
 * perfect match. A length difference counts against the pair for the same
 * reason: eight sections and fourteen are not the same page whatever they share.
 */
function skeleton_distance(string $a, string $b): float
{
    if ($a === '' || $b === '') {
        return 1.0;
    }
    if ($a === $b) {
        return 0.0;
    }

    $left = explode('|', $a);
    $right = explode('|', $b);
    $length = max(count($left), count($right));

    $same = 0;
    for ($i = 0; $i < $length; $i++) {
        if (($left[$i] ?? '') !== '' && ($left[$i] ?? '') === ($right[$i] ?? '')) {
            $same++;
        }
    }

    return 1.0 - ($same / $length);
}
