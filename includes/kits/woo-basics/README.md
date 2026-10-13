# woo-basics kit

WooCommerce catalog, store, order and customer reads, and a basic product editor with undo.
Needs WooCommerce 9.0 or newer; below that the kit is skipped and says why.

| Ability | Kind | Undo | What it does |
|---|---|---|---|
| `wppilot/woocommerce-check-setup` | read | — | Active, version, floor, HPOS, block checkout, currency, country, published product count, known extensions. |
| `wppilot/woocommerce-list-products` | read | — | Compact 15-field rows with filters, paging, stable ordering and opt-in sections. |
| `wppilot/woocommerce-get-product` | read | — | One product in full, by id or slug. |
| `wppilot/woocommerce-list-product-variations` | read | — | A variable product's variations, compact or full. |
| `wppilot/woocommerce-get-product-variation` | read | — | One variation in full. |
| `wppilot/woocommerce-list-product-categories` | read | — | Categories with parent, display type, order and optional thumbnail. |
| `wppilot/woocommerce-list-product-tags` | read | — | Tags. |
| `wppilot/woocommerce-get-store-settings` | read | — | Currency and number format, units, base address. |
| `wppilot/woocommerce-list-orders` | read, `manage_woocommerce` | — | Orders by page, status and customer, through `wc_get_orders()`. |
| `wppilot/woocommerce-get-order` | read, `manage_woocommerce` | — | One order with addresses, items, line taxes and refunds. |
| `wppilot/woocommerce-list-customers` | read, `manage_woocommerce` | — | Customer accounts with order count and total spent. |
| `wppilot/woocommerce-get-customer` | read, `manage_woocommerce` | — | One customer with billing and shipping addresses. |
| `wppilot/woocommerce-edit-product` | write | `kits/woo-basics-product` | Name, description, regular and sale price, stock. |

## Choices

- Every ability registers only while its name is free (`Runtime\unclaimed()`): where another
  plugin registered it first, that copy runs. Error codes (`wc_invalid_input`, `wc_not_found`,
  ...) carry their HTTP status in the error data.
- The editor is deliberately basic. A richer editor under the same name (SKU, status, terms,
  images, attributes, dimensions, meta) takes precedence where it is registered. Unknown fields
  are refused rather than ignored.
- Writes go through `WC_Product` setters and `save()`, so the active price, the product lookup
  tables, stock status and WooCommerce's caches follow. The name and description are slashed
  first, since `save()` hands them to `wp_update_post()`, which unslashes them: without that a
  backslash in a description would be lost.
- The undo's before-image holds only the fields the edit touched (prices together, and the stock
  fields together with backorders and the low-stock threshold, which WooCommerce resets when
  stock management is switched off). The undo writes them back the same way, re-reads the
  product and reports any field that did not come back. It refuses a product that was deleted,
  trashed or changed type since the edit.
- Orders are read only through `wc_get_orders()` / `wc_get_order()`, so HPOS and posts storage
  answer alike. Orders and customers are personal data and need `manage_woocommerce` as well as
  the host's own permission.
- Reads never write. A category sort position stored under the legacy `order_<id>` key is read
  as a fallback and left where it is.

## Host needs

`Runtime\unclaimed()` (runtime 1.2), `Ledger::capture_for()` for the edit's before-image
(attached only when this kit registered the editor) and `Ledger::register_strategy()` for
`kits/woo-basics-product`, which boot registers.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/WooBasics/` in the GoBuildWP repository (not shipped).

Ability names, input schemas, output shapes and error codes are those of the same abilities in
GoBuildWP Pro 1.10.0, so an agent reads either copy the same way. That release still registers all
thirteen itself, ahead of the kits, so on a site running it this kit stands aside. Later Pro
releases keep only their richer `wppilot/woocommerce-edit-product`.
<!-- /kit-export:omit -->
