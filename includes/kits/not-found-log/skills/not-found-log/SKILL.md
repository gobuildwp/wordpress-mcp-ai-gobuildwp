---
name: not-found-log
description: See which missing pages (404s) visitors hit on a WordPress site and where they came from. Activate when the user asks about broken links, 404 errors, missing pages or which redirects to add.
---

# 404 log

1. `wppilot/not-found-log` returns the most-hit 404 paths with hit counts, the referring host and
   when each was last seen. Only the path is kept, never the query string or the full referrer.
2. A path the site's own host refers to is a broken internal link: find the post that links to
   it and fix the link. A path other sites refer to is usually a moved page: suggest a redirect.
3. Paths like `/wp-login.php`, `/.env` or `/xmlrpc.php` variants are scanners, not broken links.
   Leave them out of the advice.
4. Hit counts are a floor: repeat hits within one second are not written again.
