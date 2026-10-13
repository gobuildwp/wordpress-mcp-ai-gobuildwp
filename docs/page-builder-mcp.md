# Page builder MCP

Every page builder stores a layout in its own way, and almost none of them store
it as the HTML a visitor sees. That single fact decides whether an AI agent can
edit a builder site at all.

| Builder | Where the layout lives | What that means for an agent |
| --- | --- | --- |
| Elementor | Typed element tree in postmeta | `post_content` is ignored on render. Edit the tree. **Free in this repository** - see [elementor-mcp.md](elementor-mcp.md). |
| Bricks | Element array in postmeta | Whole-page rewrites destroy sibling sections; patch elements in place. |
| Divi | Modules plus global presets | Flattening a layout into shortcodes loses the preset links. |
| Oxygen | JSON element tree in postmeta | `post_content` is not rendered at all. |
| Beaver Builder | `_fl_builder_data` in postmeta | Writing HTML into the post changes nothing a visitor sees. |
| Breakdance | Own element model in postmeta | Needs the builder's own node vocabulary. |
| Etch | Own element model, largest surface in Pro | Query previews and bindings, not markup. |
| WPBakery | Nested shortcodes **in `post_content`** | The exception: content is the store, so shortcodes must be parsed and edited, not replaced. |
| Mosaic | Own element model | Same read-before-write rule. |
| Flatsome UX Builder | Shortcode layout | Same as WPBakery in shape. |

## What the free plugin does on a builder site

The free plugin registers the Elementor abilities and recognises the others.
Where a builder owns the layout, a generic content write is **refused by name**
rather than being reported as a success that changes nothing. That refusal is the
useful behaviour: an agent that is told "this page is a Beaver Builder layout
stored in `_fl_builder_data`, use the builder-aware ability" can act on it,
while an agent that receives `200 OK` cannot tell that the page did not change.

## What Pro adds

[GoBuildWP Pro](https://gobuildwp.com/pro) registers builder-aware abilities on the
same endpoint, so an agent that connected once works in whichever editor the site
actually uses.

| Builder | Abilities | Free / Pro |
| --- | --- | --- |
| Elementor | 68 | **17 free** · 51 Pro |
| Etch | 60 | Pro |
| Bricks | 49 | Pro |
| Divi | 47 | Pro |
| Mosaic | 41 | Pro |
| Oxygen | 37 | Pro |
| Breakdance | 33 | Pro |
| Beaver Builder | 21 | Pro |
| WPBakery | 18 | Pro |
| Flatsome UX Builder | 11 | Pro |

Each module loads only when its plugin is detected, and each loads in isolation,
so a missing or broken plugin cannot stop the rest of the registry from
registering.

The abilities carry the builder's own vocabulary rather than a generic content
verb - `bricks-patch-elements`, `elementor-create-atomic-widget`,
`divi-apply-global-preset`, `etch-get-query-preview` - so an agent can read a
schema before it proposes a change.

## Gutenberg is free, not Pro

Block editing lives in the free plugin: parse, insert, move and replace blocks
in the core block tree, with reusable blocks and patterns. Core blocks are
validated and serialised by the block editor's own JavaScript, so writes are
staged as a pending batch and finalised through a browser session. The plugin
says so rather than pretending a server-side write is equivalent.

## Related

- [Elementor MCP](elementor-mcp.md)
- [WooCommerce MCP](woocommerce-mcp.md)
- [All integrations](https://gobuildwp.com/integrations)
