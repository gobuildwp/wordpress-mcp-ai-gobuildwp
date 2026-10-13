# Security policy

## Reporting a vulnerability

Email <security@gobuildwp.com>. That address reaches the maintainers through a
channel meant for disclosure, and it is the same one named in
[CONTRIBUTING.md](CONTRIBUTING.md).

**Do not open a public issue for a vulnerability.** Issues on this repository
are public and permanent, and a report there is a disclosure before anyone can
act on it.

Include what is needed to reproduce it: the plugin version, the WordPress and
PHP versions, the safety profile in force, the authentication route used, the
ability or endpoint involved, and the request that triggers the behaviour. A
proof of concept against your own install is welcome; traffic against a system
you do not own is not.

Do not include production credentials, private keys, application passwords,
access tokens, licence keys or customer data in a report. If a credential has
already been exposed, rotate it - deleting a message does not un-send it.

## What to expect

- An acknowledgement that the report was received and read.
- An assessment of whether it is reproducible, and at what severity.
- A fix in a release, and credit in the release notes if you want it.

## Supported versions

The current release line receives security fixes. GoBuildWP is distributed as
GitHub releases rather than through the WordPress.org directory, so an install
that has not been updated is not receiving them. `wppilot.zip` on the
[releases page](https://github.com/gobuildwp/wordpress-mcp-ai-gobuildwp/releases)
is always the newest build, and the in-plugin updater points at the same file.

## Scope

In scope: authentication and the OAuth 2.1 implementation, safety-profile and
capability enforcement, confirmation gates on destructive abilities, the change
ledger, rate limiting, the developer abilities, and anything that lets an MCP
caller exceed the capabilities of the WordPress user it authenticated as.

Out of scope: vulnerabilities in WordPress core, in a third-party plugin, or in
an AI client, unless GoBuildWP's handling of them turns into a privilege
escalation here. A finding that depends on Developer Full Access having been
enabled deliberately is in scope only where the profile itself is bypassed.

## Deployment guidance

- Keep Production Safe selected unless broader access is intentional.
- Prefer OAuth over credentials embedded in client configuration. An application password or access token written into a client config is a bearer credential in a plaintext file, and an access token is the longest-lived of the three - give one an expiry, scope it to a purpose you can name, and revoke it when that purpose ends.
- Treat an access token as equal to the account that created it. It borrows that user's capabilities on every request, so the smallest account that can do the job is the one that should hold it.
- Use HTTPS for remotely reachable sites and revoke connected apps that are no longer needed.
- Grant AI access only to a WordPress account with the minimum capabilities required.
- Maintain tested backups before enabling mutation workflows.
- Review the GoBuildWP change ledger and WordPress/WooCommerce logs after consequential operations. Ledger entries name the agent credential behind each write, so a site connected by more than one AI client can attribute a change to one of them rather than to the shared WordPress account.
- Treat Developer Full Access as privileged server administration access.

GoBuildWP's diagnostics inspect selected WordPress and PHP configuration. They are not a malware scanner, penetration test, compliance certification, accounting audit, or availability monitor.
