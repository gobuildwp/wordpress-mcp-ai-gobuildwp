# search-replace kit

Find and replace across post titles, content, excerpts and post meta. The kit works from a stored
plan that a person reviewed, and every changed post can be undone on its own.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/search-replace-preview` | read | Scans posts in scope and stores a plan (`plan_id`, one hour, bound to the user). Returns per-post fingerprints and diffs: match counts plus before/after snippets for each field or meta key, totals, skips with reasons, and the builder caches that will need clearing. Holds at most 500 posts per plan and scans 2,000 posts per call, continued with `after_id`. |
| `wppilot/search-replace-apply` | write, destructive (confirm) | Writes the plan: only posts that are in the plan and whose fingerprint still matches. At most 100 posts per call, continued through the plan cursor or queued as a background job with `background: true`. |
| `wppilot/search-replace-status` | read | Progress of a background job, and each post's state in a plan. |
| `wppilot/search-replace-cancel` | write (job record only) | Stops a background job the user queued. A running step finishes its batch; written posts stay undoable; unreached posts stay pending in the plan. Irreversible in the ledger's sense: a cancelled job cannot be resumed, so the row says so. |

## How values are changed

- **Fields**: `post_title`, `post_content` and `post_excerpt`, written through `wp_update_post()`
  with `wp_slash()`. `guid` and options are never touched.
- **Serialized meta** is read raw and unserialized with `allowed_classes => false`. Any value that
  contains an object is skipped and reported. Arrays are walked, their strings replaced, and the
  result stored through `update_post_meta()`.
- **JSON meta** (for example `_elementor_data`) is decoded, walked (keys untouched, `{}` kept as
  `{}`), and re-encoded. The kit uses whichever `json_encode()` flags reproduce the stored bytes
  exactly. When none do, the diff flags `escaping_changes`. Documents holding integers too large
  to re-encode exactly are skipped.
- **Plain meta** is replaced as text. Keys holding several values on one post are skipped, as is
  bookkeeping meta: edit locks, old slugs, attachment paths and metadata, and Elementor's CSS and
  element caches.
- **Regex** is off by default. When on, patterns run with `pcre.backtrack_limit` lowered to
  100,000. A value that hits the limit is skipped, never half-written. A pattern that can match an
  empty string is refused. Preview and apply batches also stop after 20 seconds and return a cursor.
- Every write is read back. A meta value is compared byte for byte with the preview. A field is
  compared after save, so content filtered by kses is reported as a warning.

## Undo

Before each post is written, the kit takes a `kits/post-partial` before-image. It covers exactly
the fields and meta keys the plan touches on that post, and nothing else. Each post gets one
ledger row through `Ledger::record_items()`, and every row of a plan shares the plan's group,
across calls and background steps. That means one post can be undone on its own, or the whole
plan through its group.

A call stops and returns a cursor before the next before-image would exceed
`Ledger::snapshot_budget()`. It never writes a post without one. A post whose before-image alone
exceeds the budget is left out and reported.

## Caches

The kit detects builders by the meta keys they leave on a post (Elementor, Beaver Builder, Bricks,
Divi and WPBakery). After a write it clears caches only through APIs it has checked are there:

- Elementor: `\Elementor\Plugin::$instance->files_manager->clear_cache()`, which clears CSS files
  plus the post CSS, element-cache and assets meta.
- Beaver Builder: `FLBuilderModel::delete_all_asset_cache($post_id)`.

For every other builder, and for page caches and CDNs, the result says what the person should clear.

## Host needs

- `Ledger::record_items()` with a group, `Ledger::snapshot_budget()`, and the runtime's
  `kits/post-partial` restore strategy.
- `Jobs` (the runtime's Runner) for `background: true` and `Jobs::cancel()` for search-replace-cancel.
- `confirm_guard()`. Standalone hosts enforce `confirm: true` here. The schema declares `confirm`
  for that reason.

Plans and locks are stored in non-autoloaded `wppilot_kit_sr_*` options. Expired plans are
removed on each preview.

<!-- kit-export:omit -->
Inside GoBuildWP, an MCP client on the 2026-07-28 transport can also call apply as a task
(`meta.mcp.task_support`): the task follows the background job, `tasks/cancel` cancels it, and
`tasks/result` returns search-replace-status for the job.

Inside GoBuildWP, the gate pipeline asks for confirmation before apply runs. The ledger is
GoBuildWP's change log, and the Changes screen's "Undo this batch" undoes a whole plan by its group.
<!-- /kit-export:omit -->

## Safety

Preview and status are read-only. Cancel changes only the job record of the user's own job. Apply is `destructive: true`, so it is confirmation-gated, and
each post it writes needs `edit_post` for the current user.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/SearchReplace/` in the GoBuildWP repository (not shipped).
<!-- /kit-export:omit -->
