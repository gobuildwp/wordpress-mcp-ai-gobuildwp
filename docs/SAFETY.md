# Safety profiles

## Production Safe

This is the installation default. It permits ordinary content, design, SEO, form, and commerce operations while blocking critical primitives such as raw PHP, code-snippet and privileged dynamic-shortcode engines, raw database access, WP-CLI, filesystem access, plugin and theme installation and deletion, and temporary administrator access.

Activating, deactivating, and updating already-installed plugins and themes is permitted here, with explicit confirmation - see [Plugin and theme lifecycle](#plugin-and-theme-lifecycle).

Destructive operations that are otherwise allowed require explicit confirmation. Examples include permanent deletion and refunds.

## Read Only

Only abilities marked readonly, plus MCP resources and prompts, can execute. Use this profile for audits, discovery, reporting, and support sessions that must not mutate the site.

## Developer Full Access

All manually enabled abilities can execute, including critical developer primitives. Critical and destructive calls still require explicit confirmation. Use this profile only for deliberate development or recovery work with current backups and appropriate site access controls.

## Read Mode

Read Mode is one switch, in Settings under Agent access or with `wp wppilot read-mode on|off|status`, that makes every agent connection read-only. It sits on top of the profile: while it is on, the effective profile is Read Only for every request, whatever the site profile or a token's ceiling says. MCP, the REST ability routes, core's `wp-abilities` routes, WP-CLI, Chat and the Cloud all refuse a write with the error code `wppilot_read_mode`. Only read-only abilities run, and the `wppilot_read_mode_allows_ability` filter can take a read off that list but cannot add a write to it. Two abilities read or write by their input: `rest-request` runs for a GET only and `run-wp-cli` for a read command only, and only where the site's own profile (Read Mode aside) already allows the ability. A REST write made with an application password, an access token or OAuth is refused before it reaches any route, core's included, and XML-RPC offers its read methods only. People signed in to wp-admin are not agents and keep working. While it is on, the admin bar shows a Read Mode badge. It fails closed: only a stored `0` reads as off, and `define('WPPILOT_READ_MODE', true);` in wp-config.php pins it on.

## Pause agents

Pause agents is the stop button. In the admin bar (Pause agents / Agents paused), in Settings under Agent access, or with `wp wppilot pause on|off|status`, an administrator refuses every agent call on the site at once, reads included, with the error code `agents_paused` (HTTP 503). It is checked first in the gate pipeline, in the legacy adapter's pre-tool hook and, on WordPress 7.1 and newer, in `wp_ability_permission_result`, so MCP, the REST ability routes, core's run route, WP-CLI's `wp ability run`, Chat and the Cloud all stop. A REST request made with an application password, an access token or OAuth is refused before any route runs; only `/wppilot/v1/cloud/status` (which reports `agents_paused` so GoBuildWP Cloud can show the site as paused) and `/wppilot/v1/cloud/unlink` stay open, and the `wppilot_agents_pause_route_is_exempt` filter can add a route that only reports state. Nothing is disconnected or deleted, so resuming puts everything back. Resuming needs a person on the site. It fails closed like Read Mode, and `define('WPPILOT_AGENTS_PAUSED', true);` in wp-config.php pins it on. It is not `WPPILOT_DISABLE_MCP`, which is a host's configuration hard-off; the pause is the reversible switch an administrator presses.

WP-CLI command rules narrow `run-wp-cli` further wherever a profile allows it. A deny list, by default `db drop`, `db reset`, `db clean`, `eval*`, `shell`, `user application-password create` (and its `app-password` alias), `config set *_key`, `config set *_salt` and `config shuffle-salts`, always wins; an allow list that is not empty admits only the commands it names. Rules match the leading command words case-insensitively, with `*` inside a word. Administrators edit both lists on the Abilities screen; a save needs a confirmation tick and is recorded in the change ledger, not undoable from the Changes screen. `--exec`, `--require`, `--path`, `--ssh`, `--http` and `@aliases` are always refused, because they run code or reach another site before any rule is read. In Read Mode only read commands run, refused at the gate and again inside the ability, and `config` never counts as a read because `config get` prints the database password. `expect` pins WordPress, PHP, plugin and theme versions and refuses the run with status 409 on any mismatch.

The REST bridge (`rest-request`) calls the site's own REST API through `rest_do_request()` as the connected user. Every namespace is closed until an administrator ticks Read (GET) or Write (POST, PUT, PATCH, DELETE) for it on the Abilities screen; a switch change needs a confirmation tick when it turns Write on and is recorded in the change ledger. `batch/v1` (it runs other requests), `wp-abilities/v1` (it runs abilities), and the MCP and GoBuildWP namespaces never open. `/wp/v2/users`, `/wp/v2/settings`, `/wp/v2/plugins` and any application-passwords route never take writes. A write to a route whose permission callback is missing or `__return_true` is refused, since such a route is guarded in the browser by a nonce the bridge never sends, and `_wpnonce`, `_ajax_nonce`, `_method`, `_envelope` and `_jsonp` are refused as parameters. A write needs `confirm: true` in argument mode or a wp-admin approval in human mode, and writes one ledger row with the method, route, body keys, body SHA-256 and response code, marked not undoable; reads are counted, not ledgered. Responses are redacted by key, refused when they are not JSON, and left out above 256 KB. In Read Mode only a GET runs, refused at the gate and again inside the bridge.

## Confirmation contract

For an MCP adapter call, confirmation belongs inside the target parameters:

```json
{
  "ability_name": "wppilot/woocommerce-create-refund",
  "parameters": {
    "order_id": 123,
    "amount": "10.00",
    "refund_payment": false,
    "confirm": true
  }
}
```

GoBuildWP removes the control-only `confirm` field before target schema validation unless the target ability explicitly declares its own field with that name.

### Confirmation mode: a person approves

`confirm: true` is written by the AI model, so it proves the model decided to send it, not that a person agreed. **Settings → Confirmation → Confirmation mode** chooses what counts:

- **Agent flag** (`argument`, the default): the contract above, unchanged.
- **A person approves** (`human`): on MCP (both eras) and REST, a destructive or critical call also needs one of the following, and `confirm` alone is refused.
  - **Elicitation.** A client on the 2026-07-28 revision that declares form elicitation in `_meta` `io.modelcontextprotocol/clientCapabilities` receives an `input_required` result whose `inputRequests.wppilot_confirmation` is an `elicitation/create` form asking the user to approve a summary of the exact call. The client retries the same `tools/call` with `inputResponses.wppilot_confirmation` (the user's answer) and the `requestState` it was given. That state is a token bound by HMAC (keyed from the site's auth salt) to the ability, the SHA-256 of the input without `confirm`, the user and a 5-minute expiry. It works once.
  - **An approval link.** Every other caller is refused with a one-time wp-admin link (`wppilot_human_confirmation_required`, `data.approval_url`). An administrator opens it, sees the ability, the account it runs as and the exact input, and approves or denies. The agent's identical retry within 5 minutes of the approval then runs once. After a denial, the retry is refused with `wppilot_confirmation_denied`.

The built-in Chat and Pro's approval queue already have a person approve each call, and are unaffected by this setting.

## WordPress core surface

The typed WordPress abilities in `includes/abilities/wordpress/` are subject to every rule above, and add their own:

- **Draft-first.** Content creation defaults to `draft`. An absent, blank, mistyped, or unrecognised status resolves to `draft` before any capability is evaluated, so content is never published by omission or by a malformed value. Publication requires `status: "publish"` explicitly.
- **The post type's own capability object.** `edit_posts` is never assumed. `create_posts`, `publish_posts`, `edit_others_posts`, `edit_post`, and `delete_post` are read from the registered post type, so a custom type declaring a separate capability set is enforced on its own terms. Publishing is a distinct grant from editing: moving a draft to `publish`, `future`, or `private` is checked separately.
- **Taxonomy capabilities** come from the taxonomy - `manage_terms`, `edit_terms`, `delete_terms`, `assign_terms` - never from `manage_categories`.
- **Closed surfaces.** Internal and plugin-private post types and taxonomies are refused: `attachment`, `revision`, `nav_menu_item`, `wp_block`, `wp_template*`, `wp_navigation`, `wp_global_styles`, changesets, `nav_menu`, and anything registered neither `public` nor `show_in_rest`.
- **Commenter privacy.** Email and IP are withheld unless the connected account holds `moderate_comments`, which is also required to list comments that are not approved.
- **URL schemes.** Menu item URLs are validated against `wp_allowed_protocols()`; `javascript:`, `data:`, and `vbscript:` are refused before storage.
- **Edit locks.** `update-post`, `delete-post`, `gutenberg-write-content` and the direct writes of `gutenberg-edit-block` read the post's `_edit_lock` and refuse with `post_locked` (HTTP 409, naming the person) while another user has the post open in the editor. A lock older than core's `wp_check_post_lock_window` (150 s) is stale and ignored, and a lock held by the connected account itself does not block. `wait_seconds` (0 to 120) lets the agent wait for the editor to close; the lock is re-read every 5 s. The Block Editor Queue is not guarded here because a person finalizes it in their own editor.

## Plugin and theme lifecycle

The split across profiles follows what an operation actually does to the server, not what it is called:

| Ability | Class | Production Safe | Read Only |
| --- | --- | --- | --- |
| `search-extensions`, `get-extension` | read | yes | yes |
| `activate-plugin`, `deactivate-plugin` | destructive | yes, with `confirm` | no |
| `update-plugin`, `update-theme`, `switch-theme` | destructive | yes, with `confirm` | no |
| `install-plugin`, `install-theme` | critical | no | no |
| `delete-plugin`, `delete-theme` | critical | no | no |

Installing and deleting fetch and write executable code, which is the same class of operation as `execute-php`, so they are Developer Full Access only. Activating an already-installed plugin fetches nothing and writes no files - but it runs that plugin's activation hooks and can fatal the site, so it stays confirmation-gated on every profile that allows it.

Additional rules specific to these abilities:

- **`DISALLOW_FILE_MODS` is honoured** through `wp_is_file_mod_allowed()` before any file operation begins, so a site that has opted out of code changes gets a named refusal rather than a partial install.
- **Direct filesystem access is required.** GoBuildWP runs headless, and `request_filesystem_credentials()` has no screen to render an FTP form on. Anything other than the `direct` method is refused with the method WordPress selected, instead of stalling or half-writing.
- **GoBuildWP cannot target itself.** `deactivate-plugin` and `delete-plugin` refuse GoBuildWP and GoBuildWP Pro: severing the connection mid-call would leave the agent unable to undo what it just did.
- **Deletion requires prior deactivation.** WordPress's own `delete_plugins()` does not check, so the refusal lives in the ability. The active theme, and the parent of an active child theme, are refused for the same reason.
- **Package URLs must be HTTPS.** An install from an explicit ZIP validates the scheme before download; plain HTTP would let anything on the path substitute its own code.
- **Slugs and plugin files are path-validated.** Both go through `validate_file()` and a plain-directory-name pattern, because WordPress concatenates a stylesheet directly into a filesystem path.
- **Ledger coverage.** Activation, deactivation, and theme switches record a before-image and roll back with verification. Installs, updates, and deletions are recorded as non-reversible, each stating the actual reason and the manual path back.

Terms, menus, and comments have no WordPress trash. Deleting any of them is permanent, requires explicit confirmation, and is recorded as non-reversible.

## Protocol parity

Safety is enforced identically under both MCP revisions. The modern dispatcher runs the same guards in the same order as the legacy path - safety profile, then rate limit, then the ability's permission callback, then execution - so a client on `2026-07-28` cannot reach a weaker code path than one on `2025-11-25`. Read Only blocks every mutation in both eras, including rollback.

## Change ledger

The ledger retains at most 500 records and is also capped by total serialized size. Secrets and sensitive metadata are redacted or excluded. Before images are bounded. Rollback is offered only for supported operations and succeeds only when the observed result matches the expected fingerprint.

Permanent deletion, payment refunds, and other irreversible external side effects are recorded as non-reversible.

### Provable audit

Every row recorded since 2026-11-13 carries `prev_hash` and `row_hash`. `row_hash` is SHA-256 of `prev_hash` followed by the SHA-256 of the row as canonical JSON (keys sorted at every level), leaving out only what undo, redo and a visual capture write onto a row afterwards (`rolled_back`, `rolled_back_at`, `rollback_result`, `redo`, `undone_digest`, `redone_at`, `redo_result`, `visual`). The newest `row_hash` is also kept in the `wppilot_ledger_chain_head` option. On the Changes screen, **Verify ledger chain** checks every row and reports any row edited, deleted or inserted outside GoBuildWP as "chain break at row N"; nothing is repaired or hidden. Rows from before the chain carry no hash and are counted separately. Retention pruning removes the oldest rows, so the oldest remaining row is the anchor and its `prev_hash` is taken as given: removal from the oldest end cannot be told apart from pruning.

**Download signed audit (NDJSON)** writes a header line, one line per row oldest first, the site's own chain report, and a last line signing the SHA-256 of every byte before it with the site's Ed25519 key (the key GoBuildWP Cloud pairing uses; one is created if the site has none). Check it anywhere with `php scripts/verify-ledger-export.php FILE --public-key=KEY`, using the public key shown on the Changes screen and recorded somewhere the site cannot change. Exit status 0 means the chain is intact and the pinned key signed it.

The same check runs over MCP as the read-only ability `wppilot/verify-change-log` (it returns the report with a one-line `summary` and the `storage` in use), and the Troubleshoot screen's change-ledger check warns with the first break. Diagnostics shows the current head (`chain_head`), so two readings taken apart can be compared. The chain works the same in table and option storage: the hashes live in the row itself. The age-based prune removes only the unbroken run of oldest rows, so it never opens a gap in the middle that would read as a deletion.

Limits: the hash is unkeyed SHA-256, so someone with database write access can rewrite every row and the stored head consistently. The chain catches piecemeal edits made outside GoBuildWP; against a full rewrite, only a signed export taken earlier and checked against a public key pinned off the site proves what the ledger held.

### Session undo and redo

Each record written by an agent carries the agent session it belongs to: the `Mcp-Session-Id` of a legacy MCP connection (stored as a keyed hash), one `wp wppilot mcp serve` process, or, for the stateless 2026-07-28 transport and the REST run route, one credential and client until it has been quiet for 30 minutes. Writes from wp-admin, cron and plain WP-CLI carry none and are never part of a session. The record also keeps a digest of the target's state straight after the write.

`wppilot/undo-session` undoes a session's changes newest first through the same verified rollback as `wppilot/rollback-change`. Before anything is written it checks every target: the newest session write to each must still match the state it left, and consecutive session writes to one target must chain (the newer one's before-image equals the older one's after-state). A mismatch means a person, another agent or another plugin changed the target since, and the whole run is refused with the conflict named - which change, which fields, and the later ledger rows that touched it. With `skip_conflicts: true` the run goes ahead instead: every session write to a conflicted target is left alone (undoing an older write to it would overwrite the later edit just the same) and listed under `skipped_conflicts` with its reason, and the rest is undone. Caches that change without an edit (Elementor's CSS and asset meta, edit locks, `post_modified`) are not counted. Each change is checked again just before it is undone. The run stops at the first change that fails verification and reports what was undone, the failure, and what was not attempted. A session that holds a change which cannot be undone is refused unless `allow_partial` is true, and the change is listed either way. A change whose restore strategy is missing (its plugin was deactivated) fails and stops the run the same way.

Every undo keeps the state it replaced. `wppilot/redo-session` puts those back oldest first under the same rules, and checks each result against the state recorded when the change was first made. A created term or comment is deleted permanently by its undo and cannot be redone; such changes are listed.

Both are destructive and need the same confirmation as any other destructive call; Read Only refuses them. One run handles up to 2,000 changes and only one run per session goes at a time. The Changes screen lists recent sessions and has **Undo session** and **Redo session** on a session's view.

Each record also says how the call was confirmed (`confirmation.method`): `argument`, `elicitation`, `approval-url` (with the approving user's id), `chat`, `approval-queue`, or `not-required` for a write that needed no confirmation.

Each record names the agent behind the write as well as the WordPress user. The user is not an agent identity - several AI clients usually connect as the same administrator - so the credential is what distinguishes them: an OAuth client id, stored hashed, or an application-password UUID. The client name and version the agent introduced itself with are recorded alongside it. Writes that arrive outside an authenticated MCP request, from wp-admin, WP-CLI or another plugin, are recorded as `direct` rather than attributed to the last agent seen.

## Secrets and prompt injection in results

Every ability result an agent receives passes through the output guard (`includes/output-guard.php`) on MCP, the legacy adapter, REST, core's run route and Chat. Nothing stored on the site changes.

- **Redaction.** Values that are secrets are replaced with `[redacted by GoBuildWP]`. This covers values stored under a secret-like key (`password`, `secret_key`, `api_key`, `client_secret`, `licence_key`, salts and similar), the value of an option or meta pair whose name is one, wp-config.php's `DB_USER`, `DB_PASSWORD`, keys and salts, `.env` secrets, and token shapes that ordinary text never has: AWS, GitHub, Slack, Stripe secret, Anthropic, OpenAI and Google keys, PEM private keys, JWTs, GoBuildWP tokens, `Bearer` headers and passwords in URLs. The count is reported. Abilities whose result is a credential the agent asked for, such as admin access links and upload links, are exempt. The filters are `wppilot_redaction_patterns`, `wppilot_redaction_key_pattern`, `wppilot_redaction_exempt_abilities` and `wppilot_redact_ability_result`. A write whose input still carries the placeholder is refused with `wppilot_redacted_value`, so reading a record and writing it back cannot overwrite the real secret.
- **Prompt-injection flags.** Text that reads like instructions to an agent is flagged, never changed. This covers "ignore previous instructions", "you are now", system-prompt and chat-template markers, fake tool calls, requests to send credentials somewhere, and hidden Unicode (tag characters and bidi overrides). Each flag gives the kind, the JSON path and a short excerpt. At most 20 flags are reported, from the first 2 MB of text. Flags go in MCP `_meta["gobuildwp/content_safety"]` plus a warning text block, in the `X-GoBuildWP-Content-Safety` headers on REST, beside `data` as `content_safety` on the legacy adapter, and on the Chat tool call. Skills are not scanned, because they are instructions by design. The filters are `wppilot_content_safety_patterns`, `wppilot_content_safety_flags` and `wppilot_flag_ability_result`.

## Device sign-in

An agent that cannot open a browser can sign in with a device code (RFC 8628). It calls `POST /wp-json/gobuildwp/v1/device/code` and gets a `device_code`, a short `user_code` such as `BCDF-GHJK` and the wp-admin page to approve it on. An administrator opens that page, checks the requesting address and client, picks the token's safety ceiling and expiry, and approves or denies. Meanwhile the agent polls `POST /wp-json/gobuildwp/v1/device/token` with `grant_type=urn:ietf:params:oauth:grant-type:device_code` and gets `authorization_pending`, `slow_down`, `access_denied`, `expired_token` or, once, an access token owned by the approving administrator and scoped like any token made on the Connect screen.

- Codes last 10 minutes and are single use: the first poll after a decision claims it, and every later poll gets `invalid_grant`.
- Neither code is stored in plain text. The device code is kept as a SHA-256 hash and the user code as an HMAC keyed with the site's auth salt.
- Opening a link cannot approve anything. The decision is a POST that needs the manage capability and a nonce, and the page warns against approving a code someone else sent.
- At most 20 codes are pending, 5 per address. Past that a new request gets `slow_down` and nothing is evicted. Polling faster than the interval adds 5 seconds to it.
- Returning false from the `wppilot_device_sign_in_enabled` filter stops new codes being issued. Read Mode leaves them open, because a token issued under it can only read.
