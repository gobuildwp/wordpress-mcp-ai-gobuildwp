<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\WooBasics;

use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;
use WP_Post;
use WP_Term;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Variations, product categories and tags, the store settings and check-setup.
 */

/**
 * @return array<string, mixed>
 */
function check_setup(): array
{
    if (!class_exists('WooCommerce')) {
        return [
            'active' => false,
            'version' => null,
            'meets_minimum' => false,
            'hpos_enabled' => false,
            'block_checkout_enabled' => false,
            'currency' => null,
            'default_country' => null,
            'products_count' => 0,
            'registered_extensions' => detect_extensions(),
        ];
    }
    $version = defined('WC_VERSION') ? (string) constant('WC_VERSION') : null;

    $hpos = false;
    if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
        $hpos = (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    $block_checkout = false;
    $checkout_page_id = (int) get_option('woocommerce_checkout_page_id');
    if ($checkout_page_id > 0) {
        $page = get_post($checkout_page_id);
        $block_checkout = $page instanceof WP_Post && has_block('woocommerce/checkout', $page);
    }

    $counts = wp_count_posts('product');
    $base = (array) wc_get_base_location();

    return [
        'active' => true,
        'version' => $version,
        'meets_minimum' => version_ok(),
        'hpos_enabled' => $hpos,
        'block_checkout_enabled' => $block_checkout,
        'currency' => (string) get_woocommerce_currency(),
        'default_country' => is_string($base['country'] ?? null) ? $base['country'] : null,
        'products_count' => is_object($counts) ? (int) ($counts->publish ?? 0) : 0,
        'registered_extensions' => detect_extensions(),
    ];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function get_store_settings(): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    /** @var array<string, string|null> $base */
    $base = wc_get_base_location();
    return [
        'currency' => (string) get_woocommerce_currency(),
        'currency_symbol' => (string) get_woocommerce_currency_symbol(),
        'currency_position' => (string) get_option('woocommerce_currency_pos', 'left'),
        'thousand_separator' => (string) wc_get_price_thousand_separator(),
        'decimal_separator' => (string) wc_get_price_decimal_separator(),
        'num_decimals' => (int) wc_get_price_decimals(),
        'weight_unit' => (string) get_option('woocommerce_weight_unit', ''),
        'dimension_unit' => (string) get_option('woocommerce_dimension_unit', ''),
        'default_country' => (string) ($base['country'] ?? ''),
        'store_address' => [
            'address_1' => (string) get_option('woocommerce_store_address', ''),
            'address_2' => (string) get_option('woocommerce_store_address_2', ''),
            'city' => (string) get_option('woocommerce_store_city', ''),
            'postcode' => (string) get_option('woocommerce_store_postcode', ''),
            'state' => (string) ($base['state'] ?? ''),
            'country' => (string) ($base['country'] ?? ''),
        ],
    ];
}

/**
 * A variation by id; wc_not_found for a missing, trashed or non-variation post.
 */
function post_to_variation(int $id): WC_Product_Variation|WP_Error
{
    $post = get_post($id);
    if (!$post instanceof WP_Post) {
        /* translators: %d: variation id */
        return error('wc_not_found', sprintf(__('Variation %d not found.', 'wppilot'), $id));
    }
    if ($post->post_type !== 'product_variation') {
        return error('wc_not_found', sprintf(
            /* translators: 1: post id, 2: actual post_type value */
            __('Post %1$d is not a product variation (post_type: %2$s).', 'wppilot'),
            $id,
            $post->post_type,
        ));
    }
    if ($post->post_status === 'trash') {
        /* translators: %d: variation id */
        return error('wc_not_found', sprintf(__('Variation %d is trashed.', 'wppilot'), $id));
    }
    return new WC_Product_Variation($id);
}

/**
 * A variable parent with at least one attribute used for variations.
 */
function validate_variable_parent(int $parent_id): WC_Product|WP_Error
{
    $post = get_post($parent_id);
    if (!$post instanceof WP_Post || $post->post_type !== 'product') {
        /* translators: %d: product id */
        return error('wc_not_found', sprintf(__('Parent product %d not found.', 'wppilot'), $parent_id));
    }
    if ($post->post_status === 'trash') {
        /* translators: %d: product id */
        return error('wc_not_found', sprintf(__('Parent product %d is trashed.', 'wppilot'), $parent_id));
    }
    $product = wc_get_product($parent_id);
    if (!$product instanceof WC_Product) {
        return error('wc_not_found', __('WooCommerce could not load the parent product.', 'wppilot'));
    }
    if ($product->get_type() !== 'variable') {
        return error('wc_not_variable_parent', sprintf(
            /* translators: 1: product id, 2: actual product type */
            __('Product %1$d is type=%2$s, not type=variable. Only variable products have variations.', 'wppilot'),
            $parent_id,
            $product->get_type(),
        ));
    }
    foreach ($product->get_attributes() as $attr) {
        if (is_object($attr) && method_exists($attr, 'get_variation') && $attr->get_variation()) {
            return $product;
        }
    }
    return error('wc_not_variable_parent', sprintf(
        /* translators: %d: product id */
        __('Product %d has no variation-enabled attributes.', 'wppilot'),
        $parent_id,
    ));
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function list_product_variations(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    foreach (['limit' => 50, 'offset' => 0] as $field => $default) {
        /** @var mixed $raw */
        $raw = $input[$field] ?? $default;
        if (!is_int($raw) && !is_numeric($raw)) {
            return error('wc_invalid_input', sprintf(
                /* translators: 1: field name, 2: actual type */
                __('`%1$s` must be an integer (got %2$s).', 'wppilot'),
                $field,
                gettype($raw),
            ));
        }
    }
    $invalid = validate_enum_field($input, 'orderby', ['id', 'menu_order', 'date'])
        ?? validate_enum_field($input, 'order', ['asc', 'desc']);
    if ($invalid !== null) {
        return $invalid;
    }
    if (!isset($input['parent_id']) || !is_numeric($input['parent_id'])) {
        return error('wc_invalid_input', __('`parent_id` is required.', 'wppilot'));
    }
    $parent_id = (int) $input['parent_id'];
    $parent = validate_variable_parent($parent_id);
    if ($parent instanceof WP_Error) {
        return $parent;
    }

    $fields = is_array($input['fields'] ?? null) ? array_values(array_map('strval', $input['fields'])) : [];
    try {
        /** @var mixed $results */
        $results = wc_get_products([
            'type' => 'variation',
            'parent' => $parent_id,
            'limit' => max(1, min(200, (int) ($input['limit'] ?? 50))),
            'offset' => max(0, (int) ($input['offset'] ?? 0)),
            'orderby' => (string) ($input['orderby'] ?? 'menu_order'),
            'order' => strtoupper((string) ($input['order'] ?? 'asc')),
            'return' => 'objects',
            'paginate' => false,
            'status' => ['publish', 'private', 'draft'],
        ]);
    } catch (\Throwable $e) {
        return error('wc_internal_error', __('WooCommerce query failed unexpectedly.', 'wppilot'));
    }
    if ($results instanceof WP_Error) {
        return error('wc_internal_error', $results->get_error_message());
    }
    $rows = [];
    foreach (is_array($results) ? $results : [] as $variation) {
        if ($variation instanceof WC_Product_Variation) {
            $rows[] = variation_to_array($variation, $fields);
        }
    }
    return ['variations' => $rows, 'total' => count($parent->get_children()), 'returned' => count($rows)];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function get_product_variation(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    if (!isset($input['id']) || !is_numeric($input['id'])) {
        return error('wc_invalid_input', __('`id` is required.', 'wppilot'));
    }
    $variation = post_to_variation((int) $input['id']);
    return $variation instanceof WP_Error ? $variation : variation_full_to_array($variation);
}

/**
 * The compact variation row, or compact plus full with `fields: ["full"]`.
 *
 * @param list<string> $fields
 * @return array<string, mixed>
 */
function variation_to_array(WC_Product_Variation $v, array $fields = []): array
{
    $parent_id = $v->get_parent_id();
    $row = [
        'id' => $v->get_id(),
        'parent_id' => $parent_id,
        'sku' => $v->get_sku(),
        'attributes_summary' => variation_attributes_summary($v),
        'regular_price' => $v->get_regular_price(),
        'sale_price' => $v->get_sale_price() !== '' ? $v->get_sale_price() : null,
        'stock_status' => $v->get_stock_status(),
        'stock_quantity' => $v->get_stock_quantity(),
        'is_default' => is_default_variation($parent_id, $v),
    ];
    return in_array('full', $fields, true) ? array_merge($row, variation_full_to_array($v)) : $row;
}

/**
 * @return array<string, mixed>
 */
function variation_full_to_array(WC_Product_Variation $v): array
{
    $parent_id = $v->get_parent_id();
    $modified = $v->get_date_modified();
    $data = $v->get_data();
    $image_id = (int) $v->get_image_id();
    [$description, $truncated] = maybe_truncate_description((string) $v->get_description());
    return [
        'id' => $v->get_id(),
        'parent_id' => $parent_id,
        'name' => $v->get_name(),
        'sku' => $v->get_sku(),
        'status' => $v->get_status(),
        'attributes' => variation_attributes_structured($v),
        'attributes_summary' => variation_attributes_summary($v),
        'regular_price' => $v->get_regular_price(),
        'sale_price' => $v->get_sale_price() !== '' ? $v->get_sale_price() : null,
        'price' => $v->get_price(),
        'manage_stock' => $v->get_manage_stock(),
        'stock_quantity' => $v->get_stock_quantity(),
        'stock_status' => $v->get_stock_status(),
        'weight' => array_key_exists('weight', $data) ? (string) $data['weight'] : '',
        'dimensions' => [
            'length' => array_key_exists('length', $data) ? (string) $data['length'] : '',
            'width' => array_key_exists('width', $data) ? (string) $data['width'] : '',
            'height' => array_key_exists('height', $data) ? (string) $data['height'] : '',
        ],
        'image_id' => $image_id > 0 ? $image_id : null,
        'image_src' => $image_id > 0 ? (string) wp_get_attachment_url($image_id) : null,
        'description' => $description,
        'description_truncated' => $truncated,
        'virtual' => $v->get_virtual(),
        'downloadable' => $v->get_downloadable(),
        'shipping_class' => $v->get_shipping_class(),
        'is_default' => is_default_variation($parent_id, $v),
        'date_modified' => $modified !== null ? $modified->date('c') : null,
    ];
}

/**
 * "M / Red": each attribute's term name, or its raw value for a custom attribute, "Any" when unset.
 */
function variation_attributes_summary(WC_Product_Variation $v): string
{
    $labels = [];
    foreach ($v->get_variation_attributes() as $key => $value) {
        $value = (string) $value;
        if ($value === '') {
            $labels[] = __('Any', 'wppilot');
            continue;
        }
        $taxonomy = str_replace('attribute_', '', (string) $key);
        if (!taxonomy_exists($taxonomy)) {
            $labels[] = $value;
            continue;
        }
        $term = get_term_by('slug', $value, $taxonomy);
        $labels[] = $term instanceof WP_Term ? $term->name : $value;
    }
    return implode(' / ', $labels);
}

/**
 * @return list<array{key:string, taxonomy:string, label:string, option:string}>
 */
function variation_attributes_structured(WC_Product_Variation $v): array
{
    $out = [];
    foreach ($v->get_variation_attributes() as $key => $value) {
        $value = (string) $value;
        $taxonomy = str_replace('attribute_', '', (string) $key);
        $label = $taxonomy;
        $option = $value;
        if (taxonomy_exists($taxonomy)) {
            $object = get_taxonomy($taxonomy);
            if (is_object($object)) {
                /** @var mixed $singular */
                $singular = $object->labels->singular_name ?? null;
                $label = is_string($singular) ? $singular : $taxonomy;
            }
            if ($value !== '') {
                $term = get_term_by('slug', $value, $taxonomy);
                $option = $term instanceof WP_Term ? $term->name : $value;
            }
        }
        $out[] = ['key' => (string) $key, 'taxonomy' => $taxonomy, 'label' => $label, 'option' => $option];
    }
    return $out;
}

/**
 * Whether the variation's attributes are the parent's storefront defaults.
 */
function is_default_variation(int $parent_id, WC_Product_Variation $v): bool
{
    $parent = wc_get_product($parent_id);
    if (!$parent instanceof WC_Product_Variable) {
        return false;
    }
    $defaults = $parent->get_default_attributes();
    if ($defaults === []) {
        return false;
    }
    $attrs = $v->get_variation_attributes();
    foreach ($defaults as $key => $value) {
        if (($attrs['attribute_' . $key] ?? null) !== (string) $value) {
            return false;
        }
    }
    return true;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function list_product_categories(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    $args = [
        'taxonomy' => 'product_cat',
        'hide_empty' => ($input['hide_empty'] ?? false) === true,
        'number' => min(200, max(1, (int) ($input['limit'] ?? 50))),
    ];
    if (isset($input['search']) && is_string($input['search']) && trim($input['search']) !== '') {
        $args['search'] = $input['search'];
    }
    if (isset($input['parent_id'], $input['parent'])) {
        return error('wc_invalid_input', __('Provide either `parent` or `parent_id`, not both.', 'wppilot'));
    }
    /** @var mixed $parent */
    $parent = $input['parent_id'] ?? $input['parent'] ?? null;
    if ($parent !== null) {
        $args['parent'] = (int) $parent;
    }
    try {
        /** @var mixed $terms */
        $terms = get_terms($args);
    } catch (\Throwable $e) {
        return error('wc_internal_error', __('WooCommerce query failed unexpectedly.', 'wppilot'));
    }
    if ($terms instanceof WP_Error) {
        return error('wc_invalid_input', $terms->get_error_message());
    }
    $with_thumbnail = ($input['include_thumbnail'] ?? false) === true;
    $categories = [];
    foreach (is_array($terms) ? $terms : [] as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }
        $row = category_to_array($term);
        if (!$with_thumbnail) {
            unset($row['thumbnail_id'], $row['thumbnail_src']);
        }
        $categories[] = $row;
    }
    return ['categories' => $categories, 'total' => count($categories)];
}

/**
 * @return array<string, mixed>
 */
function category_to_array(WP_Term $term): array
{
    $term_id = (int) $term->term_id;
    $parent_id = (int) $term->parent;
    $parent_slug = null;
    if ($parent_id > 0) {
        $parent = get_term($parent_id, 'product_cat');
        $parent_slug = $parent instanceof WP_Term ? $parent->slug : null;
    }
    $row = [
        'id' => $term_id,
        'name' => $term->name,
        'slug' => $term->slug,
        'description' => $term->description,
        'count' => (int) $term->count,
        'parent_id' => $parent_id,
        'parent_slug' => $parent_slug,
        'thumbnail_id' => null,
        'thumbnail_src' => null,
    ];
    $thumbnail_id = (int) get_term_meta($term_id, 'thumbnail_id', true);
    if ($thumbnail_id > 0) {
        $src = wp_get_attachment_url($thumbnail_id);
        // An attachment deleted without its term meta being pruned leaves a dangling id.
        if (is_string($src) && $src !== '') {
            $row['thumbnail_id'] = $thumbnail_id;
            $row['thumbnail_src'] = $src;
        }
    }
    $display_type = (string) get_term_meta($term_id, 'display_type', true);
    $row['display_type'] = $display_type !== '' ? $display_type : 'default';
    $row['menu_order'] = category_menu_order($term_id);
    return $row;
}

/**
 * WooCommerce keeps a category's sort position in the `order` term meta. Early GoBuildWP Pro builds
 * wrote it under `order_<id>`; that is read as a fallback, and left for Pro to migrate, since a
 * read never writes.
 */
function category_menu_order(int $term_id): int
{
    foreach (['order', 'order_' . $term_id] as $key) {
        /** @var mixed $value */
        $value = get_term_meta($term_id, $key, true);
        if ($value !== '' && $value !== false) {
            return (int) $value;
        }
    }
    return 0;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function list_product_tags(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    $args = [
        'taxonomy' => 'product_tag',
        'hide_empty' => ($input['hide_empty'] ?? false) === true,
        'number' => min(500, max(1, (int) ($input['limit'] ?? 200))),
    ];
    if (isset($input['search']) && is_string($input['search']) && trim($input['search']) !== '') {
        $args['search'] = $input['search'];
    }
    try {
        /** @var mixed $terms */
        $terms = get_terms($args);
    } catch (\Throwable $e) {
        return error('wc_internal_error', __('WooCommerce query failed unexpectedly.', 'wppilot'));
    }
    if ($terms instanceof WP_Error) {
        return error('wc_invalid_input', $terms->get_error_message());
    }
    $tags = [];
    foreach (is_array($terms) ? $terms : [] as $term) {
        if ($term instanceof WP_Term) {
            $tags[] = [
                'id' => (int) $term->term_id,
                'slug' => $term->slug,
                'name' => $term->name,
                'count' => (int) $term->count,
                'description' => $term->description,
            ];
        }
    }
    return ['tags' => $tags, 'total' => count($tags)];
}
