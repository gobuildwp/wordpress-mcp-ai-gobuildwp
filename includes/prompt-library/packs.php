<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\PromptLibrary;

/**
 * The prompt library: one complete landing-page brief per industry.
 *
 * The earlier library was organised by editor and by task, and its prompts
 * named ability chains. That taught the agent which calls to make and nothing
 * about what to build, so two people asking for a bakery and a law firm got
 * the same centred hero with three cards under it. This library is the other
 * half: every entry is a finished creative brief for one kind of business,
 * with a palette, a type pairing, a design signature that makes the page
 * unlike the others, the sections it must carry, and the facts to use
 * verbatim. The builder is chosen on the screen and written into the first
 * line, so one brief serves Elementor and the block editor alike.
 *
 * Briefs are Markdown files in prompts/, one per industry, with a small
 * front-matter block. The shared closing section — accessibility, real
 * photography, one icon set, builder-native construction, completeness — lives
 * once in prompts/_standards.md and is appended to every brief at read time,
 * so tightening a rule tightens it everywhere.
 *
 * Free ships ten briefs for simple single-page sites. Pro will add its own
 * through the `wppilot_industry_briefs` filter; this file never learns Pro
 * exists and keeps working unchanged when it is absent.
 */

if (!defined('ABSPATH')) {
    exit();
}

const PAGE = 'wppilot-prompts';

const BRIEFS_DIR = __DIR__ . '/prompts';

const BUILDER_LINE_PREFIX = '**Page builder:** ';

/**
 * The builders a brief can be written for, in display order.
 *
 * Keyed by slug. Only the two the free plugin can actually drive are listed
 * here; Pro adds the rest through the filter, so a person is never offered a
 * builder whose abilities are not on this site.
 *
 * @return array<string, string> slug => label
 */
function builders(): array
{
    $builders = [
        'elementor' => 'Elementor',
        'gutenberg' => 'Gutenberg (block editor)',
    ];

    /**
     * Filter the builders offered on the Prompts screen.
     *
     * @param array<string, string> $builders slug => label
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_prompt_builders', $builders);
    if (!is_array($filtered)) {
        return $builders;
    }

    $safe = [];
    /** @var mixed $label */
    foreach ($filtered as $slug => $label) {
        if (is_string($slug) && $slug !== '' && is_string($label) && $label !== '') {
            $safe[$slug] = $label;
        }
    }

    return $safe === [] ? $builders : $safe;
}

/**
 * The builder to preselect: the one that is installed, else the block editor.
 */
function default_builder(): string
{
    $builders = builders();
    if (defined('ELEMENTOR_VERSION') && array_key_exists('elementor', $builders)) {
        return 'elementor';
    }

    return array_key_exists('gutenberg', $builders) ? 'gutenberg' : (string) array_key_first($builders);
}

/**
 * Every brief available on this site, free first, then whatever the filter adds.
 *
 * @return list<array{slug: string, industry: string, sector: string, business: string, title: string, description: string, signature: string, scope: string, pro: bool, body: string}>
 */
function briefs(): array
{
    $briefs = bundled_briefs();

    /**
     * Filter the industry briefs.
     *
     * Add a brief: slug, industry, sector, business, title, description,
     * signature, pro flag, and the Markdown body without the builder line
     * or the standards block, both of which are added at compose time.
     *
     * @param list<array<string, mixed>> $briefs
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_industry_briefs', $briefs);
    if (!is_array($filtered)) {
        return $briefs;
    }

    $safe = [];
    $seen = [];
    /** @var mixed $brief */
    foreach ($filtered as $brief) {
        $clean = normalize_brief($brief);
        if ($clean === null || isset($seen[$clean['slug']])) {
            continue;
        }
        $seen[$clean['slug']] = true;
        $safe[] = $clean;
    }

    return $safe;
}

/**
 * The briefs shipped in prompts/, in file order.
 *
 * @return list<array{slug: string, industry: string, sector: string, business: string, title: string, description: string, signature: string, scope: string, pro: bool, body: string}>
 */
function bundled_briefs(): array
{
    $files = glob(BRIEFS_DIR . '/*.md');
    if (!is_array($files)) {
        return [];
    }
    sort($files);

    $briefs = [];
    foreach ($files as $file) {
        if (str_starts_with(basename($file), '_')) {
            continue;
        }
        $brief = read_brief_file($file);
        if ($brief !== null) {
            $briefs[] = $brief;
        }
    }

    return $briefs;
}

/**
 * Parse one brief file: a `---` front-matter block of `key: value` lines,
 * then the Markdown body.
 *
 * @return array{slug: string, industry: string, sector: string, business: string, title: string, description: string, signature: string, scope: string, pro: bool, body: string}|null
 */
function read_brief_file(string $file): ?array
{
    $raw = is_readable($file) ? file_get_contents($file) : false;
    if (!is_string($raw)) {
        return null;
    }
    $raw = str_replace("\r\n", "\n", $raw);

    if (!preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $raw, $m)) {
        return null;
    }

    $meta = [];
    foreach (explode("\n", $m[1]) as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $meta[trim(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
    }

    $meta['body'] = trim($m[2]);
    if (($meta['slug'] ?? '') === '') {
        $meta['slug'] = basename($file, '.md');
    }

    return normalize_brief($meta);
}

/**
 * Coerce a brief into the documented shape, or reject it.
 *
 * Anything can hook the filter, so nothing downstream should have to guess
 * whether a key is present or the body is a string. A malformed brief is
 * dropped rather than half-rendered.
 *
 * @return array{slug: string, industry: string, sector: string, business: string, title: string, description: string, signature: string, scope: string, pro: bool, body: string}|null
 */
function normalize_brief(mixed $brief): ?array
{
    if (!is_array($brief)) {
        return null;
    }

    $slug = sanitize_key(field($brief, 'slug'));
    $industry = field($brief, 'industry');
    $body = field($brief, 'body');
    if ($slug === '' || $industry === '' || $body === '') {
        return null;
    }

    return [
        'slug' => $slug,
        'industry' => $industry,
        'sector' => field($brief, 'sector', __('Other', domain: 'wppilot')),
        'business' => field($brief, 'business'),
        'title' => field($brief, 'title', $industry),
        'description' => field($brief, 'description'),
        'signature' => field($brief, 'signature'),
        // What the brief builds: a landing page, a whole site, a store, a
        // course. Free's own briefs are all landing pages and leave it empty;
        // Pro's mostly are not, which is the point of them, and a field the
        // normaliser drops is a field that cannot be shown or filtered on.
        'scope' => field($brief, 'scope'),
        'pro' => in_array($brief['pro'] ?? false, [true, 'true'], strict: true),
        'body' => $body,
    ];
}

/**
 * A trimmed string field, or the fallback when it is missing, blank, or not a string.
 *
 * @param array<array-key, mixed> $brief
 */
function field(array $brief, string $key, string $fallback = ''): string
{
    $value = is_string($brief[$key] ?? null) ? trim($brief[$key]) : '';

    return $value !== '' ? $value : $fallback;
}

/**
 * The closing standards every brief carries.
 */
function standards(): string
{
    $raw = file_get_contents(BRIEFS_DIR . '/_standards.md');

    return is_string($raw) ? trim(str_replace("\r\n", "\n", $raw)) : '';
}

/**
 * The builder line that opens every composed brief.
 */
function builder_line(string $builder): string
{
    $label = builders()[$builder] ?? $builder;

    return BUILDER_LINE_PREFIX . $label;
}

/**
 * The full text a person copies: builder line, the brief, the standards.
 *
 * @param array<string, mixed> $brief
 */
function compose(array $brief, string $builder): string
{
    $parts = [builder_line($builder), (string) ($brief['body'] ?? '')];
    $standards = standards();
    if ($standards !== '') {
        $parts[] = $standards;
    }

    return implode("\n\n", $parts);
}

/**
 * Briefs grouped by sector, sectors in first-seen order.
 *
 * @param list<array<string, mixed>> $briefs
 * @return array<string, list<array<string, mixed>>>
 */
function by_sector(array $briefs): array
{
    $grouped = [];
    foreach ($briefs as $brief) {
        $grouped[(string) ($brief['sector'] ?? '')][] = $brief;
    }

    return $grouped;
}
