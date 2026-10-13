# WooCommerce MCP

WooCommerce over MCP means a store's catalogue, stock and orders as typed
abilities rather than raw REST calls: query products, create a variable product
with its attributes and variations, adjust stock, read orders, add order notes.

**This surface is in [GoBuildWP Pro](https://gobuildwp.com/pro)**, on the same
endpoint as the free WordPress MCP server. The free plugin in this repository
does not register WooCommerce abilities; it is a complete WordPress MCP server
without them.

## Why not just use the REST API

The store REST API is a set of endpoints; an MCP surface has to be a set of
*decisions*. Three of them matter on a live store:

**Capability checks are per-request, not per-connection.** Every ability is
checked against the connected WordPress user at call time. An agent connected as
a shop manager cannot do what that account could not do by hand, and demoting
the account closes the agent's access in the same moment rather than at the next
token refresh.

**Anything touching money is classed destructive.** Order status changes,
refunds and price writes require an explicit confirmation flag on the call. An
agent cannot reach them by accident, and under the Read Only profile it cannot
reach them at all.

**Every supported write lands in the change ledger.** The ledger records which
agent credential made the change - not just the WordPress user, which is usually
the same administrator for every client connected to the site - and supported
changes can be rolled back from it.

## Positioning, honestly

WooCommerce has its own native MCP support in developer preview, built on the
WordPress Abilities API and the official MCP Adapter, and its product and order
abilities are canonical. GoBuildWP is independent software: it adds WooCommerce
abilities as part of a broader WordPress control plane with its own safety
profiles, confirmation gates, change evidence and rollback, and cross-plugin and
cross-builder operations on one connection. It is not an official WooCommerce,
Elementor or WordPress product and does not imply endorsement by any of them.

If a read-only view of a store is all that is needed, a focused single-purpose
server is a smaller thing to install. The case for GoBuildWP is the combination:
one endpoint that covers the store, the pages that sell from it, the builder
those pages are made in, and the governance around every write.

## Related

- [WordPress MCP server](wordpress-mcp.md)
- [Page builder MCP](page-builder-mcp.md)
- [Safety](SAFETY.md)
