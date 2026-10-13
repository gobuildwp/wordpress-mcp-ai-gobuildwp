<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\WooBasics;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * The catalog and store reads. Each registers only while its name is free: GoBuildWP Pro 1.10.0
 * registers the same abilities itself, ahead of the kits, and the first registration wins.
 */

if (Runtime\unclaimed('wppilot/woocommerce-check-setup')) {
    wp_register_ability('wppilot/woocommerce-check-setup', [
        'label' => __('Check WooCommerce Setup', 'wppilot'),
        'description' => __(
            'Reports the WooCommerce environment: whether WC is active, version, whether it meets the minimum (9.0), whether HPOS (High-Performance Order Storage) is the canonical order storage, whether the Block Checkout page is in use, store currency, default country, total published products (products_count counts published products only; to enumerate ALL products including drafts and pending, use list-products with no status filter), and known WC extensions detected on the site (subscriptions, bookings, brands, memberships, product-add-ons, advanced-shipping, stripe-gateway, woopayments, paypal-payments). Run this before any other WC ability to confirm the runtime is ready.',
            'wppilot',
        ),
        'category' => 'woocommerce',
        'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false, 'default' => []],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'active' => ['type' => 'boolean'],
                'version' => ['type' => ['string', 'null']],
                'meets_minimum' => ['type' => 'boolean'],
                'hpos_enabled' => ['type' => 'boolean'],
                'block_checkout_enabled' => ['type' => 'boolean'],
                'currency' => ['type' => ['string', 'null']],
                'default_country' => ['type' => ['string', 'null']],
                'products_count' => ['type' => 'integer'],
                'registered_extensions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'slug' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'version' => ['type' => ['string', 'null']],
                            'active' => ['type' => 'boolean'],
                        ],
                        'required' => ['slug', 'name', 'version', 'active'],
                    ],
                ],
            ],
            'required' => [
                'active',
                'version',
                'meets_minimum',
                'hpos_enabled',
                'block_checkout_enabled',
                'currency',
                'default_country',
                'products_count',
                'registered_extensions',
            ],
        ],
        'execute_callback' => static fn(array $input = []): array => check_setup(),
        'permission_callback' => static fn(): bool => Runtime\can_run(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Run this first. If `meets_minimum` is false, no other WC ability will work; instruct the user to update WooCommerce.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}

if (Runtime\unclaimed('wppilot/woocommerce-list-products')) {
    wp_register_ability('wppilot/woocommerce-list-products', [
        'label' => __('List Products', 'wppilot'),
        'description' => __(
            'Returns a paginated list of WC products with a 15-field compact shape: id, name, slug, sku, status, type, stock_status, stock_quantity, manage_stock, price, regular_price, sale_price, featured, permalink, date_modified. Use `fields` to opt into extra sections per row: categories, tags, images, attributes, dimensions, meta_data, variations (id list), brands (only when WooCommerce Brands is active). Filters: search (title+sku), status, type, category (slug|id), tag (slug|id), featured, on_sale (true=only on-sale; false=exclude on-sale), stock_status, sku (exact), include[], exclude[], modified_after, modified_before. Paging: limit (default 20, max 200; clamped silently), offset. Ordering: orderby (date/title/sku/price/menu_order/popularity/rating), order (asc/desc).',
            'wppilot',
        ),
        'category' => 'woocommerce',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Substring match on title and sku.'],
                'status' => ['type' => 'string', 'enum' => LIST_STATUSES],
                'type' => ['type' => 'string', 'enum' => ['simple', 'variable', 'grouped', 'external']],
                'category' => ['type' => ['string', 'integer'], 'description' => 'Slug or term id.'],
                'tag' => ['type' => ['string', 'integer'], 'description' => 'Slug or term id.'],
                'featured' => ['type' => 'boolean'],
                'on_sale' => [
                    'type' => 'boolean',
                    'description' => 'true = only on-sale products; false = exclude on-sale products; omit for no filter.',
                ],
                'stock_status' => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
                'sku' => ['type' => 'string', 'description' => 'Exact match.'],
                'include' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'exclude' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'modified_after' => ['type' => 'string', 'description' => 'ISO 8601, server timezone.'],
                'modified_before' => ['type' => 'string', 'description' => 'ISO 8601, server timezone.'],
                'limit' => ['type' => 'integer', 'default' => 20, 'description' => 'Default 20, max 200 (clamped silently).'],
                'offset' => ['type' => 'integer', 'default' => 0],
                'orderby' => ['type' => 'string', 'enum' => LIST_ORDERBY, 'default' => 'date'],
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => LIST_FIELDS],
                    'description' => 'Opt-in extra sections per row.',
                ],
            ],
            'additionalProperties' => false,
            'default' => [],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'products' => ['type' => 'array', 'items' => ['type' => 'object']],
                'total' => ['type' => 'integer'],
                'returned' => ['type' => 'integer'],
                'truncated' => ['type' => 'boolean'],
            ],
            'required' => ['products', 'total', 'returned', 'truncated'],
        ],
        'execute_callback' => static fn(array $input = []): array|WP_Error => list_products($input),
        'permission_callback' => static fn(): bool => Runtime\can_run(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'For typical "show me my products" calls, omit `fields`; the compact shape is enough. Only set fields when you need categories/images/etc. inline. To page beyond the first 200 results, increase offset.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}

if (Runtime\unclaimed('wppilot/woocommerce-get-product')) {
    wp_register_ability('wppilot/woocommerce-get-product', [
        'label' => __('Get Product', 'wppilot'),
        'description' => __(
            'Returns the full WC product object for one product, identified by `id` or `slug` (one is required). Includes augmented sections: images (with id, src, alt, position), categories ({id,slug,name}), tags ({id,slug,name}), attributes (id, name, slug, options[]), variations (id list, full variation objects via get-product-variation), meta_data (caller-visible meta only; underscore-prefixed system keys are filtered out), price_html (rendered HTML price string). Date fields normalized to ISO 8601 strings.',
            'wppilot',
        ),
        'category' => 'woocommerce',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Product id. Provide id OR slug.'],
                'slug' => ['type' => 'string', 'description' => 'Product slug. Provide id OR slug.'],
            ],
            'additionalProperties' => false,
            'default' => [],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'sku' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'permalink' => ['type' => 'string'],
                'images' => ['type' => 'array'],
                'categories' => ['type' => 'array'],
                'tags' => ['type' => 'array'],
                'attributes' => ['type' => 'array'],
                'variations' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'variations_truncated' => [
                    'type' => 'boolean',
                    'description' => 'True when the parent has more than 200 variations and the list is capped. More variations exist; call list-product-variations to enumerate.',
                ],
                'meta_data' => ['type' => 'array'],
                'description_truncated' => [
                    'type' => 'boolean',
                    'description' => 'True when the stored description exceeded 64 KB and was truncated in this response.',
                ],
                'price' => ['type' => 'string'],
                'regular_price' => ['type' => 'string'],
                'sale_price' => ['type' => ['string', 'null']],
                'stock_status' => ['type' => 'string'],
                'price_html' => [
                    'type' => 'string',
                    'description' => 'Rendered HTML price string (e.g. "<span class=\"woocommerce-Price-amount\">…</span>").',
                ],
                'brands' => [
                    'type' => 'array',
                    'description' => 'Brand taxonomy terms (only present when WooCommerce Brands is active).',
                ],
            ],
            'required' => ['id', 'name', 'slug'],
        ],
        'execute_callback' => static fn(array $input = []): array|WP_Error => get_product($input),
        'permission_callback' => static fn(): bool => Runtime\can_run(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'For lists, prefer list-products with fields[] over multiple get-product calls; far cheaper in tokens.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}

if (Runtime\unclaimed('wppilot/woocommerce-list-product-variations')) {
    wp_register_ability('wppilot/woocommerce-list-product-variations', [
        'label' => __('List Product Variations', 'wppilot'),
        'description' => __(
            'Returns a paginated compact list of variations for a variable product. Required: `parent_id`. Each row: id, parent_id, sku, attributes_summary (e.g. "M / Red"), regular_price, sale_price, stock_status, stock_quantity, is_default. Optional `fields: ["full"]` to include all variation fields per row. Pagination: `limit` (default 50, max 200), `offset`. Ordering: `orderby` (id|menu_order|date), `order` (asc|desc). The default compact shape is ~200 bytes per variation. Passing `fields: ["full"]` returns ~800 bytes per variation. Plan limit accordingly when expanding. Possible errors: wc_invalid_input, wc_not_found (parent does not exist), wc_not_variable_parent (parent is not a variable product, including WooCommerce Subscriptions `variable-subscription`). On multilingual sites (WPML, Polylang), translated copies of a variation may appear as separate rows.',
            'wppilot',
        ),
        'category' => 'woocommerce',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'parent_id' => ['type' => 'integer', 'description' => 'Id of the parent variable product (required).'],
                'limit' => ['type' => 'integer', 'default' => 50, 'description' => 'Maximum rows to return. Default 50, max 200.'],
                'offset' => ['type' => 'integer', 'default' => 0, 'description' => 'Pagination offset.'],
                'orderby' => [
                    'type' => 'string',
                    'enum' => ['id', 'menu_order', 'date'],
                    'default' => 'menu_order',
                    'description' => 'Sort column.',
                ],
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc', 'description' => 'Sort direction.'],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['full']],
                    'description' => 'Opt-in extra fields per row. Pass ["full"] to include all variation fields.',
                ],
            ],
            'required' => ['parent_id'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'variations' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'Array of variation rows (compact shape by default; full shape if fields=["full"] is passed).',
                ],
                'total' => ['type' => 'integer', 'description' => 'Total count of variations on the parent (before limit/offset).'],
                'returned' => ['type' => 'integer', 'description' => 'Number of rows returned in this response (after limit/offset).'],
            ],
            'required' => ['variations', 'total', 'returned'],
        ],
        'execute_callback' => static fn(array $input): array|WP_Error => list_product_variations($input),
        'permission_callback' => static fn(): bool => Runtime\can_run(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Use parent_id to scope the list. Omit fields for the compact shape; add ["full"] only when you need all variation fields.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}

if (Runtime\unclaimed('wppilot/woocommerce-get-product-variation')) {
    wp_register_ability('wppilot/woocommerce-get-product-variation', [
        'label' => __('Get Product Variation', 'wppilot'),
        'description' => __(
            'Returns the full variation object for a single product variation, identified by `id`. Includes: id, parent_id, name (auto-derived from parent; use `attributes_summary` e.g. "M / Red" to identify the specific variation), sku, status, attributes (structured + summary), regular_price, sale_price, price, manage_stock, stock_quantity, stock_status, weight, dimensions, image_id, image_src, description, description_truncated, virtual, downloadable, shipping_class, is_default, date_modified. `description_truncated` is true when the stored description exceeded 64 KB and was truncated in this response. Price fields reflect WooCommerce\'s filter chain (same value the storefront sees). Plugins that filter price at runtime will affect this output. Possible errors: wc_invalid_input, wc_not_found (variation does not exist).',
            'wppilot',
        ),
        'category' => 'woocommerce',
        'input_schema' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'Variation post id (required).']],
            'required' => ['id'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Variation post ID.'],
                'parent_id' => ['type' => 'integer', 'description' => 'Parent variable product post ID.'],
                'name' => ['type' => 'string', 'description' => 'Auto-derived display name (parent name + attribute combo).'],
                'sku' => ['type' => 'string', 'description' => 'Stock-keeping unit for this variation.'],
                'status' => ['type' => 'string', 'description' => 'Post status (publish, private, draft, trash).'],
                'attributes' => ['type' => 'array', 'description' => 'Structured attribute list: [{taxonomy_slug|name, option}].'],
                'attributes_summary' => [
                    'type' => 'string',
                    'description' => 'Human-readable attribute combination string (e.g. "M / Red"). Use this to identify the specific variation.',
                ],
                'regular_price' => ['type' => 'string', 'description' => 'Regular price as a numeric string.'],
                'sale_price' => ['type' => ['string', 'null'], 'description' => 'Sale price as a numeric string, or null when no sale is active.'],
                'price' => ['type' => 'string', 'description' => 'Effective current price as seen by the storefront (after WC filter chain).'],
                'manage_stock' => ['type' => 'boolean', 'description' => 'Whether per-variation stock management is enabled.'],
                'stock_quantity' => ['type' => ['integer', 'null'], 'description' => 'Current stock quantity, or null when manage_stock is false.'],
                'stock_status' => ['type' => 'string', 'description' => 'Stock status: instock, outofstock, or onbackorder.'],
                'weight' => ['type' => 'string', 'description' => 'Variation weight in store units.'],
                'dimensions' => ['type' => 'object', 'description' => 'Physical dimensions: {length, width, height} in store units.'],
                'image_id' => ['type' => ['integer', 'null'], 'description' => 'Attachment ID of the variation image, or null if unset.'],
                'image_src' => ['type' => ['string', 'null'], 'description' => 'URL of the variation image, or null if unset.'],
                'description' => ['type' => 'string', 'description' => 'Variation-level short description.'],
                'description_truncated' => [
                    'type' => 'boolean',
                    'description' => 'True when the stored description exceeded 64 KB and was truncated in this response.',
                ],
                'virtual' => ['type' => 'boolean', 'description' => 'Whether the variation is virtual (no shipping required).'],
                'downloadable' => ['type' => 'boolean', 'description' => 'Whether the variation is downloadable.'],
                'shipping_class' => ['type' => 'string', 'description' => 'Shipping class slug assigned to this variation.'],
                'is_default' => ['type' => 'boolean', 'description' => 'Whether this variation is the storefront default for the parent.'],
                'date_modified' => ['type' => ['string', 'null'], 'description' => 'ISO 8601 timestamp of the last modification, or null.'],
            ],
            'required' => ['id', 'parent_id', 'sku', 'attributes_summary', 'is_default'],
        ],
        'execute_callback' => static fn(array $input): array|WP_Error => get_product_variation($input),
        'permission_callback' => static fn(): bool => Runtime\can_run(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Use list-product-variations first to discover variation ids; use this for the full object of a single variation.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}

if (Runtime\unclaimed('wppilot/woocommerce-list-product-categories')) {
    wp_register_ability('wppilot/woocommerce-list-product-categories', [
        'label' => __('List Product Categories', 'wppilot'),
        'description' => __(
            'Returns the WooCommerce product categories. Tree-shaped: each item carries `parent_id` (term id) and `parent_slug` so callers can build the hierarchy. Includes empty categories by default. Use `hide_empty: true` to exclude categories that have zero published products. The `search` filter does substring match on the name field. Default limit is 50; pass `limit` to fetch more. Pass `include_thumbnail: true` to fetch the featured image id and URL per category; omitted by default to reduce payload. The `parent` and `parent_id` filter inputs are interchangeable; provide one or the other, not both.',
            'wppilot',
        ),
        'category' => 'woocommerce',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Substring match on the name field.'],
                'parent' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Filter to direct children of a parent id. 0 = top-level only. Alias: parent_id.',
                ],
                'parent_id' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Alias for `parent`. Filter to direct children of a parent id. 0 = top-level only. Provide one or the other, not both.',
                ],
                'hide_empty' => ['type' => 'boolean', 'description' => 'Exclude categories with no published products.', 'default' => false],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default 50, max 200.', 'default' => 50],
                'include_thumbnail' => [
                    'type' => 'boolean',
                    'description' => 'When true, each category row includes `thumbnail_id` and `thumbnail_src`. Omitted by default to reduce payload.',
                    'default' => false,
                ],
            ],
            'additionalProperties' => false,
            'default' => [],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'categories' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'description' => 'Category term ID.'],
                            'name' => ['type' => 'string', 'description' => 'Display name.'],
                            'slug' => ['type' => 'string', 'description' => 'URL slug.'],
                            'description' => ['type' => 'string', 'description' => 'Category description.'],
                            'count' => ['type' => 'integer', 'description' => 'Number of products directly attached.'],
                            'parent_id' => ['type' => 'integer', 'description' => 'Parent category ID; 0 if root.'],
                            'parent_slug' => ['type' => ['string', 'null'], 'description' => 'Parent category slug; null if root.'],
                            'thumbnail_id' => ['type' => ['integer', 'null'], 'description' => 'Featured image attachment ID or null.'],
                            'thumbnail_src' => ['type' => ['string', 'null'], 'description' => 'Featured image URL or null.'],
                            'display_type' => [
                                'type' => 'string',
                                'description' => 'Archive display: default, products, subcategories, or both.',
                            ],
                            'menu_order' => ['type' => 'integer', 'description' => 'Sort order.'],
                        ],
                        'required' => ['id', 'slug', 'name', 'parent_id', 'count', 'description', 'display_type', 'menu_order'],
                    ],
                ],
                'total' => ['type' => 'integer'],
            ],
            'required' => ['categories', 'total'],
        ],
        'execute_callback' => static fn(array $input = []): array|WP_Error => list_product_categories($input),
        'permission_callback' => static fn(): bool => Runtime\can_run(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Use the response to build a category tree by grouping items by `parent_id`. Top-level items have parent_id = 0.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}

if (Runtime\unclaimed('wppilot/woocommerce-list-product-tags')) {
    wp_register_ability('wppilot/woocommerce-list-product-tags', [
        'label' => __('List Product Tags', 'wppilot'),
        'description' => __(
            'Returns WooCommerce product tags as a flat list; tags have no parent hierarchy (unlike categories). Pagination: `limit` controls max results (default 200, max 500); use `offset` with the `search` arg or paginate with repeated calls. `hide_empty: true` excludes tags not assigned to any published product. `search` does a substring match on the name field. Each tag in the response includes id, slug, name, count (products tagged), and description. Possible errors: wc_invalid_input (bad limit value), wc_internal_error (query failure).',
            'wppilot',
        ),
        'category' => 'woocommerce',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Substring match on the name field.'],
                'hide_empty' => ['type' => 'boolean', 'description' => 'Exclude tags with no published products.', 'default' => false],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default 200, max 500.', 'default' => 200],
            ],
            'additionalProperties' => false,
            'default' => [],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'description' => 'Tag term ID.'],
                            'slug' => ['type' => 'string', 'description' => 'URL slug.'],
                            'name' => ['type' => 'string', 'description' => 'Display name.'],
                            'count' => ['type' => 'integer', 'description' => 'Number of products tagged.'],
                            'description' => ['type' => 'string', 'description' => 'Tag description.'],
                        ],
                        'required' => ['id', 'slug', 'name', 'count', 'description'],
                    ],
                ],
                'total' => ['type' => 'integer'],
            ],
            'required' => ['tags', 'total'],
        ],
        'execute_callback' => static fn(array $input = []): array|WP_Error => list_product_tags($input),
        'permission_callback' => static fn(): bool => Runtime\can_run(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Tags are flat; no `parent` field. Use as a secondary classification on top of categories.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}

if (Runtime\unclaimed('wppilot/woocommerce-get-store-settings')) {
    wp_register_ability('wppilot/woocommerce-get-store-settings', [
        'label' => __('Get WooCommerce Store Settings', 'wppilot'),
        'description' => __(
            'Returns the store-level settings that affect product display and pricing: currency, currency symbol and position, thousand and decimal separators, number of decimals, weight and dimension units, default country, and the store base address. Pulled from a mix of WC helpers and direct option reads (woocommerce_* options).',
            'wppilot',
        ),
        'category' => 'woocommerce',
        'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false, 'default' => []],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'currency' => ['type' => 'string'],
                'currency_symbol' => ['type' => 'string'],
                'currency_position' => ['type' => 'string'],
                'thousand_separator' => ['type' => 'string'],
                'decimal_separator' => ['type' => 'string'],
                'num_decimals' => ['type' => 'integer'],
                'weight_unit' => ['type' => 'string'],
                'dimension_unit' => ['type' => 'string'],
                'default_country' => ['type' => 'string'],
                'store_address' => [
                    'type' => 'object',
                    'properties' => [
                        'address_1' => ['type' => 'string'],
                        'address_2' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'postcode' => ['type' => 'string'],
                        'state' => ['type' => 'string'],
                        'country' => ['type' => 'string'],
                    ],
                    'required' => ['address_1', 'address_2', 'city', 'postcode', 'state', 'country'],
                ],
            ],
            'required' => [
                'currency',
                'currency_symbol',
                'currency_position',
                'thousand_separator',
                'decimal_separator',
                'num_decimals',
                'weight_unit',
                'dimension_unit',
                'default_country',
                'store_address',
            ],
        ],
        'execute_callback' => static fn(array $input = []): array|WP_Error => get_store_settings(),
        'permission_callback' => static fn(): bool => Runtime\can_run(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Treat all string values as opaque. Currency symbol is returned as an HTML entity (e.g., `&#36;`, `&euro;`, `&yen;`). Decode with html_entity_decode() before display. Use num_decimals + separators to format prices, not custom logic.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}
