<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\ViewLink;

use WP_Error;
use WP_Post;

/**
 * Ability: a URL the agent's own browser can open to look at a page.
 *
 * Every other check in GoBuildWP reads the page: the markup, the stylesheets, the
 * contrast of colours declared in CSS. None of them can see it. An agent builds
 * a hero, and whether the headline sits on top of the image or under it, whether
 * the button is legible on that photograph, whether the third card wrapped onto
 * its own row at 1024px - those are visual facts, and reading HTML answers none
 * of them.
 *
 * Most MCP clients already have a browser. This closes the loop with the one
 * they have rather than by building a second one: it returns the address to
 * open and the widths worth looking at. For a page nobody can see yet it says
 * so, and names the ability that can sign a browser in - it never mints that
 * session itself. A sign-in link is a wp-admin session: it is critical, needs
 * confirmation, and Production Safe refuses it. An ability annotated read-only
 * that handed one out would walk straight past all three.
 *
 * Deliberately not a screenshot ability. GoBuildWP has no renderer; when the
 * caller has no browser either, wppilot/capture-page asks the site's own
 * logged-in tab to take the picture instead.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Viewport widths worth checking, and why each one.
 *
 * Three, not a sweep: the point is to catch the two failures that reading HTML
 * cannot - a layout that breaks between desktop and phone, and text that is
 * unreadable over an image - and three widths find those. A longer list turns a
 * check into a chore and gets skipped.
 */
const VIEWPORTS = [
    ['width' => 1440, 'label' => 'desktop'],
    ['width' => 1024, 'label' => 'tablet'],
    ['width' => 390, 'label' => 'phone'],
];

/**
 * Register on `wp_abilities_api_init`, like every other design ability.
 *
 * Registering at file scope would run before the Abilities API exists, so the
 * ability would simply never appear - with no error anywhere to explain it.
 */
function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/get-page-view-link', [
        'label' => __('Get Page View Link', domain: 'wppilot'),
        'description' => __(
            'Returns a URL to open in your own browser tool so you can SEE a page you built, plus the viewport widths worth checking. For an unpublished page it returns the preview URL and requires_sign_in=true: a browser that is not already signed in to this site needs a session first, which only wppilot/create-admin-access-link can issue - a critical ability that needs confirmation and that the active safety profile may refuse. Use this after building or editing a page and before reporting it finished - wppilot/verify-rendered-page reads the markup, and this is how you look at the result. If you have no browser tool, wppilot/capture-page asks the site to take the screenshot instead.',
            domain: 'wppilot',
        ),
        'category' => 'preview',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The page to look at.',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'A URL on this site to look at instead, such as an archive or the front page.',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static fn(array $input): array|WP_Error => get_page_view_link($input),
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

/**
 * Resolve what to open, and what the browser needs before it can.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function get_page_view_link(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $url = trim((string) ($input['url'] ?? ''));

    if ($post_id > 0 && $url !== '') {
        return new WP_Error(
            'wppilot_view_link_ambiguous',
            __('Pass post_id or url, not both.', domain: 'wppilot'),
        );
    }

    $needs_session = false;
    $status = '';

    if ($post_id > 0) {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return new WP_Error('wppilot_view_link_no_post', __('No such post.', domain: 'wppilot'));
        }

        $status = $post->post_status;
        if ($status === 'publish') {
            $url = (string) get_permalink($post);
        } else {
            if (!current_user_can('read_post', $post_id)) {
                return new WP_Error(
                    'wppilot_view_link_not_permitted',
                    __('That post is not published and you cannot read it.', domain: 'wppilot'),
                );
            }
            $url = (string) get_preview_post_link($post);
            $needs_session = true;
        }
    }

    if ($url === '') {
        return new WP_Error(
            'wppilot_view_link_no_target',
            __('Pass either post_id or url.', domain: 'wppilot'),
        );
    }

    if (!\wppilot_url_is_same_site($url)) {
        return new WP_Error(
            'wppilot_view_link_off_site',
            __('That URL is not on this site. This ability only hands out links to pages here.', domain: 'wppilot'),
        );
    }

    $result = [
        'url' => $url,
        'post_id' => $post_id > 0 ? $post_id : null,
        'post_status' => $status,
        'public' => !$needs_session,
        'viewports' => VIEWPORTS,
        'requires_sign_in' => $needs_session,
    ];

    if ($needs_session) {
        $result['sign_in_ability'] = 'wppilot/create-admin-access-link';
    }

    $result['user_instruction'] = view_instruction($needs_session);

    return $result;
}

/**
 * What to do with what was returned.
 */
function view_instruction(bool $needs_session): string
{
    $look = __(
        'Open the URL in your browser tool and screenshot it at each listed viewport width. Compare what you see against the active design - wppilot/get-active-design has the palette and the type stack - and against what the page was meant to communicate. Look for what markup cannot tell you: text that is unreadable over an image, a layout that wraps or overflows at a narrower width, spacing that collapses, an element that renders but is invisible. Fix what is wrong, then look again. Pair this with wppilot/verify-rendered-page, which reads the served HTML for the checks a screenshot cannot make.',
        domain: 'wppilot',
    );

    if (!$needs_session) {
        return $look;
    }

    return __(
        'This page is not published, so only a browser signed in to this site can open `url`. If yours is not, do not work around it: ask the person to open the preview themselves, or - only if they agree - call wppilot/create-admin-access-link, which is critical, needs confirm=true, and may be refused by the active safety profile. Never put a sign-in token in a query string or paste it anywhere a person could read it.',
        domain: 'wppilot',
    ) . ' ' . $look;
}
