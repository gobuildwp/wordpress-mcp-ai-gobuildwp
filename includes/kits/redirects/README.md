# redirects

The site's own redirects: 301, 302 and 410, matched exactly or by prefix, with hit counts, a 301
whenever a published URL changes, and suggestions from the not-found-log kit's 404 log.

## Storage

Rules live in the autoloaded option `wppilot_kit_redirects` (id => rule, at most 500), because
every front-end request reads them. Hit counts live in the non-autoloaded option
`wppilot_kit_redirect_hits` and are written at most once per second, so they are a floor.

A rule is `{id, source, match, target, type, origin, post_id, created}`: `match` is `exact` or
`prefix` (or a kind an extension adds), `type` is 301, 302 or 410 (`target` is '' for a 410), and
`origin` is `manual` or `auto`.

## Serving

`template_redirect` at priority 0, before `redirect_canonical` and `wp_old_slug_redirect`. Exact
rules win, then the longest prefix, then extension kinds. Matching ignores case, a trailing slash
and the query string; the request's query string is appended to a target that has none. A prefix
rule appends the rest of the path. A 410 sets the 404 template with status 410 and stops core's
guessed redirects. WordPress endpoints are never redirected.

## Writes

Every create, delete and automatic change is one change-log row with strategy
`kits/redirect-rule`, holding the rule before and after. Undo refuses a rule that changed since,
and re-checks loops before putting a deleted rule back. Loops and chains of more than 3
redirects are refused, traced from every rule's source (a chain entering a prefix rule below its
source is not seen).

## Automatic redirects

`pre_post_update` remembers the published URLs a save may move (the post and up to 50
published children, while the database still has the old parent chain); `post_updated` at 99
creates the 301s. Off when the filter `wppilot_kit_redirects_auto` returns false, or when the
host's `auto-redirects-elsewhere` extension point is true.

## Extension points

| Hook | Purpose |
|---|---|
| `wppilot_kit_redirects_enabled` | Return false to stop serving (rules are kept). |
| `wppilot_kit_redirects_auto` | Return false to stop automatic redirects. |
| `wppilot_kit_redirect_match_types` | Add match kinds (for example `regex`) to the list `redirect-create` accepts. |
| `wppilot_kit_redirect_match` | `(null, $rule, $path)`: the location a rule of an added kind sends `$path` to, or null. |

| Ability | Kind |
|---|---|
| `wppilot/redirect-list` | Read-only. |
| `wppilot/redirect-test` | Read-only. Simulated trace. |
| `wppilot/redirect-suggest` | Read-only. Needs not-found-log. |
| `wppilot/redirect-create` | Write, undoable. |
| `wppilot/redirect-delete` | Destructive, undoable, needs confirmation. |

Tests: `tests/Unit/Kits/Redirects`.
