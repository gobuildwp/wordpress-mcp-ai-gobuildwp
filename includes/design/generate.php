<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Generate;

use WP_Error;
use WPPilot\Design\Contrast;
use WPPilot\Design\Gate;
use WPPilot\Design\Grammars;
use WPPilot\Design\Seed;
use WPPilot\Design\Typefaces;

/**
 * Resolve a design brief into a design.
 *
 * A design document names fixed values: this hex, that face, 56px. That is the
 * right shape for a brand somebody decided, and the wrong shape for the job of
 * producing a hundred sites that are each their own thing. Fixed values can
 * only be reused, and a reused design is a template however good it was the
 * first time.
 *
 * So a brief names intent and ranges instead — a hue window, a contrast floor,
 * a scale ratio band, how adventurous the compositions may be — and this
 * resolves them against the site's seed. Same brief on two sites gives two
 * designs; same brief on one site gives the same design however many times it
 * runs. That is the difference between a system and a preset, and it is the
 * only way to have consistency and variation at once.
 *
 * Nothing here is random. Every value is derived, which is what makes the
 * result reproducible, auditable, and re-derivable after an edit rather than a
 * lucky roll nobody can get back.
 *
 * What is deliberately not derived: anything the brief states outright. A brand
 * with a real colour says so and it is used verbatim. Deriving is for the
 * decisions nobody has made, not for overriding the ones they have.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Moods a brief can ask for, and the hue windows each one means.
 *
 * Named rather than free-form because "trustworthy" is a thing a client says
 * and 210-250 is not. The windows overlap on purpose: two briefs asking for
 * neighbouring moods should be able to land in the same region and still differ by
 * everything else, since hue is the least of what separates two designs.
 *
 * @return array<string, array{0: float, 1: float}>
 */
function moods(): array
{
    return [
        'trustworthy' => [200.0, 250.0],
        'calm' => [150.0, 210.0],
        'natural' => [70.0, 150.0],
        'warm' => [15.0, 55.0],
        'urgent' => [355.0, 400.0],
        'premium' => [255.0, 300.0],
        'technical' => [180.0, 220.0],
        'editorial' => [20.0, 45.0],
    ];
}

/**
 * Ground treatments a design can take.
 *
 * @return list<string>
 */
function grounds(): array
{
    return ['paper', 'ink', 'tinted'];
}

/**
 * Resolve a brief into concrete tokens.
 *
 * @param  array<string, mixed> $brief
 * @return array<string, mixed>|WP_Error
 */
function resolve(array $brief): array|WP_Error
{
    $name = trim((string) ($brief['name'] ?? ''));
    if ($name === '') {
        return new WP_Error(
            'wppilot_brief_name',
            __('A brief needs a name: it seeds every derived value, so two designs on one site diverge.', domain: 'wppilot'),
            ['status' => 422],
        );
    }

    $mood = strtolower(trim((string) ($brief['mood'] ?? 'trustworthy')));
    $known = moods();
    if (!array_key_exists($mood, $known)) {
        return new WP_Error(
            'wppilot_brief_mood',
            sprintf(
                /* translators: 1: the mood asked for, 2: comma-separated valid moods */
                __('"%1$s" is not a mood this can resolve. Use one of: %2$s.', domain: 'wppilot'),
                $mood,
                implode(', ', array_keys($known)),
            ),
            ['status' => 422],
        );
    }

    $seed = Seed\of($name);
    $palette = resolve_palette($seed, $known[$mood], $brief);
    if ($palette instanceof WP_Error) {
        return $palette;
    }

    $type = resolve_type($seed, $brief);
    if ($type instanceof WP_Error) {
        return $type;
    }

    return [
        'name' => $name,
        'mood' => $mood,
        'seed' => $seed,
        'colors' => $palette['colors'],
        'ground' => $palette['ground'],
        'typography' => $type['roles'],
        'faces' => ['display' => $type['display'], 'body' => $type['body']],
        'scale_ratio' => $type['ratio'],
        'rounded' => resolve_rounded($seed, $brief),
        'spacing' => resolve_spacing($seed, $brief),
        'dials' => resolve_dials($seed, $brief),
        'grammars' => resolve_grammars($seed, $brief),
    ];
}

/**
 * A palette from a hue window, checked rather than hoped.
 *
 * Colour is where a derived design most easily becomes an unusable one: a hue
 * picked at random and a lightness picked at random produce an accent nobody
 * can read half the time. So the hue is derived and the lightness is solved —
 * pushed until the pair actually passes, and refused outright if it cannot.
 * Variation belongs on the axes where being wrong is a matter of taste, never
 * on the one where being wrong means text nobody can read.
 *
 * @param  array{0: float, 1: float} $window
 * @param  array<string, mixed>      $brief
 * @return array{colors: array<string, string>, ground: string}|WP_Error
 */
function resolve_palette(string $seed, array $window, array $brief): array|WP_Error
{
    $hue = fmod(Seed\between($seed, 'hue', $window[0], $window[1], step: 5.0), 360.0);
    $ground = (string) ($brief['ground'] ?? '') !== ''
        ? (string) $brief['ground']
        : (string) Seed\pick($seed, 'ground', grounds());

    // A ground is never pure white or pure black. Both read as untouched
    // default, and the difference between paper and white is most of what
    // separates a page from a lightbox.
    $ground_hue = $ground === 'tinted' ? $hue : fmod($hue + 180.0, 360.0);
    $is_dark = $ground === 'ink';

    $bg = $is_dark
        ? Gate\hsl_to_hex($ground_hue, Seed\between($seed, 'bg-sat', 10.0, 28.0, 1.0), Seed\between($seed, 'bg-lit', 8.0, 14.0, 1.0))
        : Gate\hsl_to_hex($ground_hue, Seed\between($seed, 'bg-sat', 6.0, 20.0, 1.0), Seed\between($seed, 'bg-lit', 93.0, 97.0, 1.0));

    $ink = solve_contrast(
        $bg,
        $ground_hue,
        Seed\between($seed, 'ink-sat', 8.0, 30.0, 1.0),
        dark: !$is_dark,
        target: 12.0,
    );
    if ($ink === '') {
        return new WP_Error(
            'wppilot_brief_contrast',
            __('No readable ink could be solved for the ground this brief resolved to.', domain: 'wppilot'),
            ['status' => 500],
        );
    }

    // The accent carries a fill with ink on top of it, so it is solved against
    // the ink rather than against the ground: that is the pair a reader
    // actually has to resolve.
    $accent_hue = fmod($hue + Seed\between($seed, 'accent-shift', 90.0, 200.0, 10.0), 360.0);
    $accent = solve_accent(
        $ink,
        $bg,
        $accent_hue,
        Seed\between($seed, 'accent-sat', 55.0, 95.0, 5.0),
        Seed\between($seed, 'accent-lit', 42.0, 74.0, 4.0),
    );
    if ($accent === '') {
        return new WP_Error(
            'wppilot_brief_accent',
            __('No accent could be solved that carries ink at readable contrast.', domain: 'wppilot'),
            ['status' => 500],
        );
    }

    return [
        'ground' => $ground,
        'colors' => [
            'bg' => $bg,
            'ink' => $ink,
            'accent' => $accent,
            'muted' => Gate\hsl_to_hex($ground_hue, 10.0, $is_dark ? 62.0 : 38.0),
            'rule' => Gate\hsl_to_hex($ground_hue, 8.0, $is_dark ? 20.0 : 88.0),
        ],
    ];
}

/**
 * An accent that is actually a colour.
 *
 * Solving the accent the way the ink is solved walks lightness from one end and
 * returns the first value clearing the target, which on a light ground means it
 * stops at the palest candidate every time: the first run produced five designs
 * whose accent was a near-white pastel. Technically readable, invisible as a
 * fill, and not an accent by any definition a designer would accept.
 *
 * So the search starts at a mid lightness and steps outward, with two
 * conditions rather than one. Ink on the accent has to be readable, because the
 * accent carries a fill with ink on top of it. And the accent has to sit clearly
 * apart from the ground, because a fill the same value as the page behind it is
 * not a fill. Meeting only the first condition is exactly how a pastel passes.
 */
function solve_accent(string $ink, string $bg, float $hue, float $saturation, float $centre): string
{
    $offsets = [0.0];
    for ($step = 1; $step <= 12; $step++) {
        $offsets[] = $step * 4.0;
        $offsets[] = -$step * 4.0;
    }

    foreach ($offsets as $offset) {
        $lightness = $centre + $offset;
        if ($lightness < 24.0 || $lightness > 82.0) {
            continue;
        }
        $candidate = Gate\hsl_to_hex($hue, $saturation, $lightness);
        $on_ink = (float) (Contrast\ratio($ink, $candidate) ?? 0.0);
        $on_bg = (float) (Contrast\ratio($candidate, $bg) ?? 0.0);
        if ($on_ink >= 4.5 && $on_bg >= 1.4) {
            return $candidate;
        }
    }

    return '';
}

/**
 * Walk lightness until a colour clears a contrast target against a partner.
 *
 * Answers an empty string when the whole range fails, so the caller refuses
 * rather than shipping a pair that does not work.
 */
function solve_contrast(string $against, float $hue, float $saturation, bool $dark, float $target): string
{
    $steps = range(0, 20);
    foreach ($steps as $step) {
        $lightness = $dark
            ? 10.0 + ($step * 2.0)
            : 92.0 - ($step * 2.0);
        $candidate = Gate\hsl_to_hex($hue, $saturation, max(4.0, min(96.0, $lightness)));
        if ((float) (Contrast\ratio($candidate, $against) ?? 0.0) >= $target) {
            return $candidate;
        }
    }

    return '';
}

/**
 * A type pairing and a scale, derived and then checked by the pairing rule.
 *
 * The seed proposes and the rule disposes: a derived pairing that the rule
 * refuses is re-drawn rather than shipped, so deriving cannot produce two faces
 * of the same class or a display face set as body copy. Variation and taste are
 * not the same axis, and this is where they meet.
 *
 * @param  array<string, mixed> $brief
 * @return array{display: string, body: string, ratio: float, roles: array<string, array<string, mixed>>}|WP_Error
 */
function resolve_type(string $seed, array $brief): array|WP_Error
{
    $all = array_keys(Typefaces\all());
    $display_pool = [];
    foreach ($all as $family) {
        $face = Typefaces\get((string) $family);
        if ($face === null || ($face['overused'] ?? false) === true) {
            continue;
        }
        if (in_array('display', (array) $face['roles'], strict: true)) {
            $display_pool[] = (string) $family;
        }
    }

    $display_pool = Seed\shuffle_list($seed, 'display-face', $display_pool);
    $display = '';
    $body = '';
    foreach ($display_pool as $candidate) {
        $partners = Typefaces\partners($candidate, 'body');
        if ($partners === []) {
            continue;
        }
        $names = array_map(static fn(array $f): string => (string) $f['family'], $partners);
        $chosen = (string) Seed\pick($seed, 'body-face:' . $candidate, $names);
        if ($chosen === '') {
            continue;
        }
        $display = $candidate;
        $body = $chosen;
        break;
    }

    if ($display === '' || $body === '') {
        return new WP_Error(
            'wppilot_brief_pairing',
            __('No sound type pairing could be derived from the available faces.', domain: 'wppilot'),
            ['status' => 500],
        );
    }

    // The ratio is the whole scale. A design that steps 1.2 reads as a
    // document and one that steps 1.7 reads as a poster, and that single number
    // changes a page more than the palette does.
    $ratio = Seed\between($seed, 'scale-ratio', 1.25, 1.75, 0.05);
    $body_size = Seed\between($seed, 'body-size', 17.0, 21.0, 1.0);
    $weight = (string) Seed\pick($seed, 'display-weight', ['700', '800', '900']);
    $tracking = -1 * Seed\between($seed, 'display-tracking', 0.01, 0.05, 0.005);
    $leading = Seed\between($seed, 'display-leading', 0.92, 1.08, 0.02);

    $body_leading = Seed\between($seed, 'body-leading', 1.5, 1.7, 0.05);

    // The whole ladder, not three rungs of it.
    //
    // This emitted `heading`, `section` and `body`, which is enough to write a
    // DESIGN.md and not enough to be checked against: every rule that asks
    // whether a size is on the scale needs the scale to have more than three
    // steps in it, and a spec needs all eight roles before a page can be built
    // from one. Three declared sizes meant a page could use nine and still be
    // reported as restrained, because there was almost nothing to be off.
    //
    // The rungs are powers of the one ratio rather than eight independent
    // draws. That is what makes a scale read as a scale: the jumps are the same
    // shape all the way up, so a heading two steps above another looks two
    // steps above it rather than merely bigger.
    $step = static fn(float $power): float => round($body_size * ($ratio ** $power));

    $heading = static fn(float $power, float $leading_offset, float $tracking_scale): array => [
        'fontFamily' => $display,
        'fontWeight' => $weight,
        'fontSize' => $step($power) . 'px',
        'lineHeight' => (string) round($leading + $leading_offset, precision: 2),
        'letterSpacing' => round($tracking * $tracking_scale, precision: 4) . 'em',
    ];

    return [
        'display' => $display,
        'body' => $body,
        'ratio' => $ratio,
        'roles' => [
            // Tracking loosens as the size drops, because the negative tracking
            // that makes a hero look set makes body copy look broken.
            'display' => $heading(5.0, 0.0, 1.0),
            'h1' => $heading(4.0, 0.0, 1.0),
            'h2' => $heading(3.0, 0.06, 0.85),
            'h3' => $heading(2.0, 0.14, 0.6),
            'card' => $heading(1.0, 0.28, 0.45),
            'body' => [
                'fontFamily' => $body,
                'fontWeight' => '400',
                'fontSize' => $body_size . 'px',
                'lineHeight' => (string) $body_leading,
                'letterSpacing' => '0',
                'measure' => (string) Seed\between($seed, 'measure', 58.0, 72.0, 2.0),
            ],
            // Below body the ladder uses half steps. A full one would put small
            // text at two thirds of body, which stops being small and starts
            // being unreadable.
            'small' => [
                'fontFamily' => $body,
                'fontWeight' => '400',
                'fontSize' => $step(-0.5) . 'px',
                'lineHeight' => (string) round($body_leading - 0.1, precision: 2),
                'letterSpacing' => '0',
                'measure' => (string) Seed\between($seed, 'small-measure', 46.0, 56.0, 2.0),
            ],
            // A label is read as a mark rather than as a sentence, so it takes
            // weight instead of size and leads tight.
            'label' => [
                'fontFamily' => $body,
                'fontWeight' => '600',
                'fontSize' => $step(-1.0) . 'px',
                'lineHeight' => '1.2',
                'letterSpacing' => '0',
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed> $brief
 * @return array<string, string>
 */
function resolve_rounded(string $seed, array $brief): array
{
    // Corners cluster: a design is square, softened, or round, and mixing the
    // three at random is the tell that nobody decided. So one family is drawn
    // and the three sizes are stepped inside it.
    $family = (string) Seed\pick($seed, 'corner-family', ['square', 'soft', 'round']);
    $base = match ($family) {
        'square' => Seed\between($seed, 'corner', 0.0, 3.0, 1.0),
        'round' => Seed\between($seed, 'corner', 16.0, 32.0, 4.0),
        default => Seed\between($seed, 'corner', 4.0, 12.0, 2.0),
    };

    return [
        'sm' => $base . 'px',
        'md' => round($base * 1.6) . 'px',
        'lg' => round($base * 2.4) . 'px',
    ];
}

/**
 * @param  array<string, mixed> $brief
 * @return array<string, string>
 */
function resolve_spacing(string $seed, array $brief): array
{
    $unit = Seed\between($seed, 'space-unit', 6.0, 10.0, 1.0);
    $section = Seed\between($seed, 'space-section', 88.0, 160.0, 8.0);

    return [
        'sm' => $unit . 'px',
        'md' => round($unit * 3) . 'px',
        'lg' => $section . 'px',
    ];
}

/**
 * @param  array<string, mixed> $brief
 * @return array<string, float>
 */
function resolve_dials(string $seed, array $brief): array
{
    /** @var array<string, mixed> $bands */
    $bands = is_array($brief['dials'] ?? null) ? $brief['dials'] : [];

    $band = static function (string $key, float $min, float $max) use ($bands, $seed): float {
        /** @var mixed $declared */
        $declared = $bands[$key] ?? null;
        if (is_numeric($declared)) {
            return (float) $declared;
        }
        if (is_array($declared) && count($declared) === 2) {
            return Seed\between($seed, 'dial-' . $key, (float) $declared[0], (float) $declared[1], 0.05);
        }
        return Seed\between($seed, 'dial-' . $key, $min, $max, 0.05);
    };

    return [
        'variance' => $band('variance', 0.35, 0.9),
        'density' => $band('density', 0.3, 0.7),
        'motion' => $band('motion', 0.1, 0.5),
    ];
}

/**
 * The compositions this design permits, as a subset rather than all of them.
 *
 * A design that allows every grammar has not decided anything, and the chooser
 * then produces the same spread on every site. Taking a subset is what makes
 * two sites with the same variance still feel unalike: one is splits and
 * overlaps, the next is bleeds and sticky indexes.
 *
 * @param  array<string, mixed> $brief
 * @return list<string>
 */
function resolve_grammars(string $seed, array $brief): array
{
    $all = Grammars\names();
    $shuffled = Seed\shuffle_list($seed, 'grammar-set', $all);
    $count = (int) Seed\between($seed, 'grammar-count', 3.0, 5.0, 1.0);

    $picked = array_slice($shuffled, offset: 0, length: max(3, $count));
    // The baseline always survives. A page with no quiet section is as
    // monotonous as a page with nothing but quiet ones.
    if (!in_array('stacked-band', $picked, strict: true)) {
        $picked[] = 'stacked-band';
    }

    return array_values($picked);
}
