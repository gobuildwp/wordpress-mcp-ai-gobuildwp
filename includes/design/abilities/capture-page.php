<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\CapturePage;

use WP_Error;
use WP_Post;
use WPPilot\Design\Capture;
use WPPilot\Design\VisualRuntime;

/**
 * Ability: ask the site to photograph a page for you.
 *
 * `wppilot/get-page-view-link` is the better route when the caller has a
 * browser - it is immediate, and the agent sees the page itself. This is for
 * the caller that has none: an access-token client, a cron job, anything
 * driving the site over MCP with no window of its own. The site's own logged-in
 * tab takes the picture instead.
 *
 * The cost is that somebody has to have that tab open, and this says so plainly
 * rather than queueing into silence: the result carries whether a runtime is
 * online, and if it is not, the URL to open and the sentence to send.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Register on `wp_abilities_api_init`, like every other design ability.
 *
 * Registering at file scope would run before the Abilities API exists,
 * so the abilities would simply never appear - with no error anywhere.
 */
function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/capture-page', [
        'label' => __('Capture a Page', domain: 'wppilot'),
        'description' => __(
            'Asks the site to screenshot a page at one or more viewport widths and store each one as a capture you can compare later with wppilot/compare-captures. The screenshot is taken by a logged-in wp-admin tab - the Visual Runtime page - so it only proceeds while somebody has that tab open; the result says whether one is online and gives the URL to open if not. If YOUR client has a browser tool, prefer wppilot/get-page-view-link: it is immediate and needs nobody. The capture is a re-render rather than a browser screenshot, so a script that paints after load, a cross-origin image and an external webfont are not captured; each capture records which of those it hit.',
            domain: 'wppilot',
        ),
        'category' => 'preview',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'post_id' => ['type' => 'integer', 'minimum' => 1],
                'url' => ['type' => 'string', 'description' => 'A URL on this site to capture instead of a post.'],
                'viewports' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 320, 'maximum' => 3840],
                    'description' => 'Widths to capture. Defaults to 1440, 1024 and 390.',
                ],
                'label' => ['type' => 'string', 'description' => 'What this capture is, such as "before the hero rebuild".'],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $post_id = (int) ($input['post_id'] ?? 0);
            $url = trim((string) ($input['url'] ?? ''));

            if ($post_id > 0 && $url !== '') {
                return new WP_Error(
                    'wppilot_capture_ambiguous',
                    __('Pass post_id or url, not both.', domain: 'wppilot'),
                );
            }

            if ($post_id > 0) {
                $post = get_post($post_id);
                if (!$post instanceof WP_Post) {
                    return new WP_Error('wppilot_capture_no_post', __('No such post.', domain: 'wppilot'), ['status' => 404]);
                }
                if (!current_user_can('read_post', $post_id)) {
                    return new WP_Error(
                        'wppilot_capture_forbidden',
                        __('You cannot read that post.', domain: 'wppilot'),
                        ['status' => 403],
                    );
                }
                // A draft has no public permalink; the runtime tab is signed in, so
                // it can open the preview the same way its user would.
                $url = $post->post_status === 'publish'
                    ? (string) get_permalink($post)
                    : (string) get_preview_post_link($post);
            }

            if ($url === '') {
                return new WP_Error('wppilot_capture_no_target', __('Pass either post_id or url.', domain: 'wppilot'));
            }

            if (!\wppilot_url_is_same_site($url)) {
                return new WP_Error(
                    'wppilot_capture_off_site',
                    __('That URL is not on this site. The runtime only captures pages here.', domain: 'wppilot'),
                );
            }

            /** @var list<int> $viewports */
            $viewports = is_array($input['viewports'] ?? null) && $input['viewports'] !== []
                ? array_map('intval', $input['viewports'])
                : array_column(Capture\CAPTURE_VIEWPORTS, 'width');

            $job = VisualRuntime\enqueue_job($post_id, $url, $viewports, (string) ($input['label'] ?? ''));
            $runtime = VisualRuntime\runtime_status();

            return [
                'job_id' => $job['id'],
                'url' => $url,
                'viewports' => $job['viewports'],
                'runtime' => $runtime,
                'user_instruction' => $runtime['online']
                    ? __(
                        'The capture is queued and a runtime tab is open. Poll wppilot/list-captures for this post; the captures appear as they are taken, one per viewport.',
                        domain: 'wppilot',
                    )
                    : __(
                        'The capture is queued and nothing will happen until a runtime tab is open. Ask the person you are working with to open the Visual Runtime page in wp-admin - the URL is in runtime.page_url - and leave it open. If you have a browser tool of your own, wppilot/get-page-view-link is the better route and needs nobody.',
                        domain: 'wppilot',
                    ),
            ];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);

    wp_register_ability('wppilot/get-capture-job', [
        'label' => __('Get a Capture Job', domain: 'wppilot'),
        'description' => __(
            'Reports whether a queued capture has been taken yet, which viewports are done, and anything the capture could not include. Poll this after wppilot/capture-page.',
            domain: 'wppilot',
        ),
        'category' => 'preview',
        'input_schema' => [
            'type' => 'object',
            'properties' => ['job_id' => ['type' => 'string']],
            'required' => ['job_id'],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $job = VisualRuntime\job((string) ($input['job_id'] ?? ''));
            if ($job === null) {
                return new WP_Error(
                    'wppilot_capture_no_job',
                    __('No such capture job. Jobs are pruned once there are thirty newer ones.', domain: 'wppilot'),
                    ['status' => 404],
                );
            }

            return ['job' => $job, 'runtime' => VisualRuntime\runtime_status()];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
