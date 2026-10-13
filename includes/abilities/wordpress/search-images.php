<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;

/**
 * Stock-image search over Openverse.
 *
 * Openverse is WordPress.org's own openly-licensed media search: no API key,
 * no account, CC-licensed and public-domain results. That combination is why
 * it is the search this plugin ships rather than a commercial provider — an
 * agent building a page can find an image without the site owner signing up
 * for anything, and every result carries a licence the site may actually use.
 *
 * This is a read against an external service and nothing more. Importing a
 * chosen result into the Media Library is `wppilot/import-media-url`'s job,
 * and the description below tells the agent to carry the attribution across
 * when it does. The two stay separate on purpose: search costs nothing and
 * needs no upload capability, and a failed import should not look like a
 * failed search.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_OPENVERSE_ENDPOINT = 'https://api.openverse.org/v1/images/';

/**
 * Anonymous Openverse callers get at most 20 results per page. Asking for more
 * is not an error Openverse reports — it silently clamps — so the schema caps
 * where the API actually caps rather than promising a page size it cannot get.
 */
const WPPILOT_OPENVERSE_MAX_PER_PAGE = 20;

register_core_ability('wppilot/search-images', [
    'label' => __('Search Stock Images', domain: 'wppilot'),
    'description' => __(
        'Searches Openverse — WordPress.org\'s openly-licensed media search — for CC-licensed and public-domain images. No API key and no account: results come from api.openverse.org and carry the licence, creator, and a ready-made attribution string per image. This ability only searches. To use an image, pass its `url` to wppilot/import-media-url, put the `attribution` string in the caption, and describe the image content in the alt text — attribution is a licence obligation for every CC licence except CC0 and the public domain mark, not a courtesy. Filter with `license` (e.g. by, by-sa, cc0, pdm), `license_type` ("commercial" restricts to licences allowing commercial use, "modification" to those allowing derivatives), `orientation`, `extension`, and `source`. Results are Openverse\'s relevance order; `foreign_landing_url` is the page a human can open to verify the image in context.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'license' => [
                'type' => 'string',
                'maxLength' => 60,
                'description' => 'Comma-separated licence codes: by, by-sa, by-nc, by-nc-sa, by-nd, by-nc-nd, cc0, pdm.',
            ],
            'license_type' => [
                'type' => 'string',
                'enum' => ['commercial', 'modification'],
                'description' => 'Restrict to licences allowing commercial use, or allowing derivatives.',
            ],
            'orientation' => [
                'type' => 'string',
                'enum' => ['tall', 'wide', 'square'],
            ],
            'extension' => [
                'type' => 'string',
                'enum' => ['jpg', 'png', 'gif', 'svg'],
            ],
            'source' => [
                'type' => 'string',
                'maxLength' => 60,
                'description' => 'Restrict to one Openverse source, e.g. flickr, wikimedia, rawpixel.',
            ],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => WPPILOT_OPENVERSE_MAX_PER_PAGE,
                'default' => 10,
            ],
        ],
        'required' => ['query'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_search_images',
    'permission_callback' => static fn(): bool => current_user_can('upload_files'),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_search_images(array $input): array|WP_Error
{
    $query = sanitize_text_field((string) ($input['query'] ?? ''));
    if ($query === '') {
        return new WP_Error('wppilot_search_query_required', __('query must not be empty.', domain: 'wppilot'));
    }
    $page = max(1, (int) ($input['page'] ?? 1));
    $per_page = max(1, min(WPPILOT_OPENVERSE_MAX_PER_PAGE, (int) ($input['per_page'] ?? 10)));

    $args = ['q' => $query, 'page' => $page, 'page_size' => $per_page];
    $license = strtolower(sanitize_text_field((string) ($input['license'] ?? '')));
    if ($license !== '') {
        $valid = ['by', 'by-sa', 'by-nc', 'by-nc-sa', 'by-nd', 'by-nc-nd', 'cc0', 'pdm'];
        $codes = array_values(array_intersect(array_map('trim', explode(',', $license)), $valid));
        if ($codes === []) {
            return new WP_Error('wppilot_search_invalid_license', sprintf(
                /* translators: %s: the accepted licence codes. */
                __('license must be one or more of: %s.', domain: 'wppilot'),
                implode(', ', $valid),
            ));
        }
        $args['license'] = implode(',', $codes);
    }
    foreach (['license_type', 'extension', 'source'] as $key) {
        $value = sanitize_text_field((string) ($input[$key] ?? ''));
        if ($value !== '') {
            $args[$key] = $value;
        }
    }
    $orientation = (string) ($input['orientation'] ?? '');
    if ($orientation !== '') {
        // Openverse spells this parameter aspect_ratio; the agent-facing name
        // matches what every other image tool calls it.
        $args['aspect_ratio'] = $orientation;
    }

    $response = wp_remote_get(add_query_arg($args, WPPILOT_OPENVERSE_ENDPOINT), [
        'timeout' => 15,
        'user-agent' => 'GoBuildWP/' . (defined('WPPILOT_VERSION') ? WPPILOT_VERSION : 'dev') . ' (https://gobuildwp.com)',
    ]);
    if (is_wp_error($response)) {
        return new WP_Error('wppilot_openverse_unreachable', sprintf(
            /* translators: %s: the transport error message. */
            __('Openverse could not be reached: %s', domain: 'wppilot'),
            $response->get_error_message(),
        ));
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status === 429) {
        return new WP_Error('wppilot_openverse_rate_limited', __(
            'Openverse rate-limited this site. Anonymous callers get a modest hourly budget; wait before retrying rather than retrying immediately.',
            domain: 'wppilot',
        ));
    }
    if ($status !== 200) {
        return new WP_Error('wppilot_openverse_error', sprintf(
            /* translators: %d: the HTTP status Openverse answered with. */
            __('Openverse answered HTTP %d.', domain: 'wppilot'),
            $status,
        ));
    }
    /** @var mixed $body */
    $body = json_decode((string) wp_remote_retrieve_body($response), associative: true);
    if (!is_array($body) || !is_array($body['results'] ?? null)) {
        return new WP_Error('wppilot_openverse_malformed', __(
            'Openverse answered 200 with a body that is not the documented result shape.',
            domain: 'wppilot',
        ));
    }

    $results = [];
    foreach ($body['results'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $results[] = [
            'id' => (string) ($item['id'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'thumbnail' => (string) ($item['thumbnail'] ?? ''),
            'width' => (int) ($item['width'] ?? 0),
            'height' => (int) ($item['height'] ?? 0),
            'license' => (string) ($item['license'] ?? ''),
            'license_version' => (string) ($item['license_version'] ?? ''),
            'license_url' => (string) ($item['license_url'] ?? ''),
            'attribution' => (string) ($item['attribution'] ?? ''),
            'creator' => (string) ($item['creator'] ?? ''),
            'creator_url' => (string) ($item['creator_url'] ?? ''),
            'source' => (string) ($item['source'] ?? ''),
            'foreign_landing_url' => (string) ($item['foreign_landing_url'] ?? ''),
        ];
    }

    return [
        'results' => $results,
        'result_count' => (int) ($body['result_count'] ?? count($results)),
        'page' => $page,
        'page_count' => (int) ($body['page_count'] ?? 0),
        'per_page' => $per_page,
        'provider' => 'openverse',
        'usage' => __(
            'Import a chosen result with wppilot/import-media-url, carrying attribution into the caption. Attribution is required by every CC licence except CC0 and PDM.',
            domain: 'wppilot',
        ),
    ];
}
