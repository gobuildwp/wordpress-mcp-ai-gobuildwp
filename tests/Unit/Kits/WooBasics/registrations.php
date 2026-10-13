<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The arguments the kit's ability files register, as the kit wrote them.
 *
 * The ability files call wp_register_ability() unqualified from the kit's namespace, so PHP finds
 * this function before the global one. It keeps the arguments and does not pass the call on: the
 * harness's registry then holds only what a test registers there itself, standing in for another
 * plugin that registered a name first, which is what the kit's Runtime\unclaimed() checks read.
 */

namespace WPPilot\Tests\Unit\Kits\WooBasics {
    final class Registrations
    {
        /** @var array<string, array<string, mixed>> */
        public static array $args = [];
    }
}

namespace WPPilot\Kits\WooBasics {
    if (!function_exists(__NAMESPACE__ . '\\wp_register_ability')) {
        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            \WPPilot\Tests\Unit\Kits\WooBasics\Registrations::$args[$name] = $args;
            return null;
        }
    }
}
