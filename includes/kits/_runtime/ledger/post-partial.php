<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Runtime\PostPartial;

use WP_Error;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Before-images of just the parts of a post a write touches.
 *
 * GoBuildWP's `restore-post` puts back a whole post: every field, every meta key (deleting any the
 * snapshot does not name) and every term. That is right for "update this post", and wrong for a
 * write that touched one meta key: undoing an alt-text change a week later would also revert
 * every edit a person made to that attachment since. A partial snapshot names the fields and
 * meta keys it covers, restores those and nothing else, and verifies only those.
 *
 * It is also small. A search-replace over a hundred builder pages keeps one field per post, not
 * a hundred full documents, so the batch fits the ledger's snapshot budget.
 */
const TYPE = 'kits/post-partial';

/** Post columns a partial snapshot may cover; the rest are derived or not the kit's to restore. */
const FIELDS = ['post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_date', 'post_parent', 'menu_order'];

/**
 * @param list<string> $fields    Post columns, from FIELDS.
 * @param list<string> $meta_keys Meta keys; a key absent now is recorded as absent and deleted on undo.
 * @return array<string, mixed>|null
 */
function capture(int $post_id, array $fields, array $meta_keys = []): ?array
{
    $post = $post_id > 0 ? get_post($post_id) : null;
    if (!$post instanceof \WP_Post) {
        return null;
    }
    $snapshot = [
        'type' => TYPE,
        'post_id' => $post_id,
        'fields' => [],
        'meta' => [],
    ];
    foreach (array_intersect($fields, FIELDS) as $field) {
        $snapshot['fields'][$field] = (string) $post->{$field};
    }
    if (in_array('post_date', $fields, strict: true)) {
        // Kept so the undo can tell a floating draft date from a deliberate one.
        $snapshot['fields']['post_date_gmt'] = (string) $post->post_date_gmt;
    }
    foreach (array_values(array_unique($meta_keys)) as $key) {
        $snapshot['meta'][$key] = metadata_exists('post', $post_id, $key)
            ? array_values((array) get_post_meta($post_id, $key, single: false))
            : null;
    }
    $snapshot['fingerprint'] = fingerprint($snapshot);
    return $snapshot;
}

/**
 * @param array<string, mixed> $snapshot
 */
function fingerprint(array $snapshot): string
{
    return hash('sha256', (string) wp_json_encode([
        'post_id' => (int) ($snapshot['post_id'] ?? 0),
        'fields' => $snapshot['fields'] ?? [],
        'meta' => $snapshot['meta'] ?? [],
    ]));
}

/**
 * Put the covered fields and meta back, then re-read them and compare.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $post_id = (int) ($snapshot['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id) instanceof \WP_Post) {
        return new WP_Error('kit_rollback_target_missing', 'The post this change touched no longer exists.');
    }

    $fields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
    $postarr = ['ID' => $post_id];
    foreach ($fields as $field => $value) {
        if (!in_array($field, FIELDS, strict: true)) {
            continue;
        }
        // wp_update_post() unslashes what it is given, as if from a form; the snapshot is raw.
        $postarr[$field] = in_array($field, ['post_title', 'post_content', 'post_excerpt', 'post_name'], strict: true)
            ? wp_slash((string) $value)
            : $value;
    }
    if (array_key_exists('post_date', $postarr)) {
        // A floating draft date is moved to "now" by wp_update_post() unless edit_date is set.
        $postarr['edit_date'] = true;
        $postarr['post_date_gmt'] = (string) ($fields['post_date_gmt'] ?? '');
    }
    if (count($postarr) > 1) {
        // kit-lint: slashed — the text fields were wp_slash()ed as $postarr was built.
        $updated = wp_update_post($postarr, wp_error: true);
        if ($updated instanceof WP_Error) {
            return $updated;
        }
    }

    $meta = is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [];
    foreach ($meta as $key => $values) {
        delete_post_meta($post_id, (string) $key);
        foreach (is_array($values) ? $values : [] as $value) {
            // add_post_meta() unslashes too; builder JSON loses every backslash without this.
            add_post_meta($post_id, (string) $key, wp_slash($value));
        }
    }

    $observed = capture(
        $post_id,
        array_values(array_diff(array_keys($fields), ['post_date_gmt'])),
        array_map('strval', array_keys($meta)),
    );
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = is_array($observed) ? (string) $observed['fingerprint'] : '';

    return [
        'post_id' => $post_id,
        'expected_fingerprint' => $expected,
        'observed_fingerprint' => $actual,
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

function register(Ledger $ledger): void
{
    $ledger->register_strategy(TYPE, static fn(array $payload): array|WP_Error => restore($payload));
}
