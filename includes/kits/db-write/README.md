# db-write kit

Row writes on a plugin's own tables, one primary key at a time, each recorded with its
before-image so the change log can put it back.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/db-insert-row` | write | Inserts one row, reads it back. Undo deletes it. |
| `wppilot/db-update-rows` | write | Sets columns on the rows an equality `where` matches, at most 200, only when the match count equals `expected_count`. Undo puts the previous values back. |
| `wppilot/db-delete-rows` | destructive | Deletes the rows an equality `where` matches, at most 50, only when the count equals `expected_count`, with confirmation. Undo re-inserts each row with its primary key. |

All three declare `meta.safety.min_profile: developer` and call `Runtime\require_profile()` from
their permission callback. All take `dry_run`, which returns counts, primary keys and column
names, never values.

## Which tables

Writable: a base table carrying `$wpdb->prefix`, not another network site's, with a
single-column primary key and column names of letters, digits and underscores. Refused:

- everything db-read refuses (views, foreign prefixes, `users`, `usermeta`, `options`,
  `sitemeta`, `signups`, `registration_log`, the WooCommerce API-key and payment-token tables,
  and the `wppilot_kit_db_read_denied_tables` filter);
- WordPress core tables: posts, postmeta, comments, commentmeta, terms, termmeta, term_taxonomy,
  term_relationships, links, and the multisite tables;
- the host's own tables: names starting with `Host::id()` and `_`, plus the host's
  `protected-table-prefixes` extension point;
- names from the `wppilot_kit_db_protected_tables` filter and the host's `protected-tables`
  extension point, with or without the site prefix. Both can only add names.

## How a write runs

Names go through `%i` and are checked against the table's columns from `information_schema`;
values through `%s` or `$wpdb->insert/update/delete`. Values are scalars or null. Every
before-image is sized before anything is written; a call over the ledger's
`snapshot_budget()` is refused whole. Updates and deletes go one row at a time by primary key,
and a failure stops the call with the rows already written still recorded.

## Undo

Strategy `kits/db-write-row`. Each change-log row holds the table, the primary key, the columns
written, the state to put back and the state the write left. The undo checks the table again,
reads the row, and refuses with `kit_db_write_changed_since` when it no longer holds the state
the write left.

## Limits

- A row changed between the matching read and its update is overwritten.
- Plugin object caches are not cleared.
- No session-undo state check (`SessionLedger::register_state`): the host's session undo
  undoes these rows through the same conflict check, one at a time.

## Host needs

`Runtime\require_profile()` (runtime 1.1), `Ledger::record_items()` and
`Ledger::register_strategy()`, and `wpdb::prepare()` with `%i` (WordPress 6.2+).

<!-- kit-export:omit -->
Inside GoBuildWP the `protected-tables` point returns the `wppilot_db_protected_tables` filter.
The three names are critical in `includes/safety.php`, so they are refused
outside the Developer profile and always need confirmation, `dry_run` included.
<!-- /kit-export:omit -->

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/DbWrite/` in the GoBuildWP repository (not shipped).
<!-- /kit-export:omit -->
