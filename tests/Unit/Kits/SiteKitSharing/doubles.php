<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Site Kit 1.189.0's sharing route reduced to the rules the kit depends on, a ledger that keeps
 * what the kit gives it, and an options table read the way the kit reads it (past the option
 * filters). Defined in the kit's namespace so the rest of the suite keeps the global doubles.
 */

namespace WPPilot\Tests\Unit\Kits\SiteKitSharing {
    use WP_Error;
    use WPPilot\Kits\Runtime\Host;
    use WPPilot\Kits\Runtime\Jobs;
    use WPPilot\Kits\Runtime\Ledger;

    /** The options table and the current user, as the kit sees them. */
    final class Store
    {
        /** @var array<string, mixed> */
        public static array $rows = [];

        public static int $user = 1;
    }

    /**
     * What Site Kit does with the request: the route needs a signed-in admin
     * (googlesitekit_manage_options), sharedRoles is dropped for each module the user may not
     * manage (owner, or all_admins management), the partial is merged one level deep, and a change
     * to PageSpeed Insights makes the saving user its owner.
     */
    final class FakeSiteKit
    {
        public static bool $signedIn = true;

        public static bool $setupCompleted = true;

        /** @var array<string, array{active: bool, connected: bool, owner: int}> */
        public static array $modules = [];

        /** @var list<array{method: string, route: string, params: array<string, mixed>}> */
        public static array $calls = [];

        public static function reset(): void
        {
            self::$signedIn = true;
            self::$setupCompleted = true;
            self::$calls = [];
            self::$modules = [
                'search-console' => ['active' => true, 'connected' => true, 'owner' => 1],
                'analytics-4' => ['active' => true, 'connected' => true, 'owner' => 1],
                'pagespeed-insights' => ['active' => true, 'connected' => true, 'owner' => 1],
            ];
        }

        public static function canManage(string $slug): bool
        {
            if (!self::$signedIn) {
                return false;
            }
            $sharing = self::sharing();
            if (($sharing[$slug]['management'] ?? '') === 'all_admins') {
                return true;
            }
            return (self::$modules[$slug]['owner'] ?? 0) === Store::$user;
        }

        /** Site Kit's filtered read: PageSpeed Insights always has an all_admins entry. */
        public static function sharing(): array
        {
            $stored = Store::$rows['googlesitekit_dashboard_sharing'] ?? [];
            $stored = is_array($stored) ? $stored : [];
            if (!isset($stored['pagespeed-insights'])) {
                $stored['pagespeed-insights'] = ['sharedRoles' => [], 'management' => 'all_admins'];
            }
            return $stored;
        }

        /** @param array<string, mixed> $params */
        public static function handle(mixed $answer, string $method, string $route, array $params): mixed
        {
            self::$calls[] = ['method' => $method, 'route' => $route, 'params' => $params];
            if ($route === 'core/site/data/connection') {
                return ['connected' => self::$setupCompleted, 'setupCompleted' => self::$setupCompleted];
            }
            if ($route === 'core/modules/data/list') {
                $out = [];
                foreach (self::$modules as $slug => $module) {
                    $out[] = ['slug' => $slug, 'active' => $module['active'], 'connected' => $module['connected'], 'owner' => ['id' => $module['owner'], 'login' => 'owner' . $module['owner']]];
                }
                return $out;
            }
            if ($route !== 'core/modules/data/sharing-settings' || $method !== 'POST') {
                throw new \LogicException('Unexpected Site Kit route ' . $route);
            }
            if (!self::$signedIn) {
                return new WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
            }
            $before = self::sharing();
            $merged = $before;
            $new_owners = [];
            foreach ((array) $params['data'] as $slug => $settings) {
                if (!self::canManage($slug)) {
                    unset($settings['sharedRoles']);
                }
                if ($settings === []) {
                    continue;
                }
                $merged[$slug] = array_merge($merged[$slug] ?? [], $settings);
                if ($slug === 'pagespeed-insights' && $merged[$slug] !== $before[$slug]) {
                    $psi = Store::$rows['googlesitekit_pagespeed-insights_settings'] ?? [];
                    $psi['ownerID'] = Store::$user;
                    Store::$rows['googlesitekit_pagespeed-insights_settings'] = $psi;
                    self::$modules[$slug]['owner'] = Store::$user;
                    $new_owners[$slug] = Store::$user;
                }
            }
            Store::$rows['googlesitekit_dashboard_sharing'] = $merged;
            return ['settings' => $merged, 'newOwnerIDs' => $new_owners];
        }
    }

    /** Reads wp_options the way raw_option() does: stored rows only, serialized. */
    final class FakeWpdb
    {
        public string $options = 'wp_options';

        public function prepare(string $sql, string $name): string
        {
            return str_replace('%s', "'" . $name . "'", $sql);
        }

        public function get_var(string $sql): ?string
        {
            preg_match("/option_name = '([^']+)'/", $sql, $m);
            $name = $m[1] ?? '';
            return array_key_exists($name, Store::$rows) ? serialize(Store::$rows[$name]) : null;
        }
    }

    final class RecordingLedger implements Ledger
    {
        /** @var array<string, callable> */
        public array $captures = [];

        /** @var array<string, callable> */
        public array $strategies = [];

        public function capture_for(string $ability_name, callable $capture): void
        {
            $this->captures[$ability_name] = $capture;
        }

        public function record_items(string $ability_name, array $items, ?string $group = null): array
        {
            throw new \LogicException('not used');
        }

        public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
        {
            $this->strategies[$type] = $restore;
            return true;
        }

        public function query(array $filters = []): array
        {
            return [];
        }

        public function export_row(array $entry): array
        {
            return $entry;
        }

        public function snapshot_budget(): int
        {
            return 1048576;
        }

        public function download_url(): string
        {
            return '';
        }
    }

    final class Registrations
    {
        /** @var array<string, array<string, mixed>> */
        public static array $args = [];
    }

    final class SharingHost implements Host
    {
        public function __construct(public RecordingLedger $ledger)
        {
        }

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
            return true;
        }

        public function safety_profile(): string
        {
            return 'production';
        }

        public function ledger(): Ledger
        {
            return $this->ledger;
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

namespace WPPilot\Kits\SiteKitSharing {
    use WPPilot\Tests\Unit\Kits\SiteKitSharing\FakeSiteKit;
    use WPPilot\Tests\Unit\Kits\SiteKitSharing\Store;

    if (!function_exists(__NAMESPACE__ . '\\current_user_can')) {
        function current_user_can(string $capability, mixed ...$args): bool
        {
            if ($capability === 'googlesitekit_manage_module_sharing_options') {
                return FakeSiteKit::canManage((string) ($args[0] ?? ''));
            }
            return $capability === 'manage_options';
        }

        function get_option(string $option, mixed $default_value = false): mixed
        {
            if ($option === 'googlesitekit_dashboard_sharing') {
                return FakeSiteKit::sharing();
            }
            return array_key_exists($option, Store::$rows) ? Store::$rows[$option] : $default_value;
        }

        function delete_option(string $option): bool
        {
            $existed = array_key_exists($option, Store::$rows);
            unset(Store::$rows[$option]);
            return $existed;
        }

        function get_current_user_id(): int
        {
            return Store::$user;
        }

        /**
         * Site Kit's on_change listener rides on every write of the sharing option: a changed
         * PageSpeed Insights entry makes the writing user its owner. The undo has to survive it.
         */
        function update_option(string $option, mixed $value, mixed $autoload = null): bool
        {
            $before = FakeSiteKit::sharing();
            $written = !array_key_exists($option, Store::$rows) || Store::$rows[$option] !== $value;
            Store::$rows[$option] = $value;
            if ($written && $option === 'googlesitekit_dashboard_sharing' && FakeSiteKit::sharing()['pagespeed-insights'] !== $before['pagespeed-insights']) {
                $psi = Store::$rows['googlesitekit_pagespeed-insights_settings'] ?? [];
                $psi['ownerID'] = Store::$user;
                Store::$rows['googlesitekit_pagespeed-insights_settings'] = $psi;
            }
            return $written;
        }

        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            \WPPilot\Tests\Unit\Kits\SiteKitSharing\Registrations::$args[$name] = $args;
            return null;
        }
    }
}
