<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\GenerateDesign;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Contrast;
use WPPilot\Design\Generate;
use WPPilot\Design\Spec;
use WPPilot\Design\Typefaces;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/generate-design', [
        'label' => __('Generate Design', domain: 'wppilot'),
        'description' => __(
            'Resolve a brief into a complete design, derived from this site rather than chosen. Asked to pick, a language model tends toward the most familiar answer (Inter, three equal cards), so this does not ask the model to choose. A mood names a hue window, and the hue, the ground, the type pairing, the scale ratio, the corner family, the spacing and the permitted compositions are all derived from the site address and the design name. Two sites with the same brief get different designs; one site re-run gets the same design, because nothing here is random. Colour is solved rather than sampled: ink is pushed until it clears 12:1 on the ground and the accent until it carries ink at 7:1, and a brief that cannot be solved is refused rather than shipped unreadable. Anything the brief states outright is used verbatim, because deriving is for the decisions nobody has made. Returns the tokens and a ready DESIGN.md to review and save.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'What this design is called. It seeds every derived value, so two designs on one site diverge and the same name re-derives identically.',
                ],
                'mood' => [
                    'type' => 'string',
                    'enum' => array_keys(Generate\moods()),
                    'description' => 'The register the brand should read in. Names a hue window, not a colour.',
                ],
                'ground' => [
                    'type' => 'string',
                    'enum' => ['paper', 'ink', 'tinted'],
                    'description' => 'Force the ground treatment instead of deriving it. Omit unless the brand requires one.',
                ],
                'dials' => [
                    'type' => 'object',
                    'description' => 'Override a dial with a number, or bound it with a two-element range, e.g. {"variance": [0.6, 0.9]}. Omitted dials derive from the seed.',
                ],
                'business' => [
                    'type' => 'string',
                    'description' => 'What the business actually is, used only in the written reasoning so the document reads as a decision rather than as output.',
                ],
            ],
            'required' => ['name'],
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $resolved = Generate\resolve($input);
            if ($resolved instanceof WP_Error) {
                return $resolved;
            }

            /** @var array<string, string> $colors */
            $colors = $resolved['colors'];

            return [
                'name' => $resolved['name'],
                'mood' => $resolved['mood'],
                'ground' => $resolved['ground'],
                'colors' => $colors,
                'faces' => $resolved['faces'],
                'scale_ratio' => $resolved['scale_ratio'],
                'typography' => $resolved['typography'],
                // The same scale in the shape a reproduction spec takes, so the
                // spec agrees with the design about every number instead of
                // being retyped from it. Forty numbers copied by hand is how a
                // spec ends up grading a page against sizes its own design
                // never declared.
                'spec_type' => Spec\from_tokens($resolved['typography']),
                'rounded' => $resolved['rounded'],
                'spacing' => $resolved['spacing'],
                'dials' => $resolved['dials'],
                'grammars' => $resolved['grammars'],
                'contrast' => [
                    'ink_on_bg' => Contrast\ratio($colors['ink'], $colors['bg']),
                    'ink_on_accent' => Contrast\ratio($colors['ink'], $colors['accent']),
                ],
                'pairing' => Typefaces\pairing(
                    (string) $resolved['faces']['display'],
                    (string) $resolved['faces']['body'],
                ),
                'design_markdown' => document($resolved, trim((string) ($input['business'] ?? ''))),
                'next' => __(
                    'Read it, change what the brand actually requires, then save with wppilot/save-design. A derived value you keep is still a decision; a derived value you cannot justify should be overridden rather than shipped. To make the design reproducible rather than merely written down, pass "spec_type" straight into wppilot/save-design-spec as the spec\'s type scale.',
                    domain: 'wppilot',
                ),
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
 * The DESIGN.md a resolved brief produces.
 *
 * Written out rather than returned as tokens alone, because the document is
 * where the reasoning lives and a design nobody can read the argument for is a
 * palette. The prose says what was derived and why the ranges are what they
 * are, so somebody overriding a value knows what they are overriding.
 *
 * @param array<string, mixed> $r
 */
function document(array $r, string $business): string
{
    /** @var array<string, string> $c */
    $c = $r['colors'];
    /** @var array<string, array<string, mixed>> $t */
    $t = $r['typography'];
    /** @var array<string, string> $rounded */
    $rounded = $r['rounded'];
    /** @var array<string, string> $spacing */
    $spacing = $r['spacing'];
    /** @var array<string, float> $dials */
    $dials = $r['dials'];
    /** @var list<string> $grammars */
    $grammars = $r['grammars'];

    $ratio = Contrast\ratio($c['ink'], $c['bg']);
    $accent_ratio = Contrast\ratio($c['ink'], $c['accent']);
    $subject = $business !== '' ? $business : (string) $r['name'];

    $front = "---\nname: {$r['name']}\ndescription: A {$r['mood']} direction derived for this site: a {$r['ground']} ground, "
        . "{$r['faces']['display']} over {$r['faces']['body']}, and one accent used as a fill rather than as text.\n"
        . "colors:\n";
    foreach ($c as $role => $hex) {
        $front .= "  {$role}: \"{$hex}\"\n";
    }
    $front .= "typography:\n";
    foreach ($t as $role => $props) {
        $front .= "  {$role}:\n";
        foreach ($props as $key => $value) {
            $front .= "    {$key}: \"{$value}\"\n";
        }
    }
    $front .= "spacing:\n";
    foreach ($spacing as $key => $value) {
        $front .= "  {$key}: \"{$value}\"\n";
    }
    $front .= "rounded:\n";
    foreach ($rounded as $key => $value) {
        $front .= "  {$key}: \"{$value}\"\n";
    }
    $front .= "components:\n"
        . "  button: \"Accent fill with ink text. One per view, on the single thing the page is asking for.\"\n"
        . "  card: \"A filled block with no border and no shadow. The fill is the edge.\"\n"
        . "  link: \"Underlined in ink at all times, accent on hover. Never colour alone.\"\n"
        . "layout:\n  grammars: \"" . implode(', ', $grammars) . "\"\n"
        . "dials:\n";
    foreach ($dials as $key => $value) {
        $front .= "  {$key}: " . round($value, precision: 2) . "\n";
    }
    $front .= "---\n\n";

    $ink_pct = number_format((float) ($ratio ?? 0), 1);
    $accent_pct = number_format((float) ($accent_ratio ?? 0), 1);
    $scale = number_format((float) $r['scale_ratio'], 2);

    return $front . <<<MD
        # {$r['name']}

        For {$subject}. The register is {$r['mood']}, and everything below was derived from this
        site rather than picked, which is the point: the same brief on another site resolves to a
        different design, and this site re-run resolves to this one.

        ## The reasoning

        The ground is {$r['ground']} at `{$c['bg']}`, and it is not white or black. Both of those
        read as a default nobody touched, and the difference between paper and white is most of
        what separates a page from a lightbox.

        The ink is `{$c['ink']}`, solved against the ground rather than chosen: it clears
        {$ink_pct}:1, so it carries the whole document and there is no second text colour.

        `{$c['accent']}` is the accent and it is a fill, not a foreground. Ink on it clears
        {$accent_pct}:1, which is why it can be as loud as it likes without the text suffering.
        Spend it three or four times a page. An accent that appears everywhere is a brand colour
        applied; one that appears once per screen is a decision.

        {$r['faces']['display']} over {$r['faces']['body']}. The pairing was drawn and then checked
        against the rule that refuses two faces of the same class and a display face set as body
        copy, so it contrasts on the axis that matters and reads as one publication rather than two.

        The scale steps at {$scale}. That single number changes a page more than the palette does:
        below 1.3 it reads as a document, above 1.6 as a poster.

        Sections are composed rather than stacked, from a subset of the available grammars rather
        than all of them. A design that permits everything has decided nothing, and two sites that
        permit everything end up with the same spread.

        ## Don't

        - Don't put body text on the accent. It is a fill that carries ink.
        - Don't add a second text colour. The ink carries the document.
        - Don't introduce a heading size between the two the scale declares.
        - Don't centre a paragraph. A heading may centre, running text never does.
        - Don't use three equal cards in a row. Give the set a shape.
        - Don't write a heading that survives a find-and-replace of the business name.
        MD;
}
