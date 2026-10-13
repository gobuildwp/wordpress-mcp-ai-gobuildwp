<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Design\Typefaces;

use function WPPilot\Design\Preflight\declared_sizes;
use function WPPilot\Design\Preflight\size_to_px;
use function WPPilot\Design\Preflight\type_scale;

/**
 * The pre-flight refuses Inter by name, which stops one case and leaves the
 * habit behind it intact: asked again, the same reach produces Poppins. A set to
 * choose from is the other half of that rule, and the pairing check is what
 * makes choosing from it better than picking two names that sound nice together.
 *
 * The line these tests defend is where "no contrast" sits. Too blunt and it
 * refuses real work — grotesque over humanist is a strategy, not an accident —
 * and a check that refuses good designs is one people switch off.
 */
final class DesignTypefacesTest extends TestCase
{
    // ------------------------------------------------------------- the set

    public function test_faces_are_addressed_case_insensitively(): void
    {
        $face = Typefaces\get('fraunces');

        self::assertNotNull($face);
        self::assertSame('Fraunces', $face['family']);
    }

    public function test_an_unknown_face_is_not_in_the_set(): void
    {
        self::assertNull(Typefaces\get('Söhne'));
    }

    public function test_every_face_is_fully_described(): void
    {
        foreach (Typefaces\all() as $name => $face) {
            self::assertNotSame('', (string) $face['classification'], $name . ' has no classification');
            self::assertNotSame('', (string) $face['voice'], $name . ' has no voice');
            self::assertNotSame([], $face['roles'], $name . ' can carry nothing');
        }
    }

    // ---------------------------------------------------------- the pairing

    public function test_a_serif_display_over_a_sans_body_is_sound(): void
    {
        self::assertTrue(Typefaces\pairing('Fraunces', 'Public Sans')['ok']);
    }

    /**
     * Two faces of one class have nothing to contrast, so they read as one face
     * that went slightly wrong rather than as a decision.
     */
    public function test_two_faces_of_the_same_class_are_refused(): void
    {
        $verdict = Typefaces\pairing('Jost', 'Outfit');

        self::assertFalse($verdict['ok']);
        self::assertStringContainsString('geometric sans', $verdict['reasons'][0]);
    }

    /**
     * The blunt version of that rule judged sans-versus-serif and refused a
     * grotesque display face over a humanist text face, which is a pairing people
     * choose on purpose — the two differ on the axis that matters. It refused one
     * of our own shipped examples, which is how it was caught.
     */
    public function test_different_classes_within_one_super_class_are_allowed(): void
    {
        self::assertTrue(Typefaces\pairing('Libre Franklin', 'Source Sans 3')['ok']);
        self::assertTrue(Typefaces\pairing('Public Sans', 'Karla')['ok']);
    }

    /**
     * IBM Plex Sans over IBM Plex Mono breaks the same-class rule and is a better
     * pairing than most that satisfy it, because the two were drawn against each
     * other. A rule that could not say so would refuse the best answer in the set.
     */
    public function test_a_superfamily_pairing_survives_the_class_rule(): void
    {
        self::assertTrue(Typefaces\pairing('IBM Plex Sans', 'IBM Plex Mono')['ok']);
    }

    /**
     * Setting a paragraph in a didone is not a bold choice, it is the mistake a
     * model makes when it picks two faces it likes the look of without asking what
     * each one has to do.
     */
    public function test_a_display_face_is_refused_as_body_text(): void
    {
        $verdict = Typefaces\pairing('Bodoni Moda', 'Cormorant Garamond');

        self::assertFalse($verdict['ok']);
        self::assertStringContainsString('cannot carry body text', $verdict['reasons'][0]);
    }

    public function test_the_overused_faces_are_named_as_such(): void
    {
        $verdict = Typefaces\pairing('Playfair Display', 'Inter');

        self::assertFalse($verdict['ok']);
        self::assertCount(2, $verdict['reasons']);
    }

    public function test_one_face_in_both_roles_is_called_out(): void
    {
        self::assertFalse(Typefaces\pairing('Archivo', 'Archivo')['ok']);
    }

    /**
     * The set is a starting point, not a licence list. A face bought from a
     * foundry for the brand beats anything in a dropdown, and refusing it would
     * push people back toward the dropdown.
     */
    public function test_a_face_from_outside_the_set_is_never_refused(): void
    {
        $verdict = Typefaces\pairing('Söhne', 'Public Sans');

        self::assertTrue($verdict['ok']);
        self::assertSame([], $verdict['reasons']);
    }

    // --------------------------------------------------------- the partners

    public function test_partners_can_all_carry_the_role_asked_for(): void
    {
        foreach (Typefaces\partners('Fraunces', 'body') as $face) {
            self::assertContains('body', $face['roles'], $face['family'] . ' cannot set body text');
            self::assertFalse($face['overused'], $face['family'] . ' is an overused face');
        }
    }

    /**
     * Over-use and having no contrast are not the same kind of objection.
     * Montserrat works perfectly and is merely everywhere, so a caller browsing
     * for a partner can ask to see it — and could not, while the pairing check
     * treated being common as disqualifying. The flag silently returned the same
     * list either way.
     */
    public function test_an_overused_face_is_a_note_not_a_structural_objection(): void
    {
        $verdict = Typefaces\pairing('Fraunces', 'Lato');

        self::assertFalse($verdict['ok']);
        self::assertTrue($verdict['structural_ok']);
    }

    public function test_no_contrast_is_a_structural_objection(): void
    {
        $verdict = Typefaces\pairing('Jost', 'Outfit');

        self::assertFalse($verdict['ok']);
        self::assertFalse($verdict['structural_ok']);
    }

    public function test_every_partner_passes_the_pairing_it_was_offered_for(): void
    {
        foreach (Typefaces\partners('Fraunces', 'body') as $face) {
            self::assertTrue(
                Typefaces\pairing('Fraunces', (string) $face['family'])['ok'],
                $face['family'] . ' was offered but does not pair',
            );
        }
    }

    public function test_partners_exclude_the_face_asked_about(): void
    {
        $families = array_column(Typefaces\partners('Fraunces', 'body'), 'family');

        self::assertNotContains('Fraunces', $families);
    }

    public function test_overused_partners_are_available_on_request(): void
    {
        $without = array_column(Typefaces\partners('Fraunces', 'body'), 'family');
        $with = array_column(Typefaces\partners('Fraunces', 'body', include_overused: true), 'family');

        self::assertGreaterThan(count($without), count($with));
        self::assertNotContains('Lato', $without);
        self::assertContains('Lato', $with);
    }

    // ---------------------------------------------------------- the scale

    public function test_lengths_resolve_to_px(): void
    {
        self::assertSame(58.0, size_to_px('58px'));
        self::assertSame(18.0, size_to_px('1.125rem'));
        self::assertSame(32.0, size_to_px('2em'));
        self::assertNull(size_to_px('clamp(2rem, 5vw, 4rem)'));
        self::assertNull(size_to_px(''));
    }

    public function test_declared_sizes_come_back_largest_first(): void
    {
        $sizes = declared_sizes([
            'body' => ['fontSize' => '18px'],
            'heading' => ['fontSize' => '58px'],
        ]);

        self::assertSame([58.0, 18.0], $sizes);
    }

    public function test_a_page_on_the_declared_scale_is_clean(): void
    {
        $html = '<h1 style="font-size:58px">A</h1><p style="font-size:18px">B</p>';

        self::assertSame([], type_scale($html, [58.0, 18.0]));
    }

    /**
     * A design saying 18px and a page saying 1.125rem are the same decision.
     */
    public function test_rem_on_the_scale_is_clean(): void
    {
        $html = '<h1 style="font-size:3.625rem">A</h1><p style="font-size:1.125rem">B</p>';

        self::assertSame([], type_scale($html, [58.0, 18.0]));
    }

    /**
     * A whole pixel of slack sounds harmless and swallows the real case: 17px
     * against a declared 18px is a different decision, not a rounding artefact.
     */
    public function test_a_page_on_no_declared_size_is_reported(): void
    {
        $html = '<h1 style="font-size:44px">A</h1><p style="font-size:17px">B</p>';
        $found = type_scale($html, [58.0, 18.0]);

        self::assertSame(['type-off-scale'], array_column($found, 'rule'));
    }

    public function test_a_hero_that_runs_past_the_scale_is_reported(): void
    {
        $html = '<h1 style="font-size:120px">A</h1><p style="font-size:18px">B</p>';
        $found = type_scale($html, [58.0, 18.0]);

        self::assertSame(['type-oversized'], array_column($found, 'rule'));
    }

    public function test_a_page_that_sets_no_sizes_is_not_judged(): void
    {
        self::assertSame([], type_scale('<h1>A</h1><p>B</p>', [58.0, 18.0]));
    }

    /**
     * The contract already reports a design with no declared scale as incomplete.
     * Saying it again from the page check would be the same note twice.
     */
    public function test_a_design_with_no_scale_is_not_judged(): void
    {
        self::assertSame([], type_scale('<h1 style="font-size:44px">A</h1>', []));
    }

    /**
     * Every size on a page cannot be a declared one: a design naming a heading and
     * a body size cannot enumerate captions, small print and four heading levels.
     * Demanding an exact match would report a violation on every honest page.
     */
    public function test_sizes_between_the_declared_ones_are_not_violations(): void
    {
        $html = '<h1 style="font-size:58px">A</h1><h2 style="font-size:34px">B</h2>'
            . '<p style="font-size:18px">C</p><small style="font-size:13px">D</small>';

        self::assertSame([], type_scale($html, [58.0, 18.0]));
    }
}
