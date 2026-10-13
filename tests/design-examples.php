<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The shipped example directions are documentation an agent reads and copies
 * from, which makes a wrong one worse than a missing one: an example whose
 * accent fails AA teaches every site built from it to fail AA. So they are
 * checked the way code is.
 *
 * Run: php tests/design-examples.php
 */

require_once __DIR__ . '/bootstrap.php';

$design = dirname(__DIR__) . '/includes/design/';
require_once $design . 'parser.php';
require_once $design . 'tokens.php';
require_once $design . 'preflight.php';
require_once $design . 'markdown.php';
require_once $design . 'contract.php';
require_once $design . 'contrast.php';
require_once $design . 'examples.php';

use WPPilot\Design\Contract;
use WPPilot\Design\Contrast;
use WPPilot\Design\Examples;
use WPPilot\Design\Parser;
use WPPilot\Design\Preflight;
use WPPilot\Design\Tokens;

/** @var list<string> $failures */
$failures = [];

function example_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$slugs = Examples\slugs();
example_check($slugs !== [], 'no examples are registered');

$backgrounds = [];
$signatures = [];
$faces = [];
$dark_grounds = 0;

foreach ($slugs as $slug) {
    $record = Examples\find($slug);
    if ($record === null) {
        $failures[] = "{$slug}: find() returned null — the file is missing or unreadable";
        continue;
    }

    $parsed = Parser\parse($record['content']);
    $inspection = Contract\inspect($record['content']);
    $context = Preflight\context($record['content']);
    $vars = Tokens\css_vars(Tokens\extract($record['content']));

    $bg = $vars['--wppilot-bg'] ?? '';
    $ink = $vars['--wppilot-ink'] ?? '';
    $accent = $vars['--wppilot-accent'] ?? '';
    $heading = strtolower($vars['--wppilot-font-heading'] ?? '');
    $body = strtolower($vars['--wppilot-font-body'] ?? '');

    example_check($parsed['parse_error'] === null, "{$slug}: parse error: " . (string) $parsed['parse_error']);
    example_check($parsed['front_matter'], "{$slug}: front matter was not detected");
    example_check($record['description'] !== '', "{$slug}: no description in front matter");

    // Ready means an agent can activate it; sync_ready additionally means the
    // roles are explicit rather than guessed out of the prose, which is what
    // the Pro brand-kit needs to write a palette into a builder.
    $readiness = $inspection['readiness'];
    example_check($readiness['ready'], "{$slug}: not ready: " . implode(' | ', $readiness['errors']));
    example_check($readiness['sync_ready'], "{$slug}: not sync_ready — colours or typography are being inferred");
    example_check(
        $inspection['tokens']['components'] !== [],
        "{$slug}: no component treatments — the section most real DESIGN.md files omit is the one these exist to demonstrate",
    );

    // The scale is the half of typography that keeps page six looking like
    // page one. A shipped example without it teaches every site copied from it
    // to invent its own sizes.
    example_check(
        $inspection['token_sources']['scale'] === 'explicit',
        sprintf('%s: type scale is %s, want explicit', $slug, $inspection['token_sources']['scale']),
    );
    foreach (['heading', 'body'] as $role) {
        $props = $inspection['tokens']['typography'][$role] ?? [];
        foreach (['fontSize', 'lineHeight'] as $prop) {
            example_check(
                ($props[$prop] ?? '') !== '',
                sprintf('%s: %s role declares no %s', $slug, $role, $prop),
            );
        }
    }
    // Display type takes less leading than body, never more: large type at a
    // browser default is the untouched-web look these examples exist to model
    // out of existence.
    $head_lead = (float) ($inspection['tokens']['typography']['heading']['lineHeight'] ?? 0);
    $body_lead = (float) ($inspection['tokens']['typography']['body']['lineHeight'] ?? 0);
    example_check(
        $head_lead > 0.0 && $head_lead <= 1.25,
        sprintf('%s: heading leading is %.2f, want <= 1.25', $slug, $head_lead),
    );
    example_check(
        $body_lead >= 1.4 && $body_lead > $head_lead,
        sprintf('%s: body leading is %.2f, want >= 1.4 and looser than the heading', $slug, $body_lead),
    );

    $ink_ratio = ($bg !== '' && $ink !== '') ? Contrast\ratio($ink, $bg) : 0.0;
    $accent_ratio = ($bg !== '' && $accent !== '') ? Contrast\ratio($accent, $bg) : 0.0;
    example_check(
        $ink_ratio >= Contrast\AAA_NORMAL,
        sprintf('%s: ink on background is %.2f:1, below the AAA floor of %.1f', $slug, $ink_ratio, Contrast\AAA_NORMAL),
    );
    example_check(
        $accent_ratio >= Contrast\AA_NORMAL,
        sprintf('%s: accent on background is %.2f:1, below the AA floor of %.1f', $slug, $accent_ratio, Contrast\AA_NORMAL),
    );

    // The gate only enforces what it can parse, so an example whose rules do not
    // survive extract_donts() is prose that looks like guidance and enforces
    // nothing — precisely the failure these are meant to model out of existence.
    example_check(
        count($context['donts']) >= 4,
        sprintf('%s: only %d parseable "don\'t" rules, want at least 4', $slug, count($context['donts'])),
    );

    $backgrounds[] = strtolower($bg);
    $signatures[] = strtolower($bg . '|' . $accent);
    $faces[] = $heading;
    $faces[] = $body;
    if ($bg !== '' && Contrast\luminance($bg) < 0.2) {
        $dark_grounds++;
    }
}

// A set that is seven variations on one idea teaches nothing. These are the
// properties that make it a range rather than a house style: no two directions
// may share a background-and-accent pair (pure white legitimately recurs, since
// maximum contrast and flat poster black-on-white both genuinely want it), the
// backgrounds must still mostly differ, and at least one must be dark.
example_check(
    count(array_unique($signatures)) === count($signatures),
    'two examples share the same background and accent pair',
);
example_check(
    count(array_unique($backgrounds)) >= count($backgrounds) - 1,
    'more than two examples share a background colour',
);
example_check($dark_grounds >= 1, 'no dark-ground example — half the sites that need one are unserved');

// The pre-flight flags these as AI tells. An example that shipped one would be
// the plugin recommending the thing it refuses to let users write.
foreach (['inter', 'roboto'] as $tell) {
    example_check(!in_array($tell, $faces, strict: true), "an example uses the flagged face: {$tell}");
}

if ($failures === []) {
    printf("PASS: %d design examples parse, are activation-ready, and meet their contrast floors.\n", count($slugs));
    exit(0);
}

fwrite(STDERR, "FAIL:\n");
foreach ($failures as $failure) {
    fwrite(STDERR, "  - {$failure}\n");
}
exit(1);
