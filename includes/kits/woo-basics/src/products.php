<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\WooBasics;

use WC_Product;
use WP_Error;
use WP_Post;
use WP_Term;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reading products and shaping them for a response, and the list-products query.
 *
 * Three widths — compact, compact plus requested sections, and full — so a listing does not pay
 * for data nobody asked for. Variable parents never report stock or price of their own: those
 * live on the variations, and the parent's postmeta is a stale aggregate.
 */

const LIST_ORDERBY = ['date', 'title', 'sku', 'price', 'menu_order', 'popularity', 'rating'];
const LIST_STATUSES = ['publish', 'draft', 'pending', 'private'];
const LIST_FIELDS = ['categories', 'tags', 'images', 'attributes', 'dimensions', 'meta_data', 'variations', 'brands'];

/**
 * A product by id or slug, or wc_not_found / wc_post_type_mismatch.
 */
function product_or_error(int|string $id_or_slug): WC_Product|WP_Error
{
    if (!is_int($id_or_slug) && !ctype_digit($id_or_slug)) {
        $found = get_page_by_path($id_or_slug, 'OBJECT', 'product');
        if (!$found instanceof WP_Post) {
            return error('wc_not_found', sprintf(
                /* translators: %s: product slug */
                __('Product not found for slug `%s`.', 'wppilot'),
                $id_or_slug,
            ));
        }
        return post_to_product($found);
    }
    $post = get_post((int) $id_or_slug);
    if (!$post instanceof WP_Post) {
        return error('wc_not_found', sprintf(
            /* translators: %d: product id */
            __('Product not found for id %d.', 'wppilot'),
            (int) $id_or_slug,
        ));
    }
    return post_to_product($post);
}

/**
 * A post as a product: refused when it is another post type, trashed, an auto-draft or a revision.
 */
function post_to_product(WP_Post $post): WC_Product|WP_Error
{
    if ($post->post_type !== 'product') {
        return error('wc_post_type_mismatch', sprintf(
            /* translators: 1: post id, 2: actual post_type value */
            __('Post %1$d exists but is not a product (post_type: %2$s).', 'wppilot'),
            $post->ID,
            $post->post_type,
        ));
    }
    if (in_array($post->post_status, ['trash', 'auto-draft'], true) || wp_is_post_revision($post->ID)) {
        return error('wc_not_found', sprintf(
            /* translators: %d: product id */
            __('Product %d is trashed, an auto-draft, or a revision. Restore it in WP admin first.', 'wppilot'),
            $post->ID,
        ));
    }
    $product = wc_get_product($post->ID);
    if (!$product instanceof WC_Product) {
        return error('wc_not_found', __('WooCommerce could not load the product.', 'wppilot'));
    }
    return $product;
}

/**
 * The 15-field row list-products returns.
 *
 * @return array<string, mixed>
 */
function compact_product(WC_Product $p): array
{
    $modified = $p->get_date_modified();
    $is_variable = $p->get_type() === 'variable';
    $stock_quantity = $is_variable ? null : $p->get_stock_quantity();
    $manage_stock = $is_variable ? false : $p->get_manage_stock();
    $raw_sale = $p->get_sale_price();
    return [
        'id' => $p->get_id(),
        'name' => $p->get_name(),
        'slug' => $p->get_slug(),
        'sku' => $p->get_sku(),
        'status' => $p->get_status(),
        'type' => $p->get_type(),
        'stock_status' => $p->get_stock_status(),
        'stock_quantity' => $stock_quantity,
        'manage_stock' => $manage_stock,
        'price' => $p->get_price(),
        'regular_price' => $is_variable ? '' : $p->get_regular_price(),
        'sale_price' => $is_variable || $raw_sale === '' ? null : $raw_sale,
        'featured' => $p->get_featured(),
        'permalink' => $p->get_permalink(),
        'date_modified' => $modified !== null ? $modified->date('c') : null,
    ];
}

/**
 * The compact row plus the sections the caller named in `fields`.
 *
 * @param list<string> $fields
 * @return array<string, mixed>
 */
function compact_product_with_fields(WC_Product $p, array $fields): array
{
    $row = compact_product($p);
    if (in_array('categories', $fields, true)) {
        $row['categories'] = term_list_for_product(to_int_list($p->get_category_ids()), 'product_cat');
    }
    if (in_array('tags', $fields, true)) {
        $row['tags'] = term_list_for_product(to_int_list($p->get_tag_ids()), 'product_tag');
    }
    if (in_array('images', $fields, true)) {
        $row['images'] = images_for_product($p);
    }
    if (in_array('attributes', $fields, true)) {
        $row['attributes'] = attributes_for_product($p);
    }
    if (in_array('dimensions', $fields, true)) {
        $data = $p->get_data();
        $row['dimensions'] = [
            'length' => (string) ($data['length'] ?? ''),
            'width' => (string) ($data['width'] ?? ''),
            'height' => (string) ($data['height'] ?? ''),
            'weight' => (string) ($data['weight'] ?? ''),
        ];
    }
    if (in_array('meta_data', $fields, true)) {
        $row['meta_data'] = visible_meta($p);
    }
    if (in_array('variations', $fields, true)) {
        $row['variations'] = $p->get_children();
    }
    if (in_array('brands', $fields, true)) {
        $row['brands'] = brand_terms_for_product($p);
    }
    return $row;
}

/**
 * The full product get-product and edit-product return: WooCommerce's own data plus resolved
 * images, terms, attributes, visible meta and the rendered price, with dates as ISO 8601.
 *
 * @return array<string, mixed>
 */
function full_product(WC_Product $p): array
{
    $data = $p->get_data();
    unset($data['meta_data']);
    if (isset($data['sale_price']) && $data['sale_price'] === '') {
        $data['sale_price'] = null;
    }
    $data['permalink'] = $p->get_permalink();
    $data['images'] = images_for_product($p);
    $data['categories'] = term_list_for_product(to_int_list($p->get_category_ids()), 'product_cat');
    $data['tags'] = term_list_for_product(to_int_list($p->get_tag_ids()), 'product_tag');
    if (taxonomy_exists('product_brand')) {
        $data['brands'] = brand_terms_for_product($p);
        unset($data['brand_ids']);
    }
    $data['shipping_class_id'] = $p->get_shipping_class_id();
    $data['shipping_class_slug'] = $p->get_shipping_class();
    $data['attributes'] = attributes_for_product($p);
    $children = $p->get_children();
    $truncated = count($children) > 200;
    $data['variations'] = $truncated ? array_slice($children, 0, 200) : $children;
    $data['variations_truncated'] = $truncated;
    $data['meta_data'] = visible_meta($p);
    $data['price_html'] = $p->get_price_html();
    [$data['description'], $data['description_truncated']] = maybe_truncate_description((string) ($data['description'] ?? ''));
    if ($p->get_type() === 'variable') {
        $data['manage_stock'] = false;
        $data['stock_quantity'] = null;
        $data['regular_price'] = '';
        $data['sale_price'] = null;
    }
    if (!array_key_exists('type', $data)) {
        $data['type'] = $p->get_type();
    }
    foreach (['date_created', 'date_modified', 'date_on_sale_from', 'date_on_sale_to'] as $key) {
        /** @var mixed $value */
        $value = $data[$key] ?? null;
        if ($value instanceof \WC_DateTime) {
            $data[$key] = $value->date('c');
        }
    }
    return $data;
}

/**
 * Brand terms (WooCommerce 10's core Brands, or the Brands plugin), or [] without the taxonomy.
 *
 * @return list<array{id:int, slug:string, name:string}>
 */
function brand_terms_for_product(WC_Product $p): array
{
    if (!taxonomy_exists('product_brand')) {
        return [];
    }
    if (method_exists($p, 'get_brand_ids')) {
        /** @var array<mixed> $ids */
        $ids = $p->get_brand_ids();
        return term_list_for_product(to_int_list($ids), 'product_brand');
    }
    /** @var mixed $raw */
    $raw = $p->get_data()['brand_ids'] ?? [];
    return term_list_for_product(to_int_list(is_array($raw) ? $raw : []), 'product_brand');
}

/**
 * @param list<int> $ids
 * @return list<array{id:int, slug:string, name:string}>
 */
function term_list_for_product(array $ids, string $taxonomy): array
{
    if ($ids === []) {
        return [];
    }
    $terms = get_terms(['taxonomy' => $taxonomy, 'include' => $ids, 'hide_empty' => false]);
    if (!is_array($terms)) {
        return [];
    }
    $out = [];
    foreach ($terms as $term) {
        if ($term instanceof WP_Term) {
            $out[] = ['id' => (int) $term->term_id, 'slug' => $term->slug, 'name' => $term->name];
        }
    }
    return $out;
}

/**
 * The featured image first, then the gallery, each with its URL and alt text.
 *
 * @return list<array{id:int, src:string, alt:string, position:int}>
 */
function images_for_product(WC_Product $p): array
{
    $ids = [];
    $featured = (int) $p->get_image_id();
    if ($featured > 0) {
        $ids[] = $featured;
    }
    foreach ($p->get_gallery_image_ids() as $raw) {
        $ids[] = (int) $raw;
    }
    $images = [];
    foreach ($ids as $position => $id) {
        $images[] = [
            'id' => $id,
            'src' => (string) wp_get_attachment_url($id),
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'position' => $position,
        ];
    }
    return $images;
}

/**
 * @return list<array<string, mixed>>
 */
function attributes_for_product(WC_Product $p): array
{
    $out = [];
    foreach ($p->get_attributes() as $attr) {
        if (!is_object($attr) || !method_exists($attr, 'get_name')) {
            continue;
        }
        $name = (string) $attr->get_name();
        $options = method_exists($attr, 'get_options') ? (array) $attr->get_options() : [];
        $is_taxonomy = method_exists($attr, 'is_taxonomy') && (bool) $attr->is_taxonomy();
        $attr_id = method_exists($attr, 'get_id') ? (int) $attr->get_id() : 0;
        $option_list = array_values(array_map(static fn(mixed $v): string => (string) $v, $options));
        // A global attribute force-deleted from WooCommerce leaves an entry behind on the product
        // with id 0 and no options. A real local attribute also has id 0 but always has options.
        if ($attr_id === 0 && $option_list === []) {
            continue;
        }
        $row = [
            'id' => $attr_id,
            'name' => $name,
            'slug' => sanitize_title($name),
            'options' => $option_list,
            'visible' => method_exists($attr, 'get_visible') ? (bool) $attr->get_visible() : false,
            'variation' => method_exists($attr, 'get_variation') ? (bool) $attr->get_variation() : false,
        ];
        if ($is_taxonomy) {
            $row['taxonomy_slug'] = $name;
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Custom fields a person wrote; underscore-prefixed keys are WordPress's and WooCommerce's own.
 *
 * @return list<array{key:string, value:mixed}>
 */
function visible_meta(WC_Product $p): array
{
    $out = [];
    foreach ($p->get_meta_data() as $entry) {
        if (!is_object($entry) || !method_exists($entry, 'get_data')) {
            continue;
        }
        /** @var mixed $row */
        $row = $entry->get_data();
        if (!is_array($row)) {
            continue;
        }
        $key = (string) ($row['key'] ?? '');
        if ($key === '' || str_starts_with($key, '_')) {
            continue;
        }
        $out[] = ['key' => $key, 'value' => $row['value'] ?? null];
    }
    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function list_products(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    // on_sale first: on_sale=false merges the on-sale ids into exclude, which normalize_exclude
    // then folds into include (WordPress ignores post__not_in whenever post__in is set).
    $input = normalize_exclude(normalize_on_sale($input));
    if (isset($input['include']) && $input['include'] === []) {
        return [
            'products' => [],
            'total' => 0,
            'returned' => 0,
            'truncated' => clamp_limit((int) ($input['limit'] ?? 20))['truncated'],
        ];
    }

    /** @var mixed $limit_raw */
    $limit_raw = $input['limit'] ?? 20;
    if (!is_int($limit_raw) && !is_numeric($limit_raw)) {
        return error('wc_invalid_input', sprintf(
            /* translators: %s: actual type */
            __('`limit` must be an integer (got %s).', 'wppilot'),
            gettype($limit_raw),
        ));
    }
    $invalid = validate_enum_field($input, 'order', ['asc', 'desc'])
        ?? validate_enum_field($input, 'orderby', LIST_ORDERBY)
        ?? validate_enum_field($input, 'status', LIST_STATUSES)
        ?? validate_scalar_string_fields($input, ['modified_after', 'modified_before', 'category', 'tag']);
    if ($invalid !== null) {
        return $invalid;
    }

    $clamp = clamp_limit((int) $limit_raw);
    $args = build_list_args($input, $clamp['limit']);
    try {
        /** @var mixed $products */
        $products = wc_get_products($args);
    } catch (\Throwable $e) {
        remove_filter('posts_orderby', __NAMESPACE__ . '\\append_id_tiebreaker_once', 10);
        return error('wc_internal_error', __('WooCommerce query failed unexpectedly.', 'wppilot'));
    }
    if ($products instanceof WP_Error) {
        return error('wc_invalid_input', $products->get_error_message());
    }

    $fields = [];
    foreach (is_array($input['fields'] ?? null) ? $input['fields'] : [] as $field) {
        if (is_string($field)) {
            $fields[] = $field;
        }
    }
    $rows = [];
    foreach (is_array($products) ? $products : [] as $product) {
        if ($product instanceof WC_Product) {
            $rows[] = $fields === [] ? compact_product($product) : compact_product_with_fields($product, $fields);
        }
    }

    try {
        $total = count_filtered_products($args);
    } catch (\Throwable $e) {
        $total = count($rows);
    }

    return ['products' => $rows, 'total' => $total, 'returned' => count($rows), 'truncated' => $clamp['truncated']];
}

/**
 * Make "include AND NOT exclude" hold whatever WooCommerce does with both: remove the excluded
 * ids from include and drop exclude. An include emptied this way means no results.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function normalize_exclude(array $input): array
{
    if (
        !isset($input['include'], $input['exclude'])
        || !is_array($input['include'])
        || !is_array($input['exclude'])
        || $input['exclude'] === []
    ) {
        return $input;
    }
    $input['include'] = array_values(array_diff(array_map('intval', $input['include']), array_map('intval', $input['exclude'])));
    unset($input['exclude']);
    return $input;
}

/**
 * Turn `on_sale` into an id filter before the query, so limit, offset and total stay right.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function normalize_on_sale(array $input): array
{
    if (!array_key_exists('on_sale', $input) || !is_bool($input['on_sale'])) {
        return $input;
    }
    $on_sale = array_map('intval', (array) wc_get_product_ids_on_sale());
    if ($input['on_sale'] === true) {
        if (isset($input['include']) && is_array($input['include']) && $input['include'] !== []) {
            $on_sale = array_values(array_intersect($on_sale, array_map('intval', $input['include'])));
        }
        $input['include'] = array_values($on_sale);
        return $input;
    }
    $exclude = isset($input['exclude']) && is_array($input['exclude']) ? array_map('intval', $input['exclude']) : [];
    $input['exclude'] = array_values(array_unique(array_merge($exclude, $on_sale)));
    return $input;
}

/**
 * The wc_get_products() arguments for a list-products call.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function build_list_args(array $input, int $limit): array
{
    $orderby = (string) ($input['orderby'] ?? 'date');
    $order = strtoupper((string) ($input['order'] ?? 'desc'));
    $offset = max(0, (int) ($input['offset'] ?? 0));
    $filters = build_filter_args($input);

    // Column sorts in WP_Query's array form emit "ORDER BY col DIR, ID DIR": rows with equal sort
    // keys otherwise come back in any order, and paging through them skips and repeats rows.
    if (in_array($orderby, ['date', 'title', 'menu_order'], true)) {
        return array_merge([
            'limit' => $limit,
            'offset' => $offset,
            'orderby' => [$orderby => $order, 'ID' => $order],
            'return' => 'objects',
            'paginate' => false,
        ], $filters);
    }

    // price, popularity, rating and sku go through WooCommerce's lookup-table joins, which write
    // ORDER BY themselves; the ID tiebreaker is appended to that clause for this one query.
    add_filter('posts_orderby', __NAMESPACE__ . '\\append_id_tiebreaker_once', 10, 1);
    return array_merge([
        'limit' => $limit,
        'offset' => $offset,
        'orderby' => $orderby,
        'order' => $order,
        'return' => 'objects',
        'paginate' => false,
    ], $filters);
}

/**
 * Append ", posts.ID <dir>" to the next ORDER BY clause, then remove itself.
 */
function append_id_tiebreaker_once(string $orderby): string
{
    remove_filter('posts_orderby', __NAMESPACE__ . '\\append_id_tiebreaker_once', 10);
    global $wpdb;
    if ($orderby === '' || !is_object($wpdb)) {
        return $orderby;
    }
    $posts = (string) $wpdb->posts;
    if (preg_match('/\b' . preg_quote($posts, '/') . '\.ID\b/i', $orderby) === 1) {
        return $orderby;
    }
    $dir = preg_match('/\bDESC\b/i', $orderby) === 1 ? 'DESC' : 'ASC';
    return $orderby . ', ' . $posts . '.ID ' . $dir;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function build_filter_args(array $input): array
{
    $args = [];
    if (isset($input['search']) && is_string($input['search']) && trim($input['search']) !== '') {
        $args['s'] = $input['search'];
    }
    foreach (['status', 'type', 'stock_status'] as $key) {
        if (isset($input[$key])) {
            $args[$key] = (string) $input[$key];
        }
    }
    if (isset($input['featured'])) {
        $args['featured'] = filter_var($input['featured'], FILTER_VALIDATE_BOOLEAN);
    }
    if (isset($input['sku']) && is_string($input['sku']) && trim($input['sku']) !== '') {
        $args['sku'] = $input['sku'];
    }
    if (isset($input['category'])) {
        $args['category'] = resolve_term_slug_input((string) $input['category'], 'product_cat');
    }
    if (isset($input['tag'])) {
        $args['tag'] = resolve_term_slug_input((string) $input['tag'], 'product_tag');
    }
    foreach (['include', 'exclude'] as $key) {
        if (isset($input[$key]) && is_array($input[$key])) {
            $args[$key] = array_map('intval', $input[$key]);
        }
    }
    if (isset($input['modified_after'])) {
        $args['date_modified'] = '>=' . (string) $input['modified_after'];
    }
    if (isset($input['modified_before'])) {
        $prefix = isset($args['date_modified']) ? $args['date_modified'] . ',' : '';
        $args['date_modified'] = $prefix . '<=' . (string) $input['modified_before'];
    }
    return $args;
}

/**
 * wc_get_products() filters terms by slug; a numeric id is looked up. An unknown id matches nothing.
 */
function resolve_term_slug_input(string $input, string $taxonomy): string
{
    if (!ctype_digit($input)) {
        return $input;
    }
    $term = get_term((int) $input, $taxonomy);
    return $term instanceof WP_Term ? $term->slug : '';
}

/**
 * @param array<string, mixed> $args
 */
function count_filtered_products(array $args): int
{
    $args['limit'] = -1;
    $args['offset'] = 0;
    $args['return'] = 'ids';
    /** @var mixed $ids */
    $ids = wc_get_products($args);
    return is_array($ids) ? count($ids) : 0;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function get_product(array $input): array|WP_Error
{
    $gate = check_minimum();
    if ($gate !== null) {
        return $gate;
    }
    $has_id = isset($input['id']) && is_numeric($input['id']);
    $has_slug = isset($input['slug']) && is_string($input['slug']) && trim($input['slug']) !== '';
    if (!$has_id && !$has_slug) {
        return error('wc_invalid_input', __('Either `id` or `slug` is required.', 'wppilot'));
    }
    $product = product_or_error($has_id ? (int) $input['id'] : (string) $input['slug']);
    return $product instanceof WP_Error ? $product : full_product($product);
}
