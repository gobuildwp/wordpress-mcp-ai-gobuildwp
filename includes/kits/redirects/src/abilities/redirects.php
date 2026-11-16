<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Redirects;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

const DRY_RUN_PROPERTY = [
    'type' => 'boolean',
    'default' => false,
    'description' => 'true to check everything and show what would change, without saving.',
];

wp_register_ability('wppilot/redirect-list', [
    'label' => __('List Redirects', domain: 'wppilot'),
    'description' => sprintf(
        /* translators: %d: most redirects the site keeps. */
        __(
            'Lists this site\'s own redirects, newest first: id, source path, match (exact, or prefix for a path and everything under it), target, type (301, 302 or 410 gone), origin (manual, or auto when made because a published URL changed), hit count and last hit (Unix time; counts are a floor). Also says whether automatic redirects are on, off, or made elsewhere (elsewhere: the site\'s redirect plugin gets them instead). Up to %d redirects are kept. Redirects stored by plugins such as Redirection, Rank Math or Yoast are not listed.',
            domain: 'wppilot',
        ),
        MAX_RULES,
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'origin' => ['type' => 'string', 'enum' => ['any', 'manual', 'auto'], 'default' => 'any'],
            'search' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Only redirects whose source or target contains this text.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_RULES, 'default' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array => list_rules($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('wppilot/redirect-create', [
    'label' => __('Create a Redirect', domain: 'wppilot'),
    'description' => sprintf(
        /* translators: %d: most redirects one request may pass through. */
        __(
            'Adds a redirect from a path on this site: 301 (moved permanently, the default), 302 (temporary) or 410 (gone, no target). match exact (default) redirects that path only; prefix redirects the path and everything under it, keeping the rest of the path (/old/a goes to /new/a). Matching ignores case, a trailing slash and the query string, and the visitor\'s query string is passed on. target is a path on this site or an http(s) URL; IP addresses are refused. Refused: the dashboard, login, REST API, XML-RPC and cron URLs, a source that already redirects, and anything that would make a loop or a chain of more than %d redirects. Recorded in the change log so it can be undone. Run wppilot/redirect-test afterwards to check it.',
            domain: 'wppilot',
        ),
        MAX_HOPS,
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'source' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MAX_PATH_LENGTH, 'description' => 'Path to redirect, for example /old-page/. A full URL on this site is accepted and reduced to its path.'],
            'target' => ['type' => 'string', 'maxLength' => MAX_TARGET_LENGTH, 'description' => 'Where to send visitors: /new-page/ or https://example.com/page. Leave out for a 410.'],
            'type' => ['type' => 'integer', 'enum' => TYPES, 'default' => 301],
            'match' => ['type' => 'string', 'default' => 'exact', 'description' => 'exact or prefix.'],
            'dry_run' => DRY_RUN_PROPERTY,
        ],
        'required' => ['source'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => create_rule($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

wp_register_ability('wppilot/redirect-delete', [
    'label' => __('Delete Redirects', domain: 'wppilot'),
    'description' => sprintf(
        /* translators: %d: most redirects one call may delete. */
        __(
            'Deletes redirects by id (from wppilot/redirect-list), at most %d per call. All ids must exist or nothing is deleted. Visitors to a deleted redirect\'s source get whatever WordPress serves there, often a 404. Each deletion is recorded in the change log and can be undone. Run with dry_run first.',
            domain: 'wppilot',
        ),
        MAX_DELETE,
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'ids' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^r[0-9a-f]{32}$'], 'minItems' => 1, 'maxItems' => MAX_DELETE, 'uniqueItems' => true],
            'dry_run' => DRY_RUN_PROPERTY,
        ],
        'required' => ['ids'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => delete_rules($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

wp_register_ability('wppilot/redirect-suggest', [
    'label' => __('Suggest Redirects for 404s', domain: 'wppilot'),
    'description' => __(
        'Goes through the most-hit paths in the 404 log (wppilot/not-found-log) that no redirect covers and suggests a published page for each: one that used to have that slug (reason old_slug) or has it now (reason same_slug). Paths with no likely page are listed as unmatched. Creates nothing: check each target, then call wppilot/redirect-create.',
        domain: 'wppilot',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array => suggest($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('wppilot/redirect-test', [
    'label' => __('Test a Redirect', domain: 'wppilot'),
    'description' => sprintf(
        /* translators: %d: most redirects one request may pass through. */
        __(
            'Shows where a path on this site ends up through the stored redirects: every hop (from, to, type, redirect id), the final path or URL, and whether it is gone (410), loops, passes through more than %d redirects or leaves the site. Simulated without a request, so other plugins\' redirects and WordPress\'s own canonical redirects are not included.',
            domain: 'wppilot',
        ),
        MAX_HOPS,
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MAX_TARGET_LENGTH, 'description' => 'A path such as /old-page/, or a full URL on this site.'],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => test_path($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
