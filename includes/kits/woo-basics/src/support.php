<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\WooBasics;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce presence, the error vocabulary, and input coercion shared by every ability.
 *
 * The error codes and HTTP statuses are the ones GoBuildWP Pro's WooCommerce abilities return, so an
 * agent that learned them against Pro's copy of these abilities reads Free's the same way.
 */

/** The oldest WooCommerce these abilities are written against: HPOS-aware order queries, the product lookup tables. */
const MIN_WC_VERSION = '9.0';

/** HTTP status per error code. */
const ERROR_STATUS = [
    'wc_inactive' => 412,
    'wc_below_minimum_version' => 412,
    'wc_not_found' => 404,
    'wc_invalid_input' => 400,
    'wc_post_type_mismatch' => 400,
    'wc_internal_error' => 500,
    'wc_not_variable_parent' => 400,
];

function error(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message, ['status' => ERROR_STATUS[$code] ?? 500]);
}

/**
 * Whether the active WooCommerce is at or above the floor.
 */
function version_ok(): bool
{
    return (
        class_exists('WooCommerce')
        && defined('WC_VERSION')
        && version_compare((string) constant('WC_VERSION'), MIN_WC_VERSION, '>=')
    );
}

/**
 * A WP_Error when WooCommerce is inactive or below the floor, null when the abilities can run.
 *
 * bootstrap.php already skips the kit below the floor; this answers the request that arrives
 * while WooCommerce is being deactivated or downgraded under a loaded kit.
 */
function check_minimum(): ?WP_Error
{
    if (!class_exists('WooCommerce')) {
        return error('wc_inactive', __('WooCommerce is not active on this site.', 'wppilot'));
    }
    if (!version_ok()) {
        return error('wc_below_minimum_version', sprintf(
            /* translators: %s: minimum WooCommerce version */
            __('WooCommerce %s or newer is required.', 'wppilot'),
            MIN_WC_VERSION,
        ));
    }
    return null;
}

/**
 * WooCommerce extensions check-setup reports, from a fixed allowlist.
 *
 * @return list<array{slug:string, name:string, version:?string, active:bool}>
 */
function detect_extensions(): array
{
    $checks = [
        ['subscriptions', 'WooCommerce Subscriptions', 'WC_Subscriptions', 'WC_SUBSCRIPTIONS_VERSION'],
        ['bookings', 'WooCommerce Bookings', 'WC_Bookings', 'WC_BOOKINGS_VERSION'],
        ['brands', 'WooCommerce Brands', 'WC_Brands', 'WC_BRANDS_VERSION'],
        ['memberships', 'WooCommerce Memberships', 'WC_Memberships', 'WC_MEMBERSHIPS_VERSION'],
        ['product-add-ons', 'WooCommerce Product Add-Ons', 'WC_Product_Addons', 'WC_PRODUCT_ADDONS_VERSION'],
        ['advanced-shipping', 'WooCommerce Advanced Shipping', 'WCAS_Advanced_Shipping', 'WCAS_VERSION'],
        ['stripe-gateway', 'WooCommerce Stripe Gateway', 'WC_Stripe', 'WC_STRIPE_VERSION'],
        ['woopayments', 'WooPayments', 'WC_Payments', 'WCPAY_VERSION'],
        ['paypal-payments', 'WooCommerce PayPal Payments', 'WooCommerce\\PayPalCommerce\\PluginModule', 'PAYPAL_API_VERSION'],
    ];
    $out = [];
    foreach ($checks as [$slug, $name, $class, $const]) {
        $active = class_exists($class);
        $out[] = [
            'slug' => $slug,
            'name' => $name,
            'version' => $active && defined($const) ? (string) constant($const) : null,
            'active' => $active,
        ];
    }
    return $out;
}

/**
 * Reject a field whose value is neither a string nor numeric.
 *
 * @param array<string, mixed> $input
 * @param list<string> $fields
 */
function validate_scalar_string_fields(array $input, array $fields): ?WP_Error
{
    foreach ($fields as $field) {
        if (isset($input[$field]) && !is_string($input[$field]) && !is_numeric($input[$field])) {
            return error('wc_invalid_input', sprintf(
                /* translators: 1: field name, 2: actual type */
                __('`%1$s` must be a string (got %2$s).', 'wppilot'),
                $field,
                gettype($input[$field]),
            ));
        }
    }
    return null;
}

/**
 * Reject a string field outside its enum. `order` compares case-insensitively.
 *
 * @param array<string, mixed> $input
 * @param list<string> $allowed
 */
function validate_enum_field(array $input, string $field, array $allowed): ?WP_Error
{
    if (!isset($input[$field])) {
        return null;
    }
    /** @var mixed $value */
    $value = $input[$field];
    $compare = $field === 'order' && is_scalar($value) ? strtolower((string) $value) : $value;
    if (!is_string($value) || !in_array($compare, $allowed, true)) {
        return error('wc_invalid_input', sprintf(
            /* translators: 1: submitted value, 2: comma-separated list of allowed values, 3: field name */
            __('`%3$s` must be one of: %2$s (got "%1$s").', 'wppilot'),
            is_scalar($value) ? (string) $value : gettype($value),
            implode(', ', $allowed),
            $field,
        ));
    }
    return null;
}

/**
 * Booleans are native bools or 0/1; the strings "true" and "1" are refused.
 *
 * @param array<string, mixed> $input
 * @param list<string> $fields
 */
function strict_bool_check(array $input, array $fields): ?WP_Error
{
    foreach ($fields as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        /** @var mixed $value */
        $value = $input[$field];
        if (is_bool($value) || $value === 1 || $value === 0) {
            continue;
        }
        return error('wc_invalid_input', sprintf(
            /* translators: 1: field name, 2: actual type */
            __('`%1$s` must be true or false (bool), got %2$s.', 'wppilot'),
            $field,
            gettype($value),
        ));
    }
    return null;
}

/**
 * Strip every ASCII control character. MySQL truncates at NUL on some collations, and the rest
 * make names that break search and CSV exports.
 */
function strip_unsafe_chars(string $value): string
{
    return preg_replace('/[\x00-\x1F]/u', '', $value) ?? $value;
}

/**
 * Strip control characters from long text, keeping tab, newline and carriage return.
 */
function strip_unsafe_chars_preserve_ws(string $value): string
{
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;
}

/**
 * Cap a description at 64 KB in a response, so one legacy product cannot fill an agent's context.
 *
 * Byte-level, to match the TEXT column the cap mirrors; a multibyte character straddling the
 * boundary is split.
 *
 * @return array{0: string, 1: bool}
 */
function maybe_truncate_description(string $value): array
{
    if (strlen($value) <= 65536) {
        return [$value, false];
    }
    return [substr($value, 0, 65536), true];
}

/**
 * @param array<mixed> $ids
 * @return list<int>
 */
function to_int_list(array $ids): array
{
    return array_values(array_map('intval', $ids));
}

/**
 * Clamp a list limit into 1..200 and say whether it was cut.
 *
 * @return array{limit:int, truncated:bool}
 */
function clamp_limit(int $requested): array
{
    if ($requested < 1) {
        return ['limit' => 20, 'truncated' => false];
    }
    if ($requested > 200) {
        return ['limit' => 200, 'truncated' => true];
    }
    return ['limit' => $requested, 'truncated' => false];
}

/**
 * The `id` or `slug` a product ability was given: int for a numeric key, the slug otherwise.
 *
 * @param array<string, mixed> $input
 */
function resolve_product_key(array $input): int|string|WP_Error
{
    /** @var mixed $key */
    $key = $input['id'] ?? $input['slug'] ?? null;
    if ($key === null) {
        return error('wc_invalid_input', __('`id` or `slug` is required.', 'wppilot'));
    }
    if (!is_int($key) && !is_string($key) && !is_numeric($key)) {
        return error('wc_invalid_input', sprintf(
            /* translators: %s: actual type */
            __('`id` or `slug` must be an integer or string (got %s).', 'wppilot'),
            gettype($key),
        ));
    }
    return is_numeric($key) ? (int) $key : $key;
}
