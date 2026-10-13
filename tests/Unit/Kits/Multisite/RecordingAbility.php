<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\Multisite;

/**
 * An ability that records what it was asked to do.
 */
final class RecordingAbility extends \WP_Ability
{
    /** @var list<mixed> */
    public array $calls = [];

    /** @var (callable(mixed): mixed)|null */
    public $effect = null;

    public function execute(mixed $input = null): mixed
    {
        $this->calls[] = $input;
        return $this->effect !== null ? ($this->effect)($input) : ['done' => true];
    }
}
