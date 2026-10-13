# db-read kit

This site's database tables, and one checked, read-only SELECT over them.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/database-tables` | read | Tables carrying this site's prefix: engine, estimated rows, data and index bytes, columns, and whether a query may read each one (and why not). |
| `wppilot/database-query` | read | One SELECT, at most 200 rows, inside `START TRANSACTION READ ONLY` … `ROLLBACK`, with a server-side timeout. Sensitive columns are redacted. |

Both declare `meta.safety.min_profile: developer` and `audit_reads: true`, and call
`Runtime\require_profile()` from their permission callback, so they are refused below the
Developer profile on every WordPress version, and every call leaves an audit row with its input.

## What a query may contain

The statement is tokenized as MySQL and MariaDB lex it (strings, backtick names, comments), not
matched with patterns. Refused:

- anything but a single `SELECT`: no `WITH`, `SHOW`, `EXPLAIN`, `TABLE` or a second statement;
- comments of every kind: `#`, `-- `, `/* */`, including `/*!` (executable) and `/*+` (hints);
- backslashes (their meaning depends on `NO_BACKSLASH_ESCAPES`), double quotes (strings or names
  depending on `ANSI_QUOTES`), `@` variables, `?` and `{ }`;
- `INTO`, `OUTFILE`, `DUMPFILE`, `LOAD_FILE`, `SLEEP`, `BENCHMARK`, named-lock and
  replication-wait functions, sequence functions, `FOR UPDATE`, `FOR SHARE`,
  `LOCK IN SHARE MODE`, `PROCEDURE`, index hints, `NATURAL JOIN`;
- any name equal to `information_schema`, `mysql`, `performance_schema` or `sys`;
- any name, anywhere in the statement, equal to a table in this database that is not queryable,
  and any table in a FROM or JOIN position that is not queryable, including a
  database-qualified name.

A table is queryable when it is a base table (not a view: a view can read anything), carries
`$wpdb->prefix`, does not belong to another network site, and is not denied. Denied by default,
after the prefix: `users`, `usermeta`, `options`, `sitemeta`, `signups`, `registration_log`,
`woocommerce_api_keys`, `woocommerce_payment_tokens`, `woocommerce_payment_tokenmeta`. Add names
(without the prefix) with the `wppilot_kit_db_read_denied_tables` filter; the defaults cannot be
removed.

## Redaction

A column is sensitive when its name matches passwords, secrets, tokens, API/private/consumer/
access/activation/auth keys, salts, nonces, hashes, credentials, e-mail addresses or IP
addresses (`user_pass`, `post_password`, `comment_author_email`, `comment_author_IP`; not
`meta_key` or `post_author`). Its values come back as `[redacted]`, and so does `meta_value` /
`option_value` on a row whose `meta_key` / `option_name` is sensitive.

Because redaction reads the result's column names, a sensitive column may only be selected on its
own, by its own name, or through `*`, in the outer select or a derived table of it. It cannot be
filtered, joined, sorted, grouped, renamed, computed on, or selected in a later UNION branch or a
scalar subquery: each of those would surface its value, or an answer about it, under another name.
Redaction guards against a secret landing in a transcript by accident (a value inside JSON is
not seen); the boundary against deliberate reads is the refused tables, the Developer profile and
the audit row.

## Execution

`SELECT * FROM (<query>) AS kit_q LIMIT <n>`, `n` ≤ 200. Timeout: MySQL 5.7.8+ gets a
`MAX_EXECUTION_TIME` hint, MariaDB 10.1.1+ `SET STATEMENT max_statement_time=… FOR`; older
servers run without one and the result says so. An outer `ORDER BY` without `LIMIT` gets one,
because a derived table may otherwise drop the order. Cells are cut at 1,000 characters and a
result at 256 KB.

## Host needs

`Runtime\require_profile()` (runtime 1.1), and an audit row for `audit_reads` abilities, which
the standalone host's MiniLedger keeps.

<!-- kit-export:omit -->
Inside GoBuildWP the profile answer is GoBuildWP's own (`wppilot_safety_check_ability()`) and the
audit row is a change-log `audit-read` row.
<!-- /kit-export:omit -->

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/DbRead/` in the GoBuildWP repository (not shipped).
<!-- /kit-export:omit -->
