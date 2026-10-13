<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * PHP 8.0-compatible equivalent of array_is_list().
 *
 * @param array<array-key, mixed> $value
 */
function el_array_is_list(array $value): bool
{
    $expected = 0;
    foreach ($value as $key => $_) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

/**
 * Shared runtime guards for Elementor abilities and skills.
 *
 * These checks deliberately use runtime feature detection instead of relying
 * on the version number alone. The atomic surface requires Elementor 4.x
 * proper plus the concrete runtime classes; some feature modules remain
 * optional on top of that.
 */

/**
 * Whether a given Elementor experiment is active. Unknown / unavailable
 * experiment managers are treated as "not gating" so stable releases that
 * no longer expose the flag do not get false negatives.
 */
function el_feature_active(string $feature): bool
{
    if (!class_exists('Elementor\\Plugin')) {
        return false;
    }

    /** @var object $plugin */
    $plugin = \Elementor\Plugin::$instance;
    /** @var object|null $experiments */
    $experiments = $plugin->experiments ?? null;
    if (!is_object($experiments) || !method_exists($experiments, 'is_feature_active')) {
        return true;
    }

    /** @var mixed $active */
    $active = $experiments->is_feature_active($feature);
    return $active === true;
}

/**
 * Whether the atomic widgets / containers runtime is available.
 *
 * Beyond Elementor's minimum version and the Atomic_Element_Base class, this
 * also requires the `e_atomic_elements` experiment to be active: the runtime
 * classes ship inside Elementor itself but the experiment gates the actual
 * registration of the `e-*` widgets (e-heading, e-flexbox, e-paragraph, …).
 * Without it the widgets are never registered, so reporting "atomic
 * available" while the experiment is off is actively misleading — callers
 * would try the v4 path, get "type not available" back, and have no clean
 * fallback.
 */
function el_atomic_runtime_available(): bool
{
    if (!el_min_runtime_available()) {
        return false;
    }

    if (version_compare(el_elementor_version(), version2: '4.0.0', operator: '<')) {
        return false;
    }

    if (!class_exists(WPPILOT_ELEMENTOR_ATOMIC_BASE_CLASS)) {
        return false;
    }

    return el_feature_active('e_atomic_elements');
}

/**
 * Whether the atomic-widgets runtime classes are loaded but the
 * `e_atomic_elements` experiment is OFF — i.e. the runtime is "almost
 * there" but `e-*` widgets are not registered. Used by check-setup to emit
 * a targeted issue distinct from "Elementor too old" or "module missing".
 */
function el_atomic_runtime_classes_loaded_but_experiment_off(): bool
{
    if (!el_min_runtime_available()) {
        return false;
    }

    if (version_compare(el_elementor_version(), version2: '4.0.0', operator: '<')) {
        return false;
    }

    if (!class_exists(WPPILOT_ELEMENTOR_ATOMIC_BASE_CLASS)) {
        return false;
    }

    return !el_feature_active('e_atomic_elements');
}

/**
 * Whether the v4 Atomic Widgets style schema is available.
 */
function el_style_schema_available(): bool
{
    return el_atomic_runtime_available() && class_exists('Elementor\\Modules\\AtomicWidgets\\Styles\\Style_Schema');
}

/**
 * Whether Elementor Global Classes are available.
 */
function el_global_classes_available(): bool
{
    return el_atomic_runtime_available()
    && class_exists('Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository');
}

/**
 * Whether Elementor Variables are available.
 */
function el_variables_available(): bool
{
    return (
        el_atomic_runtime_available()
        && class_exists('Elementor\\Modules\\Variables\\Storage\\Variables_Repository')
        && el_feature_active('e_variables')
    );
}

/**
 * Whether Elementor Interactions on atomic widgets are available.
 */
function el_interactions_available(): bool
{
    return el_atomic_runtime_available();
}

/**
 * Whether the bundled v3 -> v4 conversion skill can be used honestly on this
 * site without advertising missing abilities in its playbook.
 */
function el_v4_conversion_skill_available(): bool
{
    return el_atomic_runtime_available() && el_style_schema_available() && el_global_classes_available();
}

// Ensure per-element custom_css in v4 atomic styles is always rendered,
// even when the Elementor Pro license is not activated on this site
// (e.g. staging environments). The filter receives the stripped styles
// as $arg1 and the full styles (with custom_css) as $arg2.
add_filter(
    'elementor/atomic_widgets/editor_data/element_styles',
    static fn(mixed $stripped, mixed $full): mixed => $full,
    priority: 20,
    accepted_args: 2,
);

// DEPRECATED — retained for backward compatibility only; do NOT build on this.
//
// This filter widens the e-heading `tag` enum from h1-h6 to also allow div and
// span. It was originally added so agents could use e-heading for kicker labels,
// decorative numerals, or icon text. That was a mistake: the widened enum exists
// ONLY while GoBuildWP Pro is active, so a page authored with a div/span-tagged
// e-heading is invalid under stock Elementor's atomic schema. Exported as a
// template and imported on a site without GoBuildWP Pro, Document::save runs
// parse_atomic_settings() and throws an uncaught "tag: invalid_value" — a hard
// PHP fatal on import. The content is silently non-portable.
//
// New content must NOT use it: the build skill no longer documents this path and
// steers kicker/decorative/inline text to e-paragraph (p/span), which is valid
// under stock Elementor and renders identically (same base styles, title ↔
// paragraph). Agents therefore never learn this widening exists.
//
// Why it STAYS active despite the above: sites that authored div/span e-heading
// while it was recommended already have that content in _elementor_data. Only
// the SAVE path enforces the enum — Atomic_Widget_Base::get_data_for_save() calls
// parse_atomic_settings(), which throws on an out-of-enum value (verified in
// Elementor 4.1.x, modules/atomic-widgets/elements/base/has-atomic-base.php). The
// RENDER path does not: Render_Props_Resolver::get_validated_value() passes a
// stored non-dynamic value through unchanged (render-props-resolver.php), so such
// headings keep displaying on the front end. That means the content validates on
// Document::save (an editor re-save, a template export) ONLY while this widening
// is present. Removing the filter now would fatal those sites' own editor saves —
// regressing existing users who have GoBuildWP Pro switched on — while the front
// end never needed it. So it remains as a compatibility net, not a feature.
// Candidate for removal in a future major once such legacy content has drained;
// harmless to keep until then.
//
// Identification: only schemas whose `tag` prop has exactly the h1-h6 enum are
// affected — other tag-bearing atomic widgets have different enums.
add_filter('elementor/atomic-widgets/props-schema', __NAMESPACE__ . '\\el_expand_heading_tag_enum');

/**
 * DEPRECATED filter callback: widen the e-heading tag enum to include div/span.
 * Kept only as a back-compat net for content authored before it was retired (see
 * the block above); new content is steered to e-paragraph instead. Do not extend.
 *
 * Targets only schemas where `tag` is a String_Prop_Type with exactly the
 * h1-h6 enum — the fingerprint unique to Atomic_Heading. All other atomic
 * widgets that carry a `tag` prop use a different enum and are left untouched.
 *
 * @deprecated Retained for backward compatibility; do not author against it.
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function el_expand_heading_tag_enum(array $schema): array
{
    /** @var mixed $tag */
    $tag = $schema['tag'] ?? null;
    if (!$tag instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type) {
        return $schema;
    }
    if ($tag->get_enum() !== ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) {
        return $schema;
    }
    $tag->enum(['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span']);
    return $schema;
}

// el_elementor_version() and el_min_runtime_available() are defined in bootstrap.php, which
// requires this file, so they are available here without redeclaration.
