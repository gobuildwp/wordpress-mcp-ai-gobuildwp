# WordPress MCP server

GoBuildWP turns a WordPress install into an MCP server. It is a server, not a
client: the site exposes typed abilities, and an AI client you already run -
Claude Code, Codex, Cursor, Copilot and others - connects to it. No model is
bundled and no provider key is stored by the MCP endpoint.

## Endpoints

| Route | Used by |
| --- | --- |
| `/wp-json/mcp/wppilot` | The canonical endpoint. Application Passwords (HTTP Basic) and `Authorization: Bearer wpp_…` access tokens authenticate here. |
| `/wp-json/mcp/wppilot-oauth` | OAuth 2.1 clients. |
| `/wp-json/mcp/mcp-adapter-default-server` | Legacy alias, still resolved so existing configurations keep working. New configurations should not use it. |

A full URL therefore looks like `https://example.com/wp-json/mcp/wppilot`. The
endpoint is on your own host: there is no GoBuildWP relay, and traffic between the
AI client and the site does not pass through a third party.

## Protocol revisions

Two revisions are served at once during the migration window.

| Revision | State | Served by |
| --- | --- | --- |
| `2026-07-28` | Stateless. No `initialize` call and no session id; each request carries its protocol version and client capabilities in `_meta`. | `includes/mcp/`, dispatched ahead of the adapter. |
| `2025-11-25` | Legacy. `initialize` handshake plus `Mcp-Session-Id` sessions. | The bundled WordPress MCP Adapter, unmodified. |

A request is handled under the modern revision **only** when it carries modern
per-request `_meta`. Everything else falls through to the adapter untouched, so
an existing client does not need to be reconnected when the plugin is updated.

`server/discover` is implemented and advertises both revisions plus the
capabilities actually registered on this site, including MCP Tasks when enabled
and tools are served. Subscriptions and logging are not advertised: GoBuildWP has no
change-notification producer, so `subscriptions/listen` is not implemented
rather than being advertised and then failing.

## MCP Tasks

MCP Tasks shipped in Free 1.15.0 on the modern transport (`2026-07-28`). The
legacy adapter does not provide this extension. Tasks is enabled by default;
the `wppilot_mcp_tasks_enabled` filter can disable advertisement and dispatch.
Discovery declares `tasks` and the `io.modelcontextprotocol/tasks` extension
when Tasks is enabled and tools are served.

An authenticated client adds `task: {}` (or a requested `ttl`) to the
`tools/call` parameters and receives a task object. It can then use:

- `tasks/get`: poll the task state.
- `tasks/result`: retrieve the completed result or failure.
- `tasks/list`: list tasks belonging to the connected user.
- `tasks/cancel`: request cancellation of a non-terminal task.

Only abilities declaring task support and backed by the job runner can execute
in the background. Their progress depends on the runner and WordPress cron.
Other abilities execute synchronously and return an already completed or failed
task; requesting a task does not automatically make every long call asynchronous.
Tasks do not support an `input_required` pause.

Each call retains the usual permission, safety-profile, confirmation, rate-limit
and ledger checks. Tasks are owned by the authenticated user. Cancelling a job
stops further runner steps; it does not undo writes that have already completed.
Clients without Tasks support can continue using ordinary `tools/call` results.

## The three-tool interface

Hundreds of abilities cannot be loaded into a client's context as hundreds of
tools. GoBuildWP exposes three instead, and the abilities behind them:

- `discover-abilities` - list what this install actually registered, filtered
  by category or search term.
- `get-ability-info` - read one ability's input and output schema before
  calling it.
- `execute-ability` - run it.

The first call an agent makes is discovery, and the response carries a
catalogue of the skills saved on the site along with one instruction: if a skill
matches the request, load its full instructions before starting the work. That
is why a prompt does not have to be phrased in any particular way - the routing
lives on the site, not in the prompt.

## Built on the Abilities API and the MCP Adapter

Abilities are registered through the WordPress Abilities API, and the official
WordPress MCP Adapter is bundled to serve the legacy revision. GoBuildWP supplies
the parts an adapter does not: the abilities themselves, authentication,
safety profiles, confirmation gates, rate limiting, and a change ledger with
rollback. An ability registered by another plugin through the same Abilities API
is discoverable through this endpoint too.

## Coexisting with another plugin that bundles the MCP Adapter

GoBuildWP is not the only plugin that ships the WordPress MCP Adapter. Elementor
4.3 bundles it too, inside `elementor/elementor-mcp-composer`, and so do several
community MCP plugins. The adapter's classes are global and unprefixed
(`WP\MCP\…`), so **only one copy is ever loaded on a site**, and which one wins is
decided by the Jetpack autoloader: the highest version tag in the merged classmap
is served to every plugin that asks. GoBuildWP can therefore find itself running
Elementor's adapter, or Elementor running GoBuildWP's.

That is supported, and nothing about the endpoint changes:

| | GoBuildWP's adapter loaded | Another plugin's adapter loaded |
| --- | --- | --- |
| `/wp-json/mcp/wppilot` | The adapter's default server, renamed | Registered explicitly by GoBuildWP, beside their default server |
| `/wp-json/mcp/wppilot-oauth` | Registered | Registered |
| `/wp-json/mcp/mcp-adapter-default-server` | GoBuildWP's legacy alias | **Theirs.** GoBuildWP does not claim it |
| Abilities served | GoBuildWP's, plus every ability any plugin registers | Identical |

The Abilities API registry is site-wide, so Elementor's abilities are discoverable
through GoBuildWP's endpoint and GoBuildWP's through Elementor's. That is the design
of the Abilities API, not an accident of the shared adapter.

`wppilot/system-status` reports which copy is loaded under `mcp_adapter`:
`ours`, the file path, the version, and the plugin directory that owns it. Read
that field first on any site where MCP behaves unexpectedly and another MCP
plugin is installed.

**Namespace collisions.** Composer derives the Jetpack autoloader's namespace
from the dependency set, so two plugins with similar `composer.json` files can
generate the *same* namespace and fatal on activation with `Cannot declare class
Automattic\Jetpack\Autoloader\jp…\al…\Autoloader`. GoBuildWP pins an explicit
`autoloader-suffix`, so its namespace is `…\jpwppilot\…` and cannot collide with
a suffix another project generated. On `WP_DEBUG` sites GoBuildWP also enables the
autoloader's own conflict diagnostic, so a future collision is logged by name
rather than appearing as a white screen.

## What is registered on a fresh install

GoBuildWP ships 216 abilities, plus one MCP prompt per saved skill. Not all of
them register everywhere: the WooCommerce, SEO, form, backup and security
abilities appear only while their plugin is active, and developer abilities
only under Developer Full Access, so a fresh install on the default profile
registers 154. They are grouped into a
single **WordPress** category on the Abilities screen and can be switched off
individually: content, taxonomies, media, comments, menus, revisions, user
reads, allowlisted site settings, the plugin and theme lifecycle, Gutenberg
block workflows, Elementor editing, the design system, preview, skills, the
change ledger and diagnostics. Twenty abilities register only under
Developer Full Access: the fifteen developer abilities (PHP execution, WP-CLI,
reading and writing server files, temporary admin access) plus database
queries, the options explorer, and creating or updating users.

Content creation is draft-first: an absent, blank or malformed status resolves
to `draft` before any capability check runs, so nothing is published by
accident. Capabilities are read from each post type's and taxonomy's own
capability object, so a custom type that declares its own set is enforced on its
own terms.

## Authentication

Three routes, all configured on **GoBuildWP → Connect**:

- **OAuth 2.1 with PKCE.** Client ID Metadata Documents are the preferred
  registration mechanism; RFC 7591 dynamic client registration remains as a
  compatibility fallback. Access tokens last one hour, refresh tokens 14 days,
  and every authorization appears under **Connected Apps** so it can be revoked
  on its own.
- **Application Passwords**, for clients that cannot open a browser.
- **Access tokens** (`wpp_…`), for callers with no browser and no interactive
  session: the Claude Messages API MCP connector, the OpenAI Responses API `mcp`
  tool, cron jobs, `curl`. Stored only as a SHA-256 digest, shown once,
  revocable individually, and re-checked against the creating user's
  capabilities on every request rather than frozen at creation.

None of the three is a product licence. The free plugin has no activation key
and contacts no entitlement service to run.

## Local stdio connection

`wp wppilot mcp serve` runs the same MCP server over stdin/stdout, for a client
on the machine that runs WordPress. There is no credential: WP-CLI's global
`--user` names the WordPress user, and every ability checks that user's
permissions exactly as over HTTP. The command drives the adapter's own request
router, so tool listing, the `mcp_adapter_pre_tool_call` controls (safety
profile, confirmation, rate limit, design and preview gates) and the permission
callbacks are the HTTP ones, not a copy.

```bash
claude mcp add gobuildwp-local -- wp --path=/var/www/html wppilot mcp serve --user=admin
claude mcp add gobuildwp-local -- docker exec -i <container> wp --path=/var/www/html wppilot mcp serve --user=admin

printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"ci","version":"1"}}}' \
  | wp --path=/var/www/html wppilot mcp serve --user=admin
```

A client that takes a command and arguments (Cursor's `.cursor/mcp.json`) gets
`"command": "wp"` and `"args": ["--path=/var/www/html", "wppilot", "mcp",
"serve", "--user=admin"]`.

What differs from HTTP, and why:

- It refuses to start without `--user`, and when GoBuildWP's AI abilities are
  switched off. `--server=<id>` picks another registered MCP server; the default
  is `wppilot` (the adapter's own `wp mcp-adapter serve` picks the first server
  registered, which is Elementor's when both are installed).
- stdout carries only JSON-RPC, one message per line. PHP errors go to stderr,
  and anything a plugin prints while a message is handled is moved there.
- WordPress's in-memory cache is dropped before each message, so an edit made
  in wp-admin while the client is connected is seen. Abilities switched on or
  off on the Abilities screen are registered once per process, so restart the
  client after changing them.
- Writes are credited to method `stdio` and the client name from `initialize`,
  appear on the Connections list as "WP-CLI stdio", and each process is one
  session for `wppilot/undo-session`.
- `mcp_adapter_enable_stdio_transport` and `wppilot_stdio_enabled` returning
  false switch it off.

## Related

- [Safety profiles and the change ledger](SAFETY.md)
- [AI client compatibility](ai-client-compatibility.md)
- [Elementor MCP](elementor-mcp.md)
