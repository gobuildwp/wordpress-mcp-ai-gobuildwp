<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\Gutenberg;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Addressing one block inside parse_blocks() output.
 *
 * A path is the list of indexes gutenberg-get-content shows: the top level counts every
 * entry parse_blocks() returns, including the whitespace between blocks (listed there as
 * core/freeform), and each later index counts innerBlocks. The editor's parser drops those
 * whitespace entries, so the queue is handed editor_block_path() instead.
 */
const BLOCK_PATH_MAX_DEPTH = 12;

/** @param array<array-key, mixed> $path */
function block_path_is_valid(array $path): bool
{
    if ($path === [] || count($path) > BLOCK_PATH_MAX_DEPTH || array_values($path) !== $path) {
        return false;
    }
    foreach ($path as $index) {
        if (!is_int($index) || $index < 0) {
            return false;
        }
    }

    return true;
}

/**
 * @param list<array<string, mixed>> $blocks
 * @param list<int>                  $path
 * @return array<string, mixed>|null
 */
function block_at_path(array $blocks, array $path): ?array
{
    $level = $blocks;
    $block = null;
    foreach ($path as $index) {
        $candidate = $level[$index] ?? null;
        if (!is_array($candidate)) {
            return null;
        }
        $block = $candidate;
        $level = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : [];
    }

    return $block;
}

/**
 * @param list<array<string, mixed>> $blocks
 * @param list<int>                  $path
 * @param array<string, mixed>       $replacement
 * @return list<array<string, mixed>>
 */
function replace_block_at_path(array $blocks, array $path, array $replacement): array
{
    $index = $path[0];
    if (!isset($blocks[$index])) {
        return $blocks;
    }
    if (count($path) === 1) {
        $blocks[$index] = $replacement;
        return $blocks;
    }
    $inner = is_array($blocks[$index]['innerBlocks'] ?? null) ? array_values($blocks[$index]['innerBlocks']) : [];
    /** @var list<array<string, mixed>> $inner */
    $blocks[$index]['innerBlocks'] = replace_block_at_path($inner, array_slice($path, 1), $replacement);

    return $blocks;
}

/** @param array<string, mixed> $block */
function is_whitespace_freeform(array $block): bool
{
    return !is_string($block['blockName'] ?? null)
        && trim(is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '') === '';
}

/**
 * The same block's path as the editor's parser numbers it: whitespace-only entries between
 * top-level blocks are not blocks there. Inner levels never hold them (parse_blocks keeps
 * that whitespace in the parent's innerContent), so only the first index moves.
 *
 * @param list<array<string, mixed>> $blocks
 * @param list<int>                  $path
 * @return list<int>
 */
function editor_block_path(array $blocks, array $path): array
{
    $top = 0;
    for ($index = 0; $index < $path[0]; ++$index) {
        if (isset($blocks[$index]) && !is_whitespace_freeform($blocks[$index])) {
            ++$top;
        }
    }
    $path[0] = $top;

    return $path;
}

/**
 * The edited block. Attributes merge into the existing ones, a null value removing that key.
 * inner_html replaces the saved markup of a block with no inner blocks; a container's markup
 * is woven around its children, so it is refused rather than guessed at.
 *
 * @param array<string, mixed>      $block
 * @param array<string, mixed>|null $attributes
 * @return array<string, mixed>|WP_Error
 */
function edit_parsed_block(array $block, ?array $attributes, ?string $inner_html): array|WP_Error
{
    if ($attributes !== null) {
        $merged = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                unset($merged[$key]);
                continue;
            }
            $merged[$key] = $value;
        }
        $block['attrs'] = $merged;
    }

    if ($inner_html !== null) {
        $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        if ($inner !== []) {
            return new WP_Error(
                'gutenberg_edit_container_html',
                sprintf(
                    'This block has %d inner block(s), so its markup cannot be replaced as one string. Edit the inner block by its path instead.',
                    count($inner),
                ),
                ['status' => 400],
            );
        }
        $block['innerHTML'] = $inner_html;
        $block['innerContent'] = [$inner_html];
    }

    return $block;
}

/**
 * True when the block keeps no saved markup of its own, so changing its attributes in the
 * block comment is the whole edit and the server can write it. Everything else needs the
 * block's editor JavaScript to regenerate its HTML.
 *
 * @param array<string, mixed> $block
 */
function block_saves_no_markup(array $block, bool $registered_dynamic): bool
{
    if (!$registered_dynamic) {
        return false;
    }
    $parts = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [];
    foreach ($parts as $part) {
        if (is_string($part) && trim($part) !== '') {
            return false;
        }
    }

    return true;
}
