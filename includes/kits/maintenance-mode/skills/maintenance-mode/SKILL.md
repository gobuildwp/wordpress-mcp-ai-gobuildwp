---
name: maintenance-mode
description: Put a WordPress site into maintenance mode for a set time, or take it out again. Activate when the user asks to take the site offline, show a "coming back soon" page, hide the site while they work on it, or check whether it is in maintenance mode.
---

# Maintenance mode

1. `wppilot/maintenance-mode-status` first: it says whether it is on and when it ends.
2. To switch on, call `wppilot/set-maintenance-mode` with `enabled: true`, `minutes` and an
   optional `message`. The first call returns `kit_confirmation_required` stating what visitors
   will see and until when. Repeat that to the user, and only after they approve call again with
   `confirm: true`.
3. Pick the shortest time that covers the work. It switches itself off when the time runs out;
   call it again with `enabled: true` to extend (this restarts the clock from now).
4. Visitors get a 503 page; administrators still see the site, so to check the visitor view,
   fetch the page as a logged-out visitor (a rendered-HTML ability, if the site has one).
5. Switch it off with `enabled: false` as soon as the work is done. No confirmation is needed.
6. The message is plain text. Do not promise a time in it that `minutes` does not match.
