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
 * The basic product editor. GoBuildWP Pro registers a richer editor under the same name (SKU,
 * status, categories, images, attributes, dimensions...), and on a site running Pro that one
 * wins. The before-image is attached only when this copy registered: attached to Pro's ability
 * it would take the place of Pro's own undo record.
 */

if (Runtime\unclaimed('wppilot/woocommerce-edit-product')) {
    wp_register_ability('wppilot/woocommerce-edit-product', [
        'label' => __('Edit Product', 'wppilot'),
        'description' => __(
            'Basic product editor: changes a WooCommerce product\'s name, description, regular and sale price, and stock (manage_stock, stock_quantity, stock_status). PATCH semantics: only the fields given change. Identify the product with `id` OR `slug`. Aliases: `post_title` = `name`, `post_content` = `description`. Prices are non-negative numeric strings; null or "" clears a price. On a variable product, prices and stock live on each variation, so only name and description can be changed here. A quantity given without manage_stock applies when the product already manages stock, and sets the stock status from it unless stock_status is given. Saved through WooCommerce, so the shop\'s price and stock lookups follow. Undo from the change log with wppilot/rollback-change. SKU, status, categories, tags, images, attributes, dimensions and custom fields are not edited here; a fuller product editor, where one is installed, registers under this same name and does those. Possible errors: wc_invalid_input, wc_not_found, wc_post_type_mismatch, wc_internal_error.',
            'wppilot',
        ),
        'category' => 'woocommerce',
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Product id. Provide id OR slug.'],
                'slug' => ['type' => 'string', 'description' => 'Product slug. Provide id OR slug.'],
                'name' => ['type' => 'string', 'description' => 'Product name.'],
                'post_title' => ['type' => 'string', 'description' => 'WordPress-native alias for `name`.'],
                'description' => ['type' => 'string', 'description' => 'Full product description (HTML allowed, as in the product editor).'],
                'post_content' => ['type' => 'string', 'description' => 'WordPress-native alias for `description`.'],
                'regular_price' => [
                    'type' => ['string', 'null'],
                    'description' => 'Regular price (numeric string). Must be non-negative. Pass null or empty string to clear.',
                ],
                'sale_price' => [
                    'type' => ['string', 'null'],
                    'description' => 'Sale price (numeric string). Pass null or empty string to clear. Must be non-negative.',
                ],
                'manage_stock' => ['type' => 'boolean', 'description' => 'Enable per-product stock management.'],
                'stock_quantity' => ['type' => 'integer', 'description' => 'Stock quantity.'],
                'stock_status' => [
                    'type' => 'string',
                    'enum' => ['instock', 'outofstock', 'onbackorder'],
                    'description' => 'Stock status.',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'product' => ['type' => 'object', 'description' => 'Full product object after the update.'],
            ],
            'required' => ['success', 'product'],
        ],
        'execute_callback' => static fn(array $input = []): array|WP_Error => edit_product($input),
        'permission_callback' => static fn(): bool => Runtime\can_run(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Read the product with get-product first and change only what the user asked for.',
                'readonly' => false,
                'destructive' => false,
                'idempotent' => false,
            ],
        ],
    ]);
    Runtime\host()->ledger()->capture_for(
        'wppilot/woocommerce-edit-product',
        static fn(array $input): ?array => capture_edit($input),
    );
}
