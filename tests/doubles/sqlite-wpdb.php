<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * A $wpdb that runs its SQL for real, on an in-memory SQLite database, and records every query.
 *
 * The ledger's table code is SQL; a double that only records strings could prove the strings and
 * never whether they select the right rows. SQLite runs the same statements: the ledger keeps to
 * SQL both engines accept (INSTR rather than LIKE, no INSERT IGNORE, no DELETE ... LIMIT), and
 * the few MySQL-only things it does use are handled here — named locks are granted, and
 * dbDelta()'s MySQL DDL is translated.
 *
 * `deny_create` stands in for a database user without the CREATE privilege; `fail_inserts` for
 * a database that refuses writes to the table.
 */
final class WPPilot_Test_Sqlite_Wpdb
{
    public string $prefix = 'wp_';

    public string $options = 'wp_options';

    public string $last_error = '';

    public int $insert_id = 0;

    /** @var list<string> */
    public array $queries = [];

    public bool $deny_create = false;

    public bool $fail_inserts = false;

    private PDO $pdo;

    private bool $suppress = false;

    /** @var list<array<string, mixed>> */
    private array $last_result = [];

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = $this->suppress;
        $this->suppress = $suppress;

        return $previous;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $args = array_values($args);
        $index = 0;

        return (string) preg_replace_callback('/%(%|d|s|f)/', function (array $match) use (&$index, $args): string {
            if ($match[1] === '%') {
                return '%';
            }
            $value = $args[$index++] ?? '';
            return match ($match[1]) {
                'd' => (string) (int) $value,
                'f' => (string) (float) $value,
                default => (string) $this->pdo->quote((string) $value),
            };
        }, $query);
    }

    public function query(string $query): int|bool
    {
        $this->queries[] = $query;
        $this->last_error = '';
        $this->last_result = [];
        if (preg_match('/^\s*SELECT (GET_LOCK|RELEASE_LOCK)\(/i', $query) === 1) {
            $this->last_result = [['lock' => '1']];
            return 1;
        }
        // wpdb::query() refuses a statement carrying invalid UTF-8 rather than store it damaged.
        if (preg_match('//u', $query) !== 1) {
            $this->last_error = 'WordPress database error: Could not perform query because it contains invalid data.';
            return false;
        }
        if ($this->fail_inserts && preg_match('/^\s*INSERT INTO\s+\S*wppilot_changes\b/i', $query) === 1) {
            $this->last_error = 'INSERT command denied to user for table wp_wppilot_changes';
            return false;
        }
        try {
            $statement = $this->pdo->query($query);
        } catch (PDOException $error) {
            $this->last_error = $error->getMessage();
            return false;
        }
        if (preg_match('/^\s*SELECT\b/i', $query) === 1) {
            $this->last_result = $statement->fetchAll(PDO::FETCH_ASSOC);
            return count($this->last_result);
        }
        if (preg_match('/^\s*INSERT\b/i', $query) === 1) {
            $this->insert_id = (int) $this->pdo->lastInsertId();
        }

        return $statement->rowCount();
    }

    public function get_var(string $query): ?string
    {
        if ($this->query($query) === false || $this->last_result === []) {
            return null;
        }
        $value = array_values($this->last_result[0])[0] ?? null;

        return $value === null ? null : (string) $value;
    }

    /** @return list<string|null> */
    public function get_col(string $query): array
    {
        if ($this->query($query) === false) {
            return [];
        }

        return array_map(
            static fn(array $row): ?string => ($value = array_values($row)[0] ?? null) === null ? null : (string) $value,
            $this->last_result,
        );
    }

    /**
     * wpdb::insert(). Values are quoted; null is written as NULL.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data, mixed $format = null): int|false
    {
        $values = array_map(
            fn(mixed $value): string => $value === null ? 'NULL' : (string) $this->pdo->quote((string) $value),
            array_values($data),
        );
        $result = $this->query(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_keys($data)),
            implode(', ', $values),
        ));

        return $result === false ? false : 1;
    }

    /**
     * wpdb::delete(), with every condition an equality.
     *
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where, mixed $where_format = null): int|false
    {
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = $column . ' = ' . (string) $this->pdo->quote((string) $value);
        }
        $result = $this->query(sprintf('DELETE FROM %s WHERE %s', $table, implode(' AND ', $conditions)));

        return is_int($result) ? $result : false;
    }

    /** @return array<string, mixed>|null */
    public function get_row(string $query, string $output = 'OBJECT'): ?array
    {
        if ($this->query($query) === false || $this->last_result === []) {
            return null;
        }

        return $this->last_result[0];
    }

    /** @return list<array<string, mixed>>|null */
    public function get_results(string $query, string $output = 'OBJECT'): ?array
    {
        if ($this->query($query) === false) {
            return null;
        }

        return $this->last_result;
    }

    /**
     * The MySQL CREATE TABLE dbDelta() is given, as SQLite DDL.
     */
    public function db_delta(string $sql): void
    {
        if ($this->deny_create) {
            $this->last_error = "CREATE command denied to user 'wp'@'localhost' for table 'wp_wppilot_changes'";
            return;
        }
        if (preg_match('/CREATE TABLE\s+(\S+)\s*\((.*)\)\s*[^)]*;?\s*$/s', $sql, $match) !== 1) {
            throw new RuntimeException('dbDelta double: unrecognized statement.');
        }
        $table = $match[1];
        $columns = [];
        $indexes = [];
        foreach (preg_split('/,\s*\n/', trim($match[2])) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^PRIMARY KEY/i', $line) === 1) {
                continue;
            }
            if (preg_match('/^(UNIQUE )?KEY (\w+) \((\w+)\)$/i', $line, $key) === 1) {
                $indexes[] = sprintf(
                    'CREATE %sINDEX IF NOT EXISTS %s_%s ON %s (%s)',
                    $key[1] !== '' ? 'UNIQUE ' : '',
                    $table,
                    $key[2],
                    $table,
                    $key[3],
                );
                continue;
            }
            if (stripos($line, 'AUTO_INCREMENT') !== false) {
                $columns[] = preg_replace('/^(\w+).*$/', '$1 INTEGER PRIMARY KEY AUTOINCREMENT', $line);
                continue;
            }
            $columns[] = str_ireplace(' UNSIGNED', '', $line);
        }
        $this->pdo->exec(sprintf('CREATE TABLE IF NOT EXISTS %s (%s)', $table, implode(', ', $columns)));
        foreach ($indexes as $index) {
            $this->pdo->exec($index);
        }
    }

    public function drop_changes_table(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->prefix . 'wppilot_changes');
    }
}

if (!function_exists('dbDelta')) {
    /** @return array<string, string> */
    function dbDelta(string|array $queries = '', bool $execute = true): array
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if ($wpdb instanceof WPPilot_Test_Sqlite_Wpdb) {
            foreach ((array) $queries as $sql) {
                $wpdb->db_delta((string) $sql);
            }
        }

        return [];
    }
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86_400);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3_600);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
