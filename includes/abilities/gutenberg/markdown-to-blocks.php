<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\Gutenberg;

use WP_Error;
use WP_Post;
use WPPilot\Abilities\Gutenberg\Markdown;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/markdown-to-blocks', [
    'label' => __('Markdown to Blocks', domain: 'wppilot'),
    'description' => __(
        'Converts Markdown to core Gutenberg blocks: headings, paragraphs, bullet and numbered lists (nested by indent), quotes, fenced code, horizontal rules, standalone images and pipe tables, with bold, italic, strikethrough, inline code, links and inline images. HTML in the Markdown is escaped and shown as text. Without post_id it only returns block_spec, ready for gutenberg-add-pending-change. With post_id it queues the blocks in the Block Editor Queue, replacing the post content (mode replace) or adding after it (mode append); the change is live once that batch is finalized. Refused when the post already has a queued change, or when a replace would remove most of the page\'s text or media unless confirm_content_loss is true. Links and images may only use http, https, mailto, tel or relative URLs.',
        domain: 'wppilot',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'markdown' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Markdown\MAX_BYTES],
            'post_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Queue the blocks for this post. Omit to only convert.'],
            'mode' => ['type' => 'string', 'enum' => ['replace', 'append'], 'default' => 'replace'],
            'confirm_content_loss' => ['type' => 'boolean', 'default' => false],
            'batch_id' => ['type' => 'integer', 'description' => 'Draft Block Editor Queue batch to add to. Omit to create one.'],
            'label' => ['type' => 'string', 'description' => 'Batch label when a batch is created.'],
        ],
        'required' => ['markdown'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'block_spec' => ['type' => 'array', 'items' => ['type' => 'object']],
            'block_count' => ['type' => 'integer'],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            'queued' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\markdown_to_blocks',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Prefer this to hand-writing block specs for prose. A queued change is not live until the person opens the Block Editor Queue; say so and give them the link from user_instruction.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function markdown_to_blocks(array $input): array|WP_Error
{
    $markdown = is_string($input['markdown'] ?? null) ? $input['markdown'] : '';
    if (trim($markdown) === '' || strlen($markdown) > Markdown\MAX_BYTES) {
        return new WP_Error('markdown_invalid', sprintf('markdown must be 1 to %d bytes.', Markdown\MAX_BYTES), ['status' => 400]);
    }
    $converted = Markdown\convert($markdown);
    $specs = $converted['blocks'];
    if ($specs === []) {
        return new WP_Error('markdown_empty', 'The Markdown produced no blocks.', ['status' => 400]);
    }

    $result = [
        'block_spec' => markdown_block_spec_for_output($specs),
        'block_count' => count($specs),
        'warnings' => $converted['warnings'],
        'queued' => false,
    ];
    $post_id = is_int($input['post_id'] ?? null) ? $input['post_id'] : 0;
    if ($post_id <= 0) {
        return $result;
    }

    $target = get_target($post_id);
    if (!$target instanceof WP_Post) {
        return new WP_Error('gutenberg_target_not_found', sprintf('Target post %d was not found.', $post_id), ['status' => 404]);
    }
    if (!current_user_can('edit_post', $target->ID)) {
        return new WP_Error('gutenberg_edit_forbidden', 'You cannot edit this post.', ['status' => 403]);
    }
    $conflict = active_item_for_target($target->ID, $target->post_type);
    if ($conflict instanceof WP_Post) {
        return gutenberg_pending_conflict_error($conflict, $target, $target->post_type);
    }

    $append = ($input['mode'] ?? 'replace') === 'append' && trim($target->post_content) !== '';
    if (!$append && function_exists('wppilot_content_write_guard')) {
        // The queue has not rendered the blocks yet, so measure their attributes: that is
        // where a converted paragraph's text lives until save() turns it into markup.
        $guard = \wppilot_content_write_guard(
            $target,
            serialize_dynamic_blocks($specs),
            ($input['confirm_content_loss'] ?? false) === true,
        );
        if ($guard instanceof WP_Error) {
            return $guard;
        }
    }

    $batch = gutenberg_resolve_pending_batch($input);
    if (is_wp_error($batch)) {
        return $batch;
    }
    $queued = $append ? array_merge([['raw' => $target->post_content]], $specs) : $specs;
    $item_id = create_item($batch->ID, $target->ID, $target->post_type, 'replace-content', $queued);
    if (is_wp_error($item_id)) {
        return $item_id;
    }

    $result['queued'] = true;
    $result['mode'] = $append ? 'append' : 'replace';

    return $result + gutenberg_pending_change_response($batch, $item_id, $target, $target->post_type);
}

/**
 * Empty attribute maps would serialise as a JSON list ([]), so they are
 * returned as objects ({}) for strict JSON consumers.
 *
 * @param list<array<string, mixed>> $specs
 * @return list<array<string, mixed>>
 */
function markdown_block_spec_for_output(array $specs): array
{
    foreach ($specs as $index => $spec) {
        if (array_key_exists('attributes', $spec) && $spec['attributes'] === []) {
            $spec['attributes'] = new \stdClass();
        }
        if (is_array($spec['innerBlocks'] ?? null) && $spec['innerBlocks'] !== []) {
            $spec['innerBlocks'] = markdown_block_spec_for_output(array_values($spec['innerBlocks']));
        }
        $specs[$index] = $spec;
    }

    return $specs;
}
