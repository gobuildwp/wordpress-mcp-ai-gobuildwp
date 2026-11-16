# maintenance-mode

A timed "down for maintenance" page. While it is on, every front-end request from a visitor who is not an administrator gets HTTP 503 with a `Retry-After` header (seconds until it switches off) and the stored message. Administrators see the site normally, with a red **Maintenance mode** node in the admin bar.

It hooks `template_redirect` only, so wp-admin, `wp-login.php`, the REST API, admin-ajax and WP-Cron are never blocked. It always has an end time (1 minute to 7 days) and stops by itself when that passes; nothing is written on the front end when it does.

The switch is the autoloaded option `wppilot_kit_maintenance_mode`. To let someone else through, return true from the filter `wppilot_kit_maintenance_mode_bypass` (default: `current_user_can('manage_options')`). Deactivating the plugin also ends it.

| Ability | Kind |
|---|---|
| `wppilot/maintenance-mode-status` | Read-only. `{active, expired, message, until, until_iso, seconds_left, started, started_by}` |
| `wppilot/set-maintenance-mode` | Write. `enabled`, `minutes`, `message`; switching on needs `confirm=true`. Undo restores the previous setting. |

Tests: `tests/Unit/Kits/MaintenanceMode`.
