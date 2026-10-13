<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\WooBasics;

use WC_Product;
use WP_Error;
use WP_Post;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\SessionLedger;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The basic product editor — name, description, prices and stock — and its undo.
 *
 * Every write goes through WC_Product's setters and save(), never raw post meta: save() is what
 * recomputes the active price, keeps the product lookup tables (which sort and filter the shop)
 * in step, syncs the stock status and clears WooCommerce's caches. The undo writes back the same
 * way for the same reason.
 *
 * When any post field changes, WooCommerce's data store hands the name, description and short
 * description together to wp_update_post(), which unslashes what it stores. A literal backslash
 * (a Windows path, an escaped quote in a code sample) in any of the three would be lost, the
 * untouched ones included, unless all three are slashed first. WooCommerce's own REST API passes
 * them slashed for the same reason.
 */

const STRATEGY = 'kits/woo-basics-product';

/** Input the basic editor accepts besides `id` and `slug`, after aliases are resolved. */
const EDIT_FIELDS = ['name', 'description', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status'];

const STOCK_FIELDS = ['manage_stock', 'stock_quantity', 'stock_status'];

const PRICE_FIELDS = ['regular_price', 'sale_price'];

/**
 * Fields the undo puts back. WooCommerce resets backorders and the low-stock threshold when stock
 * management is switched off, so they are kept with the stock fields.
 */
const STOCK_RESTORE_FIELDS = ['manage_stock', 'stock_quantity', 'backorders', 'low_stock_amount', 'stock_status'];

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function normalize_edit_input(array $input): array
{
    foreach (['post_title' => 'name', 'post_content' => 'description'] as $alias => $field) {
        if (array_key_exists($alias, $input) && !array_key_exists($field, $input)) {
            $input[$field] = $input[$alias];
        }
        unset($input[$alias]);
    }
    return $input;
}

/**
 * A post title or content value as WC_Product::save() must be given it: slashed, except inside
 * save_post, where WooCommerce writes the row with $wpdb directly and nothing unslashes it.
 */
function post_field_for_save(string $value): string
{
    if (doing_action('save_post')) {
        return $value;
    }
    /** @var string $slashed */
    $slashed = wp_slash($value);
    return $slashed;
}

/**
 * Set a new name and/or description, and re-set the post fields left as they were, all in the
 * form save() needs; see the header. A null argument keeps that field's stored value.
 */
function set_post_fields(WC_Product $product, ?string $name, ?string $description): void
{
    $product->set_name(post_field_for_save($name ?? (string) $product->get_name('edit')));
    $product->set_description(post_field_for_save($description ?? (string) $product->get_description('edit')));
    $product->set_short_description(post_field_for_save((string) $product->get_short_description('edit')));
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function edit_product(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    $key = resolve_product_key($input);
    if ($key instanceof WP_Error) {
        return $key;
    }
    $product = product_or_error($key);
    if ($product instanceof WP_Error) {
        return $product;
    }

    unset($input['id'], $input['slug']);
    $input = normalize_edit_input($input);
    $unknown = array_diff(array_keys($input), EDIT_FIELDS);
    if ($unknown !== []) {
        return error('wc_invalid_input', sprintf(
            /* translators: %s: comma-separated field names */
            __('The basic product editor changes the name, description, prices and stock only; it does not accept: %s.', 'wppilot'),
            implode(', ', $unknown),
        ));
    }

    if ($product->get_type() === 'variable') {
        foreach (STOCK_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                return error('wc_invalid_input', __(
                    'Stock is managed per variation on variable products. Do not pass manage_stock, stock_quantity, or stock_status on the parent.',
                    'wppilot',
                ));
            }
        }
        foreach (PRICE_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                return error('wc_invalid_input', __(
                    'Prices are managed per variation on variable products. Set prices on each variation, not on the parent.',
                    'wppilot',
                ));
            }
        }
    }

    $invalid = strict_bool_check($input, ['manage_stock'])
        ?? apply_text_fields($product, $input)
        ?? apply_price_fields($product, $input)
        ?? apply_stock_fields($product, $input);
    if ($invalid !== null) {
        return $invalid;
    }

    try {
        $id = (int) $product->save();
    } catch (\Throwable $e) {
        return error('wc_internal_error', __('WooCommerce failed to save the product.', 'wppilot'));
    }
    if ($id <= 0) {
        return error('wc_internal_error', __('WooCommerce failed to save the product.', 'wppilot'));
    }
    clean_post_cache($id);
    $saved = wc_get_product($id);
    if (!$saved instanceof WC_Product) {
        return error('wc_internal_error', __('Product saved but could not be re-read.', 'wppilot'));
    }
    return ['success' => true, 'product' => full_product($saved)];
}

/**
 * @param array<string, mixed> $input
 */
function apply_text_fields(WC_Product $product, array $input): ?WP_Error
{
    $name = null;
    if (isset($input['name'])) {
        if (!is_string($input['name']) && !is_numeric($input['name'])) {
            return error('wc_invalid_input', sprintf(
                /* translators: 1: field name, 2: actual type */
                __('`%1$s` must be a string or number (got %2$s).', 'wppilot'),
                'name',
                gettype($input['name']),
            ));
        }
        $name = strip_unsafe_chars((string) $input['name']);
        if (strlen($name) > 65535) {
            return error('wc_invalid_input', __('Product name cannot exceed 65535 bytes.', 'wppilot'));
        }
    }
    $description = null;
    if (isset($input['description'])) {
        if (!is_string($input['description']) && !is_numeric($input['description'])) {
            return error('wc_invalid_input', sprintf(
                /* translators: 1: field name, 2: actual type */
                __('`%1$s` must be a string or number (got %2$s).', 'wppilot'),
                'description',
                gettype($input['description']),
            ));
        }
        $description = (string) $input['description'];
        if (strlen($description) > 65536) {
            return error('wc_invalid_input', sprintf(
                /* translators: 1: actual byte length, 2: maximum bytes */
                __('`description` cannot exceed %2$d bytes (got %1$d).', 'wppilot'),
                strlen($description),
                65536,
            ));
        }
        $description = strip_unsafe_chars_preserve_ws($description);
    }
    if ($name !== null || $description !== null) {
        set_post_fields($product, $name, $description);
    }
    return null;
}

/**
 * Prices are non-negative decimal strings; scientific notation is refused because WooCommerce
 * stores it verbatim and the storefront then shows "1e3". null or "" clears the price.
 */
function validate_price_field(string $field, mixed $price): ?WP_Error
{
    if ($price === null || $price === '') {
        return null;
    }
    if (!is_string($price) && !is_numeric($price)) {
        return error('wc_invalid_input', sprintf(
            /* translators: 1: field name, 2: actual type */
            __('`%1$s` must be a string or number (got %2$s).', 'wppilot'),
            $field,
            gettype($price),
        ));
    }
    if (preg_match('/[eE]/', (string) $price) === 1) {
        return error('wc_invalid_input', sprintf(
            /* translators: 1: field name, 2: the submitted value */
            __('`%1$s` does not accept scientific notation (got `%2$s`).', 'wppilot'),
            $field,
            (string) $price,
        ));
    }
    if (!is_numeric($price)) {
        /* translators: %s: field name */
        return error('wc_invalid_input', sprintf(__('`%s` must be a numeric string.', 'wppilot'), $field));
    }
    if ((float) $price < 0) {
        /* translators: %s: field name */
        return error('wc_invalid_input', sprintf(__('`%s` cannot be negative.', 'wppilot'), $field));
    }
    return null;
}

/**
 * @param array<string, mixed> $input
 */
function apply_price_fields(WC_Product $product, array $input): ?WP_Error
{
    foreach (PRICE_FIELDS as $field) {
        if (array_key_exists($field, $input)) {
            $invalid = validate_price_field($field, $input[$field]);
            if ($invalid !== null) {
                return $invalid;
            }
        }
    }
    if (array_key_exists('regular_price', $input)) {
        $product->set_regular_price($input['regular_price'] === null ? '' : (string) $input['regular_price']);
    }
    if (array_key_exists('sale_price', $input)) {
        $product->set_sale_price($input['sale_price'] === null ? '' : (string) $input['sale_price']);
    }
    return null;
}

/**
 * @param array<string, mixed> $input
 */
function apply_stock_fields(WC_Product $product, array $input): ?WP_Error
{
    if (array_key_exists('stock_quantity', $input)) {
        /** @var mixed $raw */
        $raw = $input['stock_quantity'];
        if ($raw !== null && !is_int($raw) && (!is_numeric($raw) || (float) $raw !== floor((float) $raw))) {
            return error('wc_invalid_input', __('`stock_quantity` must be a whole number (integer).', 'wppilot'));
        }
    }
    if (isset($input['stock_status'])) {
        $invalid = validate_enum_field($input, 'stock_status', ['instock', 'outofstock', 'onbackorder']);
        if ($invalid !== null) {
            return $invalid;
        }
    }
    $manage = array_key_exists('manage_stock', $input) ? ($input['manage_stock'] === true || $input['manage_stock'] === 1) : null;
    $quantity = array_key_exists('stock_quantity', $input) ? (int) $input['stock_quantity'] : null;
    $status = isset($input['stock_status']) ? (string) $input['stock_status'] : null;

    if ($manage !== null) {
        $product->set_manage_stock($manage);
    }
    // A quantity alone applies to a product that already manages stock; without falling back to
    // the stored setting, `{id, stock_quantity: 5}` would silently do nothing.
    if (($manage ?? $product->get_manage_stock()) === true && $quantity !== null) {
        $product->set_stock_quantity($quantity);
        if ($status === null) {
            $product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
        }
    }
    if ($status !== null) {
        $product->set_stock_status($status);
    }
    return null;
}

/**
 * The before-image of a basic edit: the fields this call can change, as they are now.
 *
 * Only the fields the input touches are put back on undo, so undoing a price change does not also
 * rewind a description someone edited since.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function capture_edit(array $input): ?array
{
    if (!class_exists('WooCommerce')) {
        return null;
    }
    $key = resolve_product_key($input);
    if ($key instanceof WP_Error) {
        return null;
    }
    $product = product_or_error($key);
    if ($product instanceof WP_Error) {
        return null;
    }
    $input = normalize_edit_input($input);
    $fields = [];
    foreach (['name', 'description'] as $field) {
        if (array_key_exists($field, $input)) {
            $fields[] = $field;
        }
    }
    if (array_intersect(PRICE_FIELDS, array_keys($input)) !== []) {
        $fields = array_merge($fields, PRICE_FIELDS);
    }
    if (array_intersect(STOCK_FIELDS, array_keys($input)) !== []) {
        $fields = array_merge($fields, STOCK_RESTORE_FIELDS);
    }
    if ($fields === []) {
        return null;
    }
    return [
        'type' => STRATEGY,
        'product_id' => $product->get_id(),
        'product_type' => $product->get_type(),
        'fields' => $fields,
        'values' => product_values($product, $fields),
    ];
}

/**
 * @param list<string> $fields
 * @return array<string, mixed>
 */
function product_values(WC_Product $product, array $fields): array
{
    $values = [];
    foreach ($fields as $field) {
        $values[$field] = match ($field) {
            'name' => (string) $product->get_name('edit'),
            'description' => (string) $product->get_description('edit'),
            'regular_price' => (string) $product->get_regular_price('edit'),
            'sale_price' => (string) $product->get_sale_price('edit'),
            'manage_stock' => (bool) $product->get_manage_stock('edit'),
            'stock_quantity' => stock_number($product->get_stock_quantity('edit')),
            'backorders' => (string) $product->get_backorders('edit'),
            'low_stock_amount' => stock_number($product->get_low_stock_amount('edit')),
            'stock_status' => (string) $product->get_stock_status('edit'),
            default => null,
        };
    }
    return $values;
}

/**
 * WooCommerce answers an unset quantity or threshold with null or '' depending on the path.
 */
function stock_number(mixed $value): int|float|null
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return $value + 0;
}

/**
 * Undo a basic edit: write the captured fields back through WC_Product and save(), then re-read
 * the product and compare.
 *
 * Refused when the product is gone or trashed (nothing to write to), or when its type changed
 * since: a simple product's price and stock mean something else on a variable one.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the before-image is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $id = (int) ($snapshot['product_id'] ?? 0);
    $fields = is_array($snapshot['fields'] ?? null) ? array_values(array_intersect(
        array_merge(['name', 'description'], PRICE_FIELDS, STOCK_RESTORE_FIELDS),
        $snapshot['fields'],
    )) : [];
    $values = is_array($snapshot['values'] ?? null) ? $snapshot['values'] : [];
    if ($id <= 0 || $fields === []) {
        return new WP_Error('kit_woo_undo_payload', 'The change record does not name a product and the fields to put back.');
    }
    if (!class_exists('WooCommerce')) {
        return new WP_Error('kit_woo_inactive', 'WooCommerce is not active, so the product cannot be written back.');
    }

    $post = get_post($id);
    $product = $post instanceof WP_Post && $post->post_type === 'product' && $post->post_status !== 'trash'
        ? wc_get_product($id)
        : null;
    if (!$product instanceof WC_Product) {
        return new WP_Error(
            'kit_woo_product_gone',
            sprintf('Product %d has been deleted or trashed since this edit, so there is nothing to put its fields back on. Restore it from the trash first if that is intended.', $id),
        );
    }
    $was = (string) ($snapshot['product_type'] ?? '');
    if ($product->get_type() !== $was) {
        return new WP_Error(
            'kit_woo_product_type_changed',
            sprintf('Product %1$d was a %2$s product when it was edited and is %3$s now; its old prices and stock do not apply to it, so nothing was changed.', $id, $was, $product->get_type()),
        );
    }

    if (in_array('name', $fields, true) || in_array('description', $fields, true)) {
        set_post_fields(
            $product,
            in_array('name', $fields, true) ? (string) ($values['name'] ?? '') : null,
            in_array('description', $fields, true) ? (string) ($values['description'] ?? '') : null,
        );
    }
    foreach ($fields as $field) {
        /** @var mixed $value */
        $value = $values[$field] ?? null;
        match ($field) {
            'regular_price' => $product->set_regular_price((string) $value),
            'sale_price' => $product->set_sale_price((string) $value),
            'manage_stock' => $product->set_manage_stock($value === true),
            'stock_quantity' => $product->set_stock_quantity($value === null ? '' : $value),
            'backorders' => $product->set_backorders((string) $value),
            'low_stock_amount' => $product->set_low_stock_amount($value === null ? '' : $value),
            'stock_status' => $product->set_stock_status((string) $value),
            default => null,
        };
    }
    try {
        $product->save();
    } catch (\Throwable $e) {
        return new WP_Error('kit_woo_undo_failed', 'WooCommerce refused to save the product: ' . $e->getMessage());
    }
    clean_post_cache($id);

    $reread = wc_get_product($id);
    if (!$reread instanceof WC_Product) {
        return new WP_Error('kit_woo_undo_failed', sprintf('Product %d could not be read back after the undo.', $id));
    }
    $now = product_values($reread, $fields);
    $mismatched = [];
    foreach ($fields as $field) {
        if (($now[$field] ?? null) != ($values[$field] ?? null)) {
            $mismatched[] = $field;
        }
    }
    return [
        'product_id' => $id,
        'restored' => $fields,
        'mismatched' => $mismatched,
        'verified' => $mismatched === [],
    ];
}

/**
 * The fields a before-image names, as they are on the product now, in the before-image's shape:
 * what a session undo checks nothing else has changed, and what a redo hands back to restore().
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|null
 */
function current_state(array $snapshot): ?array
{
    if (!class_exists('WooCommerce')) {
        return null;
    }
    $id = (int) ($snapshot['product_id'] ?? 0);
    $fields = is_array($snapshot['fields'] ?? null) ? array_values(array_intersect(
        array_map('strval', $snapshot['fields']),
        array_merge(['name', 'description'], PRICE_FIELDS, STOCK_RESTORE_FIELDS),
    )) : [];
    if ($id <= 0 || $fields === []) {
        return null;
    }
    $post = get_post($id);
    $product = $post instanceof WP_Post && $post->post_type === 'product' && $post->post_status !== 'trash'
        ? wc_get_product($id)
        : null;
    if (!$product instanceof WC_Product) {
        return ['type' => 'absent'];
    }
    return [
        'type' => STRATEGY,
        'product_id' => $id,
        'product_type' => $product->get_type(),
        'fields' => $fields,
        'values' => product_values($product, $fields),
    ];
}

/**
 * @param array<string, mixed> $snapshot
 */
function state_target(array $snapshot): string
{
    $fields = is_array($snapshot['fields'] ?? null) ? array_map('strval', $snapshot['fields']) : [];
    sort($fields, SORT_STRING);
    $id = (int) ($snapshot['product_id'] ?? 0);
    return $id > 0 && $fields !== [] ? $id . ':' . implode(',', $fields) : '';
}

function register_undo(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore($payload));
    if ($ledger instanceof SessionLedger) {
        $ledger->register_state(
            STRATEGY,
            static fn(array $snapshot): ?array => current_state($snapshot),
            static fn(array $snapshot): string => state_target($snapshot),
        );
    }
}
