# changes-export kit

Exports the change ledger as flat rows for reports and audits.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/export-changes` | read | Ledger rows newest first, filtered by time, agent, ability, batch, kind and status. At most 500 rows / 256 KB per call, paged with `offset` / `next_offset`. Before-images never leave the site. |

## Host needs

- `Ledger::query()` and `Ledger::export_row()`: the rows, and their flat shape.
- `Ledger::download_url()`: where a person downloads an export too large to return inline, or
  empty when there is no such screen.

Standalone, the runtime's MiniLedger answers all three, covering the changes the plugin's own
kits recorded, and there is no download screen.

<!-- kit-export:omit -->
Inside GoBuildWP these are the Changes screen's own reader (`wppilot_query_change_log()`), so the
ability, the screen and the CSV/JSON download always agree, and `download_url()` is GoBuildWP's
Changes screen.
<!-- /kit-export:omit -->

## Safety

Read-only (`readonly: true`), so it runs under every safety profile and is never
confirmation-gated. The row shape leaves out rollback snapshots by construction.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/ChangesExport/` in the GoBuildWP repository (not shipped).
<!-- /kit-export:omit -->
