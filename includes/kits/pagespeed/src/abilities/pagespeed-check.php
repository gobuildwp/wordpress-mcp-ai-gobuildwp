<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Pagespeed;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

if (Runtime\unclaimed('wppilot/pagespeed-check')) {
    $pagespeed_score = ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 100];
    $pagespeed_number = ['type' => ['number', 'null']];

    wp_register_ability('wppilot/pagespeed-check', [
        'label' => __('PageSpeed Check', domain: 'wppilot'),
        'description' => __(
            'Runs Google PageSpeed Insights (Lighthouse) on a page of this site and returns its scores (performance, seo, accessibility, best_practices, 0-100), lab metrics (fcp_ms, lcp_ms, tbt_ms, cls, si_ms, ttfb_ms), Core Web Vitals field data when Google has it, the opportunities ordered by the time they would save, and failing diagnostics. Needs no Google key from anyone: it asks Site Kit by Google when its PageSpeed module is connected and readable by this user, otherwise the plugin cloud PageSpeed service, then a PageSpeed API key the site owner saved in the plugin settings, then Google\'s keyless API. `source` says which answered and `attempts` why earlier ones did not. One run takes 10-60 seconds; results are reused for 15 minutes unless refresh=true. Lab numbers are one simulated load, not what visitors experienced; field_data is what visitors experienced, when there is any.',
            domain: 'wppilot',
        ),
        'category' => 'performance',
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'A page on this site: an absolute URL or a path starting with /. Defaults to the home page.'],
                'strategy' => ['type' => 'string', 'enum' => ['mobile', 'desktop', 'both'], 'default' => 'mobile'],
                'refresh' => ['type' => 'boolean', 'default' => false, 'description' => 'Skip the 15-minute reuse (and the cloud service\'s hour-long cache) and run a new test. Use after a change, not to re-read.'],
                'source' => [
                    'type' => 'string',
                    'enum' => ['auto', 'site-kit', 'cloud', 'google-api-key', 'google-keyless'],
                    'default' => 'auto',
                    'description' => 'Ask one source only. Leave on auto.',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string'],
                'strategy' => ['type' => 'string'],
                'fetched_at' => ['type' => 'string'],
                'cached' => ['type' => 'boolean'],
                'scores' => [
                    'type' => 'object',
                    'properties' => [
                        'performance' => $pagespeed_score,
                        'seo' => $pagespeed_score,
                        'accessibility' => $pagespeed_score,
                        'best_practices' => $pagespeed_score,
                    ],
                ],
                'metrics' => [
                    'type' => 'object',
                    'properties' => [
                        'fcp_ms' => $pagespeed_number,
                        'lcp_ms' => $pagespeed_number,
                        'tbt_ms' => $pagespeed_number,
                        'cls' => $pagespeed_number,
                        'si_ms' => $pagespeed_number,
                        'ttfb_ms' => $pagespeed_number,
                    ],
                ],
                'field_data' => ['type' => ['object', 'null']],
                'opportunities' => ['type' => 'array', 'items' => ['type' => 'object']],
                'diagnostics' => ['type' => 'array', 'items' => ['type' => 'object']],
                'lighthouse_version' => ['type' => 'string'],
                'source' => ['type' => 'string', 'enum' => SOURCES],
                'attempts' => ['type' => 'array', 'items' => ['type' => 'object']],
                'note' => ['type' => 'string'],
                'fix' => ['type' => 'object'],
                'results' => ['type' => 'object', 'description' => 'strategy=both only: mobile and desktop, each the shape above or {error}.'],
            ],
        ],
        'execute_callback' => static fn(array $input = []): array|\WP_Error => check($input),
        'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Report scores with their strategy and source, and say lab numbers are one simulated load. Work opportunities from the top: they are ordered by time saved. Opportunity ids are Lighthouse audit ids (render-blocking-insight, unused-css-rules, image-delivery-insight…). A kit_pagespeed_page_too_slow error is about the page, not the tool: measure the server response before retrying. When the result carries `fix`, offer it to the user once.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}
