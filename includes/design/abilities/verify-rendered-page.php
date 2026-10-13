<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\VerifyRendered;

use WP_Error;
use WP_Post;
use WPPilot\Design\Abilities;
use WPPilot\Design\Rendered;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/verify-rendered-page', [
        'label' => __('Verify Rendered Page', domain: 'wppilot'),
        'description' => __(
            'Fetches a page as a visitor gets it and reports what is actually there: the heading outline, images with no alt text or no source, containers that rendered empty, PHP errors or unrendered shortcodes reaching the visitor, the colours and fonts the served HTML carries, and the document weight. The empty-container and skipped-level checks read only the page\'s own content (`summary.scope` says how it was found), outline entries carry `source: content|theme`, and every finding carries `source` so theme, sidebar and footer output is not mistaken for the page. Call this after building or editing a page — every other check in GoBuildWP reads what you wrote, and this reads what the site served, which is a different thing once a theme, a plugin and a cache have had their turn. Pass `post_id` for a page on this site or `url` for any address the site can reach. An unpublished page works too: its preview is fetched with your own session, so a draft can be checked before anyone sees it. No JavaScript is executed, so anything a script paints is not seen; `not_checked` lists that and the other limits with every result.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'A post or page on this site. Its permalink is fetched.',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'A URL to fetch instead. Use for archives, taxonomy pages, or another site.',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'minimum' => 5,
                    'maximum' => 30,
                    'default' => 20,
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $post_id = (int) ($input['post_id'] ?? 0);
            $url = trim((string) ($input['url'] ?? ''));
            /** @var array{cookies: array<string, string>, token: string, user_id: int}|null $session */
            $session = null;

            if ($post_id > 0 && $url !== '') {
                return new WP_Error(
                    'wppilot_rendered_ambiguous',
                    __('Pass post_id or url, not both.', domain: 'wppilot'),
                );
            }
            if ($post_id > 0) {
                $post = get_post($post_id);
                if (!$post instanceof WP_Post) {
                    return new WP_Error('wppilot_rendered_no_post', __('No such post.', domain: 'wppilot'));
                }
                // An unpublished page has no public permalink, and fetching one
                // anonymously returns the 404 rather than the page. Rather than
                // refuse - which sends the check away at the exact moment an
                // agent most wants it, while the page is still a draft - the
                // preview URL is fetched carrying the caller's own minute-long
                // session. Nothing is escalated: a caller who cannot see the
                // draft in wp-admin cannot see it here either.
                if ($post->post_status === 'publish') {
                    $url = (string) get_permalink($post);
                } else {
                    if (!current_user_can('read_post', $post_id)) {
                        return new WP_Error('wppilot_rendered_not_permitted', sprintf(
                            /* translators: %s: post status. */
                            __(
                                'That post is %s and you cannot read it, so there is nothing to fetch.',
                                domain: 'wppilot',
                            ),
                            $post->post_status,
                        ));
                    }

                    $url = (string) get_preview_post_link($post);
                    if ($url === '') {
                        return new WP_Error('wppilot_rendered_no_preview', sprintf(
                            /* translators: %s: post status. */
                            __(
                                'That post is %s and WordPress offers no preview URL for it. Publish it, or pass a URL directly.',
                                domain: 'wppilot',
                            ),
                            $post->post_status,
                        ));
                    }

                    $session = wppilot_loopback_session_start();
                    if ($session === null) {
                        return new WP_Error('wppilot_rendered_no_session', sprintf(
                            /* translators: %s: post status. */
                            __(
                                'That post is %s, and this request has no signed-in user to preview it as. Publish it, or connect with a method that authenticates a WordPress user.',
                                domain: 'wppilot',
                            ),
                            $post->post_status,
                        ));
                    }
                }
            }
            if ($url === '') {
                return new WP_Error(
                    'wppilot_rendered_no_target',
                    __('Pass either post_id or url.', domain: 'wppilot'),
                );
            }

            // The session is only ever attached to a URL on this site. An agent
            // may pass any address the site can reach, and sending the caller's
            // cookies to one of those is a credential leak with a plausible
            // cause, so the check is on the URL rather than on how it was built.
            $cookies = $session !== null && wppilot_url_is_same_site($url) ? $session['cookies'] : [];

            try {
                $result = Rendered\inspect($url, (int) ($input['timeout'] ?? 20), $cookies);
            } finally {
                wppilot_loopback_session_end($session);
            }

            if ($result instanceof WP_Error) {
                return $result;
            }
            if ($post_id > 0) {
                $result['post_id'] = $post_id;
            }
            if ($cookies !== []) {
                $result['previewed_as_draft'] = true;
                $result['note'] = __(
                    'This is an unpublished page, fetched as a preview with your own session. A preview renders through the theme like the published page will, but plugins that only run on a public request - caches, some optimisers - do not act on it.',
                    domain: 'wppilot',
                );
            }

            return $result;
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
