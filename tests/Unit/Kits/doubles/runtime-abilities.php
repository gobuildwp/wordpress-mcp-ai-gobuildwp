<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * wp_get_ability() for the kit runtime's own namespaces only.
 *
 * The suite's WordPress doubles leave the global wp_get_ability() undefined on purpose: core
 * modules branch on function_exists('wp_get_ability'), and defining it would change what every
 * other test exercises. The runtime calls it unqualified from its namespace, so PHP looks for
 * WPPilot\Kits\Runtime\wp_get_ability() first; defining it there reaches the runtime and nothing
 * else.
 */

namespace WPPilot\Tests\Unit\Kits {
    final class RuntimeAbilities
    {
        /** @var array<string, \WP_Ability> */
        public static array $abilities = [];

        /** @param array<string, mixed> $meta */
        public static function add(string $name, array $meta): void
        {
            self::$abilities[$name] = new \WP_Ability($name, $meta);
        }
    }
}

namespace WPPilot\Kits\Runtime {
    if (!function_exists(__NAMESPACE__ . '\\wp_get_ability')) {
        function wp_get_ability(string $name): ?\WP_Ability
        {
            return \WPPilot\Tests\Unit\Kits\RuntimeAbilities::$abilities[$name] ?? null;
        }
    }

    // MiniLedger drops the options cache before it writes; the doubles keep no cache to drop.
    if (!function_exists(__NAMESPACE__ . '\\wp_cache_delete') && !function_exists('wp_cache_delete')) {
        function wp_cache_delete(int|string $key, string $group = ''): bool
        {
            return true;
        }
    }
}

namespace WPPilot\Kits\Runtime\Hosts {
    if (!function_exists(__NAMESPACE__ . '\\wp_get_ability')) {
        function wp_get_ability(string $name): ?\WP_Ability
        {
            return \WPPilot\Tests\Unit\Kits\RuntimeAbilities::$abilities[$name] ?? null;
        }
    }
}
