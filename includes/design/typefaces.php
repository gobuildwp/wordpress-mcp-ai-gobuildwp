<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Typefaces;

/**
 * A considered set of typefaces, and the rule for pairing two of them.
 *
 * Asked for a font, a model reaches for Inter, Poppins, Montserrat or Playfair
 * Display, because those are the faces the training data is full of. The
 * pre-flight already refuses Inter by name, which stops the single worst case
 * and does nothing about the other three — and a rule that only says no leaves
 * whatever comes next to the same habit that produced the first answer.
 *
 * So this is the other half: a set to choose from, with enough about each face
 * to choose on purpose. Every entry says what the face sounds like and what it
 * can carry, because "a serif" is not a decision and "a transitional serif with
 * enough weight to hold a headline and a large enough x-height to set at 16px"
 * is.
 *
 * Deliberately not a catalogue. Around forty faces, all from Google Fonts so
 * they load on any install without a licence conversation, spanning grotesque to
 * old-style to didone to mono. A picker with five hundred faces optimises for
 * looking comprehensive; this optimises for a defensible pairing being the
 * easiest thing to reach.
 *
 * The over-used faces are kept rather than omitted. A design that genuinely
 * wants Montserrat should be able to name it and be told what that costs, which
 * is a more useful answer than pretending the face does not exist.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The set.
 *
 * Each entry carries:
 *   classification  which of the classes above it belongs to
 *   voice           what the face sounds like, in one line
 *   roles           what it can carry: display, body, ui, mono
 *   superfamily     the family it was designed alongside, when it was
 *   overused        whether it is one of the faces every generated page reaches for
 *
 * @return array<string, array<string, mixed>>
 */
function all(): array
{
    return [
        // ------------------------------------------------------- sans, grotesque
        'Archivo' => [
            'classification' => 'grotesque',
            'voice' => 'A workhorse grotesque built for print and screen at once. Neutral without being bland, and the wide weight range means one family can hold a whole site.',
            'roles' => ['display', 'body', 'ui'],
            'superfamily' => 'Archivo',
            'overused' => false,
        ],
        'Archivo Black' => [
            'classification' => 'display',
            'voice' => 'Archivo at its heaviest. Made for headlines that have to shout; unreadable at paragraph size and not meant to be.',
            'roles' => ['display'],
            'superfamily' => 'Archivo',
            'overused' => false,
        ],
        'Archivo Narrow' => [
            'classification' => 'grotesque',
            'voice' => 'The condensed cut. Fits more words into a fixed measure without the compression reading as an accident.',
            'roles' => ['display', 'ui'],
            'superfamily' => 'Archivo',
            'overused' => false,
        ],
        'Public Sans' => [
            'classification' => 'neo-grotesque',
            'voice' => 'The US government\'s interface face. Plain in the way a form should be plain, and unshowy enough to disappear behind the content.',
            'roles' => ['body', 'ui'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Libre Franklin' => [
            'classification' => 'grotesque',
            'voice' => 'A revival of Franklin Gothic: American, journalistic, slightly warm. Reads as civic rather than corporate.',
            'roles' => ['display', 'body', 'ui'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Space Grotesk' => [
            'classification' => 'grotesque',
            'voice' => 'A grotesque with odd, drawn details that show up at size. Technical and a little strange, which is the point.',
            'roles' => ['display', 'ui'],
            'superfamily' => 'Space',
            'overused' => false,
        ],
        'Bricolage Grotesque' => [
            'classification' => 'display',
            'voice' => 'Deliberately uneven, with an optical size axis. Looks hand-set rather than specified, and is the fastest way off a default grid.',
            'roles' => ['display'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Inter' => [
            'classification' => 'neo-grotesque',
            'voice' => 'Excellent and everywhere. The single most recognisable sign that nobody chose a typeface.',
            'roles' => ['body', 'ui'],
            'superfamily' => 'Inter',
            'overused' => true,
        ],
        'Inter Tight' => [
            'classification' => 'neo-grotesque',
            'voice' => 'Inter with the air taken out. Tighter fit makes it work at display size where Inter looks loose.',
            'roles' => ['display', 'ui'],
            'superfamily' => 'Inter',
            'overused' => false,
        ],
        'Roboto' => [
            'classification' => 'neo-grotesque',
            'voice' => 'Android\'s system face. Competent, and it makes a site look like a Google product rather than its own thing.',
            'roles' => ['body', 'ui'],
            'superfamily' => 'Roboto',
            'overused' => true,
        ],

        // ------------------------------------------------------ sans, geometric
        'Jost' => [
            'classification' => 'geometric-sans',
            'voice' => 'A Futura in all but name. Circular, rational, interwar; carries a gallery or an architecture practice without trying.',
            'roles' => ['display', 'body', 'ui'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Outfit' => [
            'classification' => 'geometric-sans',
            'voice' => 'Even, geometric and quiet. Modern without the startup accent Poppins carries.',
            'roles' => ['display', 'body', 'ui'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Sora' => [
            'classification' => 'geometric-sans',
            'voice' => 'Geometric with squared terminals. Reads technical and slightly future-facing.',
            'roles' => ['display', 'ui'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Poppins' => [
            'classification' => 'geometric-sans',
            'voice' => 'Perfect circles and a single visual note. The default face of a decade of templates.',
            'roles' => ['display', 'body', 'ui'],
            'superfamily' => '',
            'overused' => true,
        ],
        'Montserrat' => [
            'classification' => 'geometric-sans',
            'voice' => 'Buenos Aires signage, flattened into a webfont. Ubiquitous enough that it now signals "template" more than it signals anything else.',
            'roles' => ['display', 'ui'],
            'superfamily' => '',
            'overused' => true,
        ],

        // ------------------------------------------------------- sans, humanist
        'Source Sans 3' => [
            'classification' => 'humanist-sans',
            'voice' => 'Adobe\'s interface face. Open, legible, and a genuinely good paragraph at 16px.',
            'roles' => ['body', 'ui'],
            'superfamily' => 'Source',
            'overused' => false,
        ],
        'Figtree' => [
            'classification' => 'humanist-sans',
            'voice' => 'Friendly and rounded without turning cute. Holds a heading and a long paragraph equally well.',
            'roles' => ['display', 'body', 'ui'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Karla' => [
            'classification' => 'humanist-sans',
            'voice' => 'A grotesque with humanist proportions and some deliberate irregularity. Slightly off-key, in a way that reads as considered.',
            'roles' => ['body', 'ui'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Work Sans' => [
            'classification' => 'humanist-sans',
            'voice' => 'Drawn for the middle of the optical range: made to be set at text size on a screen and nothing else.',
            'roles' => ['body', 'ui'],
            'superfamily' => '',
            'overused' => false,
        ],
        'IBM Plex Sans' => [
            'classification' => 'humanist-sans',
            'voice' => 'Engineered and slightly formal, with the mono and serif cuts to match. Corporate in the good sense.',
            'roles' => ['display', 'body', 'ui'],
            'superfamily' => 'IBM Plex',
            'overused' => false,
        ],
        'Open Sans' => [
            'classification' => 'humanist-sans',
            'voice' => 'The safest choice on the web, and safe is what it communicates.',
            'roles' => ['body', 'ui'],
            'superfamily' => '',
            'overused' => true,
        ],
        'Lato' => [
            'classification' => 'humanist-sans',
            'voice' => 'Warm, semi-rounded, and on a very large share of the web\'s small-business pages.',
            'roles' => ['body', 'ui'],
            'superfamily' => '',
            'overused' => true,
        ],

        // ------------------------------------------------------ serif, old-style
        'EB Garamond' => [
            'classification' => 'old-style-serif',
            'voice' => 'A Garamond, which is to say five centuries of book typography. Quiet authority, and it wants generous leading.',
            'roles' => ['display', 'body'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Crimson Pro' => [
            'classification' => 'old-style-serif',
            'voice' => 'Drawn for book text and honest about it. Comfortable over long passages in a way most webfonts are not.',
            'roles' => ['body'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Cormorant Garamond' => [
            'classification' => 'old-style-serif',
            'voice' => 'High contrast and very fine. Beautiful large, nearly invisible small — a display face wearing a text face\'s name.',
            'roles' => ['display'],
            'superfamily' => 'Cormorant',
            'overused' => false,
        ],

        // ---------------------------------------------------- serif, transitional
        'Source Serif 4' => [
            'classification' => 'transitional-serif',
            'voice' => 'Sturdy, even-coloured, made to sit next to Source Sans. A serif that works on a screen rather than despite one.',
            'roles' => ['display', 'body'],
            'superfamily' => 'Source',
            'overused' => false,
        ],
        'Newsreader' => [
            'classification' => 'transitional-serif',
            'voice' => 'Drawn for reading on screen, with an optical size axis. News without the nostalgia.',
            'roles' => ['display', 'body'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Libre Baskerville' => [
            'classification' => 'transitional-serif',
            'voice' => 'Baskerville widened and strengthened for screens. Formal, literary, unhurried.',
            'roles' => ['body'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Spectral' => [
            'classification' => 'transitional-serif',
            'voice' => 'A screen-first serif with real weight range. Handles a headline and a caption from one family.',
            'roles' => ['display', 'body'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Lora' => [
            'classification' => 'transitional-serif',
            'voice' => 'Brushed contrast and slightly calligraphic details. Warm without being decorative.',
            'roles' => ['display', 'body'],
            'superfamily' => '',
            'overused' => false,
        ],

        // --------------------------------------------------------- serif, modern
        'Bodoni Moda' => [
            'classification' => 'modern-serif',
            'voice' => 'A didone: hairline serifs, extreme contrast, fashion-magazine cover. Commanding at 72px and unreadable at 16.',
            'roles' => ['display'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Instrument Serif' => [
            'classification' => 'display',
            'voice' => 'Tight, high-contrast and a little theatrical. Made for one line of very large text.',
            'roles' => ['display'],
            'superfamily' => '',
            'overused' => false,
        ],
        'DM Serif Display' => [
            'classification' => 'display',
            'voice' => 'Sharp transitional forms at display weight. Editorial without the Playfair association.',
            'roles' => ['display'],
            'superfamily' => 'DM',
            'overused' => false,
        ],
        'Playfair Display' => [
            'classification' => 'modern-serif',
            'voice' => 'The high-contrast serif every premium template reaches for. Genuinely good, and it now reads as a stock choice.',
            'roles' => ['display'],
            'superfamily' => 'Playfair',
            'overused' => true,
        ],

        // ----------------------------------------------------------- serif, slab
        'Bitter' => [
            'classification' => 'slab-serif',
            'voice' => 'A contemporary slab drawn for screen reading. Solid and a little blunt, in a good way.',
            'roles' => ['display', 'body'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Zilla Slab' => [
            'classification' => 'slab-serif',
            'voice' => 'Mozilla\'s slab: geometric, slightly mechanical, with unusually flat serifs.',
            'roles' => ['display', 'body'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Roboto Slab' => [
            'classification' => 'slab-serif',
            'voice' => 'Roboto\'s skeleton with slabs. Neutral, and it inherits Roboto\'s familiarity.',
            'roles' => ['display', 'body'],
            'superfamily' => 'Roboto',
            'overused' => false,
        ],

        // -------------------------------------------------------------- display
        'Fraunces' => [
            'classification' => 'display',
            'voice' => 'A variable serif with axes for softness and "wonk". Can be a sober text face or an outright strange one, from a single family.',
            'roles' => ['display', 'body'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Syne' => [
            'classification' => 'display',
            'voice' => 'Art-institution lettering: extended, irregular, contemporary. Signals culture rather than commerce.',
            'roles' => ['display'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Unbounded' => [
            'classification' => 'display',
            'voice' => 'Wide, geometric, confident. Fills a hero and refuses to be quiet.',
            'roles' => ['display'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Anton' => [
            'classification' => 'display',
            'voice' => 'Condensed and very heavy. A poster face, best used for two or three words.',
            'roles' => ['display'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Oswald' => [
            'classification' => 'display',
            'voice' => 'Condensed gothic, drawn for headlines. Common enough to be a mild tell.',
            'roles' => ['display'],
            'superfamily' => '',
            'overused' => true,
        ],

        // ----------------------------------------------------------------- mono
        'IBM Plex Mono' => [
            'classification' => 'mono',
            'voice' => 'The mono cut of IBM Plex. Pairs with its own sans by construction, which makes it the safest superfamily pairing here.',
            'roles' => ['mono', 'body', 'display'],
            'superfamily' => 'IBM Plex',
            'overused' => false,
        ],
        'JetBrains Mono' => [
            'classification' => 'mono',
            'voice' => 'Drawn for code, with a tall x-height that survives at small sizes.',
            'roles' => ['mono'],
            'superfamily' => '',
            'overused' => false,
        ],
        'Space Mono' => [
            'classification' => 'mono',
            'voice' => 'A mono with real character and some genuinely odd letterforms. Works as a display face, which most monos do not.',
            'roles' => ['mono', 'display'],
            'superfamily' => 'Space',
            'overused' => false,
        ],
        'DM Mono' => [
            'classification' => 'mono',
            'voice' => 'Low-contrast and even-textured. The quiet option when a mono should not be the loudest thing on the page.',
            'roles' => ['mono'],
            'superfamily' => 'DM',
            'overused' => false,
        ],
    ];
}

/**
 * One face by name, matched case-insensitively, or null.
 *
 * @return array<string, mixed>|null
 */
function get(string $family): ?array
{
    $needle = strtolower(trim($family));
    foreach (all() as $name => $face) {
        if (strtolower($name) === $needle) {
            return array_merge(['family' => $name], $face);
        }
    }
    return null;
}

/**
 * Whether two faces make a defensible pairing, and why or why not.
 *
 * A pairing works on contrast. Two faces from the same super-class almost never
 * contrast enough to look intentional — they look like one face that went
 * slightly wrong, which is the most common way a considered-looking site gives
 * itself away. So the rule is structural rather than aesthetic: the two must
 * differ in super-class, or one of them must be a display face, or they must
 * come from the same superfamily.
 *
 * That last exception matters. IBM Plex Sans over IBM Plex Mono breaks the
 * different-super-class rule and is a better pairing than most that satisfy it,
 * because the two were drawn against each other. A rule that could not express
 * that would refuse the best answer in the set.
 *
 * The body face is checked separately for whether it can actually carry body
 * text. Setting a paragraph in Bodoni Moda or Archivo Black is not a bold choice,
 * it is a mistake, and it is the mistake a model makes when it picks two faces it
 * likes the look of without asking what each one has to do.
 *
 * Over-use is reported separately from the structural objections, because the
 * two are not the same kind of problem. Two geometric sans together do not work;
 * Montserrat works perfectly and is merely everywhere. A caller browsing for a
 * partner can reasonably ask to see the common faces anyway, and could not if
 * being common disqualified a face the same way as having no contrast.
 *
 * @return array{ok: bool, structural_ok: bool, reasons: list<string>}
 */
function pairing(string $display, string $body): array
{
    $a = get($display);
    $b = get($body);

    if ($a === null || $b === null) {
        // A face from outside the set is not refused. The set is a starting
        // point, not a licence list, and a design naming a face bought from a
        // foundry is doing something better than picking from a dropdown.
        return ['ok' => true, 'structural_ok' => true, 'reasons' => []];
    }

    /** @var list<string> $reasons */
    $reasons = [];

    if (strtolower((string) $a['family']) === strtolower((string) $b['family'])) {
        $reasons[] = sprintf(
            /* translators: %s: a font family name */
            __(
                'Both roles are set in %s. One face for everything is a legitimate decision, but it has to be a decision — say so in the design, or give the heading a different face.',
                domain: 'wppilot',
            ),
            $a['family'],
        );
    }

    if (!in_array('body', (array) $b['roles'], strict: true)) {
        $reasons[] = sprintf(
            /* translators: 1: font family name, 2: its voice, one line */
            __(
                '%1$s cannot carry body text: %2$s Use it for headings and set the paragraphs in something drawn for reading.',
                domain: 'wppilot',
            ),
            $b['family'],
            $b['voice'],
        );
    }

    // Judged on classification rather than on sans-versus-serif. The blunt
    // version refused Libre Franklin over Source Sans 3, which is a grotesque
    // display face over a humanist text face and a strategy people use on
    // purpose: the two differ in the axis that matters even though both are
    // sans. What genuinely has no contrast is two faces of the same class, and
    // that is what this refuses.
    $same_family = $a['superfamily'] !== '' && $a['superfamily'] === $b['superfamily'];
    if (!$same_family && $a['classification'] === $b['classification']) {
        $reasons[] = sprintf(
            /* translators: 1: first family, 2: second family, 3: the classification both share */
            __(
                '%1$s and %2$s are both %3$s faces, so the pairing has nothing to contrast. Two faces this close look like one face that went slightly wrong rather than like a choice. Pair across the classes, or use a single family and separate the roles by weight and size instead.',
                domain: 'wppilot',
            ),
            $a['family'],
            $b['family'],
            str_replace('-', ' ', (string) $a['classification']),
        );
    }

    $structural_ok = $reasons === [];

    foreach ([$a, $b] as $face) {
        if (($face['overused'] ?? false) !== true) {
            continue;
        }
        $reasons[] = sprintf(
            /* translators: 1: font family name, 2: its voice, one line */
            __(
                '%1$s is one of the faces every generated page reaches for. %2$s Keep it only if the brand genuinely calls for it.',
                domain: 'wppilot',
            ),
            $face['family'],
            $face['voice'],
        );
    }

    return ['ok' => $reasons === [], 'structural_ok' => $structural_ok, 'reasons' => $reasons];
}

/**
 * Faces that pair soundly with a given one, for a given role.
 *
 * @return list<array<string, mixed>>
 */
function partners(string $family, string $role = 'body', bool $include_overused = false): array
{
    $anchor = get($family);
    if ($anchor === null) {
        return [];
    }

    /** @var list<array<string, mixed>> $out */
    $out = [];
    foreach (all() as $name => $face) {
        if (strtolower($name) === strtolower((string) $anchor['family'])) {
            continue;
        }
        if (!in_array($role, (array) $face['roles'], strict: true)) {
            continue;
        }
        if (($face['overused'] ?? false) === true && !$include_overused) {
            continue;
        }

        // Ask the same question the gate will ask, in whichever direction the
        // caller is filling: a partner for the body role is judged as the body
        // half of the pairing.
        $verdict = $role === 'body'
            ? pairing((string) $anchor['family'], $name)
            : pairing($name, (string) $anchor['family']);

        // Asked to include the common faces, judge on structure alone —
        // otherwise the flag would let an over-used face past the first filter
        // and the pairing check would drop it again, which is a promise the
        // caller can see being broken.
        if (!($include_overused ? $verdict['structural_ok'] : $verdict['ok'])) {
            continue;
        }

        $out[] = array_merge(['family' => $name], $face);
    }

    return $out;
}
