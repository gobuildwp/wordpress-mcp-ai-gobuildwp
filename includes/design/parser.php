<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Parser;

if (!defined('ABSPATH')) {
    exit();
}

/** Maximum raw DESIGN.md size (bytes). Sanity cap. */
const MAX_BYTES = 1_048_576; // 1 MB

/**
 * Parse a DESIGN.md string. Lenient by design: we extract only the
 * top-level metadata the engine needs (`name`, `description`) and the
 * list of `## ` section headings. Nested token blocks (colors, typography,
 * …) are intentionally NOT parsed here — the raw string is the source of
 * truth and structured token parsing lives in the materialization plan.
 *
 * Top-level front-matter keys are detected as lines with no leading
 * whitespace (nested keys are indented), so `name:`/`description:` are
 * read while `  fontFamily:` under `typography:` is skipped.
 *
 * @return array{name: string, description: string, front_matter: bool, sections: list<string>, parse_error: ?string}
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function parse(string $raw): array
{
    $name = '';
    $description = '';
    $front_matter = false;
    $sections = [];
    $parse_error = null;

    $normalized = \WPPilot\Design\Tokens\normalize_md($raw);
    $body = $normalized;

    if (str_starts_with($normalized, "---\n")) {
        $front_matter = true;
        $closing = strpos($normalized, needle: "\n---\n", offset: 4);
        if ($closing === false && str_ends_with($normalized, "\n---")) {
            $closing = strlen($normalized) - 4;
        }
        if ($closing === false) {
            $parse_error = __('Front matter opened with --- but had no closing ---', domain: 'wppilot');
        }
        if ($closing !== false) {
            $front = substr($normalized, offset: 4, length: $closing - 4);
            $body = ltrim(substr($normalized, offset: $closing + 5), characters: "\n");
            foreach (explode(separator: "\n", string: $front) as $line) {
                if ($line === '' || $line[0] === ' ' || $line[0] === "\t") {
                    continue; // blank or nested (indented) key
                }
                if (str_starts_with($line, '#')) {
                    continue; // comment
                }
                $colon = strpos($line, needle: ':');
                if ($colon === false) {
                    continue;
                }
                $key = strtolower(trim(substr($line, offset: 0, length: $colon)));
                $value = trim(substr($line, offset: $colon + 1));
                if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                    $value = substr($value, offset: 1, length: -1);
                }
                if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                    $value = substr($value, offset: 1, length: -1);
                }
                if ($key === 'name') {
                    $name = $value;
                }
                if ($key === 'description') {
                    $description = $value;
                }
            }
        }
    }

    // Prose-format DESIGN.md (no YAML front matter): take the name from the
    // first level-1 heading — the designmd.ai / community variant.
    if ($name === '') {
        $name = heading_name($body);
    }

    foreach (explode(separator: "\n", string: $body) as $line) {
        if (!str_starts_with($line, '## ')) {
            continue;
        }
        $sections[] = trim(substr($line, offset: 3));
    }

    return [
        'name' => $name,
        'description' => $description,
        'front_matter' => $front_matter,
        'sections' => $sections,
        'parse_error' => $parse_error,
    ];
}

/** First non-empty level-1 heading text in the body, else '' — the prose-format name source. */
function heading_name(string $body): string
{
    foreach (explode(separator: "\n", string: $body) as $line) {
        if (!str_starts_with($line, '# ')) {
            continue;
        }
        return trim(substr($line, offset: 2));
    }
    return '';
}

/**
 * A DESIGN.md is storable if a name can be derived (from YAML front matter OR
 * a `#` heading) and there is no parse error. This accepts both the
 * front-matter variant and the prose-only variant found in the wild.
 */
function is_valid(string $raw): bool
{
    $parsed = parse($raw);
    return $parsed['name'] !== '' && $parsed['parse_error'] === null;
}

/**
 * Normalize a slug candidate to the canonical internal form. Returns ''
 * if no usable slug can be derived.
 */
function normalize_slug(string $raw): string
{
    $candidate = sanitize_title($raw);
    if ($candidate === '') {
        return '';
    }
    if (strlen($candidate) > 60) {
        $candidate = rtrim(substr($candidate, offset: 0, length: 60), characters: '-');
    }
    return $candidate;
}


/**
 * Undo the double-JSON encoding some AI clients apply to tool arguments.
 *
 * Only a body with no real line breaks but literal `\n` sequences carries that
 * signature. Running stripcslashes() over every submission ate the backslashes
 * out of Windows paths (`C:\temp` -> `C:<TAB>emp`) and regexes (`\d` -> `d`),
 * corrupting documents from every correctly-encoded client.
 */
function unescape_content(string $raw): string
{
    if (str_contains($raw, "\n") || str_contains($raw, "\r")) {
        return $raw;
    }
    if (!str_contains($raw, '\n') && !str_contains($raw, '\r')) {
        return $raw;
    }
    return stripcslashes($raw);
}
