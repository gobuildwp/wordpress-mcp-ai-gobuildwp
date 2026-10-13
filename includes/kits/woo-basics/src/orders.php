<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\WooBasics;

use WC_Customer;
use WC_Order;
use WC_Order_Refund;
use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Orders and customers, read through WooCommerce's CRUD and query APIs only, so the same code
 * answers on a store using HPOS order tables and on one still storing orders as posts.
 *
 * Both are personal data: names, emails, addresses. They are gated on manage_woocommerce on top
 * of the host's own check.
 */

function commerce_permission(): bool
{
    return Runtime\can_run() && current_user_can('manage_woocommerce');
}

/**
 * The list schema the order and customer lists share: page, limit and the filters given.
 *
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function list_schema(array $extra = []): array
{
    return [
        'type' => 'object',
        'properties' => array_merge([
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ], $extra),
        'additionalProperties' => false,
        'default' => [],
    ];
}

/**
 * @return array<string, mixed>
 */
function id_schema(string $field): array
{
    return [
        'type' => 'object',
        'properties' => [$field => ['type' => 'integer']],
        'additionalProperties' => false,
        'default' => [],
        'required' => [$field],
    ];
}

/**
 * @return array<string, mixed>
 */
function order_summary(WC_Order $order, bool $full = false): array
{
    $created = $order->get_date_created();
    $modified = $order->get_date_modified();
    $data = [
        'id' => $order->get_id(),
        'number' => $order->get_order_number(),
        'status' => $order->get_status(),
        'currency' => $order->get_currency(),
        'total' => $order->get_total(),
        'refunded_total' => (string) $order->get_total_refunded(),
        'customer_id' => $order->get_customer_id(),
        'billing_email' => $order->get_billing_email(),
        'payment_method' => $order->get_payment_method(),
        'payment_method_title' => $order->get_payment_method_title(),
        'date_created' => $created !== null ? $created->date('c') : null,
        'date_modified' => $modified !== null ? $modified->date('c') : null,
    ];
    if (!$full) {
        return $data;
    }
    $items = [];
    foreach ($order->get_items() as $item) {
        $items[] = [
            'id' => $item->get_id(),
            'product_id' => $item->get_product_id(),
            'variation_id' => $item->get_variation_id(),
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'subtotal' => $item->get_subtotal(),
            'total' => $item->get_total(),
            // Tax on the line by tax rate id: a refund's per-line tax is keyed by these ids.
            'taxes' => item_taxes($item),
        ];
    }
    $data['billing'] = $order->get_address('billing');
    $data['shipping'] = $order->get_address('shipping');
    $data['items'] = $items;
    $data['customer_note'] = $order->get_customer_note();
    $refunds = [];
    foreach ($order->get_refunds() as $refund) {
        if ($refund instanceof WC_Order_Refund) {
            $refunds[] = refund_summary($refund);
        }
    }
    $data['refunds'] = $refunds;
    return $data;
}

/**
 * @return array<string, mixed>
 */
function refund_summary(WC_Order_Refund $refund): array
{
    $created = $refund->get_date_created();
    return [
        'id' => $refund->get_id(),
        'order_id' => $refund->get_parent_id(),
        'amount' => $refund->get_amount(),
        'reason' => $refund->get_reason(),
        'date_created' => $created !== null ? $created->date('c') : null,
        'refunded_by' => $refund->get_refunded_by(),
    ];
}

/**
 * @return array<int, string>
 */
function item_taxes(object $item): array
{
    if (!method_exists($item, 'get_taxes')) {
        return [];
    }
    /** @var mixed $taxes */
    $taxes = $item->get_taxes();
    $total = is_array($taxes) && is_array($taxes['total'] ?? null) ? $taxes['total'] : [];
    $out = [];
    foreach ($total as $rate_id => $amount) {
        $out[(int) $rate_id] = is_scalar($amount) ? (string) $amount : '0';
    }
    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function list_orders(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    $limit = min(100, max(1, (int) ($input['limit'] ?? 20)));
    $page = max(1, (int) ($input['page'] ?? 1));
    // Orders only: without a type wc_get_orders() also returns refunds, which are not orders, are
    // not WC_Order, and would inflate total and pages (Pro 1.10.0's copy fatals on them).
    $args = ['type' => 'shop_order', 'limit' => $limit, 'paged' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC'];
    if (isset($input['status']) && is_string($input['status']) && $input['status'] !== '') {
        $status = str_replace('wc-', '', sanitize_key($input['status']));
        if (!array_key_exists('wc-' . $status, (array) wc_get_order_statuses())) {
            return error('wc_invalid_input', __('Unknown order status.', 'wppilot'));
        }
        $args['status'] = $status;
    }
    if ((int) ($input['customer_id'] ?? 0) > 0) {
        $args['customer_id'] = (int) $input['customer_id'];
    }
    /** @var mixed $query */
    $query = wc_get_orders($args);
    $orders = is_object($query) && is_array($query->orders ?? null) ? $query->orders : [];
    $rows = [];
    foreach ($orders as $order) {
        if ($order instanceof WC_Order) {
            $rows[] = order_summary($order);
        }
    }
    return [
        'orders' => $rows,
        'page' => $page,
        'limit' => $limit,
        'total' => is_object($query) ? (int) ($query->total ?? count($orders)) : count($orders),
        'pages' => is_object($query) ? (int) ($query->max_num_pages ?? 1) : 1,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function get_order(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    $order = wc_get_order((int) ($input['order_id'] ?? 0));
    if (!$order instanceof WC_Order) {
        return error('wc_not_found', __('Order not found.', 'wppilot'));
    }
    return order_summary($order, true);
}

/**
 * @return array<string, mixed>
 */
function customer_summary(WC_Customer $customer): array
{
    $created = $customer->get_date_created();
    return [
        'id' => $customer->get_id(),
        'email' => $customer->get_email(),
        'first_name' => $customer->get_first_name(),
        'last_name' => $customer->get_last_name(),
        'display_name' => $customer->get_display_name(),
        'username' => $customer->get_username(),
        'orders_count' => wc_get_customer_order_count($customer->get_id()),
        'total_spent' => wc_get_customer_total_spent($customer->get_id()),
        'date_created' => $created !== null ? $created->date('c') : null,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function list_customers(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    $limit = min(100, max(1, (int) ($input['limit'] ?? 20)));
    $page = max(1, (int) ($input['page'] ?? 1));
    $args = ['role' => 'customer', 'number' => $limit, 'paged' => $page, 'orderby' => 'registered', 'order' => 'DESC'];
    if (isset($input['search']) && is_string($input['search']) && trim($input['search']) !== '') {
        $args['search'] = '*' . sanitize_text_field($input['search']) . '*';
        $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }
    $query = new \WP_User_Query($args);
    $customers = [];
    foreach ((array) $query->get_results() as $user) {
        if ($user instanceof \WP_User) {
            $customers[] = customer_summary(new WC_Customer((int) $user->ID));
        }
    }
    return ['customers' => $customers, 'page' => $page, 'limit' => $limit, 'total' => (int) $query->get_total()];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function get_customer(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    $id = (int) ($input['customer_id'] ?? 0);
    if ($id <= 0 || !get_user_by('id', $id)) {
        return error('wc_not_found', __('Customer not found.', 'wppilot'));
    }
    $customer = new WC_Customer($id);
    $data = customer_summary($customer);
    $data['billing'] = $customer->get_billing();
    $data['shipping'] = $customer->get_shipping();
    return $data;
}
