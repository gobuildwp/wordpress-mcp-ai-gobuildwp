<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\CheckComposition;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Spec;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Validate a composition — a block subtree written down on its own.
 *
 * This is the piece that lets composition knowledge be captured instead of
 * shipped. Everyone else answers the cold-start problem with a file per
 * industry: a dentist's homepage has these eight sections in this order. It
 * works the day it is written, it is stale the month after, it covers only the
 * trades somebody listed, and it hands every dentist the same page. Moving the
 * prescription from pixel level to section level does not stop it being a
 * template.
 *
 * The alternative is not to know less, it is to know from evidence. Measure a
 * reference in a browser, write what it is actually made of in this vocabulary,
 * and check it here. What comes back is either a composition that will build,
 * or the reason it will not — and because it goes through the same normalizer a
 * spec's blocks go through, anything that passes is expressible, buildable and
 * gradeable rather than being notes in a second format that drifts.
 *
 * The plugin never ships a composition. It ships the grammar for saying one.
 */

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/check-composition', [
        'label' => __('Check Composition', domain: 'wppilot'),
        'description' => __(
            'Validate a composition — a block subtree, in spec vocabulary, describing what something is made of — and return it normalized with its structural signature. Use it to write down a shape worth keeping: a pricing tier you measured on a reference, a hero that worked, the way a section on this site is already built. Call wppilot/get-design-spec with no slug first for the vocabulary. This is deliberately not a library of shapes: a file per industry saying what a dentist\'s homepage contains works the day it is written, is stale a month later, covers only the trades somebody listed, and gives every dentist the same page. Prescribing at section level rather than pixel level does not stop that being a template. So nothing is shipped, and what you measure is checked against the same normalizer a spec uses — anything that passes here will build, and can be graded against afterwards. Composed items are the point: a tier is a ribbon, a name, a price row on one baseline, a feature list and a button, and writing that as a title and a paragraph is how every card ends up looking like every other one.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'blocks' => [
                    'type' => 'array',
                    'description' => 'The composition, as a spec block list. Items may nest their own blocks, and a set may declare variants.',
                    'items' => ['type' => 'object'],
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'What this shape is called, for your own reference. Not stored here.',
                ],
            ],
            'required' => ['blocks'],
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            /** @var mixed $raw */
            $raw = $input['blocks'] ?? [];
            if (!is_array($raw) || $raw === []) {
                return new WP_Error(
                    'wppilot_composition_empty',
                    __('A composition needs at least one block.', domain: 'wppilot'),
                    ['status' => 422],
                );
            }

            $blocks = Spec\normalize_composition($raw);
            if ($blocks instanceof WP_Error) {
                return $blocks;
            }

            $signature = Spec\block_signature($blocks);

            return [
                'valid' => true,
                'name' => trim((string) ($input['name'] ?? '')),
                'blocks' => $blocks,
                // The same signature distinctiveness compares pages by, so two
                // captured shapes can be told apart — or found to be the same
                // shape under two names, which is the more useful answer.
                'signature' => $signature,
                'depth' => depth_of($blocks),
                'composed_items' => composed_items($blocks),
                'note' => note($blocks),
            ];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);
}

/**
 * How deep the composition nests.
 *
 * @param  list<array<string, mixed>> $blocks
 */
function depth_of(array $blocks, int $depth = 1): int
{
    $deepest = $depth;
    foreach ($blocks as $block) {
        $children = is_array($block['blocks'] ?? null) ? $block['blocks'] : [];
        if ($children !== []) {
            $deepest = max($deepest, depth_of($children, $depth + 1));
        }
        foreach ((is_array($block['items'] ?? null) ? $block['items'] : []) as $item) {
            $inner = is_array($item['blocks'] ?? null) ? $item['blocks'] : [];
            if ($inner !== []) {
                $deepest = max($deepest, depth_of($inner, $depth + 1));
            }
        }
    }

    return $deepest;
}

/**
 * How many set members were composed rather than written as shorthand.
 *
 * Reported because it is the single number that says whether a capture is worth
 * keeping. A composition whose every item is a title and a line has recorded
 * that the reference had a card grid, which was never the hard part.
 *
 * @param  list<array<string, mixed>> $blocks
 * @return array{composed: int, shorthand: int}
 */
function composed_items(array $blocks): array
{
    $composed = 0;
    $shorthand = 0;

    foreach ($blocks as $block) {
        foreach ((is_array($block['items'] ?? null) ? $block['items'] : []) as $item) {
            if (is_array($item['blocks'] ?? null) && $item['blocks'] !== []) {
                $composed++;
                continue;
            }
            $shorthand++;
        }
        $children = is_array($block['blocks'] ?? null) ? $block['blocks'] : [];
        if ($children !== []) {
            $nested = composed_items($children);
            $composed += $nested['composed'];
            $shorthand += $nested['shorthand'];
        }
    }

    return ['composed' => $composed, 'shorthand' => $shorthand];
}

/**
 * What is worth saying about a valid composition.
 *
 * @param list<array<string, mixed>> $blocks
 */
function note(array $blocks): string
{
    $counts = composed_items($blocks);
    $sets = $counts['composed'] + $counts['shorthand'];

    if ($sets === 0) {
        return __(
            'Valid. It contains no sets, so nothing here records how a repeated thing is built — which is usually the part worth capturing.',
            domain: 'wppilot',
        );
    }

    if ($counts['composed'] === 0) {
        return __(
            'Valid, but every set member is shorthand. That records only that the reference had a card grid, which was never the hard part: the difference between a reference worth measuring and a page anyone could guess is what is inside each member. Look again at one and write what it is actually made of.',
            domain: 'wppilot',
        );
    }

    return __(
        'Valid. Keep it with wppilot/memory-save as type "composition" so the next build on this site can start from it rather than from nothing, and diverge from it deliberately rather than by accident.',
        domain: 'wppilot',
    );
}
