<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Write receipts: every wppilot/ write answers with what it changed, the ledger row that records
 * it and how to undo it, and post writes can ask for a logged-out look at the page afterwards.
 *
 * WordPress 6.9 and 7.0 give no filter on an ability's result, only the before/after actions,
 * so the receipt is added by wrapping the execute callback when the ability registers
 * (`wp_register_ability_args`). The wrapper runs between the ledger's before and after hooks:
 * the before hook has already opened the pending record, with the change id the row will carry,
 * so the receipt can name a row that is written a moment later, once output validation passes.
 * The rollback payload is worked out once here and handed to the after hook, so the receipt and
 * the row cannot disagree about whether the write can be undone.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_RECEIPT_RENDER_KEY = 'verify_render';

add_filter('wp_register_ability_args', 'wppilot_write_envelope_args', 20, 2);

/**
 * Whether a registration is a write the envelope wraps: a wppilot/ ability that is not read-only
 * and not one of the ledger's own meta abilities (rollback, undo-session and the like).
 *
 * @param array<string, mixed> $args
 */
function wppilot_write_envelope_applies(string $name, array $args): bool
{
    if (!str_starts_with($name, 'wppilot/') || !is_callable($args['execute_callback'] ?? null)) {
        return false;
    }
    if (function_exists('wppilot_change_ability_is_meta') && wppilot_change_ability_is_meta($name)) {
        return false;
    }
    $meta = is_array($args['meta'] ?? null) ? $args['meta'] : [];
    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];

    return ($annotations['readonly'] ?? false) !== true;
}

/**
 * Post and page writes that can take `verify_render`.
 *
 * @return list<string>
 */
function wppilot_render_check_abilities(): array
{
    /**
     * Abilities whose result names a post the render check can fetch (`post_id` in the result).
     *
     * @param list<string> $abilities
     */
    $abilities = apply_filters('wppilot_render_check_abilities', ['wppilot/create-post', 'wppilot/update-post']);

    return is_array($abilities) ? array_values(array_filter($abilities, 'is_string')) : [];
}

/**
 * The control keys the envelope takes off the input before the ability sees it.
 *
 * @return list<string>
 */
function wppilot_write_envelope_control_keys(): array
{
    $keys = [WPPILOT_RECEIPT_RENDER_KEY];
    if (defined('WPPILOT_IDEMPOTENCY_KEY')) {
        $keys[] = WPPILOT_IDEMPOTENCY_KEY;
    }

    return $keys;
}

/**
 * Wrap a write's execute callback and declare the control keys it accepts, so a client reading the
 * schema sees them and input validation does not refuse them.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function wppilot_write_envelope_args(mixed $args, mixed $name): mixed
{
    if (!is_array($args) || !is_string($name) || !wppilot_write_envelope_applies($name, $args)) {
        return $args;
    }

    $schema = is_array($args['input_schema'] ?? null) ? $args['input_schema'] : [];
    if ($schema !== [] && ($schema['type'] ?? 'object') === 'object') {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        if (in_array($name, wppilot_render_check_abilities(), strict: true)) {
            $properties[WPPILOT_RECEIPT_RENDER_KEY] ??= [
                'type' => 'boolean',
                'description' => 'After the write, fetch the post\'s public URL logged out and report the HTTP status and whether a critical error showed. Published posts only.',
            ];
        }
        if (function_exists('wppilot_idempotency_schema')) {
            $properties[WPPILOT_IDEMPOTENCY_KEY] ??= wppilot_idempotency_schema();
        }
        $schema['properties'] = $properties;
        $args['input_schema'] = $schema;
    }

    // A result schema that forbids extra keys would fail output validation with a receipt in it.
    $output = is_array($args['output_schema'] ?? null) ? $args['output_schema'] : [];
    $receipt = ($output['additionalProperties'] ?? true) !== false;
    $original = $args['execute_callback'];
    $args['execute_callback'] = static fn(mixed ...$call): mixed => wppilot_write_envelope_execute($name, $original, $call, $receipt);

    return $args;
}

/**
 * Run a wrapped write: strip the control keys, run the ability, attach the receipt.
 *
 * @param list<mixed> $call The arguments WordPress passed (the input, or nothing).
 */
function wppilot_write_envelope_execute(string $name, callable $original, array $call, bool $receipt = true): mixed
{
    $controls = [];
    if (isset($call[0]) && is_array($call[0])) {
        foreach (wppilot_write_envelope_control_keys() as $key) {
            if (array_key_exists($key, $call[0])) {
                $controls[$key] = $call[0][$key];
                unset($call[0][$key]);
            }
        }
    }
    $input = isset($call[0]) && is_array($call[0]) ? $call[0] : [];

    if (function_exists('wppilot_idempotency_begin')) {
        $claim = wppilot_idempotency_begin($name, $controls, $input);
        if ($claim instanceof WP_Error || (is_array($claim) && ($claim['replayed'] ?? false) === true)) {
            return $claim;
        }
    }

    try {
        $result = $original(...$call);
    } catch (Throwable $error) {
        if (function_exists('wppilot_idempotency_abandon')) {
            wppilot_idempotency_abandon();
        }
        throw $error;
    }

    if ($receipt && is_array($result) && !array_is_list($result)) {
        $result['receipt'] = wppilot_write_receipt($name, $input, $result, ($controls[WPPILOT_RECEIPT_RENDER_KEY] ?? false) === true);
    }

    if (function_exists('wppilot_idempotency_finish')) {
        wppilot_idempotency_finish($result);
    }

    return $result;
}

/**
 * What a write changed, the ledger row recording it and how to take it back.
 *
 * @param array<string, mixed> $input  The input without control keys.
 * @param array<string, mixed> $result The ability's own result.
 * @return array<string, mixed>
 */
function wppilot_write_receipt(string $name, array $input, array $result, bool $verify_render): array
{
    $pending = function_exists('wppilot_change_pending') ? wppilot_change_pending($name) : null;
    $change_id = is_array($pending) ? (string) ($pending['change_id'] ?? '') : '';

    $receipt = [
        'ability' => $name,
        'change_id' => $change_id !== '' ? $change_id : null,
        'changed' => wppilot_receipt_changed($input, $result),
    ];

    // A bulk write (db-cleanup, db-write) records one ledger row per item itself, which clears the
    // pending row; its result names those rows instead.
    $item_ids = is_array($result['change_ids'] ?? null) ? array_values(array_filter($result['change_ids'], 'is_string')) : [];
    // A write that records its own single row (widgets, cron, routines, search-replace) names it
    // as change_id.
    $own_id = is_string($result['change_id'] ?? null) ? $result['change_id'] : '';
    // A refused or failed call, or a dry run, wrote nothing: never point at an undo for it.
    $failed = ($result['success'] ?? null) === false;
    $dry_run = ($result['dry_run'] ?? false) === true || ($input['dry_run'] ?? false) === true;
    if ($failed || $dry_run) {
        $receipt['change_id'] = null;
        $receipt['reversible'] = false;
        $receipt['undo_reason'] = $failed
            ? 'This call did not succeed, so there is no change to undo.'
            : 'Dry run: nothing was changed, so there is nothing to undo.';
    } elseif ($change_id === '' && $item_ids === [] && $own_id !== '') {
        $receipt['change_id'] = $own_id;
        $row = function_exists('wppilot_get_change') ? wppilot_get_change($own_id) : null;
        $rollback = is_array($row) && is_array($row['rollback'] ?? null) ? $row['rollback'] : null;
        if ($rollback === null) {
            // A background job may record its row later; the row decides then.
            $receipt['reversible'] = null;
            $receipt['undo'] = [
                'ability' => 'wppilot/rollback-change',
                'input' => ['change_id' => $own_id],
                'note' => 'Whether this can be undone is decided by the ledger row; needs the person\'s confirmation, like any destructive call.',
            ];
        } elseif (($rollback['reversible'] ?? false) === true) {
            $receipt['reversible'] = true;
            $receipt['undo'] = [
                'ability' => 'wppilot/rollback-change',
                'input' => ['change_id' => $own_id],
                'note' => 'Needs the person\'s confirmation, like any destructive call.',
            ];
        } else {
            $receipt['reversible'] = false;
            $receipt['undo_reason'] = is_string($rollback['reason'] ?? null) ? $rollback['reason'] : 'This write cannot be undone automatically.';
        }
    } elseif ($change_id === '' && $item_ids !== []) {
        unset($receipt['change_id']);
        $receipt['change_ids'] = $item_ids;
        // Per item: an item undo is refused when its row changed again since.
        $receipt['reversible'] = null;
        $receipt['undo'] = [
            'ability' => 'wppilot/rollback-change',
            'input' => ['change_id' => $item_ids[0]],
            'note' => 'One call per id in change_ids; each needs the person\'s confirmation, like any destructive call.',
        ];
    } elseif ($change_id === '') {
        $receipt['reversible'] = false;
        $receipt['undo_reason'] = 'This write was not recorded in the change ledger, so it cannot be undone from GoBuildWP.';
    } else {
        $before = is_array($pending['before'] ?? null) ? wppilot_string_keyed_array($pending['before']) : null;
        $rollback = wppilot_build_rollback_payload($name, $before, $result);
        // The after hook reuses this, so the row says what the receipt says.
        $pending['rollback'] = $rollback;
        wppilot_change_pending($name, $pending);

        $receipt['reversible'] = ($rollback['reversible'] ?? false) === true;
        if ($receipt['reversible']) {
            $receipt['undo'] = [
                'ability' => 'wppilot/rollback-change',
                'input' => ['change_id' => $change_id],
                'note' => 'Needs the person\'s confirmation, like any destructive call.',
            ];
        } else {
            $receipt['undo_reason'] = (string) ($rollback['reason'] ?? 'This write cannot be undone automatically.');
        }
    }

    if ($verify_render) {
        $post_id = (int) ($result['post_id'] ?? $input['post_id'] ?? 0);
        $receipt['render_check'] = in_array($name, wppilot_render_check_abilities(), strict: true)
            ? wppilot_write_render_check($post_id)
            : ['checked' => false, 'reason' => 'not_a_post_write'];
    }

    return $receipt;
}

/**
 * The object a write touched and the fields the call set.
 *
 * "Fields" are the input's own keys, not a diff: a value set to what it already was is listed.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $result
 * @return array{target: array<string, mixed>, fields: list<string>}
 */
function wppilot_receipt_changed(array $input, array $result): array
{
    $target = [];
    foreach (['post_id', 'attachment_id', 'term_id', 'comment_id', 'menu_id', 'user_id', 'order_id', 'id', 'path', 'file', 'slug'] as $key) {
        // @mago-expect analysis:mixed-assignment -- Scalar ids or paths only, checked below.
        $value = $result[$key] ?? $input[$key] ?? null;
        if (is_int($value) || (is_string($value) && $value !== '')) {
            $target[$key] = $value;
            break;
        }
    }

    $fields = array_values(array_filter(
        array_map('strval', array_keys($input)),
        static fn(string $key): bool => $key !== 'confirm' && !array_key_exists($key, $target),
    ));
    sort($fields);

    return ['target' => $target, 'fields' => $fields];
}

/**
 * Fetch a post's public URL logged out, after a write, and say whether it rendered.
 *
 * @return array<string, mixed>
 */
function wppilot_write_render_check(int $post_id): array
{
    $post = $post_id > 0 ? get_post($post_id) : null;
    if (!is_object($post)) {
        return ['checked' => false, 'reason' => 'no_post'];
    }
    $status = (string) ($post->post_status ?? '');
    if ($status !== 'publish') {
        return ['checked' => false, 'reason' => 'not_published', 'post_status' => $status];
    }
    if ((string) ($post->post_password ?? '') !== '') {
        return ['checked' => false, 'reason' => 'password_protected'];
    }
    if (function_exists('is_post_type_viewable') && !is_post_type_viewable((string) ($post->post_type ?? ''))) {
        return ['checked' => false, 'reason' => 'not_viewable'];
    }
    $url = get_permalink($post_id);
    if (!is_string($url) || $url === '') {
        return ['checked' => false, 'reason' => 'no_permalink'];
    }

    // Logged out and past the page cache, as the file-write probe does: visitors are who a broken
    // page hurts, and a cached copy would hide the break.
    $response = wp_remote_get(add_query_arg(['wppilot_render_check' => bin2hex(random_bytes(6))], $url), [
        'timeout' => 10,
        'redirection' => 3,
        'cookies' => [],
        'headers' => ['Cache-Control' => 'no-cache'],
        'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
    ]);

    return wppilot_write_render_verdict($url, $response);
}

/**
 * Read one render-check response.
 *
 * @param array<string, mixed>|WP_Error $response
 * @return array<string, mixed>
 */
function wppilot_write_render_verdict(string $url, array|WP_Error $response): array
{
    if ($response instanceof WP_Error) {
        return [
            'checked' => true,
            'reachable' => false,
            'url' => $url,
            'error' => $response->get_error_message(),
        ];
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $reason = wppilot_file_guard_failure_reason($status, (string) wp_remote_retrieve_body($response));

    return [
        'checked' => true,
        'reachable' => true,
        'url' => $url,
        'status' => $status,
        'ok' => $reason === '' && $status < 400,
        'critical_error' => $reason !== '' && $reason !== 'http_' . $status,
        'reason' => $reason,
    ];
}
