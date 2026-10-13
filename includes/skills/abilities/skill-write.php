<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Skills\Abilities\SkillWrite;

use WP_Error;
use WPPilot\Skills\Cpt;
use WPPilot\Skills\Parser;
use WPPilot\Skills\Sources;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/skill-write', [
        'label' => __('Write Skill', domain: 'wppilot'),
        'description' => __(
            'Create or update a GoBuildWP user skill. The `title` is the only identifier — it is sanitised server-side (lowercase, dash-separated) and becomes both the human label and the slug used for lookups.',
            domain: 'wppilot',
        ),
        'category' => 'skill',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'enable_prompt' => ['type' => 'boolean'],
                'enable_agentic' => ['type' => 'boolean'],
                'on_conflict' => ['type' => 'string', 'enum' => ['fail', 'replace', 'rename']],
            ],
            'required' => ['title', 'description', 'content'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'action' => ['type' => 'string', 'enum' => ['created', 'updated', 'renamed']],
            ],
            'required' => ['success'],
        ],
        'execute_callback' => __NAMESPACE__ . '\\execute',
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'annotations' => [
                'readonly' => false,
                'destructive' => true,
                'idempotent' => false,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function execute(array $input): array|WP_Error
{
    $title = sanitize_title((string) ($input['title'] ?? ''));
    if ($title === '') {
        return new WP_Error('invalid_title', __(
            'Title is required and must contain at least one letter or digit (lowercase, dash-separated).',
            domain: 'wppilot',
        ));
    }

    // Description is optional at write time. Skills without a description
    // exist (e.g. drafts, fresh uploads) but won't show up in the MCP
    // catalog until the user fills one in (see `Sources\discoverable()`).
    $description = trim((string) ($input['description'] ?? ''));

    // The double-JSON unescape exists for AI clients that encode their tool
    // arguments twice. A body typed into the admin form or read from an
    // uploaded .md file is already literal, and running it through
    // stripcslashes() there would eat regexes and Windows paths.
    $content_raw = (string) ($input['content'] ?? '');
    $content = ($input['literal_content'] ?? false) === true
        ? $content_raw
        : Parser\unescape_content($content_raw);
    if (strlen($content) > Parser\MAX_BODY_BYTES) {
        return new WP_Error('body_too_large', __('Body exceeds 1 MB.', domain: 'wppilot'));
    }

    // Cross-source collision: external sources are read-only; we cannot
    // overwrite or rename into another plugin's namespace.
    $external_label = Sources\exists_in_external_source($title);
    if ($external_label !== null) {
        return new WP_Error(
            'slug_in_external_source',
            sprintf(
                /* translators: 1: slug, 2: source label e.g. "Pro" */
                __('Slug "%1$s" is already used by source "%2$s". Choose a different title.', domain: 'wppilot'),
                $title,
                $external_label,
            ),
            ['slug' => $title, 'source' => $external_label],
        );
    }

    $resolved = resolve_conflict($title, (string) ($input['on_conflict'] ?? 'fail'));
    if ($resolved instanceof WP_Error) {
        return $resolved;
    }
    [$slug, $action, $existing] = $resolved;

    $enable_prompt = filter_var($input['enable_prompt'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $enable_agentic = filter_var($input['enable_agentic'] ?? true, FILTER_VALIDATE_BOOLEAN);

    $postarr = [
        'post_type' => Cpt\POST_TYPE,
        'post_status' => ($input['status'] ?? 'publish') === 'draft' ? 'draft' : 'publish',
        'post_title' => $slug,
        'post_name' => $slug,
        'post_excerpt' => $description,
        'post_content' => $content,
    ];

    if ($existing !== null) {
        $postarr['ID'] = $existing->ID;
    }

    // wp_slash() preserves array shape at runtime (it only slashes string
    // leaves) but its stub return type widens to a generic `array`, so we
    // re-pin the post-data shape before handing it to wp_*_post().
    /** @var array{ID?: int, post_type: string, post_status: string, post_title: string, post_name: string, post_excerpt: string, post_content: string} $slashed */
    $slashed = wp_slash($postarr);

    $post_id = 0;
    if ($existing !== null) {
        $post_id = wp_update_post($slashed, wp_error: true);
    }
    if ($existing === null) {
        $post_id = wp_insert_post($slashed, wp_error: true);
    }

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    update_post_meta($post_id, Cpt\META_ENABLE_PROMPT, $enable_prompt);
    update_post_meta($post_id, Cpt\META_ENABLE_AGENTIC, $enable_agentic);

    return [
        'success' => true,
        'slug' => $slug,
        'action' => $action,
    ];
}

/**
 * Resolves a title against any existing user skill with the same slug.
 *
 * Returns a WP_Error when the slug is taken and the caller asked to fail;
 * otherwise the resolved [slug, action, existing post] tuple. On "rename"
 * the existing post is dropped so the caller inserts a fresh, suffixed skill.
 *
 * @return WP_Error|array{0: string, 1: 'created'|'updated'|'renamed', 2: ?\WP_Post}
 */
function resolve_conflict(string $title, string $on_conflict): WP_Error|array
{
    if (!in_array($on_conflict, ['fail', 'replace', 'rename'], strict: true)) {
        $on_conflict = 'fail';
    }

    $existing = find_user_post_by_slug($title);
    if ($existing === null) {
        return [$title, 'created', null];
    }

    if ($on_conflict === 'fail') {
        return new WP_Error('slug_exists', __('A skill with this title already exists.', domain: 'wppilot'), [
            'slug' => $title,
            'suggested_slug' => find_free_suffix($title),
        ]);
    }

    if ($on_conflict === 'rename') {
        return [find_free_suffix($title), 'renamed', null];
    }

    return [$title, 'updated', $existing];
}

function find_user_post_by_slug(string $slug): ?\WP_Post
{
    /** @var list<\WP_Post> $posts */
    $posts = get_posts([
        'post_type' => Cpt\POST_TYPE,
        'post_status' => ['publish', 'draft'],
        'name' => $slug,
        'posts_per_page' => 1,
        'no_found_rows' => true,
    ]);
    return $posts[0] ?? null;
}

function find_free_suffix(string $slug): string
{
    $i = 2;
    while (
        find_user_post_by_slug($slug . '-' . $i) !== null
        || Sources\exists_in_external_source($slug . '-' . $i) !== null
    ) {
        $i++;
        if ($i > 9999) {
            return $slug . '-' . time();
        }
    }
    return $slug . '-' . $i;
}
