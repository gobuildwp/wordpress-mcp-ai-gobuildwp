<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * What the site-issues kit calls beyond the suite's WordPress doubles: the host it runs under,
 * WordPress's paused-extension storage, and a few helpers, defined in the kit's own namespace so
 * the rest of the suite is untouched.
 */

namespace WPPilot\Tests\Unit\Kits\SiteIssues {
    use WP_Error;
    use WPPilot\Kits\Runtime\Host;
    use WPPilot\Kits\Runtime\Jobs;
    use WPPilot\Kits\Runtime\Ledger;

    final class IssuesHost implements Host
    {
        public bool $enabled = true;

        public function id(): string
        {
            return 'test';
        }

        public function can_manage(): bool
        {
            return true;
        }

        public function is_enabled(): bool
        {
            return $this->enabled;
        }

        public function safety_profile(): string
        {
            return 'production_safe';
        }

        public function ledger(): Ledger
        {
            throw new \LogicException('site-issues never writes the ledger');
        }

        public function jobs(): Jobs
        {
            throw new \LogicException('site-issues runs no jobs');
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

namespace {
    if (!defined('WP_CONTENT_DIR')) {
        define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
    }
    if (!defined('MB_IN_BYTES')) {
        define('MB_IN_BYTES', 1_048_576);
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86_400);
    }

    if (!class_exists('WP_Paused_Extensions_Storage')) {
        class WP_Paused_Extensions_Storage
        {
            /** @var array<string, array<string, mixed>> */
            public array $paused = [];

            /** @return array<string, array<string, mixed>> */
            public function get_all(): array
            {
                return $this->paused;
            }
        }
    }

    final class SiteIssuesPaused
    {
        public static ?WP_Paused_Extensions_Storage $plugins = null;
    }

    if (!function_exists('wp_paused_plugins')) {
        function wp_paused_plugins(): WP_Paused_Extensions_Storage
        {
            return SiteIssuesPaused::$plugins ??= new WP_Paused_Extensions_Storage();
        }
    }
}

namespace WPPilot\Kits\SiteIssues {
    if (!function_exists(__NAMESPACE__ . '\\trailingslashit')) {
        function trailingslashit(string $value): string
        {
            return rtrim($value, '/\\') . '/';
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_doing_cron')) {
        function wp_doing_cron(): bool
        {
            return false;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_convert_hr_to_bytes')) {
        function wp_convert_hr_to_bytes(string $value): int
        {
            $bytes = (int) $value;
            return match (strtolower(substr(trim($value), -1))) {
                'g' => $bytes * 1_073_741_824,
                'm' => $bytes * 1_048_576,
                'k' => $bytes * 1024,
                default => $bytes,
            };
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\has_filter')) {
        function has_filter(string $hook, mixed $callback = false): bool|int
        {
            foreach ($GLOBALS['wp_filter'][$hook] ?? [] as $priority => $entries) {
                foreach ($entries as $entry) {
                    if ($entry['callback'] === $callback) {
                        return $priority;
                    }
                }
            }
            return false;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_get_ability')) {
        function wp_get_ability(string $name): ?\WP_Ability
        {
            return null;
        }
    }
}
