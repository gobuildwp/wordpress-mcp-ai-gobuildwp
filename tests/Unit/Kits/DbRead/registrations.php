<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The arguments the kit's ability files register, as the kit wrote them.
 *
 * The ability files call wp_register_ability() unqualified from the kit's namespace, so PHP finds
 * this function before the global one. It keeps the arguments and passes the call on, so the
 * registration still reaches whatever the harness provides — this suite's doubles here, the
 * receiving plugin's once the kit is exported — without the test naming either.
 */

namespace WPPilot\Tests\Unit\Kits\DbRead {
    final class Registrations
    {
        /** @var array<string, array<string, mixed>> */
        public static array $args = [];
    }
}

namespace WPPilot\Kits\DbRead {
    if (!function_exists(__NAMESPACE__ . '\\wp_register_ability')) {
        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            \WPPilot\Tests\Unit\Kits\DbRead\Registrations::$args[$name] = $args;
            return \wp_register_ability($name, $args);
        }
    }
}
