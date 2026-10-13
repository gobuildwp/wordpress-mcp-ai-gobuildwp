# Elementor MCP

Editing an Elementor page is free, in this repository, since GoBuildWP 1.10.0.
The abilities register automatically when Elementor 3.6 or newer is active and
stay unregistered otherwise, so an agent is never offered a tool that cannot
work on this site. Elementor Pro is not required for anything below.

## Why generated HTML does not work

An Elementor page is not stored as markup. The layout lives in postmeta as a
typed element tree, and Elementor renders from that tree and ignores
`post_content` entirely. Writing generated HTML into a post therefore produces a
call that reports success and changes nothing a visitor sees - the single most
common failure mode when a general-purpose WordPress tool meets a builder site.
GoBuildWP refuses that write by name instead of reporting a false success.

The abilities below work on the tree itself.

## The 17 free abilities

| Ability | What it does |
| --- | --- |
| `wppilot/elementor-check-setup` | Elementor and Elementor Pro versions, and whether the v4 atomic runtime, the style schema, global classes, variables and interactions exist on this install. The first call an agent should make. |
| `wppilot/elementor-audit-atomic-readiness` | How much of the site is v4 atomic and how much is classic v3, across every Elementor document: a widget histogram, the share an atomic-only tool can work with, and the widget types blocking the rest. Read-only. |
| `wppilot/elementor-get-schema` | Discover widgets, or describe named ones: which controls exist, their types, their allowed values. Filterable by category, by name, and by whether a widget is atomic. |
| `wppilot/elementor-get-style-schema` | The 73 style properties Elementor's atomic engine accepts, with the value shape each one takes. |
| `wppilot/elementor-get-widget-params` | One widget's parameters, without reading its whole schema. |
| `wppilot/elementor-get-content` | Read a document's element tree, as structure or in full. |
| `wppilot/elementor-find-elements` | Locate elements by type, by widget, by id, or by the text they contain. |
| `wppilot/elementor-set-content` | Replace a document's tree in one call. Invalid properties are dropped and reported rather than failing the page; pass `strict` to refuse instead. |
| `wppilot/elementor-add-element` | Insert a widget, a container or a whole subtree at a chosen position, with settings and per-element styles validated against the schema first. |
| `wppilot/elementor-edit-element` | Change one element's settings or styles in place, leaving the rest of the page untouched. |
| `wppilot/elementor-move-element` | Move an element to a new parent or position. |
| `wppilot/elementor-duplicate-element` | Copy an element, with fresh ids throughout its subtree. |
| `wppilot/elementor-reorder-children` | Reorder a container's children in one call. |
| `wppilot/elementor-delete-element` | Remove an element and its subtree. |
| `wppilot/elementor-get-page-settings` · `set-page-settings` | Read and write document-level settings: page layout, title visibility, background and the rest. |
| `wppilot/elementor-clear-document-cache` | Regenerate Elementor's CSS for a document after a write. |

## Both element models

- **v4 atomic elements** - `e-div-block`, `e-heading`, `e-paragraph`,
  `e-button`, `e-image` and the rest, styled through the atomic style schema.
  Available when Elementor 4.0 or newer is active; `elementor-check-setup`
  reports whether the runtime, global classes and variables are present.
- **v3 classic widgets and containers** - the settings keys each widget
  actually uses, read from the live control registry on this install rather
  than from a bundled snapshot that can drift from the installed version.

An install can hold both models in the same site, and often does. Read the setup
before assuming either.

## A working sequence

```text
elementor-check-setup          → which model and which features exist here
elementor-get-schema           → the widgets this install offers
elementor-get-style-schema     → the style properties that will validate
elementor-get-content          → the current tree, with element ids
elementor-add-element / edit-element / move-element …
elementor-clear-document-cache → regenerate CSS so the change renders
```

Read before write is not a style preference here. A widget's controls differ
between Elementor versions and between free and Pro, so a settings key guessed
from documentation is a silently dropped property; a key read from
`elementor-get-schema` is one that exists.

## What Pro adds

The free abilities are primitives, and they compose. [GoBuildWP
Pro](https://gobuildwp.com/pro) adds the authoring layer above them plus everything
Elementor keeps outside a single document: whole-page composition from one
compact description (`elementor-build-page`), reproduction specs compiled into
global classes and a matching tree, templates and theme parts with display
conditions, popups, Elementor Pro forms and submissions, dynamic tags,
interactions, SVG upload, stock-image placement, site-wide custom code, v4
variables and v3 global colours and typography.

The dividing line: **free can edit an Elementor page, Pro can compose one.**

## Related

- [Page builder MCP](page-builder-mcp.md) - the other builders, and where each stores its layout
- [WordPress MCP server](wordpress-mcp.md) - endpoints, discovery, authentication
- [Safety](SAFETY.md) - confirmation gates, the change ledger, rollback
