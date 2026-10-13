---
name: custom-theme-build
description: Build or restyle pages by writing a real WordPress theme — child theme, page template, CSS, JS — instead of builder widgets. The realization skill for the coded-theme surface. Activate when the user chooses a bespoke coded page or theme and no visual drag-and-drop editing is needed.
---

# Building in a custom theme (code)

This is a **realization** skill: it turns the site's saved design direction into
real theme code. It is the max-freedom end of the surface spectrum.

```
less freedom, visual self-edit            more freedom, edit via the agent
|----------------+-------------------+------------------------------|
Gutenberg /       Elementor / Bricks   CUSTOM THEME (this skill)
theme.json        / Divi (widgets)     (HTML / CSS / JS / PHP)
```

On a builder the output is native widgets, constrained to what the builder can
express. Here the output is real code, so there is no ceiling and no medium to
fight: the signature moves work at full strength.

## Shared intent first (do not skip)

The design direction is not yours to invent on this surface. Call
`wppilot/get-active-design` and build strictly within its tokens, `dials`, and
Do's / Don'ts. The same DESIGN.md drives every surface; only the realization
differs. If none is active, establish one first (the `wppilot-design` skill).
Use `wppilot/get-design` to read a specific saved direction to evolve.

Map the direction into code as one source of truth:

- Tokens to CSS custom properties: `:root { --bg; --ink; --accent; --font-display; --radius; --space }`. For block themes, put the token layer in `theme.json` so the editor sees it too.
- Dials to behavior: `variance` drives how asymmetric the layout is and how far images break the frame; `density` drives the spacing scale; `motion` drives animation intensity. `variance` near 0 means composed and symmetric, no breakout.

## Choose the foundation by the site's state (do this first)

How you host the code depends on the state the site is in, not a fixed rule. **You
do not decide that state on your own: ask the user, or confirm your read with them,
before choosing.** Activating a new theme is a site-wide, hard-to-reverse change (it
re-skins every page), so never take the blank-starter path on a site that might be
live without an explicit yes.

- **New site, or a full-site rebuild the user has approved:** start from a **blank,
  minimal starter theme** (a clean `style.css` + `index.php` + `functions.php`, plus
  your templates) and activate it site-wide. This is the cleanest foundation: you
  inherit no parent theme's styles, scripts, or leaks, so there is almost nothing to
  fight. A blank starter is not an empty theme: give it sensible fallback templates so
  pages you did not hand-build still render.
- **A full-custom page on an existing, live site:** do NOT swap the theme, and do NOT
  strip the messy document. Render your page as a **minimal, self-owned document** that
  loads only your assets: control the head up front instead of loading everything and
  removing it. The **GoBuildWP blank canvas** is the clean, plugin-owned surface for
  exactly this: a barebones document where you inject the design, with nothing foreign
  to fight. Scoped to that one page, the rest of the site untouched.
- **A page that must keep the site's own header, footer, and nav:** keep the current
  theme, build a **child theme** or a **page template**, and **scope/override** the
  leaks (higher specificity, `:where()` resets, explicit fonts and colors on your
  elements). Do not strip: removing the whole document's CSS also breaks the WordPress
  **admin bar** and any functional plugin CSS on the page (forms, widgets).

## Canonical sequence

1. **Read the direction.** `wppilot/get-active-design` (or `get-design` for a
   specific slug). If none, establish one via `wppilot-design` first.
2. **Create the theme you chose above** with `wppilot/write-file` (a blank starter
   for a new or approved-rebuild site; a child of the active theme for a live site you
   must not break):
   - `style.css` (for a child theme, a header naming `Template: <active-theme-folder>`).
   - `functions.php` that enqueues your CSS and JS, guarded by
     `is_page_template()` so it only loads on the landing template.
   - `template-landing.php` with `Template Name:` and your own full markup.
   - `assets/landing.css`, `assets/landing.js`.
3. **Activate** the child theme and **create the page**, assigning the template
   (`_wp_page_template`). Use `wppilot/execute-php` where no dedicated ability
   fits (`switch_theme`, `wp_insert_post`, `update_post_meta`).
4. **Build section by section** in the template, applying tokens as CSS
   variables and the beauty moves.
5. **Pre-flight** the rendered output with `wppilot/check-design`; fix every
   `fail`.

## The freedom (this is the point)

You write HTML, CSS and JS directly, so reach for the full ceiling:

- Full-bleed sections and images that break their frame (negative margins, `position`, `transform`, `clip-path`), not a tidy rectangle in every slot.
- Directional gradient scrims (`linear-gradient(...) , url(...)`) for legible cinematic heroes, inline-heading accent words, real numbers set very large.
- Dramatic scale contrast, layered depth, real CSS grid.
- Exact type: self-hosted faces via `@font-face` with `font-display: swap`. Never a Google Fonts `<link>` (performance and GDPR).

Keep **one shared content-width container** across all sections (hero included)
so the hero and body align on the same left edge. Misaligned containers are the
most common "everything looks shifted" bug.

## Motion

GSAP and scroll-driven animations are legitimate here: this is their medium. But
the discipline holds:

- Motion must be motivated (hierarchy, feedback, storytelling, state), never decoration for its own sake.
- Honor `prefers-reduced-motion`; collapse loops, parallax and scroll-hijack to static.
- Enqueue scripts with `wp_enqueue_script` (correct deps, one controller file). Never scatter inline `<script>` blobs in content. Hook animation by selector; clean up.

## The bold register (gated by the dials, never a default)

Because this surface writes real code, the full showpiece vocabulary is available:
gapless bento grids, scroll-pinned sections, scrub-driven reveals, image scale/fade
on scroll, hover physics, dramatic scale contrast, an image or a large number set
inline in a heading. Reach for these to hit the ceiling **when the direction wants
it**.

Availability is not obligation. This register is **gated**, first by the dials and
then by the task:

- **`motion` gates the animation.** Low `motion` means static: no GSAP, no
  scroll-hijack, no parallax. `motion` near 0 is a calm, precise page, and that is a
  first-class result, not a shortfall. Only high `motion` earns the scroll-driven
  moves.
- **`variance` gates the composition.** Low `variance` means composed and symmetric:
  no bento acrobatics, no breakout. High `variance` earns them.
- **How hard you push follows the established direction (the dials), not the page's
  category.** The dials were set with the user when the direction was established, so
  they already encode how bold to be: honor them. Do not assume a contact form must
  be plain or a landing must be loud. A client may want a striking, animated form, or
  a deliberately calm and minimal landing.

Note: the taste tradition these moves come from treats static interfaces as
forbidden and motion as mandatory. That stance does not hold here. GoBuildWP serves
the whole range, from a maximalist landing to a plain form, so restraint is a
deliberate design choice, not a missing feature. Forced acrobatics are their own
kind of slop.

## Working on a loaded WordPress site (proven gotchas)

These bite in practice on a real site running a heavy theme plus builders plus
plugins:

- **Global CSS leaks into your full-custom template, and `wp_dequeue_style` is not enough.** A standalone template still loads the site's global styles, but the damaging leaks arrive through channels dequeue cannot touch. Three seen in practice on a Divi + builder stack:
  - The parent theme **inlines its whole stylesheet** as an inline `<style id="...-inline-css">` block, and page builders print inline reset `<style>` blocks. `wp_dequeue_style` only removes *enqueued* `<link>`s, never inline `<style>`.
  - A leaked property you never declared still wins: a Divi `border-left` / `padding` landed on a bare `<blockquote>` because scoping only overrides properties you actually set.
  - A builder can **re-inject a stylesheet after your template closes**: it echoes a placeholder comment on `wp_head` / `wp_footer` (e.g. `<!-- BREAKDANCE_HEADER_DEPENDENCIES -->`) and expands it into real `<link>`s via `str_replace` on the whole response, so at your strip time it is a comment, not a link.

  Do NOT fix this by stripping the whole document (`ob_start` plus removing every inline `<style>` and foreign `<link>`). That nukes legitimate CSS too: the WordPress **admin bar** loses its styles, and any functional plugin CSS on the page (forms, widgets) breaks. Instead: for a full-custom page, render it as a **self-owned minimal document** that never loads the foreign CSS in the first place (the **GoBuildWP blank canvas**); for a page that keeps the theme, **scope and override** (higher specificity, `:where()` resets, explicit fonts and colors), and reset `border` / `margin` / `padding` on any bare element you reuse (`blockquote`, `figure`, `ul`, `dl`), since scoping guards only declared properties.
- **Neutralize UA resets with `:where()`, not blanket selectors.** A reset like `body.x h1, h2, ... { font: inherit }` has real specificity (0,1,2) and silently beats a single-class component rule such as `.hero__title { font-family: var(--font-display) }` (0,1,0), so every heading quietly falls back to the body font. Wrap reset selectors in `:where(...)` to drop them to specificity (0,0,0): author origin still neutralizes UA defaults, but any component rule wins.
- **The display font must actually load, or the platform font wins.** Setting `font-family` is not enough if the face is never delivered: the site's global font (often Inter, enqueued by the theme or builder) becomes the fallback. Self-host your faces with `@font-face` + `font-display: swap` and enqueue them on this template, or set the site's global font tokens so the whole site is on-brand, not just this page.
- **Scroll-reveal must degrade to visible.** A `.reveal { opacity: 0 }` that only becomes visible when JS adds a class hides all content if the script never runs (no-JS, crawler, print). Gate the hiding behind a JS-added class on `<html>` (no-JS renders visible), and honor `prefers-reduced-motion` by forcing the visible state. (When screenshotting such a page, force the end state first, e.g. add the reveal class to every `.reveal`, or a full-page capture shows blank bands.)

## Discipline and images

The anti-slop floor is identical to every surface: no em-dashes, no Inter by
default, no AI-purple, one accent locked, on-palette fonts and colors, real copy.
Greater freedom is not a license to drop the floor.

Images are the user's choice, never a silent default. Read the Media Library
freely and use existing photos; ask before writing (upload / generate).
Hotlinking a placeholder service (picsum and the like) is the user's call,
never yours: offer it, note the external request when the site is production,
and prefer saving the final pick locally once it is chosen. While the user
decides, an on-palette CSS gradient or inline SVG block keeps the layout
beautiful at zero cost.

## Pre-flight

Before calling it done, pass the rendered HTML / CSS to `wppilot/check-design`.
On this surface the output IS HTML / CSS, so the validator inspects it natively:
zero em-dashes, no Inter / AI-purple, one accent, on-palette, plus the structural
self-audit (hero fits the viewport, no three identical cards, eyebrow restraint,
zigzag cap).

## Abilities used

- Filesystem: `wppilot/write-file`, `edit-file`, `read-file`, `list-directory` for the child theme, `style.css`, `functions.php`, template, `assets/`.
- `wppilot/execute-php` narrowly for WP operations with no dedicated ability (activate theme, insert page, assign template, enqueue).
- `wppilot/get-active-design` / `get-design` and `wppilot/check-design` for direction and pre-flight.
- NOT the builder abilities (`elementor-*`, `bricks-*`). Different surface.

## The real tradeoff (state it honestly to the user)

Custom theme is not "static" or "developer-only". GoBuildWP stays underneath, so
the site remains fully changeable: the client asks in natural language ("make the
hero calmer", "swap the accent to green") and the agent edits the theme files.
The only real difference from a builder is the **editing interface**:

- **Builder**: the client can also self-serve in a visual drag-and-drop editor, without the agent.
- **Custom theme**: changes go through GoBuildWP (conversational). No visual canvas, but fully editable via the agent.

The surface was chosen upstream, with the user; on this one, custom code is the
native vocabulary. If the client expects a visual canvas they open themselves,
that belongs in the upstream choice — flag it there, do not re-decide it here.

## What this skill is not

- Not for clients who need a visual drag-and-drop canvas they operate without the agent (use a builder path).
- Not a reason to ignore the active design or the anti-slop floor. The direction and the rules are shared across every surface; here they are realized in code.
