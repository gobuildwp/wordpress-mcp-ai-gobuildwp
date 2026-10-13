<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Contract;

use WPPilot\Design\Markdown;
use WPPilot\Design\Preflight;
use WPPilot\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/** JSON Schema properties shared by design ability responses. */
function ability_output_properties(): array
{
    return [
        'tokens' => ['type' => 'object'],
        'dials' => [
            'type' => 'object',
            'properties' => [
                'variance' => ['type' => 'number'],
                'density' => ['type' => 'number'],
                'motion' => ['type' => 'number'],
            ],
        ],
        'guidance' => [
            'type' => 'object',
            'properties' => [
                'dos' => ['type' => 'array', 'items' => ['type' => 'string']],
                'donts' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ],
        'token_sources' => ['type' => 'object'],
        'readiness' => [
            'type' => 'object',
            'properties' => [
                'ready' => ['type' => 'boolean'],
                'sync_ready' => ['type' => 'boolean'],
                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['ready', 'sync_ready', 'errors', 'warnings'],
        ],
        'waivers' => ['type' => 'array', 'items' => ['type' => 'string']],
    ];
}

/**
 * Inspect a DESIGN.md without changing it. The raw document remains the source
 * of truth; this is the stable machine-readable view abilities and sync
 * consumers can use without re-parsing Markdown themselves.
 *
 * `ready` means the design has the minimum direction required for activation.
 * `sync_ready` is deliberately stricter: colors and typography must be explicit
 * front-matter tokens, not values inferred heuristically from narrative prose.
 *
 * @return array{
 *   tokens: array{
 *     colors: array<string, string>,
 *     typography: array<string, array<string, string>>,
 *     spacing: array<string, string>,
 *     rounded: array<string, string>,
 *     components: array<string, string>
 *   },
 *   dials: array{variance: float, density: float, motion: float},
 *   guidance: array{dos: list<string>, donts: list<string>},
 *   token_sources: array{colors: string, typography: string, spacing: string, rounded: string, components: string, dials: string, layout: string, scale: string},
 *   readiness: array{ready: bool, sync_ready: bool, errors: list<string>, warnings: list<string>},
 *   waivers: list<string>
 * }
 */
function inspect(string $content): array
{
    $all_tokens = Tokens\extract($content);
    $sources = token_sources($content, $all_tokens);
    $readiness = readiness($all_tokens, $sources);

    return [
        'tokens' => [
            'colors' => $all_tokens['colors'],
            'typography' => $all_tokens['typography'],
            'spacing' => $all_tokens['spacing'],
            'rounded' => $all_tokens['rounded'],
            'components' => $all_tokens['components'],
            'layout' => $all_tokens['layout'] ?? [],
        ],
        'dials' => Tokens\dials($all_tokens),
        'guidance' => guidance($content),
        'token_sources' => $sources,
        'readiness' => $readiness,
        // Anti-slop rules this design waives, so the agent knows what is
        // approved house style and will not be flagged by the pre-flight.
        'waivers' => Preflight\waivers($content),
    ];
}

/**
 * Semantic activation gate. Missing optional craft tokens stay warnings so
 * imported real-world DESIGN.md files remain usable.
 *
 * @param array{
 *   colors: array<string, string>,
 *   typography: array<string, array<string, string>>,
 *   spacing: array<string, string>,
 *   rounded: array<string, string>,
 *   components: array<string, string>,
 *   dials: array<string, string>
 * } $tokens
 * @param array{colors: string, typography: string, spacing: string, rounded: string, components: string, dials: string, scale: string} $sources
 * @return array{ready: bool, sync_ready: bool, errors: list<string>, warnings: list<string>}
 */
function readiness(array $tokens, array $sources): array
{
    $errors = required_token_errors($tokens);
    $warnings = readiness_warnings($sources);
    $ready = $errors === [];
    return [
        'ready' => $ready,
        'sync_ready' => $ready && $sources['colors'] === 'explicit' && $sources['typography'] === 'explicit',
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

/**
 * @param array{
 *   colors: array<string, string>,
 *   typography: array<string, array<string, string>>,
 *   spacing: array<string, string>,
 *   rounded: array<string, string>,
 *   components: array<string, string>,
 *   dials: array<string, string>
 * } $tokens
 * @return list<string>
 */
function required_token_errors(array $tokens): array
{
    $errors = [];
    $vars = Tokens\css_vars($tokens);

    foreach ([
        '--wppilot-bg' => __('A background color is required.', domain: 'wppilot'),
        '--wppilot-ink' => __('An ink/text color is required.', domain: 'wppilot'),
        '--wppilot-accent' => __('An accent color is required.', domain: 'wppilot'),
    ] as $property => $missing_message) {
        $value = $vars[$property] ?? '';
        if ($value === '') {
            $errors[] = $missing_message;
            continue;
        }
        if (Preflight\normalize_hex($value) === '') {
            $errors[] = sprintf(
                /* translators: %s: CSS custom property identifying the color role */
                __('The color for %s must be an exact hex value.', domain: 'wppilot'),
                $property,
            );
        }
    }

    if (($vars['--wppilot-font-heading'] ?? '') === '') {
        $errors[] = __('A heading/display font is required.', domain: 'wppilot');
    }
    if (($vars['--wppilot-font-body'] ?? '') === '') {
        $errors[] = __('A body font is required.', domain: 'wppilot');
    }
    return $errors;
}

/**
 * @param array{colors: string, typography: string, spacing: string, rounded: string, components: string, dials: string, scale: string} $sources
 * @return list<string>
 */
function readiness_warnings(array $sources): array
{
    $warnings = [];

    foreach ([
        'colors' => __(
            'Colors were inferred from prose; make the roles explicit before syncing site globals.',
            domain: 'wppilot',
        ),
        'typography' => __(
            'Typography was inferred from prose; make the roles explicit before syncing site globals.',
            domain: 'wppilot',
        ),
    ] as $key => $message) {
        if ($sources[$key] !== 'inferred') {
            continue;
        }
        $warnings[] = $message;
    }
    if ($sources['spacing'] === 'missing') {
        $warnings[] = __('No spacing tokens are declared.', domain: 'wppilot');
    }
    if ($sources['scale'] === 'partial') {
        $warnings[] = __(
            'The type scale declares sizes but no line height. Large type at the browser default leading is the most recognisable untouched-web look there is; set lineHeight on at least the heading and body roles.',
            domain: 'wppilot',
        );
    }
    if ($sources['scale'] === 'missing') {
        $warnings[] = __(
            'No type scale is declared, so each page will pick its own heading and body sizes. Declare fontSize and lineHeight on the heading and body roles to keep pages consistent with one another.',
            domain: 'wppilot',
        );
    }
    if ($sources['rounded'] === 'missing') {
        $warnings[] = __('No corner-radius tokens are declared.', domain: 'wppilot');
    }
    if ($sources['components'] === 'missing') {
        $warnings[] = __('No component treatments are declared.', domain: 'wppilot');
    }
    if ($sources['dials'] === 'missing') {
        $warnings[] = __('No compositional dials are declared; defaults will be used.', domain: 'wppilot');
    }

    return $warnings;
}

/**
 * Say whether each token family was declared, inferred from prose, or absent.
 *
 * @param array{
 *   colors: array<string, string>,
 *   typography: array<string, array<string, string>>,
 *   spacing: array<string, string>,
 *   rounded: array<string, string>,
 *   components: array<string, string>,
 *   dials: array<string, string>
 * } $tokens
 * @return array{colors: string, typography: string, spacing: string, rounded: string, components: string, dials: string, scale: string}
 */
function token_sources(string $content, array $tokens): array
{
    return [
        'colors' => token_source($content, key: 'colors', values: $tokens['colors']),
        'typography' => token_source($content, key: 'typography', values: $tokens['typography']),
        'spacing' => token_source($content, key: 'spacing', values: $tokens['spacing']),
        'rounded' => token_source($content, key: 'rounded', values: $tokens['rounded']),
        'components' => token_source($content, key: 'components', values: $tokens['components']),
        'dials' => token_source($content, key: 'dials', values: $tokens['dials']),
        'layout' => token_source($content, key: 'layout', values: $tokens['layout'] ?? []),
        'scale' => scale_source($tokens['typography']),
    ];
}

/**
 * Whether the design declares a type scale.
 *
 * Not a top-level key like the others: sizes and leading live inside the
 * typography roles, so this asks whether the roles that matter carry them
 * rather than whether a section exists.
 *
 * @param array<string, array<string, string>> $typography
 */
function scale_source(array $typography): string
{
    $sized = false;
    $led = false;
    foreach ($typography as $props) {
        if (!is_array($props)) {
            continue;
        }
        $sized = $sized || ($props['fontSize'] ?? '') !== '';
        $led = $led || ($props['lineHeight'] ?? '') !== '';
    }
    if (!$sized) {
        return 'missing';
    }

    // Sizes without leading is the half that causes the damage: big type at a
    // browser-default line-height is the most recognisable untouched-web look
    // there is, so it is reported as partial rather than as present.
    return $led ? 'explicit' : 'partial';
}

/** @param array<array-key, mixed> $values */
function token_source(string $content, string $key, array $values): string
{
    if ($values === []) {
        return 'missing';
    }
    return has_top_level_key($content, $key) ? 'explicit' : 'inferred';
}

function has_top_level_key(string $content, string $wanted): bool
{
    foreach (Tokens\front_matter_lines($content) as $line) {
        if ($line === '' || $line[0] === ' ' || $line[0] === "\t") {
            continue;
        }
        $colon = strpos($line, needle: ':');
        if ($colon === false) {
            continue;
        }
        $key = strtolower(Tokens\unquote(trim(substr($line, offset: 0, length: $colon))));
        if ($key === strtolower($wanted)) {
            return true;
        }
    }
    return false;
}

/**
 * Plain-text Do/Don't guidance for machine consumers.
 *
 * @return array{dos: list<string>, donts: list<string>}
 */
function guidance(string $content): array
{
    $parsed = Markdown\guidance($content, [
        "do's and don'ts",
        "dos and don'ts",
        "do's & don'ts",
        "dos & don'ts",
        "do and don't",
        'guidelines',
        'principles',
        'rules',
    ]);

    // The Don'ts come from the gate's own reader rather than from the section
    // titles above. Those titles never included a section called plainly
    // "Don't", which is the heading the shipped examples and the documented
    // format actually use, so the contract reported a design as declaring no
    // Don'ts while the gate was enforcing six of them and quoting them back in
    // its refusals. Two readers of one document disagreeing is worse than
    // either being wrong, and the gate's answer is the one that has teeth, so
    // it is the one reported.
    $donts = plain_items($parsed['donts']);
    foreach (Preflight\extract_donts($content) as $dont) {
        if (!in_array($dont, $donts, strict: true)) {
            $donts[] = $dont;
        }
    }

    return [
        'dos' => plain_items($parsed['dos']),
        'donts' => $donts,
    ];
}

/** @param list<string> $items
 * @return list<string>
 */
function plain_items(array $items): array
{
    return array_values(array_map(static fn(string $item): string => trim(html_entity_decode(
        wp_strip_all_tags($item),
        ENT_QUOTES | ENT_HTML5,
        encoding: 'UTF-8',
    )), $items));
}

/** Human-readable reason suitable for an activation error. */
function activation_error(array $inspection): string
{
    /** @var list<string> $errors */
    $errors = $inspection['readiness']['errors'] ?? [];
    return $errors !== []
        ? implode(' ', $errors)
        : __('The design is incomplete and cannot be activated.', domain: 'wppilot');
}
