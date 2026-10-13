<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

const CATEGORY = 'design-system';

/** Register the `design-system` ability category. Idempotent. */
function register_category(): void
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    try {
        wp_register_ability_category(CATEGORY, [
            'label' => __('Design', domain: 'wppilot'),
            'description' => __('Read and manage the active site design system.', domain: 'wppilot'),
        ]);

        // @mago-expect lint:no-empty-catch-clause
    } catch (\Throwable) {
        // Already registered — fine.
    }
}
