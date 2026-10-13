<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Examples;

use WPPilot\Design\Contract;
use WPPilot\Design\Parser;
use WPPilot\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Worked example directions, shipped as real DESIGN.md files.
 *
 * These are examples, not a catalogue. The distinction is the whole design of
 * this module: there is no ability that applies one, no picker that installs
 * one, and nothing anywhere calls them themes or presets. An agent reads one to
 * see what a finished direction looks like — how the reasoning is written down,
 * how a palette is checked against contrast before it is committed to, how a
 * "Don't" list is phrased so the gate can act on it — and then writes one for
 * the site in front of it.
 *
 * A picker would be the more marketable feature and it would make the product
 * worse. Fifty selectable palettes produce fifty sites that look like the
 * palette rather than like the business, which is the exact failure the design
 * system exists to prevent; and it would contradict the pre-flight, which flags
 * a cream-and-terracotta palette precisely because it is what gets reached for
 * when nobody decided anything.
 *
 * Each file is a genuine DESIGN.md that passes the contract, so an agent can
 * read the tokens as well as the prose, and the set deliberately spans
 * light/dark, serif/sans, dense/airy and loud/quiet rather than being seven
 * variations on a safe middle.
 */

/** Where the example files live. */
const DIR = __DIR__ . '/examples/';

/**
 * The examples, in the order they should be offered.
 *
 * An explicit list rather than a directory scan: the order is editorial — the
 * two most broadly useful directions first — and a file dropped into the folder
 * by anything else should not become something an agent presents as ours.
 *
 * @return list<string>
 */
function slugs(): array
{
    return [
        'editorial-broadsheet',
        'civic-service',
        'clinical-calm',
        'terminal-dark',
        'gallery-quiet',
        'warm-craft',
        'poster-brutal',
    ];
}

/**
 * One example, or null when the slug is not one of ours.
 *
 * @return array{slug: string, name: string, description: string, content: string}|null
 */
function find(string $slug): ?array
{
    $slug = Parser\normalize_slug($slug);
    if (!in_array($slug, slugs(), strict: true)) {
        return null;
    }

    $path = DIR . $slug . '.md';
    // realpath is checked against DIR even though the slug was matched against a
    // fixed list first: normalize_slug is the only thing between input and a
    // filesystem read, and a path check costs nothing next to trusting it.
    $real = realpath($path);
    if ($real === false || !str_starts_with($real, realpath(DIR) ?: DIR)) {
        return null;
    }
    $content = file_get_contents($real);
    if (!is_string($content) || $content === '') {
        return null;
    }

    $parsed = Parser\parse($content);

    return [
        'slug' => $slug,
        'name' => $parsed['name'] !== '' ? $parsed['name'] : $slug,
        'description' => $parsed['description'],
        'content' => $content,
    ];
}

/**
 * Every example, loaded.
 *
 * @return list<array{slug: string, name: string, description: string, content: string}>
 */
function all(): array
{
    $out = [];
    foreach (slugs() as $slug) {
        $record = find($slug);
        if ($record !== null) {
            $out[] = $record;
        }
    }

    return $out;
}

/**
 * Short cards for the list view: enough to choose what to read, not enough to
 * copy. Returning seven full documents to answer "what examples are there"
 * would spend most of a context window on six directions nobody wanted.
 *
 * @return list<array<string, mixed>>
 */
function summaries(): array
{
    $out = [];
    foreach (all() as $record) {
        $tokens = Tokens\extract($record['content']);
        $vars = Tokens\css_vars($tokens);
        $out[] = [
            'slug' => $record['slug'],
            'name' => $record['name'],
            'description' => $record['description'],
            'palette' => [
                'bg' => $vars['--wppilot-bg'] ?? '',
                'ink' => $vars['--wppilot-ink'] ?? '',
                'accent' => $vars['--wppilot-accent'] ?? '',
            ],
            'fonts' => [
                'heading' => $vars['--wppilot-font-heading'] ?? '',
                'body' => $vars['--wppilot-font-body'] ?? '',
            ],
            'dials' => Contract\inspect($record['content'])['dials'],
        ];
    }

    return $out;
}
