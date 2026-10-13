# multisite kit

Lists a multisite network's sites and runs one ability on one of them.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/network-list-sites` | read | Sites with ID, name, home URL, domain/path, main-site flag and status flags; paged; `manage_sites`. |
| `wppilot/network-run-ability` | write | Runs another ability on a chosen site through the host's controls, then restores the original site; `manage_network`. |

## Loading only on a network

kit.json requires `is_multisite()`, which every install has, so the manifest alone cannot keep the
kit off a single site. `bootstrap.php` returns `['skip' => 'This site is not a multisite
network.']` when `is_multisite()` is false; the runtime's loader reports the kit as skipped with
that reason and registers nothing.

## Running an ability on another site

1. Refused before anything switches: no `manage_network`; the ability is this one; no such site;
   the site is flagged deleted; no such ability.
2. `switch_to_blog($site_id)`, then `Runtime\run_ability()`, then `restore_current_blog()` in a
   `finally`, so a refusal, an error or an exception all leave the request on the site it came
   from. The result is checked: if the current site is not the original one, the call fails.
3. `Runtime\run_ability()` runs the inner ability through the host's controls, never
   `execute()` alone. On a host with no runner of its own it applies the confirm guard (a
   destructive inner ability needs `confirm: true` in its own input) and then `execute()`.
   `execute()` runs the inner ability's own permission callback, on the target site, for the
   user's role there.
4. Everything the inner ability reads or writes through site-scoped APIs is the target site's,
   including options, so its safety profile and agent switch are the target site's, and its
   change ledger. The inner change is recorded there; the result's `change_record.change_ids`
   lists the rows it added (compared by ability name before and after the call).

## Undo

The inner change is undone on its own site: `network-run-ability` with the rollback ability and the
change ID. The outer call's row on the calling site is recorded as not reversible, with the reason
naming the site and change IDs, through the `kits/network-run` strategy whose build only says
that.

<!-- kit-export:omit -->
## Inside GoBuildWP

GoBuildWP's host answers `extension('ability-runner')` with `WPPilotHost::run_ability()`: the
target site's Abilities Hub switch for that ability, then
`wppilot_gate_ability_call($ability, $input, transport: 'nested')`, then `execute()`. It all runs
after the switch, so the target site's Hub rules and safety profile, the inner ability's
confirmation rule, the confirm strip, and every `wppilot_pre_ability_execute` control (design and
preview gates) apply there. The rate limiter and Pro's approval holds skip `nested`: the outer call was charged
and held when it arrived, and a held inner call would be replayed later on the wrong site. Pro's
approval policy therefore sees `wppilot/network-run-ability`, not what it carries.

Abilities are registered once per request, from the plugins active on the site the request
arrived at, and the ability policy unregisters what the calling site's profile or Abilities Hub
refuses. So an ability must be available on the calling site too: one the calling site has
switched off cannot be run on any site through this, and an ability from a plugin active only on
the target site is not registered at all. The reverse is not caught: an ability from a plugin
active on the calling site but not on the target still runs there, against a site whose tables and
options that plugin never set up.
<!-- /kit-export:omit -->

## Safety

Not destructive in itself (`destructive: false`): the carried ability brings its own risk class,
applied on the target site. Marking this one destructive would demand a confirmation for every
read across the network and still say nothing about the call inside it.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/Multisite/` in the GoBuildWP repository (not shipped), plus
`tests/Unit/Kits/AbilityRunnerTest.php` for the runtime helper and GoBuildWP's runner.
<!-- /kit-export:omit -->
