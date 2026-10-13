# site-tools kit

WP-Cron, Site Health, transients and the options table.

| Ability | Kind | Undo | What it does |
|---|---|---|---|
| `wppilot/cron-list` | read | — | Events soonest first: hook, time, schedule, args and their key, due/overdue, whether anything listens; the schedules; whether cron is disabled or running. |
| `wppilot/cron-run` | write | irreversible | Runs one due or overdue occurrence as wp-cron.php does: reschedule if recurring, unschedule, fire the hook as no user with `wp_doing_cron()` true, under the `doing_cron` lock. |
| `wppilot/cron-delete` | write, destructive (confirm) | `kits/cron-event` | Unschedules one occurrence. Undo schedules the same hook, time, schedule and args and verifies it is back; it refuses rather than duplicate an event its plugin has already re-added. |
| `wppilot/site-health-tests` | read | — | Runs the direct `WP_Site_Health` tests as core's weekly check does (through `site_status_test_result`); asynchronous tests are listed as not run. |
| `wppilot/transients-flush` | write | irreversible | `expired` (default): `delete_expired_transients(true)`. `all` (needs `confirm: true`): every transient, 5,000 per call, plus the object cache's transient groups where it can flush by group. |
| `wppilot/options-explore` | read, Developer, audited | — | Option names, autoload, size and a raw (never unserialized) preview, by substring or `*`/`?` pattern, ordered by name or size, with the total autoloaded bytes. |

## Choices

- An occurrence is named by `hook` + `timestamp` + `key`, where `key` is WordPress's own
  `md5(serialize($args))`. Arguments that do not survive JSON (objects, floats) can still be
  addressed by key; `args` is accepted too and must hash to the same key.
- `cron-run` refuses a future event: catching up what is late and running something early are
  different requests. It also refuses while another cron run holds the lock.
- `transients-flush` confirms `all` itself rather than being annotated destructive, so the safe
  `expired` scope never needs a confirmation. With an external object cache the table rows are
  stale leftovers and are deleted as options.
- `options-explore` withholds a value when the name looks secret (keys, salts, tokens,
  passwords, licences, API, SMTP, OAuth, webhooks, emails), when it is a known secret option
  (core keys and salts, `recovery_keys`, `mailserver_pass`, Freemius `fs_accounts`), when the
  name carries the host plugin's id (its own settings), or when the
  `wppilot_kit_options_explore_denied` filter lists it (exact names, or prefixes ending in `*`).
  Names are always shown.

## Host needs

`Runtime\require_profile()` (runtime 1.1), `Ledger::record_items()` for the irreversible rows
and the cron-delete before-image, and `Ledger::register_strategy()` for `kits/cron-event`, which
boot registers.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/SiteTools/` in the GoBuildWP repository (not shipped).
<!-- /kit-export:omit -->
