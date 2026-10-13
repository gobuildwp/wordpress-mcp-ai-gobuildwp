<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\DbRead\Sql;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The gate a raw SELECT passes before it reaches the database.
 *
 * It reads the statement the way MySQL and MariaDB lex it — strings, backtick names, comments —
 * rather than matching patterns over the text, because every evasion of a pattern check lives in
 * exactly that difference: a keyword inside a string is data, the same word outside one is SQL,
 * and a comment can hide either. Wherever the two servers (or their SQL modes) would lex a piece
 * differently, that piece is refused rather than guessed at:
 *
 * - comments of every kind (`#`, `-- `, `/* *\/`, and the executable `/*!` and hint `/*+` forms);
 * - backslashes, which escape inside a string except under NO_BACKSLASH_ESCAPES, so a string
 *   ending in one could end in two different places;
 * - double quotes, which are strings except under ANSI_QUOTES, where they are names;
 * - `@` variables and system variables, `?` placeholders and ODBC `{ }` escapes.
 *
 * What remains is checked twice over. Every name in the statement, anywhere, is compared with the
 * tables this database actually has, so a table the ability refuses cannot be reached by a parse
 * this file got wrong; and every table in a FROM or JOIN position must be one it allows.
 */

/** Longest statement accepted, in bytes. */
const MAX_LENGTH = 10000;

/**
 * Words refused anywhere outside a string: writes to files or variables, sleeps, lock and
 * replication waits, locking reads, sequence writes, index hints, and the TABLE
 * statement form.
 */
const FORBIDDEN_WORDS = [
    'INTO' => 'INTO writes the result to a file or a variable',
    'OUTFILE' => 'OUTFILE writes to the server\'s disk',
    'DUMPFILE' => 'DUMPFILE writes to the server\'s disk',
    'LOAD_FILE' => 'LOAD_FILE reads the server\'s files',
    'SLEEP' => 'SLEEP holds a connection open',
    'BENCHMARK' => 'BENCHMARK burns server time',
    'GET_LOCK' => 'named locks outlive the statement',
    'RELEASE_LOCK' => 'named locks are not a read',
    'RELEASE_ALL_LOCKS' => 'named locks are not a read',
    'IS_FREE_LOCK' => 'named locks are not a read',
    'IS_USED_LOCK' => 'named locks are not a read',
    'MASTER_POS_WAIT' => 'replication waits hold a connection open',
    'SOURCE_POS_WAIT' => 'replication waits hold a connection open',
    'MASTER_GTID_WAIT' => 'replication waits hold a connection open',
    'WAIT_FOR_EXECUTED_GTID_SET' => 'replication waits hold a connection open',
    'WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS' => 'replication waits hold a connection open',
    'UPDATE' => 'FOR UPDATE takes row locks',
    'LOCK' => 'LOCK IN SHARE MODE takes row locks',
    'NOWAIT' => 'locking clauses are not a read',
    'PROCEDURE' => 'PROCEDURE runs server code',
    'HANDLER' => 'HANDLER is not a SELECT',
    'NEXTVAL' => 'NEXTVAL advances a sequence',
    'SETVAL' => 'SETVAL writes a sequence',
    'LASTVAL' => 'sequence functions are not supported',
    'TABLE' => 'the TABLE statement form is not supported; write SELECT * FROM',
    'USE' => 'index hints are not supported',
    'FORCE' => 'index hints are not supported',
    'IGNORE' => 'index hints are not supported',
    'NATURAL' => 'NATURAL JOIN joins on columns the statement never names; write the ON condition',
    // The shell-command UDFs an intruder installs; the read-only transaction cannot stop them.
    'SYS_EXEC' => 'it runs a shell command',
    'SYS_EVAL' => 'it runs a shell command',
];

/** Schemas that describe the server rather than this site. */
const SYSTEM_SCHEMAS = ['information_schema', 'mysql', 'performance_schema', 'sys'];

const JOIN_WORDS = ['JOIN', 'STRAIGHT_JOIN'];
const JOIN_MODIFIERS = ['INNER', 'CROSS', 'LEFT', 'RIGHT', 'OUTER'];
const CLAUSE_WORDS = ['WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'WINDOW', 'OFFSET'];
const SET_OPERATORS = ['UNION', 'EXCEPT', 'INTERSECT', 'MINUS'];
const SELECT_MODIFIERS = [
    'ALL', 'DISTINCT', 'DISTINCTROW', 'HIGH_PRIORITY', 'STRAIGHT_JOIN', 'SQL_SMALL_RESULT',
    'SQL_BIG_RESULT', 'SQL_BUFFER_RESULT', 'SQL_NO_CACHE', 'SQL_CACHE', 'SQL_CALC_FOUND_ROWS',
];

/**
 * Whether a column (or other name) holds something that is redacted on the way out.
 *
 * Deliberately broad on credentials and contact data, and narrow on the words WordPress uses for
 * ordinary columns: `meta_key` and `post_author` are not sensitive, `user_pass`,
 * `post_password`, `user_activation_key`, `comment_author_email` and `comment_author_IP` are.
 */
function is_sensitive_name(string $name): bool
{
    return preg_match(
        '/password|passwd|pwd|(^|_)pass($|_)|secret|token|api_?key|private_?key|consumer_key|access_key|activation_key|auth_key|(^|_)salt($|_)|nonce|(^|_)hash($|_)|_hash|hash_|credential|e_?mail|(^|_)ip($|_)|ip_?address/i',
        $name,
    ) === 1;
}

function reject(string $message): WP_Error
{
    return new WP_Error('kit_sql_rejected', $message, ['status' => 400]);
}

/**
 * Split a statement into tokens, refusing anything the servers could read two ways.
 *
 * @return list<array{type: string, text: string, upper: string}>|WP_Error
 *         `type` is word (a bare word or number), name (backtick-quoted), string or punct;
 *         `text` is the value (a name without its backticks, a string without its quotes).
 */
function tokenize(string $sql): array|WP_Error
{
    $tokens = [];
    $length = strlen($sql);
    $i = 0;
    while ($i < $length) {
        $char = $sql[$i];
        $byte = ord($char);
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
            $i++;
            continue;
        }
        if ($byte < 0x20 || $byte === 0x7f) {
            return reject('Control characters are not allowed in the statement.');
        }
        if ($char === '#' || ($char === '/' && $next === '*') || ($char === '-' && $next === '-')) {
            return reject('Comments are not allowed (#, --, /* */, including /*! and /*+ forms). Remove them; "--" as two minus signs needs a space between them.');
        }
        if ($char === '\\') {
            return reject('Backslashes are not allowed: whether one escapes depends on the server\'s SQL mode. Write a quote inside a string as two quotes (\'\').');
        }
        if ($char === '"') {
            return reject('Double quotes are not allowed: they are strings or names depending on the server\'s SQL mode. Use single quotes for strings and backticks for names.');
        }
        if ($char === '@') {
            return reject('Variables (@name and @@system variables) are not allowed.');
        }
        if ($char === '?' || $char === '{' || $char === '}' || $char === ';') {
            return reject($char === ';' ? 'Only one statement is allowed.' : sprintf('"%s" is not allowed in the statement.', $char));
        }

        if ($char === "'" || $char === '`') {
            $value = '';
            $j = $i + 1;
            while (true) {
                if ($j >= $length) {
                    return reject($char === "'" ? 'A string is not closed.' : 'A backtick name is not closed.');
                }
                if ($sql[$j] === '\\') {
                    return reject('Backslashes are not allowed: whether one escapes depends on the server\'s SQL mode. Write a quote inside a string as two quotes (\'\').');
                }
                if ($sql[$j] === $char) {
                    if ($j + 1 < $length && $sql[$j + 1] === $char) {
                        $value .= $char;
                        $j += 2;
                        continue;
                    }
                    break;
                }
                $value .= $sql[$j];
                $j++;
            }
            if ($char === '`' && $value === '') {
                return reject('An empty backtick name is not a name.');
            }
            $tokens[] = ['type' => $char === "'" ? 'string' : 'name', 'text' => $value, 'upper' => strtoupper($value)];
            $i = $j + 1;
            continue;
        }

        if (ctype_alnum($char) || $char === '_' || $char === '$' || $byte >= 0x80) {
            $j = $i;
            while ($j < $length && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '$' || ord($sql[$j]) >= 0x80)) {
                $j++;
            }
            $word = substr($sql, $i, $j - $i);
            $tokens[] = ['type' => 'word', 'text' => $word, 'upper' => strtoupper($word)];
            $i = $j;
            continue;
        }

        $tokens[] = ['type' => 'punct', 'text' => $char, 'upper' => $char];
        $i++;
    }
    return $tokens;
}

/**
 * Check one SELECT against this database's tables.
 *
 * @param array{
 *     known: array<string, true>,
 *     allowed: array<string, string>,
 *     refused: array<string, string>,
 * } $tables Every table and view in the database by lower-cased name; the queryable ones mapped
 *           to their real name; and why each other one is refused.
 * @return array{sql: string, tables: list<string>, sensitive: list<string>, order_without_limit: bool}|WP_Error
 *         The statement to run (trailing `;` removed), the tables it reads, the sensitive
 *         columns it selects by name, and whether its outer ORDER BY lacks a LIMIT.
 */
function validate(string $sql, array $tables): array|WP_Error
{
    // Only the whitespace the tokenizer skips: trim()'s default would also drop NUL and vertical
    // tab at the ends, and those are refused, not tidied away.
    $sql = trim($sql, " \t\n\r");
    if (str_ends_with($sql, ';')) {
        $sql = rtrim(substr($sql, 0, -1), " \t\n\r");
    }
    if ($sql === '') {
        return reject('The statement is empty.');
    }
    if (strlen($sql) > MAX_LENGTH) {
        return reject(sprintf('The statement is longer than %d bytes.', MAX_LENGTH));
    }
    $tokens = tokenize($sql);
    if ($tokens instanceof WP_Error) {
        return $tokens;
    }
    if (($tokens[0]['upper'] ?? '') !== 'SELECT' || $tokens[0]['type'] !== 'word') {
        return reject('Only a single SELECT statement is allowed (no WITH, SHOW, EXPLAIN or writes).');
    }

    $words = static function (array $token): bool {
        return $token['type'] === 'word' || $token['type'] === 'name';
    };

    // Pass 1: names and words, wherever they stand.
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!$words($token)) {
            continue;
        }
        $lower = strtolower($token['text']);
        if ($token['type'] === 'word' && isset(FORBIDDEN_WORDS[$token['upper']])) {
            return reject(sprintf('%s is not allowed: %s.', $token['upper'], FORBIDDEN_WORDS[$token['upper']]));
        }
        if ($token['type'] === 'word' && $token['upper'] === 'FOR' && in_array($tokens[$i + 1]['upper'] ?? '', ['SHARE', 'UPDATE'], true)) {
            return reject('Locking clauses (FOR SHARE, FOR UPDATE, LOCK IN SHARE MODE) are not allowed.');
        }
        if ($token['type'] === 'word' && in_array($token['upper'], ['NEXT', 'PREVIOUS'], true) && ($tokens[$i + 1]['upper'] ?? '') === 'VALUE') {
            return reject('Sequence access (NEXT VALUE FOR) is not allowed.');
        }
        if (in_array($lower, SYSTEM_SCHEMAS, true)) {
            return reject(sprintf('%s is a system schema; only this site\'s tables can be read.', $token['text']));
        }
        if (isset($tables['known'][$lower]) && !isset($tables['allowed'][$lower])) {
            return reject(sprintf('Table %s is refused: %s', $token['text'], $tables['refused'][$lower] ?? 'it is not one of this site\'s queryable tables.'));
        }
    }

    // Pass 2: structure — which names stand where a table goes, and where sensitive columns do.
    $read = [];
    $sensitive_selected = [];
    $order_without_limit = false;
    $stack = [new_frame('select', 'start', true)];
    $next_significant = static fn(int $i): ?array => $tokens[$i + 1] ?? null;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $top = count($stack) - 1;
        $frame = &$stack[$top];

        if ($token['type'] === 'punct' && $token['text'] === '(') {
            $after = $next_significant($i);
            if (($after['upper'] ?? '') === 'WITH' && ($after['type'] ?? '') === 'word') {
                return reject('Common table expressions (WITH) are not supported.');
            }
            $is_select = ($after['upper'] ?? '') === 'SELECT' && ($after['type'] ?? '') === 'word';
            if ($frame['state'] === 'list') {
                // A call or subquery inside a select-list item makes the item computed.
                $frame['item']['nested'] = true;
            }
            if ($frame['state'] === 'table') {
                $frame['on_close'] = 'after_table';
                $child = $is_select
                    ? new_frame('select', 'start', output_of($stack))
                    : new_frame('group', 'table', false);
            } elseif ($frame['state'] === 'table_function') {
                $frame['on_close'] = 'after_table';
                $child = new_frame('expr', 'expr', false);
            } elseif ($frame['state'] === 'setop') {
                $frame['on_close'] = 'other';
                $child = $is_select ? new_frame('select', 'start', false) : new_frame('expr', 'expr', false);
            } else {
                $child = $is_select ? new_frame('select', 'start', false) : new_frame('expr', 'expr', false);
            }
            unset($frame);
            $stack[] = $child;
            continue;
        }

        if ($token['type'] === 'punct' && $token['text'] === ')') {
            if ($top === 0) {
                return reject('A parenthesis is closed that was never opened.');
            }
            $closed = finish_frame($frame);
            if ($closed instanceof WP_Error) {
                return $closed;
            }
            $sensitive_selected = array_merge($sensitive_selected, $closed);
            unset($frame);
            array_pop($stack);
            $parent = &$stack[count($stack) - 1];
            if ($parent['on_close'] !== '') {
                $parent['state'] = $parent['on_close'];
                $parent['on_close'] = '';
            }
            unset($parent);
            continue;
        }

        $upper = $token['type'] === 'word' ? $token['upper'] : '';
        $is_name = $words($token);
        // A name followed by a dot qualifies a column (a table or its alias); it is not one.
        $qualifier = ($next_significant($i)['text'] ?? '') === '.' && ($next_significant($i)['type'] ?? '') === 'punct';
        $sensitive = $is_name && !$qualifier && is_sensitive_name($token['text']);

        if ($upper === 'SELECT') {
            if ($frame['state'] === 'start' || $frame['state'] === 'setop' || $frame['kind'] !== 'select') {
                // A SELECT inside an expression or table group is a set-operation branch: its
                // columns take the first branch's names, so it is never an output frame.
                if ($frame['kind'] !== 'select') {
                    $frame['kind'] = 'select';
                    $frame['output'] = false;
                }
                $frame['state'] = 'list';
                $frame['item'] = new_item(true);
                continue;
            }
            return reject('SELECT is not expected here.');
        }

        if ($frame['kind'] === 'expr') {
            if (in_array($upper, JOIN_WORDS, true)) {
                return reject('JOIN is not expected inside an expression.');
            }
            if (in_array($upper, SET_OPERATORS, true)) {
                continue;
            }
            if ($sensitive) {
                return sensitive_refusal($token['text']);
            }
            continue;
        }

        switch ($frame['state']) {
            case 'start':
                return reject('Expected SELECT.');

            case 'list':
                if ($upper === 'FROM') {
                    $closed = finish_item($frame);
                    if ($closed instanceof WP_Error) {
                        return $closed;
                    }
                    $frame['state'] = 'table';
                } elseif ($token['type'] === 'punct' && $token['text'] === ',') {
                    $closed = finish_item($frame);
                    if ($closed instanceof WP_Error) {
                        return $closed;
                    }
                    $frame['item'] = new_item(false);
                } elseif (in_array($upper, CLAUSE_WORDS, true) || in_array($upper, SET_OPERATORS, true)) {
                    $closed = finish_item($frame);
                    if ($closed instanceof WP_Error) {
                        return $closed;
                    }
                    enter_clause($frame, $upper, $top === 0, $order_without_limit);
                } else {
                    add_to_item($frame, $token, $sensitive);
                }
                break;

            case 'table':
                if ($upper === 'LATERAL') {
                    break;
                }
                if ($upper === 'DUAL') {
                    $frame['state'] = 'after_table';
                    break;
                }
                if ($upper === 'JSON_TABLE' && ($next_significant($i)['text'] ?? '') === '(') {
                    $frame['state'] = 'table_function';
                    break;
                }
                if (!$is_name) {
                    return reject(sprintf('Expected a table name after FROM or JOIN, found "%s".', $token['text']));
                }
                if (($next_significant($i)['text'] ?? '') === '(') {
                    return reject(sprintf('%s(...) is not a table; only JSON_TABLE is supported as a table function.', $token['text']));
                }
                if (($next_significant($i)['text'] ?? '') === '.' && ($next_significant($i)['type'] ?? '') === 'punct') {
                    return reject(sprintf('Name tables without a database or schema (%s.…); only this site\'s database is read.', $token['text']));
                }
                $lower = strtolower($token['text']);
                if (!isset($tables['allowed'][$lower])) {
                    return reject(sprintf(
                        'Table %s is not one of this site\'s queryable tables. List them with the database-tables ability.',
                        $token['text'],
                    ));
                }
                $read[$tables['allowed'][$lower]] = true;
                $frame['state'] = 'after_table';
                break;

            case 'table_function':
                return reject('JSON_TABLE must be followed by its arguments.');

            case 'after_table':
            case 'cond':
                if (in_array($upper, JOIN_WORDS, true) || ($token['type'] === 'punct' && $token['text'] === ',')) {
                    $frame['state'] = 'table';
                } elseif (in_array($upper, JOIN_MODIFIERS, true)) {
                    // Part of the join that follows.
                } elseif ($upper === 'ON') {
                    $frame['state'] = 'cond';
                } elseif (in_array($upper, CLAUSE_WORDS, true) || in_array($upper, SET_OPERATORS, true)) {
                    enter_clause($frame, $upper, $top === 0, $order_without_limit);
                } elseif ($frame['state'] === 'cond') {
                    if ($sensitive) {
                        return sensitive_refusal($token['text']);
                    }
                } elseif ($upper === 'USING' || $upper === 'AS' || $is_name) {
                    // A table alias, or USING's column list (an expression frame follows).
                } else {
                    return reject(sprintf('"%s" is not expected after a table name.', $token['text']));
                }
                break;

            case 'setop':
                if (!in_array($upper, ['ALL', 'DISTINCT'], true)) {
                    return reject('A set operation must be followed by SELECT.');
                }
                break;

            default: // 'other': WHERE, GROUP BY, HAVING, ORDER BY, LIMIT, WINDOW
                if ($upper === 'FROM' || in_array($upper, JOIN_WORDS, true)) {
                    // Not valid SQL here, but a name after it is treated as a table all the same.
                    $frame['state'] = 'table';
                } elseif (in_array($upper, CLAUSE_WORDS, true) || in_array($upper, SET_OPERATORS, true)) {
                    enter_clause($frame, $upper, $top === 0, $order_without_limit);
                } elseif ($sensitive) {
                    return sensitive_refusal($token['text']);
                }
        }
        unset($frame);
    }
    unset($frame);

    if (count($stack) !== 1) {
        return reject('A parenthesis is opened and never closed.');
    }
    if ($stack[0]['state'] === 'table' || $stack[0]['state'] === 'table_function') {
        return reject('FROM or JOIN is not followed by a table.');
    }
    $closed = finish_frame($stack[0]);
    if ($closed instanceof WP_Error) {
        return $closed;
    }
    $sensitive_selected = array_merge($sensitive_selected, $closed);

    return [
        'sql' => $sql,
        'tables' => array_keys($read),
        'sensitive' => array_values(array_unique($sensitive_selected)),
        'order_without_limit' => $order_without_limit,
    ];
}

/**
 * @return array{kind: string, state: string, output: bool, on_close: string, union: bool, item: array<string, mixed>, sensitive: list<string>}
 */
function new_frame(string $kind, string $state, bool $output): array
{
    return [
        'kind' => $kind,
        'state' => $state,
        'output' => $output,
        'on_close' => '',
        'union' => false,
        'item' => new_item(true),
        'sensitive' => [],
    ];
}

/**
 * @return array{first: bool, shape: list<string>, nested: bool, sensitive: list<string>}
 */
function new_item(bool $first): array
{
    return ['first' => $first, 'shape' => [], 'nested' => false, 'sensitive' => []];
}

/**
 * Whether a derived table opened in the innermost select frame still reaches the result.
 *
 * Only a derived table of a frame whose own columns are the result keeps its columns' names;
 * one inside a scalar subquery hands them to an alias the redaction cannot see.
 *
 * @param list<array<string, mixed>> $stack
 */
function output_of(array $stack): bool
{
    for ($i = count($stack) - 1; $i >= 0; $i--) {
        if ($stack[$i]['kind'] === 'select') {
            return (bool) $stack[$i]['output'];
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $frame
 * @param array{type: string, text: string, upper: string} $token
 * @param bool $sensitive Whether the token is a sensitive column name.
 */
function add_to_item(array &$frame, array $token, bool $sensitive): void
{
    $item = &$frame['item'];
    if ($item['first'] && $item['shape'] === [] && $token['type'] === 'word' && in_array($token['upper'], SELECT_MODIFIERS, true)) {
        return;
    }
    $is_name = $token['type'] === 'word' || $token['type'] === 'name';
    $item['shape'][] = $is_name ? 'name' : ($token['type'] === 'punct' ? $token['text'] : $token['type']);
    if ($sensitive) {
        $item['sensitive'][] = $token['text'];
    }
}

/**
 * Close a select-list item: a sensitive column may be selected only on its own, by its own name,
 * in a select whose columns are the result.
 *
 * Redaction happens on the result's column names. Anything that renames, computes, compares or
 * sorts on a sensitive column would put its value, or an answer about it, under a name the
 * redaction does not recognise.
 *
 * @param array<string, mixed> $frame
 * @return true|WP_Error
 */
function finish_item(array &$frame): bool|WP_Error
{
    $item = $frame['item'];
    if ($item['sensitive'] === []) {
        return true;
    }
    $lone = !$item['nested'] && in_array($item['shape'], [['name'], ['name', '.', 'name']], true);
    if (!$lone || !$frame['output']) {
        return sensitive_refusal($item['sensitive'][0]);
    }
    $frame['sensitive'] = array_merge($frame['sensitive'], $item['sensitive']);
    $frame['item'] = new_item(false);
    return true;
}

/**
 * @param array<string, mixed> $frame
 * @return list<string>|WP_Error The sensitive columns the frame selected.
 */
function finish_frame(array &$frame): array|WP_Error
{
    if ($frame['kind'] === 'select' && $frame['state'] === 'list') {
        $closed = finish_item($frame);
        if ($closed instanceof WP_Error) {
            return $closed;
        }
    }
    if ($frame['kind'] === 'select' && ($frame['state'] === 'table' || $frame['state'] === 'table_function')) {
        return reject('FROM or JOIN is not followed by a table.');
    }
    if ($frame['union'] && $frame['sensitive'] !== []) {
        // Every branch after the first returns its values under the first branch's names.
        return sensitive_refusal($frame['sensitive'][0]);
    }
    return $frame['sensitive'];
}

/**
 * @param array<string, mixed> $frame
 */
function enter_clause(array &$frame, string $word, bool $outermost, bool &$order_without_limit): void
{
    if (in_array($word, SET_OPERATORS, true)) {
        $frame['union'] = true;
        $frame['state'] = 'setop';
        $frame['on_close'] = '';
        return;
    }
    if ($outermost && $word === 'ORDER') {
        $order_without_limit = true;
    }
    if ($outermost && $word === 'LIMIT') {
        $order_without_limit = false;
    }
    $frame['state'] = 'other';
}

function sensitive_refusal(string $column): WP_Error
{
    return reject(sprintf(
        'Column %s looks sensitive, so its value is redacted in the result. It may only be selected on its own by its own name (or through SELECT *); it cannot be filtered, joined, sorted, grouped, renamed or computed on.',
        $column,
    ));
}

/**
 * Why a table in this database is not queryable here, or '' when it is.
 *
 * @param list<string> $denied Table names without the prefix, lower-cased.
 */
function table_refusal(string $name, string $type, string $prefix, string $base_prefix, bool $multisite, array $denied): string
{
    $lower = strtolower($name);
    if (strtoupper($type) !== 'BASE TABLE') {
        return 'it is a view, and a view can read tables this ability refuses.';
    }
    if ($prefix === '' || !str_starts_with($lower, strtolower($prefix))) {
        return sprintf('it does not carry this site\'s table prefix (%s).', $prefix);
    }
    if ($multisite && strtolower($prefix) === strtolower($base_prefix)
        && preg_match('/^' . preg_quote(strtolower($base_prefix), '/') . '\d+_/', $lower) === 1) {
        return 'it belongs to another site in this network.';
    }
    $suffix = substr($lower, strlen($prefix));
    foreach ($denied as $name) {
        // `users_backup` and `options_old` hold the same data as the tables they copy.
        if ($suffix === $name || str_starts_with($suffix, $name . '_')) {
            return 'it holds credentials, settings or personal data; use the abilities made for it instead.';
        }
    }
    return '';
}

/**
 * The statement actually sent: the checked SELECT as a derived table, capped, with the server's
 * own statement timeout.
 *
 * Detected per server because the two differ: MySQL 5.7.8+ reads a MAX_EXECUTION_TIME optimizer
 * hint on the outer SELECT (milliseconds); MariaDB 10.1.1+ ignores that hint and needs
 * `SET STATEMENT max_statement_time=<seconds> FOR`. Older servers get no timeout, and the caller
 * reports that rather than pretending.
 *
 * @param array{flavor: string, version: string} $server
 * @return array{sql: string, timeout_ms: int|null}
 */
function wrap(string $sql, int $limit, int $timeout_ms, array $server): array
{
    $limit = max(1, $limit);
    $inner = sprintf('SELECT * FROM (%s) AS kit_q LIMIT %d', $sql, $limit);
    if ($server['flavor'] === 'mariadb' && version_compare($server['version'], '10.1.1', '>=')) {
        return [
            'sql' => sprintf('SET STATEMENT max_statement_time=%s FOR %s', rtrim(rtrim(sprintf('%.3F', $timeout_ms / 1000), '0'), '.'), $inner),
            'timeout_ms' => $timeout_ms,
        ];
    }
    if ($server['flavor'] === 'mysql' && version_compare($server['version'], '5.7.8', '>=')) {
        return [
            'sql' => sprintf('SELECT /*+ MAX_EXECUTION_TIME(%d) */ * FROM (%s) AS kit_q LIMIT %d', $timeout_ms, $sql, $limit),
            'timeout_ms' => $timeout_ms,
        ];
    }
    return ['sql' => $inner, 'timeout_ms' => null];
}

/**
 * MySQL or MariaDB, and the version, from `SELECT VERSION()`.
 *
 * @return array{flavor: string, version: string}
 */
function parse_server_version(string $version): array
{
    $flavor = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
    // Some MariaDB builds report `5.5.5-10.11.6-MariaDB` for old replication clients.
    $version = (string) preg_replace('/^5\.5\.5-/', '', $version);
    return [
        'flavor' => $flavor,
        'version' => preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) === 1 ? $m[1] : '0.0.0',
    ];
}
