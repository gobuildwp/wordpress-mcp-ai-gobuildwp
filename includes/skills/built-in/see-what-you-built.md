---
name: see-what-you-built
description: Look at a page you built or changed, at real viewport widths, and catch what reading the markup cannot show. Activate after building, rebuilding or restyling any page, before reporting it finished, and whenever a change might have moved something you did not mean to move.
---

# Seeing the page

Every other check in GoBuildWP reads the page. None of them can see it, and the
failures that matter most to whoever owns the site are visual: a headline that
is illegible over the photograph behind it, a card that wrapped onto its own row
at 1024px, an element that rendered and is invisible, a section whose spacing
collapsed. A page can pass every structural check and be visibly wrong.

Do not report a page finished until you have looked at it.

## Which route you have

**If you have a browser tool** — Claude Code, Cursor, an MCP browser, anything
that can open a URL — use that. It is immediate and needs nobody.

1. `wppilot/get-page-view-link` with the `post_id`. On a draft it returns the
   preview URL and `requires_sign_in: true`.
2. If `requires_sign_in` is true and your browser is not already signed in to
   this site, do not work around it. Ask the person to open the preview, or use
   the no-browser route below. `wppilot/create-admin-access-link` can sign a
   browser in, but it is a critical ability: call it only when the person has
   agreed, with `confirm=true`, and accept a refusal from the safety profile.
3. Screenshot at each width in `viewports`. Three widths, not a sweep: the
   failures worth catching are a layout that breaks between desktop and phone,
   and text that is unreadable over an image.

**If you have no browser tool**, the site takes the picture for you:

1. `wppilot/capture-page` with the `post_id`.
2. Read `runtime.online`. If it is false, nothing will happen until somebody
   opens the Visual Runtime page in wp-admin — ask for that, giving them
   `runtime.page_url`, and say it has to stay open.
3. Poll `wppilot/get-capture-job` until the job is `done`.
4. The captures are in the media library; `wppilot/list-captures` finds them.

## What to look for

Compare what you see against two things:

- **The active design.** `wppilot/get-active-design` has the palette and the
  type stack. A colour that is not in the palette is a finding even when it
  looks fine.
- **What the page was meant to communicate.** A hero that renders perfectly and
  buries the thing it exists to say is a failed hero.

Then look for what markup cannot tell you: text unreadable over an image,
a layout that wraps or overflows at a narrower width, spacing that collapses,
an element that renders but is invisible, a heading that is smaller than the
paragraph under it.

Fix what is wrong. Then look again.

## Catching what you did not mean to change

A restyle that fixes one section and quietly moves another is the failure this
catches and nothing else does.

1. Capture **before** the change.
2. Make the change.
3. Capture **after**, at the same viewport width. Comparing captures taken at
   different widths reports the whole page as changed, which is true and
   useless.
4. `wppilot/compare-captures` with both attachment IDs.
5. Read `changed_regions`. Each one is a rectangle in the coordinates of the
   newer image. Go and look at the ones you did not intend.
6. `height_ratio` away from 1.0 means the page got longer or shorter, which the
   region list cannot show you.
7. `wppilot/attach-captures-to-change` records the pair against the ledger entry
   for the write, so the change history carries what the write did to the page
   rather than only what it did to the data.

## Pair it with the structural check

`wppilot/verify-rendered-page` reads the served HTML for the checks a screenshot
cannot make: heading order, images with no alt text, containers that rendered
empty, PHP errors that reached the visitor, page weight. It works on drafts too.
Run both. Neither is a substitute for the other.

## What neither of them sees

- Anything a script paints after the page loads.
- Anything below the captured viewport height.
- On a site-taken capture: cross-origin images and webfonts served from another
  origin. Each capture records which of those it hit — read the notes.

Say what you did not check. A clean result on a check that did not run is worse
than no check at all.
