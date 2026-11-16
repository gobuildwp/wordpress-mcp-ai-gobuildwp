<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Drafts only: an access-token ceiling that lets an agent read and write drafts, and nothing else.
 *
 * A token capped at Drafts only:
 * - may run any read-only ability the site's profile allows (it is Production Safe underneath);
 * - may run the content-drafting abilities in wppilot_drafts_only_abilities(), and no other write:
 *   no options, plugins, themes, users, files, menus, media uploads or settings;
 * - may only create posts as `draft` or `pending`, and may not create templates, navigation or
 *   other `wp_*` post types;
 * - may not touch a post that is already live (published, scheduled or private). Changing a live
 *   post is refused rather than turned into a draft revision: WordPress has no draft-of-a-published
 *   post state, and quietly unpublishing the page would be worse than saying no.
 *
 * The input checks run in the `wp_ability_permission_result` filter, so every transport gets the
 * same refusal. A `wp_insert_post_data` backstop forces any other post an agent's request would
 * publish back to draft, and `wp_insert_post_empty_content` refuses an update to a live post, for
 * an ability that writes posts without going through the checks above.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_DRAFTS_ONLY_CEILING = 'drafts';

/**
 * Whether the access token, OAuth app or application password behind this request is capped at
 * Drafts only.
 */
function wppilot_drafts_only_active(): bool
{
    if (!function_exists('wppilot_current_token_policy')) {
        return false;
    }
    $policy = wppilot_current_token_policy();

    return $policy !== null && ($policy['ceiling'] ?? '') === WPPILOT_DRAFTS_ONLY_CEILING;
}

/**
 * The write abilities a Drafts-only token may call.
 *
 * @return list<string>
 */
function wppilot_drafts_only_abilities(): array
{
    $abilities = [
        'wppilot/create-post',
        'wppilot/update-post',
        'wppilot/gutenberg-write-content',
        'wppilot/set-featured-image',
        'wppilot/remove-featured-image',
        'wppilot/assign-terms',
    ];

    /**
     * Filter the write abilities a Drafts-only access token may call. An added ability must keep
     * to drafts on its own: the input checks here only know the abilities listed above.
     *
     * @param list<string> $abilities
     */
    $filtered = apply_filters('wppilot_drafts_only_abilities', $abilities);

    return is_array($filtered) ? array_values(array_filter($filtered, 'is_string')) : $abilities;
}

/**
 * The post statuses a Drafts-only token may leave a post in.
 *
 * @return list<string>
 */
function wppilot_drafts_only_statuses(): array
{
    return ['draft', 'pending', 'auto-draft'];
}

/**
 * Whether a post is live, and so out of reach for a Drafts-only token.
 */
function wppilot_drafts_only_post_is_live(int $post_id): bool
{
    if ($post_id <= 0) {
        return false;
    }
    $status = get_post_status($post_id);

    return is_string($status) && in_array($status, ['publish', 'future', 'private'], strict: true);
}

/**
 * Post types a Drafts-only token may not create or change: site structure, not content.
 */
function wppilot_drafts_only_post_type_refused(string $post_type): bool
{
    return $post_type === 'attachment' || $post_type === 'nav_menu_item' || str_starts_with($post_type, 'wp_');
}

function wppilot_drafts_only_error(string $message, string $ability_name): WP_Error
{
    return new WP_Error(
        'wppilot_drafts_only',
        $message . ' ' . (function_exists('wppilot_current_token_id') && wppilot_current_token_id() <= 0
            ? __('The connection behind this request is at the level "Drafts only"; the site owner sets that on the GoBuildWP Connect screen. Do not retry.', domain: 'wppilot')
            : __('The access token behind this request is capped at Drafts only; the site owner sets that on the token. Do not retry.', domain: 'wppilot')),
        ['status' => 403, 'ability' => $ability_name, 'ceiling' => WPPILOT_DRAFTS_ONLY_CEILING],
    );
}

/**
 * Refuse an ability a Drafts-only token may not call at all. Null when it may.
 *
 * Read by discovery too (through wppilot_agent_scope_error()), so the agent is never shown a tool
 * its next call would be refused.
 */
function wppilot_drafts_only_scope_error(WP_Ability $ability): ?WP_Error
{
    if (!wppilot_drafts_only_active()) {
        return null;
    }
    $name = $ability->get_name();
    if (function_exists('wppilot_ability_is_hub_protected') && wppilot_ability_is_hub_protected($name)) {
        return null;
    }
    if (function_exists('wppilot_ability_is_readonly') && wppilot_ability_is_readonly($ability)) {
        return null;
    }
    if (in_array($name, wppilot_drafts_only_abilities(), strict: true)) {
        return null;
    }

    return wppilot_drafts_only_error(
        sprintf(
            /* translators: %s: ability name */
            __('Ability "%s" changes more than drafts.', domain: 'wppilot'),
            $name,
        ),
        $name,
    );
}

/**
 * The post an ability call targets, from the id aliases the content abilities accept.
 */
function wppilot_drafts_only_target_id(array $input): int
{
    foreach (['post_id', 'id', 'target_id'] as $key) {
        if (isset($input[$key]) && is_numeric($input[$key]) && (int) $input[$key] > 0) {
            return (int) $input[$key];
        }
    }

    return 0;
}

/**
 * Refuse an allowed ability's input when it would publish, or touch a live post. Null when fine.
 */
function wppilot_drafts_only_input_error(string $ability_name, mixed $input): ?WP_Error
{
    if (!wppilot_drafts_only_active() || !in_array($ability_name, wppilot_drafts_only_abilities(), strict: true)) {
        return null;
    }
    $input = is_array($input) ? $input : [];

    $status = isset($input['status']) && is_string($input['status']) ? $input['status'] : '';
    if ($status !== '' && !in_array($status, wppilot_drafts_only_statuses(), strict: true)) {
        return wppilot_drafts_only_error(
            sprintf(
                /* translators: %s: post status */
                __('A post can only be saved as a draft or pending review, not "%s".', domain: 'wppilot'),
                $status,
            ),
            $ability_name,
        );
    }

    if ($ability_name === 'wppilot/create-post') {
        $type = isset($input['post_type']) && is_string($input['post_type']) ? $input['post_type'] : 'page';
        if (wppilot_drafts_only_post_type_refused($type)) {
            return wppilot_drafts_only_error(
                sprintf(
                    /* translators: %s: post type */
                    __('Posts of type "%s" are site structure, not drafts.', domain: 'wppilot'),
                    $type,
                ),
                $ability_name,
            );
        }

        return null;
    }

    $post_id = wppilot_drafts_only_target_id($input);
    if ($post_id > 0) {
        $type = get_post_type($post_id);
        if (is_string($type) && wppilot_drafts_only_post_type_refused($type)) {
            return wppilot_drafts_only_error(
                sprintf(
                    /* translators: %s: post type */
                    __('Posts of type "%s" are site structure, not drafts.', domain: 'wppilot'),
                    $type,
                ),
                $ability_name,
            );
        }
        if (wppilot_drafts_only_post_is_live($post_id)) {
            return wppilot_drafts_only_error(
                sprintf(
                    /* translators: %d: post ID */
                    __('Post %d is already live, and only drafts can be changed.', domain: 'wppilot'),
                    $post_id,
                ),
                $ability_name,
            );
        }
    }

    return null;
}

/**
 * Backstop: whatever wrote it, a post saved during a Drafts-only request is not published.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function wppilot_drafts_only_filter_post_data(mixed $data): mixed
{
    if (!is_array($data) || !wppilot_drafts_only_active()) {
        return $data;
    }
    $status = is_string($data['post_status'] ?? null) ? $data['post_status'] : 'draft';
    // Revisions and attachments carry `inherit`; trash is not publishing.
    if (in_array($status, array_merge(wppilot_drafts_only_statuses(), ['inherit', 'trash']), strict: true)) {
        return $data;
    }
    $data['post_status'] = 'draft';

    return $data;
}

/**
 * Backstop: refuse an update to a live post during a Drafts-only request.
 *
 * WordPress reports this as "content, title, and excerpt are empty", which is misleading, but it
 * is the one filter that can stop wp_insert_post() before it writes. The input checks above give
 * the clear error for every ability that is meant to reach here.
 *
 * @param array<string, mixed> $postarr
 */
function wppilot_drafts_only_filter_empty_content(mixed $maybe_empty, mixed $postarr): mixed
{
    if ($maybe_empty === true || !is_array($postarr) || !wppilot_drafts_only_active()) {
        return $maybe_empty;
    }
    $post_id = isset($postarr['ID']) && is_numeric($postarr['ID']) ? (int) $postarr['ID'] : 0;
    $type = is_string($postarr['post_type'] ?? null) ? $postarr['post_type'] : '';
    if ($type === 'revision') {
        return $maybe_empty;
    }

    return wppilot_drafts_only_post_is_live($post_id) ? true : $maybe_empty;
}

function wppilot_drafts_only_register_hooks(): void
{
    add_filter('wp_insert_post_data', callback: 'wppilot_drafts_only_filter_post_data', priority: 999, accepted_args: 1);
    add_filter('wp_insert_post_empty_content', callback: 'wppilot_drafts_only_filter_empty_content', priority: 999, accepted_args: 2);
}
