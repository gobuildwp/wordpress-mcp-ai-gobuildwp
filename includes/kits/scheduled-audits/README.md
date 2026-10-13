# scheduled-audits kit

Read-only audits the site runs on a schedule, on the server, with no agent connected; the results
are emailed to chosen administrators and stored with a comparison to the previous run. Free tier;
runs the `a11y-audit` and `content-audit` kits' abilities.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/routines-list` | read | Routines with schedule, next run, run in progress, last result; the administrators to choose from by user id. |
| `wppilot/routines-report` | read | The last runs (20 kept, or 1 with history off), each with per-audit summaries and a diff against the run before: new, resolved and changed issues. |
| `wppilot/routines-save` | write | Create or update a routine: audits (with inputs validated against each audit's own schema), daily/weekly schedule in the site timezone, delivery (admin user ids, stored report), run-as user. Ledger: restores that one routine's definition. |
| `wppilot/routines-delete` | write, destructive | Removes a routine, its events and its reports. Ledger: restores the definition and the reports. |
| `wppilot/routines-run-now` | write | Queues one run on WP-Cron; refused while one is queued or running and for 10 minutes after the last one by hand. Ledger: irreversible (nothing to undo). |

Each ability registers only while its name is unclaimed (`Runtime\unclaimed()`), so a plugin
that registered the same name first keeps it.

## Runs

A routine may run only `wppilot/audit-accessibility`, `wppilot/audit-content` and
`wppilot/audit-media-alt` (the `a11y-audit` and `content-audit` kits), and the runner checks
each is still annotated read-only before every call. Calls go through `Runtime\run_ability()`, so
every audit passes the host's controls and its own permission check, as the routine's `run_as`
administrator: WP-Cron runs as nobody.

<!-- kit-export:omit -->
Inside GoBuildWP those controls are the whole gate pipeline: safety profile, Abilities Hub and every
`wppilot_pre_ability_execute` control.
<!-- /kit-export:omit -->

WP-Cron hook `wppilot_kit_routines_tick` (argument: routine id) starts and advances a run for up
to 20 seconds per tick and saves its place. The content audit is a background job; the routine
starts it and reads `wppilot/audit-content-status` on later ticks (every 30 seconds) until it is
done. A run unfinished after 3 hours is closed with its open audits failed. The schedule is
re-derived from the stored definitions whenever the `wppilot_kit_routines` option changes (so an
undo from the change log reschedules too) and hourly (`wppilot_kit_routines_reconcile`).

Issues are compared by a key per audit — accessibility: page path and rule; content: finding type,
post id and link; media: attachment id and alt status — capped at 300 per audit. Only the newest
run keeps its issue list.

## Storage

- `wppilot_kit_routines` — definitions.
- `wppilot_kit_routines_state_<id>` — next run, run in progress, run-now request, last result.
- `wppilot_kit_routines_report_<id>` — stored runs.
- `wppilot_kit_routines_lock_<id>` — a tick's lease.

## Email

Plain text through `wp_mail()`, one message per recipient: counts per audit, the diff counts and a
link to the report (`wppilot_kit_routines_report_url` filter; the host's admin screen supplies
it). No page content, titles, markup or personal data. Recipients are administrators by user id,
re-checked at send time.

## Host needs

`Runtime\can_run()` plus `manage_options` for every ability; `Runtime\run_ability()`; the host's
ledger (`record_items`, `register_strategy` for `kits/routines-routine`). The host must boot the
kit on every request, WP-Cron's included, since `register_hooks()` runs in the boot.

Extension point `routines-elsewhere`: a non-empty string when another copy of this feature already
runs the same stored routines on this site. The kit then does not load (the string is the skip
reason), because two copies would both answer every tick. Standalone it is always null.

<!-- kit-export:omit -->
## Inside GoBuildWP

The names above are the ones GoBuildWP Pro 1.10.0's `routines` kit used, kept so routines a site
already has keep running, reporting and undoing once Free carries them. Pro 1.10.0 boots its own
copy early (`WPPilot\Pro\Kits\register_routines` on `plugins_loaded` 25); while that hook is
there, `includes/admin/routines.php` answers `routines-elsewhere` and this kit stands aside. The
same file adds the Routines screen (Activity group, slug `wppilot-routines`) with its Run now
button and supplies the report URL, only when this kit loaded. `uninstall.php` removes the options
and events above.

## Tests

`tests/Unit/Kits/ScheduledAudits` (not shipped).
<!-- /kit-export:omit -->
