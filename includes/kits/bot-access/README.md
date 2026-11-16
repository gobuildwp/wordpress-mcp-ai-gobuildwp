# bot-access kit

Which search and AI crawlers may read the site, and which actually visit. Read-only, monitor only.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/bot-access-report` | read | robots.txt verdict per known crawler (allowed, blocked, partial), Settings > Reading visibility, Cloudflare managed robots.txt, ai.txt, crawler hits from readable access logs. `include_logs` (true). |

## robots.txt

Read as crawlers receive it: a loopback HTTP request to `/robots.txt` first, so a Cloudflare or
cache-layer rewrite is what gets judged; the file in the WordPress root when the request fails;
WordPress's virtual robots.txt (with the `robots_txt` filter applied) when there is no file.
Groups and rules follow RFC 9309: groups naming the crawler's token are merged, else `*` applies,
and the home page `/` is decided by the longest matching rule with Allow winning a tie.

## Access logs

Only fixed, common host locations are tried (cPanel `../logs`, aaPanel `/www/wwwlogs/<host>.log`,
nginx, Apache, OpenLiteSpeed), plus paths added with the `wppilot_kit_bot_access_log_paths`
filter. At most three logs, the last 2 MiB of each. Only the crawler's name, hit count and newest
timestamp leave the scan; addresses, paths and user-agent strings are never returned. A path
outside `open_basedir` counts as unreadable.

## Not covered

Cloudflare's bot settings (no API connection here) and blocking of any kind.
