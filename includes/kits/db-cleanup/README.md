# db-cleanup

Removes old spam comments, trashed comments and post revisions.

| Ability | Kind |
|---|---|
| `wppilot/db-cleanup-preview` | Read-only. Counts per target. |
| `wppilot/db-cleanup` | Destructive, needs confirmation. Undo strategy `kits/db-cleanup-row` re-inserts each row and its meta with the original ids. |

Arguments: `targets` (`spam_comments`, `trash_comments`, `revisions`), `older_than_days` (1–3650, default 30) and `limit` (1–500).

GoBuildWP Cloud schedules runs. It sends `confirm: true` only for a schedule that an owner or admin approved. Otherwise it only calls the preview.

Tests: `tests/Unit/Kits/DbCleanup`.
