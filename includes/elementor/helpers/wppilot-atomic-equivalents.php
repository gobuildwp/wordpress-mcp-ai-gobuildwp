<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Which classic (v3) Elementor elements have an atomic (v4) equivalent.
 *
 * One table, two readers. The readiness audit uses it to answer "how much of
 * this site can an atomic-only tool touch"; GoBuildWP Pro's converter uses the
 * same entries to decide what a classic element becomes. Keeping it in the free
 * plugin is the point - an audit that says a widget is mappable and a converter
 * that then cannot map it is worse than either alone.
 *
 * An entry is an honest structural equivalent, not a resemblance. `icon-list`
 * is absent because atomic has no list element: it can be rebuilt as a flexbox
 * of paragraphs, but that is a reconstruction with its own losses and belongs in
 * a converter's loss report rather than in a table that claims equivalence.
 *
 * This is deliberately *not* the list of widgets Elementor's own MCP supports.
 * That list is theirs, changes on their release schedule, and is tracked
 * separately in the audit so the two can disagree without either being wrong.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Classic element or widget name => atomic element name.
 *
 * Structural elements first, then widgets. `spacer` maps to a div block because
 * atomic expresses empty vertical space as a sized box rather than as its own
 * element; the converter sets `min-height` from the spacer's own value.
 *
 * @return array<string, string>
 */
function el_atomic_equivalents(): array
{
    return [
        // Structure.
        'section' => 'e-flexbox',
        'column' => 'e-flexbox',
        'container' => 'e-flexbox',

        // Widgets.
        'heading' => 'e-heading',
        'text-editor' => 'e-paragraph',
        'button' => 'e-button',
        'image' => 'e-image',
        'divider' => 'e-divider',
        'spacer' => 'e-div-block',
        'video' => 'e-youtube',
        'html' => 'html',
    ];
}

/**
 * The atomic element a classic type becomes, or null when nothing equivalent exists.
 */
function el_atomic_equivalent_for(string $type): ?string
{
    return el_atomic_equivalents()[$type] ?? null;
}

/**
 * Whether an element type is already atomic.
 *
 * Atomic elements carry their type in `elType` with an `e-` prefix, which is
 * also how `el_element_widget_type()` reports them, so one prefix test covers
 * both atomic containers and atomic widgets.
 */
function el_type_is_atomic(string $type): bool
{
    return str_starts_with($type, 'e-');
}
