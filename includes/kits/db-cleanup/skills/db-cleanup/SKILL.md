---
name: db-cleanup
description: Remove old spam comments, trashed comments and post revisions from a WordPress database, with undo. Activate when the user asks to clean up or slim the database, clear spam, empty the comment trash or prune revisions.
---

# Database cleanup

1. `wppilot/db-cleanup-preview` counts what would go: spam comments, trashed comments and post
   revisions older than `older_than_days` (default 30). Tell the user the counts.
2. `wppilot/db-cleanup` removes them, oldest first, up to `limit` rows (at most 500) per call. It
   needs the user's confirmation. Name only the `targets` the user agreed to.
3. Each removed row is recorded with its meta. Undo from the change log puts it back with its
   original id; it refuses when that id is in use again or the post it belonged to is gone.
4. If `remaining` is above zero or `stopped_at_snapshot_budget` is true, there is more to clean:
   run it again after telling the user.

Revisions of published posts are history the user may want. Ask before removing them.
