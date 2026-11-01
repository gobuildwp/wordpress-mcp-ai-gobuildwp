# not-found-log

Records the paths that return 404 on `template_redirect`. The log is rolled up per path, capped at 200 paths and stored in the non-autoloaded option `wppilot_kit_not_found_log`. It keeps the path without its query string and the referrer's host only.

To turn recording off, return false from the filter `wppilot_kit_not_found_log_enabled`.

| Ability | Kind |
|---|---|
| `wppilot/not-found-log` | Read-only. `{entries: [{path, hits, referrer, last_seen}], tracked_paths, max_paths, recording}` |

GoBuildWP Cloud reads it daily for the broken-link monitor.

Tests: `tests/Unit/Kits/NotFoundLog`.
