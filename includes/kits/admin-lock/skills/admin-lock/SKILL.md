---
name: admin-lock
description: Restrict a WordPress site's login page and dashboard to the owner's IP addresses, or lift that restriction. Activate when the user asks to lock wp-admin or wp-login.php to their IP, allowlist an office network, or says they are locked out of the dashboard.
---

# Admin IP lock

1. Call `wppilot/admin-lock-status` first. `your_ip` is the address this request came from. If
   the agent runs on a server of its own, that is the agent's address, not the person's: ask the
   person for their own address (or office range) and do not switch the lock on until `your_ip`
   is on the list they give.
2. If `proxy_headers_seen` is not empty while `ip_source` is `remote_addr`, the site is behind a
   CDN or proxy. Ask which: `cloudflare`, or `x_forwarded_for` with `trusted_proxies`.
3. Call `wppilot/set-admin-lock` with `enabled: true` and `allow`. The first call returns
   `kit_confirmation_required` listing the addresses. Repeat it to the user, warn that anyone
   else signing in through wp-login.php (members, customers) is shut out, and only after they
   approve call again with `confirm: true`.
4. `kit_admin_lock_self_lockout` means the list leaves out the address making the change. Do not
   work around it; add that address or stop.
5. Home and mobile addresses change. Suggest a range or an office address over a single home IP.
6. Locked out: `escape_hatches` in the status names the wp-config.php constant and the WP-CLI
   command that switch it off. Either works without the dashboard.
