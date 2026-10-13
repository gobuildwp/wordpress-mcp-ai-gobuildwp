# security-status kit

What the site's security plugin reports, for Wordfence and Solid Security (formerly iThemes
Security; the `better-wp-security` plugin, branded "Kadence Security" since 10.0). Each vendor is
optional and every result names the provider that answered. Read-only.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/security-plugin-status` | read | Per provider: version, firewall on/off and mode, scan schedule, last scan time and result, open findings by severity, brute-force and two-factor settings, active lockouts, premium tier. |
| `wppilot/security-scan-findings` | read | Open findings from the latest scan, most severe first: severity, type, short description, path relative to ABSPATH or plugin/theme slug, first seen. `severity` filter, `limit` 50 (max 200), counts by severity. |
| `wppilot/security-lockouts` | read | Current and lifted lockouts, IP blocks, bans and rate limits: reason, start, expiry, active. `include_expired`, `limit` 50 (max 200). |

## What never leaves

- IPs: only their network, /24 (IPv4) or /48 (IPv6); an IPv4 address stored as IPv6 is masked as
  IPv4; a banned range keeps its own width when it is wider; anything that is not an IP is dropped.
- Logins: an account on the site becomes `user_id`; any other login (an attacker's guess, or an
  email typed into the login form) becomes its first character and asterisks.
- Free text (reasons, scan descriptions, failure messages): email addresses become `[email]`,
  IPs their network, markup is removed.
- File paths: relative to ABSPATH; a path outside it keeps only its file name.
- Licence keys, API keys and secrets are never read.

## Vendor data

- **Wordfence 9.0.1**: `wfConfig::get()` for the named settings only; `wfFirewall` for mode,
  protection and rules; `wfScanner::shared()` for the schedule and last scan; the issues table from
  `wfIssues::shared()->getIssuesTable()`, open (`status = 'new'`) rows only, severity 100/75/50/25/0;
  `wfBlock::allBlocks()` for current blocks (Wordfence deletes expired ones), counted in SQL.
- **Solid Security 10.0.4**: `ITSEC_Core` identity, `ITSEC_Modules` for modules and thresholds, the
  site scanner's `Scans_Repository` (a file-type log keeps no scan history to read back), the
  scheduler's `malware-scan` event, `$itsec_lockout->get_lockouts()` for current and lifted
  lockouts, and the `Ban_Hosts` repository for bans.

One provider throwing is reported against that provider and never hides the other's answer.

## Host needs

`Runtime\can_run()` plus `manage_options`, and `Runtime\unclaimed()` (runtime 1.2).

<!-- kit-export:omit -->
Inside GoBuildWP this kit carries the three reads GoBuildWP Pro 1.10.0's `security` kit registered;
Pro keeps the hardening plan and apply, IP blocks and scans. The output is kept identical to
Pro's, which still registers these names first on a site running Pro 1.10.0.

## Tests

`tests/Unit/Kits/SecurityStatus/` in the GoBuildWP repository (not shipped).
<!-- /kit-export:omit -->
