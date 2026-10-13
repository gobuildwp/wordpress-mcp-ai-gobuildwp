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
 * Order and customer reads. Personal data: gated on manage_woocommerce as well as the host's
 * own check. Each registers only while GoBuildWP Pro 1.10.0 has not registered its own copy.
 */

if (Runtime\unclaimed('wppilot/woocommerce-list-orders')) {
    wp_register_ability('wppilot/woocommerce-list-orders', [
        'label' => __('List Orders', 'wppilot'),
        'description' => __('List bounded WooCommerce orders with pagination and status filtering.', 'wppilot'),
        'category' => 'woocommerce',
        'input_schema' => list_schema(['status' => ['type' => 'string'], 'customer_id' => ['type' => 'integer']]),
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static fn(array $input = []): array|WP_Error => list_orders($input),
        'permission_callback' => static fn(): bool => commerce_permission(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);
}

if (Runtime\unclaimed('wppilot/woocommerce-get-order')) {
    wp_register_ability('wppilot/woocommerce-get-order', [
        'label' => __('Get Order', 'wppilot'),
        'description' => __('Get one order including addresses, items, totals, notes, and refunds.', 'wppilot'),
        'category' => 'woocommerce',
        'input_schema' => id_schema('order_id'),
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static fn(array $input = []): array|WP_Error => get_order($input),
        'permission_callback' => static fn(): bool => commerce_permission(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);
}

if (Runtime\unclaimed('wppilot/woocommerce-list-customers')) {
    wp_register_ability('wppilot/woocommerce-list-customers', [
        'label' => __('List Customers', 'wppilot'),
        'description' => __('List bounded WooCommerce customer accounts without password or token data.', 'wppilot'),
        'category' => 'woocommerce',
        'input_schema' => list_schema(['search' => ['type' => 'string']]),
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static fn(array $input = []): array|WP_Error => list_customers($input),
        'permission_callback' => static fn(): bool => commerce_permission(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);
}

if (Runtime\unclaimed('wppilot/woocommerce-get-customer')) {
    wp_register_ability('wppilot/woocommerce-get-customer', [
        'label' => __('Get Customer', 'wppilot'),
        'description' => __('Get one WooCommerce customer account and aggregate order statistics.', 'wppilot'),
        'category' => 'woocommerce',
        'input_schema' => id_schema('customer_id'),
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static fn(array $input = []): array|WP_Error => get_customer($input),
        'permission_callback' => static fn(): bool => commerce_permission(),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);
}
