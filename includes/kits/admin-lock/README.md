# admin-lock

Restricts `wp-login.php` and `/wp-admin/` to a list of IP addresses and CIDR ranges (IPv4 and IPv6, up to 100). Any other address gets a 403 page there, before the login form or dashboard loads.

Never restricted: the front end, the REST API, `admin-ajax.php`, `admin-post.php`, WP-Cron, and the `wp-login.php` actions a visitor without an account uses (`postpass`, `confirmaction`, `logout`). Other users who sign in through `wp-login.php` (members, customers) are restricted too, so add their addresses or leave the lock off on such sites.

The setting is the autoloaded option `wppilot_kit_admin_lock`. The visitor address is `REMOTE_ADDR` unless `ip_source` says the site is behind Cloudflare (the `CF-Connecting-IP` header, believed only from Cloudflare's ranges) or a proxy listed in `trusted_proxies` (`X-Forwarded-For`). Address matching is copied from the security-protection kit.

Saving a list that does not include the address of the request saving it is refused, and so is an undo that would restore such a list. If you are locked out anyway:

- define the constant that `escape_hatches.constant` in the status names as `true` in `wp-config.php`, or
- run `wp wppilot-kit admin-lock disable`.

| Ability | Kind |
|---|---|
| `wppilot/admin-lock-status` | Read-only. `{enabled, enforced, allow, ip_source, your_ip, your_ip_allowed, proxy_headers_seen, escape_hatches, ...}` |
| `wppilot/set-admin-lock` | Write. Pass only what changes; switching on or changing the list while on needs `confirm=true`. Undo restores the previous setting. |

Tests: `tests/Unit/Kits/AdminLock`.
