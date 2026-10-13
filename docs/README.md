# GoBuildWP documentation

<!-- current-releases:start -->
Current releases: **GoBuildWP Free 1.18.6 / Pro 1.12.4**. Pro requires Free 1.18.1 or newer; that minimum is not the latest Free release.
<!-- current-releases:end -->


The technical documentation that ships with the free plugin. Task-based guides
for site owners live at <https://gobuildwp.com/docs>; these files describe how the
server itself behaves, so they can be read next to the code.

| Document | What it covers |
| --- | --- |
| [wordpress-mcp.md](wordpress-mcp.md) | The WordPress MCP server: endpoints, authentication, protocol revisions, the three-tool interface and how discovery works. |
| [elementor-mcp.md](elementor-mcp.md) | The free Elementor MCP surface: 17 abilities, the v3 classic and v4 atomic element models, and the read-before-write sequence an agent should follow. |
| [page-builder-mcp.md](page-builder-mcp.md) | Where each page builder stores a layout, why writing HTML into `post_content` does nothing, and which builders the Pro plugin speaks natively. |
| [woocommerce-mcp.md](woocommerce-mcp.md) | WooCommerce over MCP: capability checks, destructive classification, orders and money. |
| [ai-client-compatibility.md](ai-client-compatibility.md) | Which AI clients connect, by which authentication route, and what each one needs from the site. |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Request lifecycle, ability registration, where the code lives. |
| [SAFETY.md](SAFETY.md) | Safety profiles, confirmation gates, rate limits, the change ledger and rollback. |
| [../SECURITY.md](../SECURITY.md) | Reporting a vulnerability, and hardening guidance for a production install. |

Free and Pro, in one sentence: everything documented here except
[page-builder-mcp.md](page-builder-mcp.md) and
[woocommerce-mcp.md](woocommerce-mcp.md) is in the free plugin in this
repository, including Elementor editing. Those two files describe the free
behaviour first and mark the Pro surface explicitly.
