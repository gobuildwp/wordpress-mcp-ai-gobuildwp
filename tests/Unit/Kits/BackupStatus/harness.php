<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The host the kit runs under in these tests, and a wrapper that keeps what each ability file
 * registered.
 *
 * The ability files call wp_register_ability() unqualified from the kit's namespace, so PHP finds
 * the wrapper before the global double; it records the arguments and passes the call on.
 */

namespace WPPilot\Tests\Unit\Kits\BackupStatus {
    use WP_Error;
    use WPPilot\Kits\Runtime\Host;
    use WPPilot\Kits\Runtime\Jobs;
    use WPPilot\Kits\Runtime\Ledger;

    final class Registrations
    {
        /** @var array<string, array<string, mixed>> */
        public static array $args = [];
    }

    final class Caps
    {
        /** @var list<string> */
        public static array $granted = [];
    }

    final class ReadHost implements Host
    {
        public bool $enabled = true;

        public bool $manage = true;

        public function id(): string
        {
            return 'test';
        }

        public function can_manage(): bool
        {
            return $this->manage;
        }

        public function is_enabled(): bool
        {
            return $this->enabled;
        }

        public function safety_profile(): string
        {
            return 'production';
        }

        public function ledger(): Ledger
        {
            throw new \LogicException('A read-only kit never records a change.');
        }

        public function jobs(): Jobs
        {
            throw new \LogicException('not used');
        }

        public function extension(string $point): mixed
        {
            return null;
        }

        public function admin_parent_slug(): string
        {
            return 'tools.php';
        }

        public function confirm_guard(string $ability_name, array $input): bool|WP_Error
        {
            return true;
        }
    }
}

namespace WPPilot\Kits\BackupStatus {
    if (!function_exists(__NAMESPACE__ . '\\wp_register_ability')) {
        function current_user_can(string $capability): bool
        {
            return in_array($capability, \WPPilot\Tests\Unit\Kits\BackupStatus\Caps::$granted, true);
        }

        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            \WPPilot\Tests\Unit\Kits\BackupStatus\Registrations::$args[$name] = $args;
            // Kept here only: the suite's shared registry stays as it was, so the names stay
            // unclaimed for the next test. The stand-aside test claims them there itself.
            return null;
        }
    }
}
