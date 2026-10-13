<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Preview\Projectors;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Projected after-images for the writes GoBuildWP understands.
 *
 * A projector is a pure function of (before-snapshot, normalized input) that
 * returns an after-snapshot in the exact shape wppilot_snapshot_post() and its
 * siblings already produce, so includes/preview/diff.php can compare the two
 * symmetric structures without knowing which ability produced them.
 *
 * Projection rather than execution, for one decisive reason: wp_update_post()
 * calls clean_post_cache(), which re-primes the object cache from whatever row
 * is current. A transaction would roll the database back and leave Redis holding
 * the uncommitted content, so a `readonly: true` preview would have permanently
 * changed the site. Third-party code hooked on transition_post_status — which
 * this plugin's own schema notes builders rely on — would also have run for
 * real, writing options and sending mail that no ROLLBACK reaches. Cloning the
 * target instead costs two real writes to preview one, and every id-dependent
 * field then diffs against the clone's id rather than the target's.
 *
 * The cost of projecting is that this file restates each ability's final field
 * assignment and can drift from it. That is contained by scope: a projector
 * calls the ability's own normalization and guard functions, and reimplements
 * only the assignment — which for the post wrapper is a literal field map short
 * enough to review against its original.
 *
 * Nothing here guesses. An ability with no projector is refused by name, with a
 * reason, in the same voice wppilot_extension_files_rollback_reason() uses to
 * explain a non-reversible change.
 */

/**
 * Ability name to projector callable.
 *
 * Deliberately shaped as the sibling of wppilot_capture_before_image(), which is
 * the de-facto registry of writes this plugin understands well enough to undo.
 * A write that can be rolled back can usually be projected, and one that cannot
 * be rolled back is exactly where a preview earns its place.
 *
 * @return array<string, callable(array<string, mixed>, array<string, mixed>): (array<string, mixed>|WP_Error)>
 */
function registry(): array
{
    $projectors = [
        'wppilot/update-post' => __NAMESPACE__ . '\\project_update_post',
        'wppilot/update-media' => __NAMESPACE__ . '\\project_update_post',
        'wppilot/update-site-settings' => __NAMESPACE__ . '\\project_update_settings',
        'wppilot/delete-post' => __NAMESPACE__ . '\\project_delete',
        'wppilot/delete-media' => __NAMESPACE__ . '\\project_delete',
        'wppilot/delete-term' => __NAMESPACE__ . '\\project_delete',
        'wppilot/delete-comment' => __NAMESPACE__ . '\\project_delete',
        'wppilot/delete-menu' => __NAMESPACE__ . '\\project_delete',
        'wppilot/delete-menu-item' => __NAMESPACE__ . '\\project_delete',
    ];

    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_preview_projectors', $projectors);
    if (!is_array($filtered)) {
        return $projectors;
    }

    $safe = [];
    foreach ($filtered as $name => $callable) {
        if (is_string($name) && is_callable($callable)) {
            $safe[$name] = $callable;
        }
    }
    return $safe;
}

function has_projector(string $ability_name): bool
{
    return array_key_exists($ability_name, registry());
}

/**
 * Why an ability cannot be previewed, or null when it can.
 *
 * Named reasons rather than one generic refusal, because "we cannot show you
 * this" and "showing you this would require doing it" are different answers and
 * an agent should be able to act on the difference.
 *
 * @return array{reason: string, message: string}|null
 */
function unsupported_reason(string $ability_name): ?array
{
    if (has_projector($ability_name)) {
        return null;
    }

    $name = strtolower($ability_name);

    if (str_starts_with($name, 'wppilot/gutenberg-')) {
        return [
            'reason' => 'already_staged',
            'message' => 'The Gutenberg queue abilities are themselves a staging and review mechanism: a queued '
                . 'change is not live until a batch is finalized. Inspect the pending batch with '
                . 'wppilot/gutenberg-get-pending-batch instead of previewing the call that creates it.',
        ];
    }

    foreach (['install-plugin', 'install-theme', 'update-plugin', 'update-theme', 'delete-plugin', 'delete-theme'] as $needle) {
        if (str_contains($name, $needle)) {
            return [
                'reason' => 'writes_files',
                'message' => 'Preview is not available for ' . $ability_name . ': it writes files to the server, and '
                    . 'the resulting file tree cannot be computed without performing the operation. Run it directly '
                    . '— the change ledger records it, and wppilot/list-changes reports how to undo it by hand.',
            ];
        }
    }

    foreach (['execute-php', 'run-wp-cli', 'file', 'directory', 'user', 'database', 'snippet'] as $needle) {
        if (str_contains($name, $needle)) {
            return [
                'reason' => 'arbitrary_effect',
                'message' => 'Preview is not available for ' . $ability_name . ': its effect cannot be predicted '
                    . 'without running it. Nothing about the resulting state is knowable in advance.',
            ];
        }
    }

    if (str_contains($name, 'create-')) {
        return [
            'reason' => 'no_projector',
            'message' => 'Preview is not available for ' . $ability_name . ': a create changes nothing that exists '
                . 'yet, so a diff would only hand your own input back. Creates are also the best-covered case for '
                . 'undo — wppilot/list-changes reports them as reversible.',
        ];
    }

    return [
        'reason' => 'no_projector',
        'message' => 'Preview is not available for ' . $ability_name . '. GoBuildWP only previews abilities it has a '
            . 'typed projector for, rather than guessing at a diff that might be wrong.',
    ];
}

/**
 * Fields WordPress recomputes, which a projection states rather than predicts.
 *
 * @return list<array{path_label: string, reason: string}>
 */
function unpredicted_for(string $ability_name, array $input): array
{
    $notes = [];
    if (
        in_array($ability_name, ['wppilot/update-post', 'wppilot/update-media'], strict: true)
        && array_key_exists('date', $input)
    ) {
        $notes[] = [
            'path_label' => 'post.post_date_gmt',
            'reason' => 'The write clears post_date_gmt so WordPress recomputes it from the new local date and the '
                . 'site timezone. The resulting value is not projected here.',
        ];
    }
    return $notes;
}

/**
 * Side effects a diff cannot show, which the reviewer still has to weigh.
 *
 * @return list<string>
 */
function side_effects_for(string $ability_name, array $input): array
{
    $effects = [];

    if (in_array($ability_name, ['wppilot/update-post', 'wppilot/update-media'], strict: true)) {
        $effects[] = 'Applying runs wp_update_post, so transition_post_status fires and other plugins — page '
            . 'builders, SEO and cache tooling — may act on it.';
    }

    if (($input['status'] ?? null) === 'publish') {
        $effects[] = 'This transitions the post to publish, which can send notifications and make the content '
            . 'publicly visible.';
    }

    return $effects;
}

/**
 * Project wppilot/update-post and wppilot/update-media.
 *
 * Mirrors wordpress_update_post_core_fields() and wordpress_update_post_meta_fields()
 * in includes/abilities/wordpress/update-post.php.
 *
 * wp_slash() is deliberately NOT mirrored. Those functions slash because
 * wp_update_post() and update_post_meta() call wp_unslash() internally, so the
 * net stored value is the raw input; the snapshot then reads it back through
 * get_post(), unslashed. Mirroring the statement rather than the semantics would
 * report a spurious change on every value containing a backslash.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity -- Mirrors the ability's own field map one branch at a time, so drift is visible in review.
function project_update_post(array $before, array $input): array|WP_Error
{
    if (($before['type'] ?? '') !== 'post') {
        return new WP_Error('wppilot_preview_unsupported_target', 'The target is not a post-shaped record.');
    }

    $after = $before;
    /** @var array<string, mixed> $post */
    $post = is_array($before['post'] ?? null) ? $before['post'] : [];

    $string_fields = [
        'title' => 'post_title',
        'slug' => 'post_name',
        'status' => 'post_status',
        'content' => 'post_content',
        'excerpt' => 'post_excerpt',
        'date' => 'post_date',
    ];
    $int_fields = [
        'parent' => 'post_parent',
        'menu_order' => 'menu_order',
        'author' => 'post_author',
    ];

    foreach ($string_fields as $input_key => $wp_key) {
        if (!array_key_exists($input_key, $input)) {
            continue;
        }
        $post[$wp_key] = (string) $input[$input_key];
    }

    foreach ($int_fields as $input_key => $wp_key) {
        if (!array_key_exists($input_key, $input)) {
            continue;
        }
        $post[$wp_key] = (int) $input[$input_key];
    }

    // WordPress derives the stored slug from the title on an auto-draft, and
    // uniquifies whatever it is given. wp_unique_post_slug() only reads, so the
    // projection can use the same function the write will.
    if (array_key_exists('slug', $input) && function_exists('wp_unique_post_slug')) {
        $post['post_name'] = wp_unique_post_slug(
            sanitize_title((string) $input['slug']),
            (int) ($post['ID'] ?? 0),
            (string) ($post['post_status'] ?? 'draft'),
            (string) ($post['post_type'] ?? 'post'),
            (int) ($post['post_parent'] ?? 0),
        );
    }

    $after['post'] = $post;

    if (array_key_exists('meta', $input) && is_array($input['meta'])) {
        /** @var array<string, mixed> $meta */
        $meta = is_array($after['meta'] ?? null) ? $after['meta'] : [];
        /** @var mixed $value */
        foreach ($input['meta'] as $key => $value) {
            // Stored meta is always a list of values; the diff layer normalizes
            // a scalar into a one-element list, and matching that here keeps the
            // two sides the same shape.
            $meta[(string) $key] = [$value];
        }
        $after['meta'] = $meta;
    }

    return $after;
}

/**
 * Project wppilot/update-site-settings.
 *
 * The input is the after-state for each allowlisted key. sanitize_option() only
 * reads, so calling it here yields the value update_option() would actually
 * store rather than the raw input.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function project_update_settings(array $before, array $input): array|WP_Error
{
    if (($before['type'] ?? '') !== 'settings') {
        return new WP_Error('wppilot_preview_unsupported_target', 'The target is not a settings record.');
    }

    if (function_exists('WPPilot\\Abilities\\WordPress\\wordpress_validate_site_settings')) {
        $invalid = \WPPilot\Abilities\WordPress\wordpress_validate_site_settings($input);
        if ($invalid instanceof WP_Error) {
            return $invalid;
        }
    }

    /** @var array<string, mixed> $values */
    $values = is_array($before['values'] ?? null) ? $before['values'] : [];
    /** @var mixed $value */
    foreach ($input as $key => $value) {
        $key_string = (string) $key;
        if (!array_key_exists($key_string, $values)) {
            continue;
        }
        $values[$key_string] = function_exists('sanitize_option')
            ? sanitize_option($key_string, $value)
            : $value;
    }

    return ['type' => 'settings', 'values' => $values];
}

/**
 * Project any delete: the after-state is the absence of the record.
 *
 * Returning empty payload subtrees makes every captured field diff as `removed`,
 * which is what a reviewer needs to see before an operation the change ledger
 * reports as non-reversible.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function project_delete(array $before, array $input): array|WP_Error
{
    $after = ['type' => (string) ($before['type'] ?? 'unknown')];

    foreach (['post', 'meta', 'terms', 'values'] as $section) {
        if (array_key_exists($section, $before)) {
            $after[$section] = [];
        }
    }

    return $after;
}
