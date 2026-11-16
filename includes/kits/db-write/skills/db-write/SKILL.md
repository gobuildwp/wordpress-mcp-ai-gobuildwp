---
name: db-write
description: Insert, update or delete rows in a plugin's own database table when no dedicated ability covers the change — one primary key at a time, each row recorded so it can be undone. Activate when the user asks to fix, add or remove records in a custom table (a form plugin's entries, a booking plugin's slots). Developer profile only; never for posts, terms, comments, options or users.
---

# Writing rows in a custom table

Three abilities, all available only under the Developer safety profile, and all recorded in the
change log row by row with the row's previous state.

- `wppilot/db-insert-row`: one new row.
- `wppilot/db-update-rows`: set columns on up to 200 rows matched by an equality `where`.
- `wppilot/db-delete-rows`: delete up to 50 rows matched the same way. Destructive: confirm.

## Before writing

1. Prefer a dedicated ability. Posts, terms, comments, options, users and their meta are refused
   here on purpose: a raw row write skips the caches, counters and hooks WordPress keeps for them.
2. `wppilot/database-tables` to find the table and its columns, then `wppilot/database-query` to
   look at the rows you mean to change and count them.
3. Call the write with `dry_run: true`. It checks the table and returns the matched count, the
   primary keys and the columns that would change, never values.
4. Call it for real with `expected_count` set to that count. A different count changes nothing,
   so a row that appeared or vanished in between does not get swept up.

## Rules the abilities enforce

- `table` is the full name with the site prefix (`wp_myplugin_items`).
- The table needs a single-column primary key; tables without one are refused, because each row
  is recorded and undone by that key.
- `where` is column => value, every pair must match, `null` means `IS NULL`. No ranges, no LIKE.
  Credential-looking columns (passwords, tokens, keys, emails, IPs) cannot be filtered on.
- `set` cannot change the primary key.
- Refused tables: WordPress core tables, this plugin's own tables, credential tables
  (WooCommerce API keys, payment tokens), views, other network sites' tables, and any the site
  protects (the `wppilot_kit_db_protected_tables` filter).
- A call whose before-images would not fit the change log is refused as a whole; write fewer rows.

## Undo

The result's `change_ids` (and its receipt) list one change-log row per written row. Undo one with
`wppilot/rollback-change`. The undo is refused when the row changed again after the write, so a
later edit is never overwritten.

## Things it cannot know

Writes go straight to the table: a plugin that caches its rows in the object cache may keep
serving the old values until that cache expires. A row edited by someone else between the read
and the write is overwritten by `db-update-rows`; the count check narrows that window, it does not
close it.
