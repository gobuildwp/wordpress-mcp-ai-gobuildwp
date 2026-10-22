<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Wordfence 9.0.1 and Solid Security 10.0.4 stubbed down to the calls the kit makes, the host the
 * kit runs under, and the WordPress functions it calls, defined in the kit's own namespace so the
 * rest of the suite is untouched. The vendor classes are global because the kit detects them with
 * class_exists(); each is guarded.
 */

namespace WPPilot\Tests\Unit\Kits\SecurityStatus {
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

    final class Users
    {
        /** @var array<string, int> login or email => user id */
        public static array $byLogin = ['admin' => 1, 'owner@example.com' => 1, 'editor' => 7];
    }

    /** Answers the few queries the Wordfence reader makes, and records them. */
    final class FakeWpdb
    {
        /** @var list<string> */
        public array $queries = [];

        /** @var list<array<string, mixed>> */
        public array $issues = [];

        public int $blocks = 0;

        /** @return list<array<string, mixed>> */
        public function get_results(string $sql, string $output = ''): array
        {
            $this->queries[] = $sql;
            if (str_contains($sql, 'GROUP BY `severity`')) {
                $counts = [];
                foreach ($this->issues as $row) {
                    $counts[$row['severity']] = ($counts[$row['severity']] ?? 0) + 1;
                }
                return array_map(static fn($s, $n): array => ['severity' => $s, 'n' => $n], array_keys($counts), $counts);
            }
            preg_match('/LIMIT (\d+)/', $sql, $m);
            $rows = $this->issues;
            if (preg_match_all('/`severity` >= (\d+) AND `severity` < (\d+)/', $sql, $bands, PREG_SET_ORDER)) {
                $rows = array_values(array_filter($rows, static function (array $r) use ($bands): bool {
                    foreach ($bands as $b) {
                        if ($r['severity'] >= (int) $b[1] && $r['severity'] < (int) $b[2]) {
                            return true;
                        }
                    }
                    return false;
                }));
            }
            usort($rows, static fn($a, $b): int => $b['severity'] <=> $a['severity']);
            return array_slice($rows, 0, (int) $m[1]);
        }

        public function get_var(string $sql): int
        {
            $this->queries[] = $sql;
            return $this->blocks;
        }

        /**
         * wpdb::prepare() for the placeholders the reader uses: values passed variadically or as one
         * array, %i quoted as a backtick identifier (backticks doubled, as WordPress 6.2+ does), %d
         * cast to int, %s quoted, %% a literal percent.
         */
        public function prepare(string $sql, mixed ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            $args = array_values($args);
            $index = 0;
            return (string) preg_replace_callback('/%(%|i|d|s)/', static function (array $m) use (&$index, $args): string {
                if ($m[1] === '%') {
                    return '%';
                }
                $value = $args[$index++] ?? '';
                return match ($m[1]) {
                    'i' => '`' . str_replace('`', '``', (string) $value) . '`',
                    'd' => (string) (int) $value,
                    default => "'" . addslashes((string) $value) . "'",
                };
            }, $sql);
        }
    }

    final class FakeLockout
    {
        /** @var list<array<string, string|null>> */
        public array $rows = [];

        /** @return array<string, array<string, string>> */
        public function get_lockout_modules(): array
        {
            return ['brute_force' => ['type' => 'brute_force', 'reason' => 'Too many bad login attempts']];
        }

        /**
         * @param array<string, mixed> $args
         * @return list<array<string, string|null>>|int
         */
        public function get_lockouts(string $type = 'all', array $args = []): array|int
        {
            $rows = $this->rows;
            if (!empty($args['current'])) {
                $rows = array_values(array_filter($rows, static fn($r): bool => $r['lockout_active'] === '1' && $r['lockout_expire_gmt'] > gmdate('Y-m-d H:i:s')));
            }
            if (($args['return'] ?? '') === 'count') {
                return count($rows);
            }
            return isset($args['limit']) ? array_slice($rows, 0, (int) $args['limit']) : $rows;
        }
    }

    final class ReadHost implements Host
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

namespace {
    if (!defined('WORDFENCE_VERSION')) {
        define('WORDFENCE_VERSION', '9.0.1');
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    if (!class_exists('wfConfig')) {
        final class wfConfig
        {
            /** @var array<string, mixed> */
            public static array $values = [
                'isPaid' => false, 'apiKey' => 'SECRET-API-KEY', 'scheduledScansEnabled' => true,
                'lastScanCompleted' => 'ok', 'loginSecurityEnabled' => true, 'loginSec_maxFailures' => 20,
                'loginSec_lockoutMins' => 240, 'loginSec_lockInvalidUsers' => false, 'other_WFNet' => true, 'firewallEnabled' => true,
            ];

            public static function get(string $key, mixed $default = false): mixed
            {
                return self::$values[$key] ?? $default;
            }
        }
    }

    if (!class_exists('wfFirewall')) {
        final class wfFirewall
        {
            public function firewallMode(): string
            {
                return 'learning-mode';
            }

            public function protectionMode(): string
            {
                return 'basic';
            }

            public function ruleMode(): string
            {
                return 'community';
            }

            public function learningModeStatus(): int|bool
            {
                return 1790000000;
            }
        }
    }

    if (!class_exists('wfScanner')) {
        final class wfScanner
        {
            public static function shared(): self
            {
                return new self();
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function nextScheduledScanTime(): int|false
            {
                return false;
            }

            public function scanType(): string
            {
                return 'standard';
            }

            public function isRunning(): bool
            {
                return false;
            }

            public function lastScanTime(): float
            {
                return 1790000000.25;
            }
        }
    }

    if (!class_exists('wfIssues')) {
        final class wfIssues
        {
            public static function shared(): self
            {
                return new self();
            }

            public function getIssuesTable(): string
            {
                return 'wp_wfissues';
            }
        }
    }

    if (!class_exists('wfBlock')) {
        final class wfBlock
        {
            /** @var list<wfBlock> */
            public static array $blocks = [];

            public function __construct(
                public int $id,
                public int $type,
                public ?string $ip,
                public int $blockedTime,
                public string $reason,
                public int $lastAttempt,
                public int $blockedHits,
                public int $expiration,
            ) {
            }

            public static function blocksTable(): string
            {
                return 'wp_wfblocks7';
            }

            /**
             * @param list<int> $types
             * @return list<wfBlock>
             */
            public static function allBlocks(bool $prefetch = false, array $types = [], int $offset = 0, int $limit = -1): array
            {
                return $limit > -1 ? array_slice(self::$blocks, $offset, $limit) : self::$blocks;
            }

            public static function nameForType(int $type): string
            {
                return $type === 7 ? 'Lockout' : 'IP Block';
            }
        }
    }

    if (!class_exists('ITSEC_Core')) {
        final class ITSEC_Core
        {
            public static bool $broken = false;

            public static function get_plugin_name(): string
            {
                if (self::$broken) {
                    throw new \Error('moved');
                }
                return 'Kadence Security Basic';
            }

            public static function get_plugin_version(): string
            {
                return '10.0.4';
            }

            public static function is_pro(): bool
            {
                return false;
            }
        }
    }

    if (!class_exists('ITSEC_Lib_IP_Detector')) {
        final class ITSEC_Lib_IP_Detector
        {
            public static bool $configured = false;

            public static function is_configured(): bool
            {
                return self::$configured;
            }
        }
    }

    if (!class_exists('ITSEC_Modules')) {
        final class ITSEC_Modules
        {
            /** @var array<string, bool> */
            public static array $active = ['firewall' => true, 'brute-force' => true];

            public static function is_active(string $id): bool
            {
                return self::$active[$id] ?? false;
            }

            public static function get_setting(string $module, string $name): mixed
            {
                return ['brute-force' => ['max_attempts_host' => 5, 'max_attempts_user' => 10], 'global' => ['lockout_period' => 15]][$module][$name] ?? null;
            }

            public static function get_container(): object
            {
                return new class () {
                    public function get(string $id): object
                    {
                        throw new \RuntimeException('no ' . $id);
                    }
                };
            }
        }
    }
}

namespace WPPilot\Kits\SecurityStatus {
    use WPPilot\Tests\Unit\Kits\SecurityStatus\Registrations;
    use WPPilot\Tests\Unit\Kits\SecurityStatus\Users;

    if (!function_exists(__NAMESPACE__ . '\\get_user_by')) {
        function get_user_by(string $field, string $value): object|false
        {
            $id = Users::$byLogin[$value] ?? null;
            return $id ? (object) ['ID' => $id] : false;
        }

        function get_userdata(int $id): object|false
        {
            return in_array($id, Users::$byLogin, true) ? (object) ['ID' => $id] : false;
        }

        function current_user_can(string $capability): bool
        {
            return in_array($capability, \WPPilot\Tests\Unit\Kits\SecurityStatus\Caps::$granted, true);
        }

        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            Registrations::$args[$name] = $args;
            // Kept here only: the suite's shared registry stays as it was, so the names stay
            // unclaimed for the next test. The stand-aside test claims them there itself.
            return null;
        }
    }
}
