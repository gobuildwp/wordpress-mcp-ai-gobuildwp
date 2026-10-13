<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockTheme;

use WP_Error;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\PostPartial;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The site editor's own objects, through WordPress's own REST controllers.
 *
 * Every read and write here is an in-process REST request: core validates the input, checks the
 * current user's capabilities, filters HTML for users without unfiltered_html, sanitises
 * theme.json through WP_Theme_JSON, and creates the database copy of a template the first time
 * a theme-file template is edited. Nothing here writes a post directly.
 */

/** Largest block markup one write accepts. A full page template is rarely a tenth of this. */
const MAX_CONTENT_BYTES = 512_000;

/** Undo of a write that created a post (first edit of a theme template, a new pattern): delete it. */
const STRATEGY_CREATED = 'block-theme/delete-created-post';

/** Undo of a revert to the theme file: save the customised version back. */
const STRATEGY_REVERTED = 'block-theme/recreate-template';

const TEMPLATE_TYPES = ['wp_template' => 'templates', 'wp_template_part' => 'template-parts'];

/**
 * One in-process REST request; the response data, or the error the controller returned.
 *
 * @param array<string, mixed> $params
 * @return array<mixed>|WP_Error
 */
function rest(string $method, string $route, array $params = []): array|WP_Error
{
    $request = new \WP_REST_Request($method, $route);
    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }
    $response = rest_do_request($request);
    if ($response->is_error()) {
        return $response->as_error();
    }
    $data = rest_get_server()->response_to_data($response, false);

    return is_array($data) ? $data : [];
}

/** Template routes take the full "theme//slug" id, unencoded. */
function template_route(string $type, string $id = ''): string
{
    return '/wp/v2/' . TEMPLATE_TYPES[$type] . ($id === '' ? '' : '/' . $id);
}

function template_type(mixed $value): string
{
    return $value === 'wp_template_part' ? 'wp_template_part' : 'wp_template';
}

/**
 * Block markup a write may store: a string, within the size cap, that parses into at least one
 * block. Anything else is refused before it reaches the site.
 */
function valid_markup(mixed $content): string|WP_Error
{
    if (!is_string($content) || trim($content) === '') {
        return new WP_Error('kit_block_theme_content', 'content must be block markup, for example <!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->.', ['status' => 400]);
    }
    if (strlen($content) > MAX_CONTENT_BYTES) {
        return new WP_Error('kit_block_theme_content_size', sprintf('content is larger than %d KB.', MAX_CONTENT_BYTES / 1000), ['status' => 400]);
    }
    $blocks = array_filter(parse_blocks($content), static fn(array $b): bool => ($b['blockName'] ?? null) !== null);
    if ($blocks === []) {
        return new WP_Error('kit_block_theme_content_blocks', 'content holds no blocks. Wrap it in block comments, the way the block editor saves it.', ['status' => 400]);
    }

    return $content;
}

/** The text of a rendered/raw REST field. */
function raw(mixed $field): string
{
    if (is_array($field)) {
        return (string) ($field['raw'] ?? $field['rendered'] ?? '');
    }

    return is_string($field) ? $field : '';
}

/**
 * Deep merge for theme.json objects: keys in $patch replace or extend $base, a null in $patch
 * removes the key, and lists (palettes, font families) are replaced whole, not merged by index.
 *
 * @param array<mixed> $base
 * @param array<mixed> $patch
 * @return array<mixed>
 */
function merge(array $base, array $patch): array
{
    foreach ($patch as $key => $value) {
        if ($value === null) {
            unset($base[$key]);
        } elseif (is_array($value) && !array_is_list_compat($value) && is_array($base[$key] ?? null) && !array_is_list_compat($base[$key])) {
            $base[$key] = merge($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }

    return $base;
}

/** array_is_list() is PHP 8.1; the plugin supports 8.0. */
function array_is_list_compat(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

/** The global styles post of the active theme, creating it the way the site editor does. */
function global_styles_id(): int
{
    return class_exists('WP_Theme_JSON_Resolver') ? (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id() : 0;
}

/**
 * Template summary for lists: what an agent needs to pick one, not the markup.
 *
 * @param array<mixed> $t
 * @return array<string, mixed>
 */
function template_summary(array $t): array
{
    return array_filter([
        'id' => (string) ($t['id'] ?? ''),
        'slug' => (string) ($t['slug'] ?? ''),
        'title' => raw($t['title'] ?? ''),
        'description' => (string) ($t['description'] ?? ''),
        'source' => (string) ($t['source'] ?? ''),
        'customized' => ($t['source'] ?? '') === 'custom',
        'has_theme_file' => (bool) ($t['has_theme_file'] ?? false),
        'area' => isset($t['area']) ? (string) $t['area'] : null,
        'wp_id' => isset($t['wp_id']) ? (int) $t['wp_id'] : null,
    ], static fn(mixed $v): bool => $v !== null && $v !== '');
}

/**
 * The template or part an id names, as the REST controller describes it, or null.
 *
 * @return array<mixed>|null
 */
function find_template(string $type, string $id): ?array
{
    if (preg_match('#^[A-Za-z0-9_.-]+//[A-Za-z0-9_./-]+$#', $id) !== 1) {
        return null;
    }
    $found = rest('GET', template_route($type, $id), ['context' => 'edit']);

    return $found instanceof WP_Error ? null : $found;
}

/**
 * Before-image for a write to an existing post: just its content, title and status.
 *
 * @return array<string, mixed>|null
 */
function capture_post(int $post_id): ?array
{
    if ($post_id <= 0) {
        return null;
    }
    $snapshot = PostPartial\capture($post_id, ['post_content', 'post_title', 'post_status']);
    if ($snapshot === null) {
        return null;
    }
    $snapshot['type'] = PostPartial\TYPE;

    return $snapshot;
}

/**
 * Undo a write that created a post: delete exactly that post, if it is still the one created.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function undo_created(array $payload): array|WP_Error
{
    $id = (int) ($payload['post_id'] ?? 0);
    $type = (string) ($payload['post_type'] ?? '');
    $post = $id > 0 ? get_post($id) : null;
    if (!$post instanceof \WP_Post) {
        return ['post_id' => $id, 'already_deleted' => true, 'verified' => true];
    }
    if ($post->post_type !== $type || !in_array($type, ['wp_template', 'wp_template_part', 'wp_block', 'wp_navigation'], strict: true)) {
        return new WP_Error('kit_block_theme_undo_changed', 'The post this change created is no longer the same kind of post, so it was left alone.');
    }
    wp_delete_post($id, true);
    $gone = !get_post($id) instanceof \WP_Post;

    return ['post_id' => $id, 'deleted' => $gone, 'verified' => $gone];
}

/**
 * Undo a revert: put the customised template back through the controller.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function undo_reverted(array $payload): array|WP_Error
{
    // The host keeps a capture under `snapshot` when no build step reshapes it, as for post-partial.
    $payload = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $type = template_type($payload['template_type'] ?? '');
    $id = (string) ($payload['template_id'] ?? '');
    $content = (string) ($payload['content'] ?? '');
    if ($id === '' || $content === '') {
        return new WP_Error('kit_block_theme_undo_payload', 'The change record does not hold the customised template.');
    }
    $saved = rest('POST', template_route($type, $id), ['content' => $content, 'title' => (string) ($payload['title'] ?? '')]);
    if ($saved instanceof WP_Error) {
        return $saved;
    }

    return ['template_id' => $id, 'restored' => true, 'verified' => raw($saved['content'] ?? '') === $content];
}

function register_ledger(Ledger $ledger): void
{
    $ledger->register_strategy(
        STRATEGY_CREATED,
        static fn(array $payload): array|WP_Error => undo_created($payload),
        static function (array $before, mixed $result): array {
            $id = is_array($result) ? (int) ($result['created_post_id'] ?? 0) : 0;
            if ($id <= 0) {
                return ['reversible' => false, 'reason' => 'The write did not create a post, so there is nothing to remove.'];
            }

            return ['post_id' => $id, 'post_type' => (string) ($result['post_type'] ?? '')];
        },
    );
    $ledger->register_strategy(STRATEGY_REVERTED, static fn(array $payload): array|WP_Error => undo_reverted($payload));

    // Global styles: the user-layer post always exists by the time a write reaches it.
    $ledger->capture_for('wppilot/update-global-styles', static fn(array $input): ?array => capture_post(global_styles_id()));

    // A template edit either changes the database copy or creates it from the theme file.
    $ledger->capture_for('wppilot/update-template', static function (array $input): ?array {
        $type = template_type($input['type'] ?? '');
        $found = find_template($type, (string) ($input['id'] ?? ''));
        $wp_id = (int) ($found['wp_id'] ?? 0);
        if ($found !== null && ($found['source'] ?? '') === 'custom' && $wp_id > 0) {
            return capture_post($wp_id);
        }

        return ['type' => STRATEGY_CREATED];
    });
    $ledger->capture_for('wppilot/revert-template', static function (array $input): ?array {
        $type = template_type($input['type'] ?? '');
        $id = (string) ($input['id'] ?? '');
        $found = find_template($type, $id);
        if ($found === null || ($found['source'] ?? '') !== 'custom') {
            return null;
        }

        return ['type' => STRATEGY_REVERTED, 'template_type' => $type, 'template_id' => $id, 'content' => raw($found['content'] ?? ''), 'title' => raw($found['title'] ?? '')];
    });
    $ledger->capture_for('wppilot/create-pattern', static fn(array $input): array => ['type' => STRATEGY_CREATED]);
    $ledger->capture_for('wppilot/update-pattern', static fn(array $input): ?array => capture_post((int) ($input['id'] ?? 0)));
    $ledger->capture_for('wppilot/update-navigation-menu', static fn(array $input): ?array => capture_post((int) ($input['id'] ?? 0)));
}
