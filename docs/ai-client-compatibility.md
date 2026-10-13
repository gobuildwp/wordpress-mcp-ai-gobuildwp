# AI client compatibility

GoBuildWP is a remote MCP server over HTTP. Any client that can reach a URL and
send an `Authorization` header can connect. The lists below are generated from
the client registry the plugin itself ships (`includes/clients.php`), which is
the same list the **GoBuildWP → Connect** screen offers, so they cannot drift
apart from what the software actually does.

A client is listed as running OAuth natively only where that has been verified.
Everything else sits in the proxied group and is given the route that always
works: being wrong in that direction costs a few seconds of `npx` startup, being
wrong in the other costs a failed connection with no explanation.

Per-client setup guides with copyable configuration:
<https://gobuildwp.com/wordpress-mcp>

## Runs the OAuth browser flow natively

| Client | Methods offered |
| --- | --- |
| Claude Code | OAuth · access token · application password |
| Claude Desktop | OAuth · `.mcpb` bundle · access token · application password |
| Codex CLI | OAuth · access token · application password |
| Cursor | OAuth · access token · application password |
| VS Code | OAuth · access token · application password |
| GitHub Copilot | OAuth · access token · application password |
| Factory Droid | OAuth · access token · application password |
| Antigravity CLI | OAuth · access token · application password |
| Antigravity IDE | OAuth · access token · application password |
| Gemini CLI | OAuth · access token · application password |
| Qwen Code | OAuth · access token · application password |
| Kimi Code CLI | OAuth · access token · application password |
| ZCode (GLM) | OAuth · access token · application password |

## Hosted web interfaces

These connect from their own servers, so none of them can reach a site that is
only running on your own machine: the site has to be publicly reachable over
HTTPS.

| Client | Methods offered |
| --- | --- |
| Claude (web) | OAuth · access token |
| ChatGPT | OAuth |
| Codex (desktop app) | OAuth |
| Mistral Le Chat | OAuth · access token |
| Perplexity | OAuth · access token |
| Manus | OAuth |

Claude on the web, Le Chat and Perplexity each store a fixed `Authorization`
header per connector, which is what makes an access token usable from a browser
client at all. ChatGPT's developer mode offers OAuth or no authentication and
has no header field; Manus and the Codex app take OAuth client credentials
rather than a header. Those three are OAuth-only for that reason.

## Connects through a local proxy

| Client | Methods offered |
| --- | --- |
| Devin Desktop (formerly Windsurf) | OAuth · access token · application password |
| Zed | OAuth · access token · application password |
| Cline | OAuth · access token · application password |
| Roo Code | OAuth · access token · application password |
| Kilo Code | OAuth · access token · application password |
| Amazon Q | OAuth · access token · application password |
| OpenCode | OAuth · access token · application password |
| OpenClaw | OAuth · access token · application password |

## Programmatic callers

Not clients as such, but the same endpoint and the same credential:

- **Claude Messages API** MCP connector
- **OpenAI Responses API** `mcp` tool
- cron jobs, automation platforms, `curl`

All three have no browser and no interactive session, so they use an access
token: `Authorization: Bearer wpp_…` against the canonical endpoint.

## Choosing a route

**OAuth 2.1 with PKCE** wherever the client can open a browser. Access tokens
last one hour, refresh tokens 14 days, and each authorization is listed under
**Connected Apps** in WordPress so it can be revoked on its own. Client ID
Metadata Documents are the preferred registration mechanism; RFC 7591 dynamic
client registration remains available as a fallback.

**Application Passwords** for clients that cannot run a browser flow. Sent as
HTTP Basic on the canonical endpoint.

**Access tokens** for callers with neither a browser nor an interactive session.
Created with an optional expiry, shown once, stored only as a SHA-256 digest,
revocable per token. A token borrows the capabilities of the user who created
it, and that check is re-run on every request rather than frozen at creation -
demoting or deleting the user closes the token in the same moment.

## Endpoints

```text
https://example.com/wp-json/mcp/wppilot         # canonical: application passwords, access tokens
https://example.com/wp-json/mcp/wppilot-oauth   # OAuth clients
```

The older `/wp-json/mcp/mcp-adapter-default-server` route still resolves as a
legacy alias. New configurations should use the canonical path.

## If a client will not connect

1. **GoBuildWP → Diagnostics** in wp-admin runs the same checks a client does and
   usually names the problem outright.
2. HTTPS is required for anything remotely reachable, and a hosted web client
   cannot reach `localhost` at all.
3. A client that predates the `2026-07-28` protocol revision is served the
   legacy `2025-11-25` revision automatically. No reconnection is needed after a
   plugin update.
4. A refusal on a call that used to work is usually the safety profile or a
   WordPress capability rather than authentication - the two read differently in
   the error text.

## Related

- [WordPress MCP server](wordpress-mcp.md)
- [Safety](SAFETY.md)
- [Troubleshooting](https://gobuildwp.com/docs/troubleshooting)
