# Kits

A kit is one feature in a folder that can be copied into another plugin with
`scripts/export-kit.php`: `kit.json`, `bootstrap.php`, `src/`, `skills/` and a
`README.md`. `_runtime/` is the only code a kit may lean on besides WordPress;
see the header of `_runtime/runtime.php`. The rules a kit is held to are in the
header of `scripts/check-kit-boundaries.php`, and the proof that an exported
copy can run beside GoBuildWP's own is `scripts/test-kit-coexistence.php`.

## kit.json

| Field | Meaning |
| --- | --- |
| `slug` | The folder name. |
| `version` | `MAJOR.MINOR.PATCH` of the kit itself. |
| `namespace` | `WPPilot\Kits\<Name>`; the exporter rewrites the `WPPilot\Kits` part. |
| `tier` | `free` or `pro`, matching the repository the kit lives in. |
| `runtime` | The runtime major it is written against, as `^1.0`. |
| `requires.php`, `requires.wp` | Minimum versions; the runtime skips the kit below them and says why. |
| `requires.classes`, `requires.functions` | What the vendor plugin must provide; the kit is skipped while it is inactive. |
| `requires.any` | `{"constants": [], "classes": [], "functions": []}`: for a kit serving whichever of several plugins is active, the symbols of which at least one must exist; the kit is skipped while none does. The coexistence check stands every one of them in, so all the kit's vendor files register there. Runtime 1.2. |
| `requires.kits` | Other kits this one runs or reads (`scheduled-audits` runs `a11y-audit` and `content-audit`). The exporter adds them to every export of this kit, transitively. A Free kit may only require Free kits. |
| `vendor_storage` | Storage names another plugin owns that this kit reads or writes, by kind: `{"option": [], "meta": [], "transient": [], "cron": []}`. See below. |
| `categories` | Ability categories the kit registers when the host has not. |
| `abilities` | Every ability the kit registers, with `readonly`, `destructive` and `ledger`. |
| `skills` | Skill folders under `skills/`. |
| `tests` | Test paths in this repository, exported with the kit. |

### vendor_storage

The coexistence gate fails when GoBuildWP's copy of a kit and an exported copy
name the same option, meta key, transient or cron hook, because the exporter
should have renamed it and did not. A name that belongs to another plugin is
the exception: Elementor's `_elementor_data`, Slim SEO's `slim_seo` array or
UpdraftPlus's `updraft_backup` event is where that plugin keeps its data, both
copies talking to it is the point, and renaming it would break the kit. Declare
such names here and the gate accepts them:

```json
"vendor_storage": {
    "meta": ["_elementor_data", "_elementor_element_cache"],
    "cron": ["backwpup_cron"]
}
```

Only another plugin's names belong here; `check-kit-boundaries.php` refuses one
naming GoBuildWP, since those are the kit's own and have to be renamed. WordPress's
own options (`blogname`, `gmt_offset` and the like) are not declared: the gate
knows them.
