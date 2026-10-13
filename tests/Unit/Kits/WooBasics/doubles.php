<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * WooCommerce and the WordPress term, user and query functions the woo-basics kit reads.
 *
 * WooCommerce's classes are global and defined here, guarded, since nothing else in the suite
 * defines them. Its functions and the WordPress ones the shared doubles lack are defined in the
 * kit's own namespace: the kit calls them unqualified, so PHP finds these first and the rest of
 * the suite sees nothing new.
 *
 * WC_Product::save() models the part of WooCommerce the kit's slashing depends on: outside
 * save_post the data store hands the name and description to wp_update_post(), which unslashes
 * them. It also runs WooCommerce's validate_props() stock rules, which the undo has to survive.
 */

namespace WPPilot\Tests\Unit\Kits\WooBasics {
    final class Store
    {
        /** @var array<int, array<string, mixed>> */
        public static array $products = [];

        /** @var array<int, \WP_Term> */
        public static array $terms = [];

        /** @var array<int, array<string, mixed>> */
        public static array $termMeta = [];

        /** @var list<string> */
        public static array $taxonomies = ['product_cat', 'product_tag', 'pa_size'];

        public static ?\WP_Error $termsError = null;

        /** @var array<int, \WC_Order> */
        public static array $orders = [];

        /** @var array<int, array<string, mixed>> */
        public static array $users = [];

        /** @var list<int> */
        public static array $onSale = [];

        /** @var list<array<string, mixed>> */
        public static array $productQueries = [];

        /** @var list<array<string, mixed>> */
        public static array $orderQueries = [];

        public static bool $doingSavePost = false;

        /** @var array<int, \WP_Post> */
        public static array $posts = [];

        /** @var array<int, array<string, mixed>> */
        public static array $postMeta = [];

        /** @var array<string, mixed> */
        public static array $options = [];

        /** @var list<string> */
        public static array $caps = [];

        public static function reset(): void
        {
            self::$options = [];
            self::$caps = [];
            self::$products = [];
            self::$terms = [];
            self::$termMeta = [];
            self::$termsError = null;
            self::$orders = [];
            self::$users = [];
            self::$onSale = [];
            self::$productQueries = [];
            self::$orderQueries = [];
            self::$doingSavePost = false;
            Store::$posts = [];
            Store::$postMeta = [];
        }

        /** @param array<string, mixed> $data */
        public static function product(int $id, array $data = []): void
        {
            $data = array_merge([
                'id' => $id,
                'name' => 'Product ' . $id,
                'slug' => 'product-' . $id,
                'type' => 'simple',
                'status' => 'publish',
                'description' => '',
                'short_description' => '',
                'sku' => 'SKU-' . $id,
                'price' => '10',
                'regular_price' => '10',
                'sale_price' => '',
                'manage_stock' => false,
                'stock_quantity' => null,
                'stock_status' => 'instock',
                'backorders' => 'no',
                'low_stock_amount' => '',
                'featured' => false,
                'category_ids' => [],
                'tag_ids' => [],
                'image_id' => 0,
                'gallery_image_ids' => [],
                'attributes' => [],
                'children' => [],
                'parent_id' => 0,
                'default_attributes' => [],
                'variation_attributes' => [],
                'meta' => [],
                'length' => '',
                'width' => '',
                'height' => '',
                'weight' => '',
                'virtual' => false,
                'downloadable' => false,
            ], $data);
            self::$products[$id] = $data;
            $post = new \WP_Post();
            $post->ID = $id;
            $post->post_type = $data['type'] === 'variation' ? 'product_variation' : 'product';
            $post->post_status = (string) $data['status'];
            $post->post_name = (string) $data['slug'];
            $post->post_title = (string) $data['name'];
            $post->post_content = (string) $data['description'];
            $post->post_parent = (int) $data['parent_id'];
            Store::$posts[$id] = $post;
        }

        public static function term(int $id, string $taxonomy, string $slug, int $parent = 0, int $count = 0): \WP_Term
        {
            $term = new \WP_Term();
            $term->term_id = $id;
            $term->taxonomy = $taxonomy;
            $term->slug = $slug;
            $term->name = ucfirst($slug);
            $term->description = 'About ' . $slug;
            $term->parent = $parent;
            $term->count = $count;
            self::$terms[$id] = $term;
            return $term;
        }
    }

    /** A product attribute as WC_Product_Attribute answers. */
    final class Attribute
    {
        /** @param list<string> $options */
        public function __construct(
            private string $name,
            private array $options,
            private bool $variation = false,
            private int $id = 0,
        ) {
        }

        public function get_name(): string
        {
            return $this->name;
        }

        /** @return list<string> */
        public function get_options(): array
        {
            return $this->options;
        }

        public function is_taxonomy(): bool
        {
            return $this->id > 0;
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_visible(): bool
        {
            return true;
        }

        public function get_variation(): bool
        {
            return $this->variation;
        }
    }

    final class MetaEntry
    {
        public function __construct(private string $key, private mixed $value)
        {
        }

        /** @return array{key: string, value: mixed} */
        public function get_data(): array
        {
            return ['key' => $this->key, 'value' => $this->value];
        }
    }

    final class OrderItem
    {
        /** @param array<string, mixed> $data */
        public function __construct(private array $data)
        {
        }

        public function get_id(): int
        {
            return (int) $this->data['id'];
        }

        public function get_product_id(): int
        {
            return (int) $this->data['product_id'];
        }

        public function get_variation_id(): int
        {
            return 0;
        }

        public function get_name(): string
        {
            return (string) $this->data['name'];
        }

        public function get_quantity(): int
        {
            return (int) $this->data['quantity'];
        }

        public function get_subtotal(): string
        {
            return (string) $this->data['total'];
        }

        public function get_total(): string
        {
            return (string) $this->data['total'];
        }

        /** @return array{total: array<int, string>} */
        public function get_taxes(): array
        {
            return ['total' => [3 => '1.50']];
        }
    }
}

namespace {
    if (!defined('WC_VERSION')) {
        define('WC_VERSION', '9.4.0');
    }

    if (!class_exists('WooCommerce')) {
        final class WooCommerce
        {
        }
    }

    if (!class_exists('WP_Term')) {
        class WP_Term
        {
            public int $term_id = 0;
            public string $taxonomy = '';
            public string $slug = '';
            public string $name = '';
            public string $description = '';
            public int $parent = 0;
            public int $count = 0;
        }
    }

    if (!class_exists('WP_User')) {
        class WP_User
        {
            public function __construct(public int $ID = 0)
            {
            }
        }
    }

    if (!class_exists('WP_User_Query')) {
        class WP_User_Query
        {
            /** @var list<WP_User> */
            private array $results = [];

            private int $total = 0;

            /** @param array<string, mixed> $args */
            public function __construct(public array $args)
            {
                $matches = [];
                $search = trim((string) ($args['search'] ?? ''), '*');
                foreach (\WPPilot\Tests\Unit\Kits\WooBasics\Store::$users as $id => $user) {
                    if (($user['role'] ?? '') !== ($args['role'] ?? '')) {
                        continue;
                    }
                    if ($search !== '' && !str_contains((string) $user['email'], $search)) {
                        continue;
                    }
                    $matches[] = new WP_User($id);
                }
                $this->total = count($matches);
                $per_page = (int) ($args['number'] ?? 20);
                $this->results = array_slice($matches, ((int) ($args['paged'] ?? 1) - 1) * $per_page, $per_page);
            }

            /** @return list<WP_User> */
            public function get_results(): array
            {
                return $this->results;
            }

            public function get_total(): int
            {
                return $this->total;
            }
        }
    }

    if (!class_exists('WC_DateTime')) {
        class WC_DateTime
        {
            public function __construct(private string $iso = '2026-09-01T10:00:00+00:00')
            {
            }

            public function date(string $format): string
            {
                return $this->iso;
            }
        }
    }

    if (!class_exists('WC_Product')) {
        class WC_Product
        {
            /** @var array<string, mixed> */
            protected array $data = [];

            public function __construct(int $id = 0)
            {
                $this->data = \WPPilot\Tests\Unit\Kits\WooBasics\Store::$products[$id] ?? ['id' => 0];
            }

            public function get_id(): int
            {
                return (int) $this->data['id'];
            }

            public function get_type(): string
            {
                return (string) $this->data['type'];
            }

            public function get_name(string $context = 'view'): string
            {
                return (string) $this->data['name'];
            }

            public function get_slug(): string
            {
                return (string) $this->data['slug'];
            }

            public function get_sku(): string
            {
                return (string) $this->data['sku'];
            }

            public function get_status(): string
            {
                return (string) $this->data['status'];
            }

            public function get_description(string $context = 'view'): string
            {
                return (string) $this->data['description'];
            }

            public function get_price(): string
            {
                return (string) $this->data['price'];
            }

            public function get_regular_price(string $context = 'view'): string
            {
                return (string) $this->data['regular_price'];
            }

            public function get_sale_price(string $context = 'view'): string
            {
                return (string) $this->data['sale_price'];
            }

            public function get_manage_stock(string $context = 'view'): bool
            {
                return (bool) $this->data['manage_stock'];
            }

            public function get_stock_quantity(string $context = 'view'): ?int
            {
                return $this->data['stock_quantity'] === null ? null : (int) $this->data['stock_quantity'];
            }

            public function get_stock_status(string $context = 'view'): string
            {
                return (string) $this->data['stock_status'];
            }

            public function get_backorders(string $context = 'view'): string
            {
                return (string) $this->data['backorders'];
            }

            public function get_low_stock_amount(string $context = 'view'): int|string
            {
                return $this->data['low_stock_amount'];
            }

            public function get_featured(): bool
            {
                return (bool) $this->data['featured'];
            }

            public function get_permalink(): string
            {
                return 'https://shop.test/product/' . $this->data['slug'] . '/';
            }

            public function get_date_modified(): ?WC_DateTime
            {
                return new WC_DateTime();
            }

            /** @return list<int> */
            public function get_category_ids(): array
            {
                return $this->data['category_ids'];
            }

            /** @return list<int> */
            public function get_tag_ids(): array
            {
                return $this->data['tag_ids'];
            }

            public function get_image_id(): int
            {
                return (int) $this->data['image_id'];
            }

            /** @return list<int> */
            public function get_gallery_image_ids(): array
            {
                return $this->data['gallery_image_ids'];
            }

            /** @return list<object> */
            public function get_attributes(): array
            {
                return $this->data['attributes'];
            }

            /** @return list<int> */
            public function get_children(): array
            {
                return $this->data['children'];
            }

            /** @return list<object> */
            public function get_meta_data(): array
            {
                $out = [];
                foreach ($this->data['meta'] as $key => $value) {
                    $out[] = new \WPPilot\Tests\Unit\Kits\WooBasics\MetaEntry((string) $key, $value);
                }
                return $out;
            }

            public function get_shipping_class_id(): int
            {
                return 0;
            }

            public function get_shipping_class(): string
            {
                return '';
            }

            public function get_price_html(): string
            {
                return '<span class="amount">' . $this->get_price() . '</span>';
            }

            public function get_parent_id(): int
            {
                return (int) $this->data['parent_id'];
            }

            public function get_virtual(): bool
            {
                return (bool) $this->data['virtual'];
            }

            public function get_downloadable(): bool
            {
                return (bool) $this->data['downloadable'];
            }

            /** @return array<string, mixed> */
            public function get_data(): array
            {
                $data = $this->data;
                unset($data['attributes'], $data['children'], $data['meta'], $data['variation_attributes'], $data['default_attributes']);
                $data['date_created'] = new WC_DateTime();
                $data['date_modified'] = new WC_DateTime();
                $data['meta_data'] = [];
                return $data;
            }

            public function set_name(string $name): void
            {
                $this->data['name'] = $name;
            }

            public function set_description(string $description): void
            {
                $this->data['description'] = $description;
            }

            public function get_short_description(string $context = 'view'): string
            {
                return (string) $this->data['short_description'];
            }

            public function set_short_description(string $description): void
            {
                $this->data['short_description'] = $description;
            }

            public function set_regular_price(string $price): void
            {
                $this->data['regular_price'] = $price;
            }

            public function set_sale_price(string $price): void
            {
                $this->data['sale_price'] = $price;
            }

            public function set_manage_stock(bool $manage): void
            {
                $this->data['manage_stock'] = $manage;
            }

            public function set_stock_quantity(mixed $quantity): void
            {
                $this->data['stock_quantity'] = $quantity === '' || $quantity === null ? null : (int) $quantity;
            }

            public function set_stock_status(string $status): void
            {
                $this->data['stock_status'] = $status;
            }

            public function set_backorders(string $backorders): void
            {
                $this->data['backorders'] = $backorders;
            }

            public function set_low_stock_amount(mixed $amount): void
            {
                $this->data['low_stock_amount'] = $amount === '' ? '' : (int) $amount;
            }

            /**
             * WC_Product_Data_Store_CPT::update() and validate_props(), in the parts the kit leans on.
             */
            public function save(): int
            {
                $id = $this->get_id();
                if ($id <= 0) {
                    return 0;
                }
                $stored = $this->data;
                if (!$stored['manage_stock']) {
                    $stored['stock_quantity'] = null;
                    $stored['backorders'] = 'no';
                    $stored['low_stock_amount'] = '';
                } elseif ($stored['stock_quantity'] !== null) {
                    $stored['stock_status'] = $stored['stock_quantity'] > 0
                        ? 'instock'
                        : ($stored['backorders'] !== 'no' ? 'onbackorder' : 'outofstock');
                }
                $stored['price'] = $stored['sale_price'] !== '' ? $stored['sale_price'] : $stored['regular_price'];
                $was = \WPPilot\Tests\Unit\Kits\WooBasics\Store::$products[$id];
                $post_fields = ['name', 'description', 'short_description'];
                $post_changed = false;
                foreach ($post_fields as $field) {
                    $post_changed = $post_changed || $stored[$field] !== $was[$field];
                }
                if ($post_changed && !\WPPilot\Tests\Unit\Kits\WooBasics\Store::$doingSavePost) {
                    // The data store sends all three to wp_update_post() when any one changed, and
                    // wp_insert_post() unslashes them.
                    foreach ($post_fields as $field) {
                        $stored[$field] = stripslashes((string) $stored[$field]);
                    }
                }
                \WPPilot\Tests\Unit\Kits\WooBasics\Store::$products[$id] = $stored;
                $post = \WPPilot\Tests\Unit\Kits\WooBasics\Store::$posts[$id];
                $post->post_title = (string) $stored['name'];
                $post->post_content = (string) $stored['description'];
                return $id;
            }
        }
    }

    if (!class_exists('WC_Product_Variable')) {
        class WC_Product_Variable extends WC_Product
        {
            /** @return array<string, string> */
            public function get_default_attributes(): array
            {
                return $this->data['default_attributes'];
            }
        }
    }

    if (!class_exists('WC_Product_Variation')) {
        class WC_Product_Variation extends WC_Product
        {
            /** @return array<string, string> */
            public function get_variation_attributes(): array
            {
                return $this->data['variation_attributes'];
            }
        }
    }

    if (!class_exists('WC_Order')) {
        class WC_Order
        {
            /** @param array<string, mixed> $data */
            public function __construct(public array $data)
            {
            }

            public function get_id(): int
            {
                return (int) $this->data['id'];
            }

            public function get_order_number(): string
            {
                return (string) $this->data['id'];
            }

            public function get_status(): string
            {
                return (string) $this->data['status'];
            }

            public function get_currency(): string
            {
                return 'EUR';
            }

            public function get_total(): string
            {
                return (string) $this->data['total'];
            }

            public function get_total_refunded(): float
            {
                return (float) ($this->data['refunded'] ?? 0);
            }

            public function get_customer_id(): int
            {
                return (int) $this->data['customer_id'];
            }

            public function get_billing_email(): string
            {
                return (string) $this->data['email'];
            }

            public function get_payment_method(): string
            {
                return 'bacs';
            }

            public function get_payment_method_title(): string
            {
                return 'Bank transfer';
            }

            public function get_date_created(): ?WC_DateTime
            {
                return new WC_DateTime();
            }

            public function get_date_modified(): ?WC_DateTime
            {
                return null;
            }

            /** @return list<object> */
            public function get_items(): array
            {
                return array_map(
                    static fn(array $item): object => new \WPPilot\Tests\Unit\Kits\WooBasics\OrderItem($item),
                    $this->data['items'] ?? [],
                );
            }

            /** @return array<string, string> */
            public function get_address(string $type): array
            {
                return ['first_name' => 'Ada', 'city' => $type === 'billing' ? 'Paris' : 'Lyon'];
            }

            public function get_customer_note(): string
            {
                return (string) ($this->data['note'] ?? '');
            }

            /** @return list<WC_Order_Refund> */
            public function get_refunds(): array
            {
                return $this->data['refunds'] ?? [];
            }
        }
    }

    if (!class_exists('WC_Order_Refund')) {
        class WC_Order_Refund
        {
            public function __construct(private int $id, private int $parent, private string $amount)
            {
            }

            public function get_id(): int
            {
                return $this->id;
            }

            public function get_parent_id(): int
            {
                return $this->parent;
            }

            public function get_amount(): string
            {
                return $this->amount;
            }

            public function get_reason(): string
            {
                return 'Damaged';
            }

            public function get_date_created(): ?WC_DateTime
            {
                return new WC_DateTime();
            }

            public function get_refunded_by(): int
            {
                return 1;
            }
        }
    }

    if (!class_exists('WC_Customer')) {
        class WC_Customer
        {
            /** @var array<string, mixed> */
            private array $data;

            public function __construct(int $id)
            {
                $this->data = array_merge(['id' => $id], \WPPilot\Tests\Unit\Kits\WooBasics\Store::$users[$id] ?? []);
            }

            public function get_id(): int
            {
                return (int) $this->data['id'];
            }

            public function get_email(): string
            {
                return (string) ($this->data['email'] ?? '');
            }

            public function get_first_name(): string
            {
                return 'Ada';
            }

            public function get_last_name(): string
            {
                return 'Lovelace';
            }

            public function get_display_name(): string
            {
                return 'Ada Lovelace';
            }

            public function get_username(): string
            {
                return 'ada' . $this->data['id'];
            }

            public function get_date_created(): ?WC_DateTime
            {
                return new WC_DateTime();
            }

            /** @return array<string, string> */
            public function get_billing(): array
            {
                return ['city' => 'Paris', 'email' => $this->get_email()];
            }

            /** @return array<string, string> */
            public function get_shipping(): array
            {
                return ['city' => 'Lyon'];
            }
        }
    }
}

namespace WPPilot\Kits\WooBasics {
    use WPPilot\Tests\Unit\Kits\WooBasics\Store;

    if (!function_exists(__NAMESPACE__ . '\\wc_get_product')) {
        function wc_get_product(int $id): \WC_Product|false
        {
            $data = Store::$products[$id] ?? null;
            if ($data === null) {
                return false;
            }
            return match ($data['type']) {
                'variable' => new \WC_Product_Variable($id),
                'variation' => new \WC_Product_Variation($id),
                default => new \WC_Product($id),
            };
        }

        /**
         * @param array<string, mixed> $args
         * @return list<\WC_Product>|list<int>
         */
        function wc_get_products(array $args): array
        {
            Store::$productQueries[] = $args;
            $rows = [];
            foreach (Store::$products as $id => $data) {
                $is_variation = $data['type'] === 'variation';
                if ((($args['type'] ?? null) === 'variation') !== $is_variation) {
                    continue;
                }
                if (isset($args['parent']) && (int) $data['parent_id'] !== (int) $args['parent']) {
                    continue;
                }
                if (isset($args['include']) && !in_array($id, $args['include'], true)) {
                    continue;
                }
                if (isset($args['exclude']) && in_array($id, $args['exclude'], true)) {
                    continue;
                }
                if (isset($args['stock_status']) && $data['stock_status'] !== $args['stock_status']) {
                    continue;
                }
                if (is_string($args['status'] ?? null) && $data['status'] !== $args['status']) {
                    continue;
                }
                $rows[] = $id;
            }
            if (($args['limit'] ?? -1) !== -1) {
                $rows = array_slice($rows, (int) ($args['offset'] ?? 0), (int) $args['limit']);
            }
            if (($args['return'] ?? 'objects') === 'ids') {
                return $rows;
            }
            return array_values(array_map(static fn(int $id): \WC_Product => wc_get_product($id), $rows));
        }

        /** @return list<int> */
        function wc_get_product_ids_on_sale(): array
        {
            return Store::$onSale;
        }

        /** @param array<string, mixed> $args */
        function wc_get_orders(array $args): object
        {
            Store::$orderQueries[] = $args;
            $orders = [];
            foreach (Store::$orders as $order) {
                if (isset($args['status']) && $order->get_status() !== $args['status']) {
                    continue;
                }
                if (isset($args['customer_id']) && $order->get_customer_id() !== $args['customer_id']) {
                    continue;
                }
                $orders[] = $order;
            }
            return (object) ['orders' => $orders, 'total' => count($orders), 'max_num_pages' => 1];
        }

        function wc_get_order(int $id): \WC_Order|false
        {
            return Store::$orders[$id] ?? false;
        }

        /** @return array<string, string> */
        function wc_get_order_statuses(): array
        {
            return ['wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-completed' => 'Completed'];
        }

        function wc_get_customer_order_count(int $id): int
        {
            return count(array_filter(Store::$orders, static fn(\WC_Order $o): bool => $o->get_customer_id() === $id));
        }

        function wc_get_customer_total_spent(int $id): string
        {
            return '42.00';
        }

        /** @return array<string, string> */
        function wc_get_base_location(): array
        {
            return ['country' => 'FR', 'state' => ''];
        }

        function get_woocommerce_currency(): string
        {
            return 'EUR';
        }

        function get_woocommerce_currency_symbol(): string
        {
            return '&euro;';
        }

        function wc_get_price_thousand_separator(): string
        {
            return '.';
        }

        function wc_get_price_decimal_separator(): string
        {
            return ',';
        }

        function wc_get_price_decimals(): int
        {
            return 2;
        }

        function get_page_by_path(string $path, string $output, string $post_type): ?\WP_Post
        {
            foreach (Store::$posts as $post) {
                if ($post->post_name === $path && $post->post_type === $post_type) {
                    return $post;
                }
            }
            return null;
        }

        function wp_is_post_revision(int $id): int|false
        {
            return false;
        }

        /** @return object{publish: int} */
        function wp_count_posts(string $type): object
        {
            $published = 0;
            foreach (Store::$posts as $post) {
                $published += $post->post_type === $type && $post->post_status === 'publish' ? 1 : 0;
            }
            return (object) ['publish' => $published];
        }

        function has_block(string $block, \WP_Post $post): bool
        {
            return str_contains($post->post_content, '<!-- wp:' . $block);
        }

        /**
         * @param array<string, mixed> $args
         * @return list<\WP_Term>|\WP_Error
         */
        function get_terms(array $args): array|\WP_Error
        {
            if (Store::$termsError !== null) {
                return Store::$termsError;
            }
            $out = [];
            foreach (Store::$terms as $term) {
                if ($term->taxonomy !== $args['taxonomy']) {
                    continue;
                }
                if (isset($args['include']) && !in_array($term->term_id, $args['include'], true)) {
                    continue;
                }
                if (isset($args['parent']) && $term->parent !== $args['parent']) {
                    continue;
                }
                if (($args['hide_empty'] ?? false) === true && $term->count === 0) {
                    continue;
                }
                if (isset($args['search']) && !str_contains($term->name, (string) $args['search'])) {
                    continue;
                }
                $out[] = $term;
            }
            return array_slice($out, 0, (int) ($args['number'] ?? 100));
        }

        function get_term(int $id, string $taxonomy): ?\WP_Term
        {
            $term = Store::$terms[$id] ?? null;
            return $term !== null && $term->taxonomy === $taxonomy ? $term : null;
        }

        function get_term_by(string $field, string $value, string $taxonomy): \WP_Term|false
        {
            foreach (Store::$terms as $term) {
                if ($term->taxonomy === $taxonomy && $term->slug === $value) {
                    return $term;
                }
            }
            return false;
        }

        function get_term_meta(int $id, string $key, bool $single): mixed
        {
            return Store::$termMeta[$id][$key] ?? '';
        }

        function taxonomy_exists(string $taxonomy): bool
        {
            return in_array($taxonomy, Store::$taxonomies, true);
        }

        function wp_get_attachment_url(int $id): string|false
        {
            return $id === 99 ? false : 'https://shop.test/uploads/' . $id . '.jpg';
        }

        function clean_post_cache(int $id): void
        {
        }

        function get_post(int $id): ?\WP_Post
        {
            return Store::$posts[$id] ?? null;
        }

        function get_post_meta(int $id, string $key = '', bool $single = false): mixed
        {
            return Store::$postMeta[$id][$key] ?? '';
        }

        function get_option(string $option, mixed $default = false): mixed
        {
            return Store::$options[$option] ?? $default;
        }

        function current_user_can(string $capability): bool
        {
            return in_array($capability, Store::$caps, true);
        }

        function doing_action(string $hook): bool
        {
            return $hook === 'save_post' && Store::$doingSavePost;
        }

        function get_user_by(string $field, int $id): \WP_User|false
        {
            return isset(Store::$users[$id]) ? new \WP_User($id) : false;
        }

        function remove_filter(string $hook, mixed $callback, int $priority = 10): bool
        {
            foreach ($GLOBALS['wp_filter'][$hook][$priority] ?? [] as $index => $entry) {
                if ($entry['callback'] === $callback) {
                    unset($GLOBALS['wp_filter'][$hook][$priority][$index]);
                }
            }
            return true;
        }
    }
}
