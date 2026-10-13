<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Design\Generate;
use WPPilot\Design\Spec;

/**
 * The ladder a generated design declares, and the bridge into a spec.
 *
 * A generated design used to name three type roles. That is enough to write a
 * document and not enough to be held to one: every rule that asks whether a
 * size is on the scale needs a scale with more than three steps in it, so a
 * page could use nine sizes and still be reported as restrained because there
 * was almost nothing to be off. It also meant a spec could not be seeded from a
 * design without somebody retyping forty numbers, which is exactly how a spec
 * ends up grading a page against sizes its own design never declared.
 */
final class DesignGenerateScaleTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function resolved(string $name = 'harbour'): array
    {
        $result = Generate\resolve(['name' => $name, 'mood' => 'editorial']);
        self::assertIsArray($result, 'the brief should resolve');

        return $result;
    }

    public function test_every_spec_type_role_is_declared(): void
    {
        $typography = self::resolved()['typography'];

        foreach (Spec\type_roles() as $role) {
            self::assertArrayHasKey($role, $typography, $role . ' has no declared size');
        }
    }

    /**
     * The rungs are powers of one ratio rather than eight independent draws.
     * That is what makes a scale read as a scale: a heading two steps above
     * another should look two steps above it, not merely bigger.
     */
    public function test_the_ladder_descends_without_ties(): void
    {
        $typography = self::resolved()['typography'];

        $previous = INF;
        foreach (['display', 'h1', 'h2', 'h3', 'card', 'body', 'small', 'label'] as $role) {
            $size = (float) rtrim((string) $typography[$role]['fontSize'], 'px');
            self::assertLessThan($previous, $size, $role . ' does not step down from the role above it');
            $previous = $size;
        }
    }

    /**
     * A scale with three steps starves every rule that measures against it.
     */
    public function test_the_declared_sizes_are_enough_to_measure_against(): void
    {
        $sizes = \WPPilot\Design\Preflight\declared_sizes(self::resolved()['typography']);

        self::assertGreaterThanOrEqual(6, count($sizes));
    }

    /**
     * Negative tracking that makes a hero look set makes body copy look broken.
     */
    public function test_tracking_loosens_as_the_size_drops(): void
    {
        $typography = self::resolved()['typography'];

        $h1 = Spec\tracking_em((string) $typography['h1']['letterSpacing']);
        $card = Spec\tracking_em((string) $typography['card']['letterSpacing']);

        self::assertLessThan(0.0, $h1);
        self::assertGreaterThan($h1, $card);
        self::assertSame(0.0, Spec\tracking_em((string) $typography['body']['letterSpacing']));
    }

    // ------------------------------------------------------------- bridge

    public function test_a_generated_design_seeds_a_spec_without_retyping(): void
    {
        $resolved = self::resolved();
        $type = Spec\from_tokens($resolved['typography']);

        $spec = Spec\normalize([
            'source' => 'prompt',
            'reference' => 'derived',
            'container' => 1380,
            'section_padding' => 144,
            'surfaces' => $resolved['colors'],
            'type' => $type,
            'sections' => [['surface' => 'bg', 'layout' => 'stack']],
        ]);

        self::assertIsArray($spec, 'a design should seed a spec that validates');
        self::assertSame(
            (float) rtrim((string) $resolved['typography']['h1']['fontSize'], 'px'),
            $spec['type']['h1']['size'],
            'the spec must agree with the design about the number',
        );
    }

    /** A role the spec does not know is dropped, not a reason to fail. */
    public function test_an_unknown_role_is_dropped_rather_than_refused(): void
    {
        $out = Spec\from_tokens([
            'h1' => ['fontFamily' => 'Archivo', 'fontSize' => '87px', 'fontWeight' => '900'],
            'pull-quote' => ['fontFamily' => 'Archivo', 'fontSize' => '40px'],
        ]);

        self::assertSame(['h1'], array_keys($out));
    }

    public function test_a_role_without_a_readable_size_is_dropped(): void
    {
        self::assertSame([], Spec\from_tokens(['h1' => ['fontSize' => 'clamp(2rem, 5vw, 4rem)']]));
    }

    /**
     * Em is the only unit that survives a size change, and a spec is applied at
     * more than one size the moment it has a mobile variant. A px tracking
     * converted without its size would be a guess.
     */
    public function test_a_px_tracking_is_refused_rather_than_guessed(): void
    {
        self::assertSame(-0.035, Spec\tracking_em('-0.035em'));
        self::assertSame(0.0, Spec\tracking_em('-2px'));
        self::assertSame(0.0, Spec\tracking_em('normal'));
        self::assertSame(0.0, Spec\tracking_em(''));
    }
}
