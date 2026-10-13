# Architecture

## Protocol eras

GoBuildWP is a dual-era MCP server.

- **Legacy (`2025-11-25`)** is served by the bundled MCP Adapter: `initialize`, `notifications/initialized`, and `Mcp-Session-Id` sessions, unchanged.
- **Modern (`2026-07-28`)** is served by `includes/mcp/`. It is stateless - no handshake, no session - and every request carries its protocol version and client capabilities in `_meta`.

`includes/mcp/transport.php` hooks `rest_pre_dispatch` and claims a request **only** when it carries modern per-request `_meta`. Everything else reaches the adapter untouched. Era selection never keys off the `MCP-Protocol-Version` header, because legacy `2025-06-18`+ clients send that header too and routing them into the stateless path would break existing connections.

The adapter is a third-party Composer package. It is never patched: a `composer update` would discard the changes.

Abilities are protocol-independent. Authentication, safety profiles, capability checks, rate limits, and the change ledger run identically in both eras; only the serializer differs, so a modern client cannot reach a weaker code path than a legacy one.

An ability that wants the model to see an image returns it under the result key `_mcp_content` as `[{type: "image", data: <base64>, mimeType}]` (PNG, JPEG, GIF or WebP) beside its ordinary fields. The modern transport sends each valid item as an MCP `image` content block after the JSON text and drops the key from the text and `structuredContent`; the legacy path converts it into the adapter's image result, which carries the image alone; REST and Chat return the whole array as JSON. See `tool_result()` and `legacy_image_result()` in `includes/mcp/transport.php`.

`server/discover` advertises only what is actually registered. MCP Tasks shipped in Free 1.15.0 on the modern transport and is advertised when enabled and tools are served. `includes/mcp/tasks.php` implements user-owned polling, results, listing and cancellation. Job-backed abilities execute in the background; other abilities return a completed or failed synchronous task. Tasks use the ordinary execution safety checks, and cancellation does not undo completed writes. The legacy adapter does not provide this extension. See [the Tasks contract](wordpress-mcp.md#mcp-tasks).

Subscriptions and logging are not advertised by the modern transport - GoBuildWP has no change-notification producer, so `subscriptions/listen` is not implemented. Cacheable results carry `cacheScope: "private"`, because the ability list is filtered per user, per safety profile, and per site.

## Request path

1. WordPress registers typed abilities through `wp_register_ability()`.
2. The official MCP Adapter exposes the default HTTP server at `/wp-json/mcp/mcp-adapter-default-server`.
3. An authenticated client discovers public abilities, reads one ability's schema, and executes it through the adapter's compact meta-tool.
4. GoBuildWP applies the manual ability policy and active safety profile before execution.
5. The target ability performs its WordPress capability check and validates its schema.
6. The change ledger records a redacted before/after fingerprint for supported state changes, attributed to the agent credential the request arrived on.

The compact adapter surface keeps client context small while retaining typed schemas for every target operation.

## Base plugin boundaries

- `includes/abilities/`: built-in developer abilities.
- `includes/abilities/wordpress/`: the typed WordPress-core surface - content, taxonomies, media, comments, revisions, menus, user reads, allowlisted settings, and the plugin and theme lifecycle. Ships in Free and never calls into Pro.
- `includes/mcp/`: protocol-era classification, the modern dispatcher, the shared error catalog, result decoration, and `server/discover`.
- `includes/oauth/client-id-metadata.php`: Client ID Metadata Documents, with the SSRF controls that fetching a caller-supplied URL requires.
- `includes/safety.php`: profiles, risk classification, and explicit-confirmation enforcement.
- `includes/change-log.php`: bounded audit records, redaction, fingerprints, and verified rollback.
- `includes/change-log-table.php`: ledger storage in `{prefix}wppilot_changes` (one row per change), the verified migration from the old `wppilot_change_log` option, the option fallback when the table cannot be created, and the daily retention prune.
- `includes/connections.php`: per-credential connection records, and the agent identity the ledger attributes writes to. Resolved once at the MCP entry point, because the credential and the client name are only reachable while the request is.
- `includes/rest/transport-hardening.php`: MCP/REST host validation and response security headers.
- `includes/oauth/`: OAuth authorization server, token repositories, discovery, and connected-app management. `middleware.php` also routes the Bearer path: a credential carrying the `wpp_` prefix is validated as an access token, anything else as an OAuth JWT.
- `includes/tokens.php`: long-lived access tokens - minting, SHA-256 digest storage, expiry, and revocation. The third connection method, for callers that can run neither a browser sign-in nor HTTP Basic.
- `includes/abilities/diagnostics.php`: scoped health, performance, and configuration-security checks.
- `includes/abilities/bootstrap.php`: ability categories and loaders.

## Pro boundaries

GoBuildWP Pro registers specializations only when their third-party dependency is available. Every manifest gate, bootstrap, category registration, and loader is isolated with per-integration health reporting. An error in one integration must not prevent unrelated abilities from registering.

Typed WordPress and WooCommerce operations use public platform APIs. WooCommerce order reads use `wc_get_orders()` for HPOS compatibility. Refund payment and inventory restocking are opt-in and destructive dispatch requires explicit confirmation.

## Extension rules

- Prefer a typed ability over raw PHP, filesystem, database, or WP-CLI access.
- Use the narrowest WordPress capability that matches the operation.
- Set `meta.mcp.public` only for operations intended for remote discovery.
- Mark readonly, destructive, and idempotent behavior accurately.
- Bound pagination, recursion, payload size, and result size.
- Do not turn diagnostics into unsupported malware, compliance, accounting, or uptime claims.
- Use public WordPress/WooCommerce APIs so storage implementations such as WooCommerce HPOS remain supported.
