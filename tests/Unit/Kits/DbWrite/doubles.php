<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * A $wpdb on in-memory SQLite that answers the kit's information_schema lookups from SQLite's
 * own catalogue, and has the wpdb::update() the shared double lacks.
 */

namespace WPPilot\Tests\Unit\Kits\DbWrite {
    final class Registrations
    {
        /** @var array<string, array<string, mixed>> */
        public static array $args = [];
    }

    final class SchemaWpdb
    {
        public string $prefix = 'wp_';

        public string $base_prefix = 'wp_';

        public string $last_error = '';

        public int $insert_id = 0;

        /** Update calls that fail, by primary key value. @var list<string> */
        public array $failUpdate = [];

        public function __construct(public \Kit_Test_Sqlite_Wpdb $db)
        {
        }

        /** @return array<string, mixed>|null */
        public function get_row(string $query, string $output = 'OBJECT'): ?array
        {
            if (preg_match("/FROM information_schema\\.TABLES .* TABLE_NAME = '([^']+)'/", $query, $m) === 1) {
                $name = $this->db->get_var($this->db->prepare("SELECT name FROM sqlite_master WHERE name = %s AND type IN ('table', 'view')", $m[1]));
                $type = $this->db->get_var($this->db->prepare('SELECT type FROM sqlite_master WHERE name = %s', $m[1]));
                return $name === null ? null : ['name' => $name, 'type' => $type === 'view' ? 'VIEW' : 'BASE TABLE'];
            }
            return $this->forward('get_row', [$query, $output]);
        }

        /** @return list<array<string, mixed>>|null */
        public function get_results(string $query, string $output = 'OBJECT'): ?array
        {
            if (preg_match("/FROM information_schema\\.COLUMNS .* TABLE_NAME = '([^']+)'/", $query, $m) === 1) {
                $columns = [];
                foreach ($this->db->get_results($this->db->prepare('SELECT * FROM pragma_table_info(%s)', $m[1]), ARRAY_A) ?? [] as $column) {
                    $pk = (int) $column['pk'] > 0;
                    $columns[] = [
                        'name' => $column['name'],
                        'col_key' => $pk ? 'PRI' : '',
                        'extra' => $pk && strtoupper((string) $column['type']) === 'INTEGER' ? 'auto_increment' : '',
                    ];
                }
                return $columns;
            }
            return $this->forward('get_results', [$query, $output]);
        }

        /**
         * wpdb::update(), every condition an equality.
         *
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         */
        public function update(string $table, array $data, array $where): int|false
        {
            if (in_array((string) reset($where), $this->failUpdate, true)) {
                $this->last_error = 'update refused by the test';
                return false;
            }
            $set = [];
            foreach ($data as $column => $value) {
                $set[] = $value === null ? "{$column} = NULL" : $this->db->prepare("{$column} = %s", (string) $value);
            }
            $conditions = [];
            foreach ($where as $column => $value) {
                $conditions[] = $this->db->prepare("{$column} = %s", (string) $value);
            }
            $result = $this->forward('query', [sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $conditions))]);
            return is_int($result) ? $result : false;
        }

        /** @param list<mixed> $args */
        public function __call(string $name, array $args): mixed
        {
            return $this->forward($name, $args);
        }

        /** @param list<mixed> $args */
        private function forward(string $name, array $args): mixed
        {
            $result = $this->db->{$name}(...$args);
            $this->last_error = $this->db->last_error;
            $this->insert_id = $this->db->insert_id;
            return $result;
        }
    }
}

namespace WPPilot\Kits\DbWrite {
    if (!function_exists(__NAMESPACE__ . '\\wp_register_ability')) {
        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            \WPPilot\Tests\Unit\Kits\DbWrite\Registrations::$args[$name] = $args;
            return \wp_register_ability($name, $args);
        }
    }
}
