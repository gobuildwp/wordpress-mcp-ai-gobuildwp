<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Gate;

use WP_Ability;
use WP_Error;
use WPPilot\Design\Library;
use WPPilot\Design\Preflight;
use WPPilot\Design\Store;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Check agent writes against the active design, before they land.
 *
 * `wppilot/check-design` has always been able to judge a candidate page, but an
 * agent has to remember to call it, and the one that drifts on page six of a
 * build is exactly the one that will not. This runs on the write path itself,
 * beside the rate limiter (priority 6), the preview gate (8) and Pro's approval
 * queue (9), so drift is caught whether or not anybody asked.
 *
 * WHAT IT CHECKS, AND WHY ONLY THAT
 *
 * Three rules, all of them design-conditional: a colour outside the palette, a
 * font outside the type stack, and the design's own "Don't" lines. Every one is
 * objective, because it compares the write against something the site owner
 * wrote down. The universal anti-slop rules — filler copy, generic names,
 * em-dash, AI-purple, Inter — stay in `check-design`, where an agent is asking
 * for a judgement. Refusing a write because a paragraph says "seamless" is
 * taste, it fires on legitimate content nobody generated, and a gate that cries
 * wolf is a gate a site owner switches off.
 *
 * A site with no active design is not gated at all. There is nothing to compare
 * against, and inventing a house style to enforce would be the same averaging
 * mistake the design system exists to prevent.
 *
 * BUILDER-AGNOSTIC BY CONSTRUCTION
 *
 * The rules read colour and font literals out of the JSON-encoded input rather
 * than understanding any builder's schema. That is deliberate: one
 * implementation covers Elementor settings, Bricks element trees, Flatsome
 * shortcode attributes, Gutenberg block specs and raw HTML nodes, and it keeps
 * working for the next builder without a line of new parsing. It cannot see a
 * colour the write only refers to — a global token id, a CSS variable — which
 * is the correct blind spot: referring to the site's own tokens is what we want
 * agents to do, and Phase 3's materialisation makes that the easy path.
 */

const OPTION = 'wppilot_design_gate_mode';

const MODE_OFF = 'off';

const MODE_WARN = 'warn';

const MODE_ENFORCE = 'enforce';

/** Rule ids this gate is willing to act on. */
const GATED_RULES = ['color-off-palette', 'font-off-palette', 'design-dont'];

/**
 * Abilities whose input carries colour and font strings that are not design.
 *
 * Saving a design is the obvious one: a DESIGN.md declaring the palette would
 * be measured against the palette it is declaring. The developer surface is
 * here because a stylesheet edit or a PHP snippet is not a page, and the
 * profile that allows those at all is not the profile this gate is for.
 */
const EXEMPT_PREFIXES = [
    'wppilot/save-design',
    'wppilot/activate-design',
    'wppilot/delete-design',
    'wppilot/check-design',
    'wppilot/preview-ability',
    'wppilot/apply-preview',
    'wppilot/execute-php',
    'wppilot/run-wp-cli',
    'wppilot/write-file',
    'wppilot/edit-file',
    'wppilot/read-file',
    'wppilot/delete-file',
    'wppilot/rollback-change',
    'wppilot/undo-session',
    'wppilot/redo-session',
];

/** The configured mode, defaulting to off. */
function mode(): string
{
    /** @var mixed $value */
    $value = get_option(OPTION, default_value: MODE_OFF);
    $value = is_string($value) ? $value : MODE_OFF;

    return in_array($value, [MODE_OFF, MODE_WARN, MODE_ENFORCE], strict: true) ? $value : MODE_OFF;
}

/**
 * Findings for the ability currently executing, so the change ledger can record
 * what the gate saw even when it let the write through.
 *
 * @param  list<array<string, mixed>>|null $set
 * @return list<array<string, mixed>>
 */
function pending_findings(?array $set = null, bool $clear = false): array
{
    static $findings = [];
    if ($clear) {
        $findings = [];

        return [];
    }
    if ($set !== null) {
        $findings = $set;
    }

    return $findings;
}

/**
 * Decide whether a write may proceed.
 *
 * Returns null to allow. In warn mode it always returns null, having recorded
 * what it found for the ledger.
 *
 * @param array<string, mixed>|mixed $input
 */
function check(WP_Ability $ability, mixed $input): ?WP_Error
{
    pending_findings(clear: true);

    $mode = mode();
    if ($mode === MODE_OFF) {
        return null;
    }
    if (!is_array($input) || $input === []) {
        return null;
    }
    $name = $ability->get_name();
    foreach (EXEMPT_PREFIXES as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return null;
        }
    }
    if (function_exists('wppilot_ability_is_readonly') && \wppilot_ability_is_readonly($ability)) {
        return null;
    }

    $slug = Store\get_active_slug();
    if ($slug === '') {
        return null;
    }
    $record = Library\find($slug);
    if ($record === null) {
        return null;
    }
    $ctx = Preflight\context($record['content']);
    if (!$ctx['has_active']) {
        return null;
    }

    $candidate = candidate_string($input);
    if ($candidate === '') {
        return null;
    }

    $violations = [];
    foreach (Preflight\violations($candidate, $ctx) as $violation) {
        if (in_array($violation['rule'], GATED_RULES, strict: true)) {
            $violations[] = $violation;
        }
    }
    if ($violations === []) {
        return null;
    }

    $violations = annotate($violations, $ctx);
    pending_findings([
        // The ability these belong to. An MCP write is recorded twice — once for
        // the execute-ability meta-tool the transport calls, once for the real
        // ability underneath — and without this the same drift is attached to
        // both rows, which reads as two violations where there was one.
        'ability' => $name,
        'design' => $slug,
        'mode' => $mode,
        'violations' => $violations,
    ]);

    if ($mode !== MODE_ENFORCE) {
        return null;
    }

    return new WP_Error(
        'wppilot_design_off_direction',
        sprintf(
            /* translators: 1: design slug, 2: the violation list. */
            __(
                'This write does not match the site\'s active design "%1$s". %2$s Use the design\'s own tokens, or call wppilot/get-active-design to read them. If the value is deliberate, add it to the design or record an allowance in its DESIGN.md rather than working around this check.',
                domain: 'wppilot',
            ),
            $slug,
            summarize($violations),
        ),
        ['status' => 409, 'ability' => $name, 'design' => $slug, 'violations' => $violations],
    );
}

/**
 * Flatten an ability input into something the rules can read.
 *
 * A recursive walk collecting scalars, not a JSON encode. JSON was the obvious
 * first choice and it is wrong here: it escapes the quotes inside an HTML
 * attribute, so `font-family:Fraunces"` arrives at the font rule as
 * `fraunces\` and matches nothing in the palette. Every declared font then
 * reads as a violation, which is the worst possible failure for a gate — it
 * fires on correct work.
 *
 * Keys are collected alongside values because a builder can carry a colour in
 * either, and both are joined with newlines so a value cannot run into its
 * neighbour and invent a token that was never written.
 *
 * @param array<string, mixed> $input
 */
function candidate_string(array $input): string
{
    $parts = [];
    collect_scalars($input, $parts);

    return normalize_color_functions(implode("\n", $parts));
}

/**
 * @param mixed        $value
 * @param list<string> $parts
 */
function collect_scalars(mixed $value, array &$parts, int $depth = 0): void
{
    if ($depth > 12) {
        return;
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $parts[] = $key;
            }
            collect_scalars($item, $parts, $depth + 1);
        }

        return;
    }
    if (is_string($value)) {
        $parts[] = $value;

        return;
    }
    if (is_int($value) || is_float($value) || is_bool($value)) {
        $parts[] = (string) $value;
    }
}

/**
 * Rewrite rgb()/rgba()/hsl()/hsla() colours as hex.
 *
 * The palette rules compare hex, and builders do not agree on notation:
 * Flatsome writes `bg_color="rgb(124, 58, 237)"`, Elementor stores hex, a
 * hand-written style block may use either. Without this the same purple is a
 * violation in one builder and invisible in another, which would make the gate
 * look arbitrary to anyone using more than one.
 */
function normalize_color_functions(string $candidate): string
{
    $candidate = preg_replace_callback(
        '/rgba?\(\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})\s*(?:[,\/][^)]*)?\)/i',
        static function (array $m): string {
            foreach ([1, 2, 3] as $index) {
                if ((int) $m[$index] > 255) {
                    return $m[0];
                }
            }

            return sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
        },
        $candidate,
    ) ?? $candidate;

    return preg_replace_callback(
        '/hsla?\(\s*(-?[\d.]+)\s*(?:deg)?\s*[,\s]\s*([\d.]+)%\s*[,\s]\s*([\d.]+)%\s*(?:[,\/][^)]*)?\)/i',
        static fn(array $m): string => hsl_to_hex((float) $m[1], (float) $m[2], (float) $m[3]),
        $candidate,
    ) ?? $candidate;
}

/** Inverse of Preflight\hex_to_hsl(), for normalising authored hsl() values. */
function hsl_to_hex(float $hue, float $saturation, float $lightness): string
{
    $hue = fmod(fmod($hue, 360.0) + 360.0, 360.0);
    $saturation = max(0.0, min(1.0, $saturation / 100));
    $lightness = max(0.0, min(1.0, $lightness / 100));

    $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
    $second = $chroma * (1 - abs(fmod($hue / 60, 2.0) - 1));
    $match = $lightness - $chroma / 2;

    $sextant = (int) floor($hue / 60);
    [$r, $g, $b] = match ($sextant) {
        0 => [$chroma, $second, 0.0],
        1 => [$second, $chroma, 0.0],
        2 => [0.0, $chroma, $second],
        3 => [0.0, $second, $chroma],
        4 => [$second, 0.0, $chroma],
        default => [$chroma, 0.0, $second],
    };

    return sprintf(
        '#%02x%02x%02x',
        (int) round(($r + $match) * 255),
        (int) round(($g + $match) * 255),
        (int) round(($b + $match) * 255),
    );
}

/**
 * Attach the nearest palette entry to an off-palette colour finding.
 *
 * The difference between a refusal an agent can act on and one it has to guess
 * at. "#7c3aed is off-palette" invites another guess; "#7c3aed is off-palette,
 * nearest is #6d28d9" is a fix.
 *
 * @param  list<array<string, mixed>> $violations
 * @param  array<string, mixed>       $ctx
 * @return list<array<string, mixed>>
 */
function annotate(array $violations, array $ctx): array
{
    /** @var list<string> $palette */
    $palette = is_array($ctx['allowed_colors'] ?? null) ? $ctx['allowed_colors'] : [];
    $out = [];
    foreach ($violations as $violation) {
        if ($violation['rule'] === 'color-off-palette' && $palette !== []) {
            $suggestions = [];
            foreach (explode(',', (string) ($violation['evidence'] ?? '')) as $hex) {
                $hex = trim($hex);
                $nearest = nearest_color($hex, $palette);
                if ($nearest !== '') {
                    $suggestions[] = $hex . ' -> ' . $nearest;
                }
            }
            if ($suggestions !== []) {
                $violation['nearest'] = implode(', ', $suggestions);
            }
        }
        if ($violation['rule'] === 'font-off-palette') {
            /** @var list<string> $fonts */
            $fonts = is_array($ctx['allowed_fonts'] ?? null) ? $ctx['allowed_fonts'] : [];
            if ($fonts !== []) {
                $violation['allowed'] = implode(', ', array_slice($fonts, offset: 0, length: 6));
            }
        }
        $out[] = $violation;
    }

    return $out;
}

/**
 * The palette entry to suggest in place of an off-palette colour.
 *
 * Not nearest-by-distance, which is the obvious implementation and gives bad
 * advice. Measured in RGB, the closest palette entry to a vivid purple in a
 * warm editorial palette is the pale cream — numerically true, visually
 * useless, and an agent that took the suggestion would produce a worse page
 * than the one that was refused.
 *
 * Substitution is a question about role, not proximity. A saturated colour was
 * doing the accent's job; something near-black was text; something near-white
 * was a surface. Match the role first and fall back to distance only when the
 * colour is a genuine mid-tone that fits no role cleanly.
 *
 * @param list<string> $palette
 */
function nearest_color(string $hex, array $palette): string
{
    $normalized = Preflight\normalize_hex($hex);
    $target = rgb($normalized);
    if ($target === null || $palette === []) {
        return '';
    }
    // Chroma, not HSL saturation. HSL divides by a term that collapses toward
    // zero at the extremes, so #fffdf9 — a near-white with the faintest warm
    // tint — reports as fully saturated and would be answered with the accent.
    // Chroma is just max minus min, and stays honest at both ends.
    $lightness = (max($target) + min($target)) / 510;
    $chroma = (max($target) - min($target)) / 255;

    if ($lightness <= 0.22) {
        return extreme_by_lightness($palette, darkest: true);
    }
    if ($lightness >= 0.90) {
        return extreme_by_lightness($palette, darkest: false);
    }
    if ($chroma >= 0.25) {
        $accent = most_chromatic($palette);
        if ($accent !== '') {
            return $accent;
        }
    }

    return nearest_by_distance($target, $palette);
}

/**
 * Closest entry by weighted RGB distance ("redmean"), for mid-tones with no
 * obvious role. Cheap, and markedly closer to human judgement than plain RGB.
 *
 * @param array{0: int, 1: int, 2: int} $target
 * @param list<string>                  $palette
 */
function nearest_by_distance(array $target, array $palette): string
{
    $best = '';
    $best_distance = INF;
    foreach ($palette as $candidate) {
        $rgb = rgb($candidate);
        if ($rgb === null) {
            continue;
        }
        $mean = ($rgb[0] + $target[0]) / 2;
        $dr = $rgb[0] - $target[0];
        $dg = $rgb[1] - $target[1];
        $db = $rgb[2] - $target[2];
        $distance = (2 + $mean / 256) * $dr ** 2 + 4 * $dg ** 2 + (2 + (255 - $mean) / 256) * $db ** 2;
        if ($distance < $best_distance) {
            $best_distance = $distance;
            $best = $candidate;
        }
    }

    return $best;
}

/** @param list<string> $palette */
function extreme_by_lightness(array $palette, bool $darkest): string
{
    $best = '';
    $best_lightness = $darkest ? INF : -INF;
    foreach ($palette as $candidate) {
        $rgb = rgb($candidate);
        if ($rgb === null) {
            continue;
        }
        $lightness = (max($rgb) + min($rgb)) / 510;
        if ($darkest ? $lightness < $best_lightness : $lightness > $best_lightness) {
            $best_lightness = $lightness;
            $best = $candidate;
        }
    }

    return $best;
}

/**
 * The palette's most colourful entry: whatever plays the accent role.
 *
 * @param list<string> $palette
 */
function most_chromatic(array $palette): string
{
    $best = '';
    $best_chroma = -INF;
    foreach ($palette as $candidate) {
        $rgb = rgb($candidate);
        if ($rgb === null) {
            continue;
        }
        $chroma = (max($rgb) - min($rgb)) / 255;
        if ($chroma > $best_chroma) {
            $best_chroma = $chroma;
            $best = $candidate;
        }
    }

    return $best;
}

/** @return array{0: int, 1: int, 2: int}|null */
function rgb(string $hex): ?array
{
    $hex = ltrim(Preflight\normalize_hex($hex), characters: '#');
    if (strlen($hex) !== 6 || preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
        return null;
    }

    return [
        (int) hexdec(substr($hex, offset: 0, length: 2)),
        (int) hexdec(substr($hex, offset: 2, length: 2)),
        (int) hexdec(substr($hex, offset: 4, length: 2)),
    ];
}

/**
 * One sentence naming every violation, for the refusal message.
 *
 * @param list<array<string, mixed>> $violations
 */
function summarize(array $violations): string
{
    $parts = [];
    foreach ($violations as $violation) {
        $line = (string) $violation['message'];
        $evidence = (string) ($violation['evidence'] ?? '');
        if ($evidence !== '') {
            $line .= ' (' . $evidence . ')';
        }
        if (isset($violation['nearest'])) {
            $line .= ' Nearest in palette: ' . (string) $violation['nearest'] . '.';
        }
        if (isset($violation['allowed'])) {
            $line .= ' The design allows: ' . (string) $violation['allowed'] . '.';
        }
        $parts[] = $line;
    }

    return implode(' ', $parts);
}

/**
 * REST shim filter. Returning a WP_Error short-circuits execution.
 *
 * @param  mixed $input
 * @return mixed
 */
function filter_pre_ability_execute(mixed $input, WP_Ability $ability, string $transport): mixed
{
    $error = check($ability, $input);

    return $error ?? $input;
}

/**
 * Legacy MCP adapter filter, which routes every call through the
 * execute-ability meta-tool rather than the ability's own name.
 *
 * @param  mixed $args
 * @return mixed
 */
function filter_pre_mcp_tool_call(mixed $args, string $tool_name): mixed
{
    if ($tool_name !== 'mcp-adapter-execute-ability' || !is_array($args)) {
        return $args;
    }
    $ability_name = (string) ($args['ability_name'] ?? '');
    if ($ability_name === '' || !function_exists('wp_get_ability')) {
        return $args;
    }
    $ability = \wp_get_ability($ability_name);
    if (!$ability instanceof WP_Ability) {
        return $args;
    }
    /** @var array<string, mixed> $parameters */
    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    $error = check($ability, $parameters);

    return $error ?? $args;
}

/**
 * Contribute the mode selector to the Settings screen.
 *
 * @param  mixed $sections
 * @return mixed
 */
function register_setting(mixed $sections): mixed
{
    if (!is_array($sections)) {
        return $sections;
    }
    $current = mode();

    $sections[] = [
        'id' => 'wppilot-design-gate',
        'title' => __('Design direction on writes', domain: 'wppilot'),
        'description' => __(
            'Check agent writes against the active design before they land. Does nothing until a design is active.',
            domain: 'wppilot',
        ),
        'fields' => [
            [
                'type' => 'select',
                'name' => OPTION,
                'label' => __('When a write uses a colour or font the design does not list', domain: 'wppilot'),
                'help' => __(
                    'Warn records the drift in the change ledger and lets the write through. Enforce refuses it and tells the agent which token to use instead. Only the palette, the type stack and the design\'s own Don\'t rules are checked here; the copy and anti-slop rules stay in wppilot/check-design, where an agent asks for a judgement rather than being blocked by one.',
                    domain: 'wppilot',
                ),
                'value' => $current,
                'options' => [
                    MODE_OFF => __('Off', domain: 'wppilot'),
                    MODE_WARN => __('Warn and record', domain: 'wppilot'),
                    MODE_ENFORCE => __('Refuse the write', domain: 'wppilot'),
                ],
                'state' => $current === MODE_ENFORCE ? 'armed' : 'ready',
            ],
        ],
        'save' => static function (array $post): void {
            $value = is_string($post[OPTION] ?? null) ? $post[OPTION] : MODE_OFF;
            if (!in_array($value, [MODE_OFF, MODE_WARN, MODE_ENFORCE], strict: true)) {
                $value = MODE_OFF;
            }
            update_option(OPTION, $value, autoload: true);
        },
    ];

    return $sections;
}
