<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Skills;

use WPPilot\Kits\Runtime;
use WPPilot\Skills\Parser;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The skills that ship inside loaded kits, as one source in GoBuildWP's skill catalog.
 *
 * A kit carries its SKILL.md under skills/<slug>/ so the prompt travels with the abilities it
 * names when the folder is copied into another plugin. Inside GoBuildWP they join the catalog like
 * the built-ins do, for free and Pro kits alike — only kits that actually loaded, so a skill never
 * names an ability that is not registered.
 */
const SOURCE_ID = 'kits';

/**
 * @param array<string, array{id: string, priority: int, label: string, loader: callable}> $sources
 * @return array<string, array{id: string, priority: int, label: string, loader: callable}>
 */
function register(array $sources): array
{
    $sources[SOURCE_ID] = [
        'id' => SOURCE_ID,
        'priority' => 12,
        'label' => 'Kits',
        'loader' => __NAMESPACE__ . '\\load',
    ];
    return $sources;
}

/**
 * @return list<array{slug: string, name: string, description: string, content: string, enable_prompt: bool, enable_agentic: bool}>
 */
function load(): array
{
    $result = [];
    foreach (Runtime\pending_kits() as $kit) {
        $files = glob(rtrim((string) ($kit['manifest']['dir'] ?? ''), '/\\') . '/skills/*/SKILL.md');
        foreach (is_array($files) ? $files : [] as $path) {
            $slug = Parser\normalize_slug(basename(dirname($path)));
            $raw = $slug === '' ? false : file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $parsed = Parser\parse($raw);
            if ($parsed['parse_error'] !== null || trim($parsed['body']) === '') {
                continue;
            }
            $result[] = [
                'slug' => $slug,
                'name' => $parsed['name'] !== '' ? $parsed['name'] : $slug,
                'description' => $parsed['description'],
                'content' => $parsed['body'],
                'enable_prompt' => $parsed['enable_prompt'],
                'enable_agentic' => $parsed['enable_agentic'],
            ];
        }
    }
    return $result;
}

add_filter('wppilot_skill_lookup_sources', __NAMESPACE__ . '\\register');
