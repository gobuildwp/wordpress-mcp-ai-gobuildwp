<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Skills\Catalog;

use WPPilot\Skills\Sources;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Prepend the unified skill catalog block to the GoBuildWP discover-abilities
 * instructions string. Skills are listed only if their `description` is
 * non-empty and `enable_agentic` is true (default for sources that do not
 * specify it).
 *
 * Other plugins that contribute additional sources via
 * `wppilot_skill_lookup_sources` automatically appear here under their
 * source label.
 */
function inject(mixed $instructions): mixed
{
    if (!is_string($instructions)) {
        return $instructions;
    }

    $skills = Sources\discoverable('agentic');
    if ($skills === []) {
        return $instructions;
    }

    return $instructions . "\n" . render($skills);
}

/**
 * Render the markdown catalog block. Public so admin previews can reuse it.
 *
 * @param list<array<string,mixed>> $skills
 */
function render(array $skills): string
{
    $lines = [
        '',
        '## Available Skills',
        '',
        'Each entry shows its source badge: `(User)` for skills the site admin created, plugin-specific labels for skills contributed by other plugins.',
        '',
        'If a listed skill matches the request, load its full instructions with `wppilot/skill-get` before starting the work — not after.',
        '',
    ];
    foreach ($skills as $skill) {
        $slug = (string) ($skill['slug'] ?? '');
        $description = trim((string) ($skill['description'] ?? ''));
        $label = (string) ($skill['source_label'] ?? '');
        $lines[] = sprintf('- **`%s`** *(%s)* — %s', $slug, $label, $description);
    }
    $lines[] = '';
    return implode("\n", $lines);
}
