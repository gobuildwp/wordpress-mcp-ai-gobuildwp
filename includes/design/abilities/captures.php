<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\Captures;

use WP_Error;
use WPPilot\Design\Capture;

/**
 * Abilities for looking at a page and for comparing two looks at it.
 *
 * The capture itself is taken by a browser - the agent's own, through
 * `wppilot/get-page-view-link`, or a logged-in wp-admin tab. These abilities
 * are what turns a screenshot into something a later call can compare against,
 * which is the part that makes "did my change break anything else" answerable
 * at all.
 *
 * Registering a capture takes an attachment rather than image bytes. An ability
 * response is JSON in a context window; a full-page screenshot base64-encoded
 * into one would cost more than the whole rest of the conversation. The upload
 * goes through the media library, and what travels is an id.
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

    wp_register_ability('wppilot/register-capture', [
        'label' => __('Register a Page Capture', domain: 'wppilot'),
        'description' => __(
            'Records an image already in the media library as a screenshot of a page, so later calls can compare against it. Upload the screenshot first - wppilot/create-upload-link gives a browser somewhere to PUT it, or wppilot/import-media-url pulls one from a URL - then call this with the attachment ID, the post it shows and the viewport width it was taken at. Take a capture BEFORE a risky change and another after, then call wppilot/compare-captures: that is how a visual regression gets caught rather than reported by a visitor.',
            domain: 'wppilot',
        ),
        'category' => 'preview',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'attachment_id' => ['type' => 'integer', 'minimum' => 1],
                'post_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The page the screenshot shows.'],
                'url' => ['type' => 'string', 'description' => 'The URL that was captured, when it is not a post.'],
                'viewport' => ['type' => 'integer', 'minimum' => 320, 'maximum' => 3840, 'default' => 1440],
                'label' => ['type' => 'string', 'description' => 'What this capture is, such as "before the hero rebuild".'],
            ],
            'required' => ['attachment_id'],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $attachment_id = (int) ($input['attachment_id'] ?? 0);
            if (get_post_type($attachment_id) !== 'attachment') {
                return new WP_Error(
                    'wppilot_capture_not_attachment',
                    __('That ID is not an attachment. Upload the screenshot to the media library first.', domain: 'wppilot'),
                    ['status' => 404],
                );
            }

            if (!current_user_can('edit_post', $attachment_id)) {
                return new WP_Error(
                    'wppilot_capture_forbidden',
                    __('You cannot edit that attachment.', domain: 'wppilot'),
                    ['status' => 403],
                );
            }

            $mime = (string) get_post_mime_type($attachment_id);
            if (!str_starts_with($mime, 'image/')) {
                return new WP_Error(
                    'wppilot_capture_not_image',
                    sprintf(
                        /* translators: %s: MIME type. */
                        __('That attachment is %s, not an image, so it cannot be a capture.', domain: 'wppilot'),
                        $mime,
                    ),
                    ['status' => 422],
                );
            }

            Capture\record($attachment_id, [
                'post_id' => (int) ($input['post_id'] ?? 0),
                'url' => (string) ($input['url'] ?? ''),
                'viewport' => (int) ($input['viewport'] ?? 1440),
                'label' => (string) ($input['label'] ?? ''),
            ]);

            return [
                'capture' => Capture\capture_summary($attachment_id),
                'kept_per_post' => Capture\CAPTURE_KEEP_PER_POST,
                'user_instruction' => __(
                    'Take the matching capture after your change at the same viewport width, then call wppilot/compare-captures with both IDs. Comparing captures taken at different widths reports the whole page as changed, which is true and useless.',
                    domain: 'wppilot',
                ),
            ];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);

    wp_register_ability('wppilot/list-captures', [
        'label' => __('List Page Captures', domain: 'wppilot'),
        'description' => __(
            'Lists the screenshots recorded for a page, newest first, with the viewport each was taken at and what it was labelled. Use it to find the "before" capture to compare a change against.',
            domain: 'wppilot',
        ),
        'category' => 'preview',
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'post_id' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array {
            $captures = Capture\listing((int) ($input['post_id'] ?? 0), (int) ($input['limit'] ?? 20));

            return [
                'captures' => $captures,
                'count' => count($captures),
                'viewports' => Capture\CAPTURE_VIEWPORTS,
            ];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);

    wp_register_ability('wppilot/attach-captures-to-change', [
        'label' => __('Attach Captures to a Change', domain: 'wppilot'),
        'description' => __(
            'Records a before and an after capture against an entry in the change ledger, with the difference score between them. That turns "this write changed something visually" from a thing somebody has to remember into a thing the ledger carries: wppilot/get-change then shows what the write did to the page, not only what it did to the data. Capture before the write, capture after it, then call this with the change ID from the write and the two attachment IDs.',
            domain: 'wppilot',
        ),
        'category' => 'changes',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'change_id' => ['type' => 'string', 'description' => 'The ledger entry ID, from the write\'s result or wppilot/list-changes.'],
                'before' => ['type' => 'integer', 'minimum' => 1],
                'after' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['change_id', 'before', 'after'],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $change_id = (string) ($input['change_id'] ?? '');

            if (!function_exists('wppilot_get_change') || !function_exists('wppilot_replace_change')) {
                return new WP_Error(
                    'wppilot_capture_no_ledger',
                    __('The change ledger is not available on this site.', domain: 'wppilot'),
                    ['status' => 501],
                );
            }

            $change = wppilot_get_change($change_id);
            if (!is_array($change)) {
                return new WP_Error(
                    'wppilot_capture_no_change',
                    sprintf(
                        /* translators: %s: change ID. */
                        __('No change with ID %s. The ledger keeps the most recent entries only.', domain: 'wppilot'),
                        $change_id,
                    ),
                    ['status' => 404],
                );
            }

            $comparison = Capture\compare((int) ($input['before'] ?? 0), (int) ($input['after'] ?? 0));
            if ($comparison instanceof WP_Error) {
                return $comparison;
            }

            // Attachment IDs and one number, never the images: the whole ledger is
            // capped at 4MB, and a screenshot in it would evict real history.
            $change['visual'] = [
                'before_attachment_id' => (int) ($input['before'] ?? 0),
                'after_attachment_id' => (int) ($input['after'] ?? 0),
                'diff_score' => $comparison['diff_score'],
                'changed_regions' => count($comparison['changed_regions']),
                'identical' => $comparison['identical'],
            ];

            if (!wppilot_replace_change($change_id, $change)) {
                return new WP_Error(
                    'wppilot_capture_not_recorded',
                    __('The ledger entry could not be updated.', domain: 'wppilot'),
                    ['status' => 500],
                );
            }

            return ['change_id' => $change_id, 'visual' => $change['visual']];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);

    wp_register_ability('wppilot/compare-captures', [
        'label' => __('Compare Two Page Captures', domain: 'wppilot'),
        'description' => __(
            'Compares two screenshots of a page and reports how much changed and where. Returns a score from 0 (identical) to 1, the regions that differ in the coordinates of the newer image, and whether the page got taller. Use it after a change to see whether anything moved that you did not mean to move - the regions are what to go and look at. Compare captures taken at the same viewport width; different widths report the whole page as changed.',
            domain: 'wppilot',
        ),
        'category' => 'preview',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'before' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Attachment ID of the earlier capture.'],
                'after' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Attachment ID of the later capture.'],
            ],
            'required' => ['before', 'after'],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $before = (int) ($input['before'] ?? 0);
            $after = (int) ($input['after'] ?? 0);

            foreach ([$before, $after] as $attachment_id) {
                if (!current_user_can('read_post', $attachment_id)) {
                    return new WP_Error(
                        'wppilot_capture_forbidden',
                        __('You cannot read one of those captures.', domain: 'wppilot'),
                        ['status' => 403],
                    );
                }
            }

            $result = Capture\compare($before, $after);
            if ($result instanceof WP_Error) {
                return $result;
            }

            $before_viewport = (int) ($result['before']['viewport'] ?? 0);
            $after_viewport = (int) ($result['after']['viewport'] ?? 0);
            if ($before_viewport > 0 && $after_viewport > 0 && $before_viewport !== $after_viewport) {
                $result['warning'] = sprintf(
                    /* translators: 1: first viewport width, 2: second viewport width. */
                    __(
                        'These captures were taken at different widths (%1$dpx and %2$dpx), so almost everything will read as changed. Compare captures taken at the same width.',
                        domain: 'wppilot',
                    ),
                    $before_viewport,
                    $after_viewport,
                );
            }

            return $result;
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
