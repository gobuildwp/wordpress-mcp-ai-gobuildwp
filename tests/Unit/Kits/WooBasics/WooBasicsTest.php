<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\WooBasics;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\WooBasics;

/**
 * The woo-basics kit against WooCommerce doubles: every ability's answer and refusal, the
 * stand-aside when another plugin registered a name first, and the edit's undo.
 */
final class WooBasicsTest extends TestCase
{
    private const KIT = __DIR__ . '/../../../../includes/kits/woo-basics';

    private const EDIT = 'wppilot/woocommerce-edit-product';

    /** @var array<string, callable> */
    public static array $captures = [];

    /** @var array<string, callable> */
    public static array $strategies = [];

    public static bool $enabled = true;

    /** The edit's before-image callback, as the kit first attached it. */
    private static mixed $capture = null;

    /** @var array<string, array<string, mixed>> Registration args, as the kit first registered them. */
    private static array $abilities = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/doubles.php';
        require_once __DIR__ . '/registrations.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        Runtime\host(self::host());
        $kit = require self::KIT . '/bootstrap.php';
        ($kit['boot'])(Runtime\host());
        self::registerAll($kit['ability_files']);
        self::$abilities = Registrations::$args;
        self::$capture = self::$captures[self::EDIT] ?? null;
    }

    protected function setUp(): void
    {
        Store::reset();
        self::$enabled = true;
        Runtime\host(self::host());
        Store::$caps = ['manage_woocommerce'];
    }

    protected function tearDown(): void
    {
        remove_all_filters('posts_orderby');
        Store::$caps = [];
    }

    /** @param list<string> $files */
    private static function registerAll(array $files): void
    {
        Registrations::$args = [];
        self::$captures = [];
        foreach ($files as $file) {
            require $file;
        }
    }

    /** @param array<string, mixed> $input */
    private static function call(string $name, array $input = []): mixed
    {
        return (self::$abilities[$name]['execute_callback'])($input);
    }

    private static function code(mixed $result): string
    {
        return $result instanceof WP_Error ? $result->get_error_code() : 'no error';
    }

    private function seedCatalog(): void
    {
        Store::term(5, 'product_cat', 'clothing', 0, 3);
        Store::term(6, 'product_cat', 'shirts', 5, 2);
        Store::term(7, 'product_tag', 'summer', 0, 1);
        Store::term(8, 'product_tag', 'unused', 0, 0);
        Store::term(20, 'pa_size', 'm');
        Store::$termMeta[6] = ['thumbnail_id' => 44, 'display_type' => 'products', 'order' => 3];
        Store::product(10, [
            'name' => 'Linen shirt',
            'slug' => 'linen-shirt',
            'short_description' => 'Fits C:\\ drives',
            'description' => 'Path C:\\x and a \\"quote\\"',
            'category_ids' => [6],
            'tag_ids' => [7],
            'image_id' => 44,
            'meta' => ['_internal' => 'x', 'fabric' => 'linen'],
            'manage_stock' => true,
            'stock_quantity' => 4,
            'sale_price' => '8',
            'price' => '8',
        ]);
        Store::product(11, [
            'name' => 'Tee',
            'type' => 'variable',
            'regular_price' => '99',
            'stock_quantity' => 3,
            'manage_stock' => true,
            'children' => [12],
            'attributes' => [new Attribute('pa_size', ['m'], true, 1), new Attribute('ghost', [])],
            'default_attributes' => ['pa_size' => 'm'],
        ]);
        Store::product(12, [
            'name' => 'Tee - M',
            'type' => 'variation',
            'parent_id' => 11,
            'regular_price' => '12',
            'variation_attributes' => ['attribute_pa_size' => 'm'],
        ]);
        Store::product(13, ['name' => 'Draft mug', 'slug' => 'draft-mug', 'status' => 'draft', 'stock_status' => 'outofstock']);
        $page = new \WP_Post();
        $page->ID = 30;
        $page->post_type = 'page';
        Store::$posts[30] = $page;
    }

    // Registration.

    public function testEveryAbilityIsRegisteredAsKitJsonDeclaresIt(): void
    {
        $manifest = json_decode((string) file_get_contents(self::KIT . '/kit.json'), true);
        self::assertCount(13, $manifest['abilities']);
        foreach ($manifest['abilities'] as $declared) {
            $args = self::$abilities[$declared['name']] ?? null;
            self::assertIsArray($args, $declared['name']);
            self::assertSame('woocommerce', $args['category']);
            self::assertSame($declared['readonly'], $args['meta']['annotations']['readonly'], $declared['name']);
            self::assertSame($declared['destructive'], $args['meta']['annotations']['destructive'], $declared['name']);
        }
        self::assertArrayHasKey(WooBasics\STRATEGY, self::$strategies, 'boot registers the undo');
        self::assertIsCallable(self::$capture, 'the edit takes a before-image');
    }

    public function testANameRegisteredFirstIsLeftAloneWithItsLedgerCapture(): void
    {
        // Another plugin registered these two first, in the registry
        // the kit asks through Runtime\unclaimed().
        foreach ([self::EDIT, 'wppilot/woocommerce-list-products'] as $name) {
            if (!\wp_has_ability($name)) {
                \wp_register_ability($name, ['label' => 'Registered first elsewhere']);
            }
        }

        self::registerAll([self::KIT . '/src/abilities/catalog.php', self::KIT . '/src/abilities/edit-product.php']);

        self::assertArrayNotHasKey(self::EDIT, Registrations::$args, 'not registered a second time');
        self::assertArrayNotHasKey('wppilot/woocommerce-list-products', Registrations::$args);
        self::assertArrayNotHasKey(self::EDIT, self::$captures, 'no before-image for an ability that is not ours');
        self::assertArrayHasKey('wppilot/woocommerce-get-product', Registrations::$args, 'the free names still register');
        self::assertArrayHasKey('wppilot/woocommerce-check-setup', Registrations::$args);
    }

    public function testPermissionsFollowTheHostAndOrdersNeedManageWoocommerce(): void
    {
        self::assertTrue((self::$abilities['wppilot/woocommerce-get-product']['permission_callback'])());
        self::assertTrue((self::$abilities['wppilot/woocommerce-list-orders']['permission_callback'])());

        Store::$caps = [];
        self::assertFalse((self::$abilities['wppilot/woocommerce-list-orders']['permission_callback'])());
        self::assertFalse((self::$abilities['wppilot/woocommerce-get-customer']['permission_callback'])());
        self::assertTrue((self::$abilities['wppilot/woocommerce-list-products']['permission_callback'])());

        self::$enabled = false;
        self::assertFalse((self::$abilities['wppilot/woocommerce-check-setup']['permission_callback'])());
        self::assertFalse((self::$abilities[self::EDIT]['permission_callback'])());
    }

    // Store.

    public function testCheckSetupReportsTheStore(): void
    {
        $this->seedCatalog();
        $page = new \WP_Post();
        $page->ID = 31;
        $page->post_type = 'page';
        $page->post_content = '<!-- wp:woocommerce/checkout -->';
        Store::$posts[31] = $page;
        Store::$options['woocommerce_checkout_page_id'] = '31';

        $setup = self::call('wppilot/woocommerce-check-setup');

        self::assertTrue($setup['active']);
        self::assertSame(WC_VERSION, $setup['version']);
        self::assertTrue($setup['meets_minimum']);
        self::assertTrue($setup['block_checkout_enabled']);
        self::assertSame('EUR', $setup['currency']);
        self::assertSame('FR', $setup['default_country']);
        self::assertSame(2, $setup['products_count'], 'published products only; variations and drafts excluded');
        self::assertCount(9, $setup['registered_extensions']);
        self::assertFalse($setup['registered_extensions'][0]['active']);
        unset(Store::$options['woocommerce_checkout_page_id']);
    }

    public function testStoreSettingsReadTheWooCommerceOptions(): void
    {
        Store::$options['woocommerce_currency_pos'] = 'right_space';
        Store::$options['woocommerce_store_city'] = 'Paris';

        $settings = self::call('wppilot/woocommerce-get-store-settings');

        self::assertSame('&euro;', $settings['currency_symbol']);
        self::assertSame('right_space', $settings['currency_position']);
        self::assertSame(2, $settings['num_decimals']);
        self::assertSame([',', '.'], [$settings['decimal_separator'], $settings['thousand_separator']]);
        self::assertSame('Paris', $settings['store_address']['city']);
        self::assertSame('FR', $settings['store_address']['country']);
        self::assertSame('', $settings['weight_unit']);
        unset(Store::$options['woocommerce_currency_pos'], Store::$options['woocommerce_store_city']);
    }

    // Products.

    public function testProductsListInTheCompactShapeAndHideAVariableParentsOwnPriceAndStock(): void
    {
        $this->seedCatalog();

        $list = self::call('wppilot/woocommerce-list-products', []);

        self::assertSame(3, $list['total']);
        self::assertSame(3, $list['returned']);
        self::assertFalse($list['truncated']);
        $rows = array_column($list['products'], null, 'id');
        self::assertCount(15, $rows[10]);
        self::assertSame(['8', 4, true], [$rows[10]['sale_price'], $rows[10]['stock_quantity'], $rows[10]['manage_stock']]);
        self::assertSame(['', null, null, false], [$rows[11]['regular_price'], $rows[11]['sale_price'], $rows[11]['stock_quantity'], $rows[11]['manage_stock']]);
        self::assertNull($rows[13]['sale_price'], 'an empty sale price reads as null');
        self::assertSame(['date' => 'DESC', 'ID' => 'DESC'], Store::$productQueries[0]['orderby'], 'an ID tiebreaker keeps paging stable');
    }

    public function testListFiltersFieldsAndOnSale(): void
    {
        $this->seedCatalog();
        Store::$onSale = [10];

        $sale = self::call('wppilot/woocommerce-list-products', ['on_sale' => false, 'fields' => ['categories', 'tags', 'meta_data', 'attributes']]);
        self::assertSame([11, 13], array_column($sale['products'], 'id'));
        self::assertSame([['id' => 1, 'name' => 'pa_size', 'slug' => 'pa-size', 'options' => ['m'], 'visible' => true, 'variation' => true, 'taxonomy_slug' => 'pa_size']], $sale['products'][0]['attributes'], 'a force-deleted global attribute is not listed');

        $only = self::call('wppilot/woocommerce-list-products', ['on_sale' => true, 'fields' => ['categories', 'tags', 'meta_data', 'images']]);
        self::assertSame([10], array_column($only['products'], 'id'));
        self::assertSame([['id' => 6, 'slug' => 'shirts', 'name' => 'Shirts']], $only['products'][0]['categories']);
        self::assertSame([['key' => 'fabric', 'value' => 'linen']], $only['products'][0]['meta_data']);
        self::assertSame(44, $only['products'][0]['images'][0]['id']);

        $none = self::call('wppilot/woocommerce-list-products', ['include' => [10], 'exclude' => [10]]);
        self::assertSame(['products' => [], 'total' => 0, 'returned' => 0, 'truncated' => false], $none);

        $clamped = self::call('wppilot/woocommerce-list-products', ['limit' => 500, 'orderby' => 'price', 'category' => '6']);
        self::assertTrue($clamped['truncated']);
        $args = end(Store::$productQueries);
        self::assertSame(['limit' => -1, 'offset' => 0, 'return' => 'ids'], array_intersect_key($args, ['limit' => 0, 'offset' => 0, 'return' => 0]));
        self::assertSame('shirts', $args['category'], 'a numeric category is looked up to its slug');
        self::assertSame('price', $args['orderby']);
    }

    public function testListRefusesBadInput(): void
    {
        self::assertSame('wc_invalid_input', self::code(self::call('wppilot/woocommerce-list-products', ['orderby' => 'random'])));
        self::assertSame('wc_invalid_input', self::code(self::call('wppilot/woocommerce-list-products', ['limit' => 'ten'])));
        self::assertSame('wc_invalid_input', self::code(self::call('wppilot/woocommerce-list-products', ['category' => ['a']])));
        self::assertSame(400, self::call('wppilot/woocommerce-list-products', ['order' => 'sideways'])->get_error_data()['status']);
    }

    public function testGetProductReturnsTheFullProductBySlugOrId(): void
    {
        $this->seedCatalog();

        $product = self::call('wppilot/woocommerce-get-product', ['slug' => 'linen-shirt']);

        self::assertSame(10, $product['id']);
        self::assertSame('Path C:\\x and a \\"quote\\"', $product['description']);
        self::assertFalse($product['description_truncated']);
        self::assertSame('2026-09-01T10:00:00+00:00', $product['date_created']);
        self::assertSame([['key' => 'fabric', 'value' => 'linen']], $product['meta_data']);
        self::assertSame('<span class="amount">8</span>', $product['price_html']);
        self::assertSame([['id' => 7, 'slug' => 'summer', 'name' => 'Summer']], $product['tags']);

        $variable = self::call('wppilot/woocommerce-get-product', ['id' => 11]);
        self::assertSame([12], $variable['variations']);
        self::assertSame(['', null, false, null], [$variable['regular_price'], $variable['sale_price'], $variable['manage_stock'], $variable['stock_quantity']]);
    }

    public function testGetProductRefusesWhatIsNotAProduct(): void
    {
        $this->seedCatalog();

        self::assertSame('wc_invalid_input', self::code(self::call('wppilot/woocommerce-get-product', [])));
        self::assertSame('wc_post_type_mismatch', self::code(self::call('wppilot/woocommerce-get-product', ['id' => 30])));
        self::assertSame('wc_not_found', self::code(self::call('wppilot/woocommerce-get-product', ['id' => 404])));
        self::assertSame('wc_not_found', self::code(self::call('wppilot/woocommerce-get-product', ['slug' => 'nope'])));
        Store::$posts[10]->post_status = 'trash';
        self::assertSame(404, self::call('wppilot/woocommerce-get-product', ['id' => 10])->get_error_data()['status']);
    }

    // Variations.

    public function testVariationsListCompactlyAndMarkTheDefault(): void
    {
        $this->seedCatalog();

        $list = self::call('wppilot/woocommerce-list-product-variations', ['parent_id' => 11]);

        self::assertSame(1, $list['total']);
        self::assertSame([
            'id' => 12,
            'parent_id' => 11,
            'sku' => 'SKU-12',
            'attributes_summary' => 'M',
            'regular_price' => '12',
            'sale_price' => null,
            'stock_status' => 'instock',
            'stock_quantity' => null,
            'is_default' => true,
        ], $list['variations'][0]);
        self::assertSame(['publish', 'private', 'draft'], Store::$productQueries[0]['status']);

        $full = self::call('wppilot/woocommerce-list-product-variations', ['parent_id' => 11, 'fields' => ['full']]);
        self::assertArrayHasKey('dimensions', $full['variations'][0]);
    }

    public function testVariationsNeedAVariableParent(): void
    {
        $this->seedCatalog();

        self::assertSame('wc_not_variable_parent', self::code(self::call('wppilot/woocommerce-list-product-variations', ['parent_id' => 10])));
        self::assertSame('wc_not_found', self::code(self::call('wppilot/woocommerce-list-product-variations', ['parent_id' => 404])));
        self::assertSame('wc_invalid_input', self::code(self::call('wppilot/woocommerce-list-product-variations', [])));
        self::assertSame('wc_invalid_input', self::code(self::call('wppilot/woocommerce-list-product-variations', ['parent_id' => 11, 'orderby' => 'price'])));
    }

    public function testOneVariationInFull(): void
    {
        $this->seedCatalog();

        $variation = self::call('wppilot/woocommerce-get-product-variation', ['id' => 12]);

        self::assertSame('Tee - M', $variation['name']);
        self::assertSame([['key' => 'attribute_pa_size', 'taxonomy' => 'pa_size', 'label' => 'pa_size', 'option' => 'M']], $variation['attributes']);
        self::assertSame(['length' => '', 'width' => '', 'height' => ''], $variation['dimensions']);
        self::assertNull($variation['image_id']);
        self::assertTrue($variation['is_default']);

        self::assertSame('wc_not_found', self::code(self::call('wppilot/woocommerce-get-product-variation', ['id' => 10])));
        self::assertSame('wc_invalid_input', self::code(self::call('wppilot/woocommerce-get-product-variation', [])));
    }

    // Terms.

    public function testCategoriesCarryTheirTreeAndThumbnailsOnRequest(): void
    {
        $this->seedCatalog();

        $list = self::call('wppilot/woocommerce-list-product-categories', []);
        $shirts = array_column($list['categories'], null, 'id')[6];
        self::assertSame(['clothing', 'products', 3], [$shirts['parent_slug'], $shirts['display_type'], $shirts['menu_order']]);
        self::assertArrayNotHasKey('thumbnail_id', $shirts);

        $with = self::call('wppilot/woocommerce-list-product-categories', ['include_thumbnail' => true, 'parent_id' => 5]);
        self::assertSame(1, $with['total']);
        self::assertSame([44, 'https://shop.test/uploads/44.jpg'], [$with['categories'][0]['thumbnail_id'], $with['categories'][0]['thumbnail_src']]);

        Store::$termMeta[6] = ['thumbnail_id' => 99, 'order_6' => 7];
        $dangling = self::call('wppilot/woocommerce-list-product-categories', ['include_thumbnail' => true, 'parent' => 5]);
        self::assertNull($dangling['categories'][0]['thumbnail_id'], 'a deleted attachment is not reported');
        self::assertSame(7, $dangling['categories'][0]['menu_order'], 'the legacy sort key is read');

        self::assertSame('wc_invalid_input', self::code(self::call('wppilot/woocommerce-list-product-categories', ['parent' => 0, 'parent_id' => 5])));
    }

    public function testTagsListFlat(): void
    {
        $this->seedCatalog();

        self::assertSame(2, self::call('wppilot/woocommerce-list-product-tags', [])['total']);
        $used = self::call('wppilot/woocommerce-list-product-tags', ['hide_empty' => true]);
        self::assertSame([['id' => 7, 'slug' => 'summer', 'name' => 'Summer', 'count' => 1, 'description' => 'About summer']], $used['tags']);

        Store::$termsError = new WP_Error('invalid_taxonomy', 'Invalid taxonomy.');
        self::assertSame('wc_invalid_input', self::code(self::call('wppilot/woocommerce-list-product-tags', [])));
    }

    // Orders and customers.

    private function seedOrders(): void
    {
        Store::$users[20] = ['role' => 'customer', 'email' => 'ada@example.test'];
        Store::$users[21] = ['role' => 'customer', 'email' => 'bob@example.test'];
        Store::$users[22] = ['role' => 'administrator', 'email' => 'admin@example.test'];
        Store::$orders[100] = new \WC_Order([
            'id' => 100,
            'status' => 'processing',
            'total' => '30.00',
            'customer_id' => 20,
            'email' => 'ada@example.test',
            'items' => [['id' => 1, 'product_id' => 10, 'name' => 'Linen shirt', 'quantity' => 2, 'total' => '16.00']],
            'refunds' => [new \WC_Order_Refund(101, 100, '5.00')],
            'refunded' => 5,
        ]);
        Store::$orders[102] = new \WC_Order(['id' => 102, 'status' => 'completed', 'total' => '9.00', 'customer_id' => 21, 'email' => 'bob@example.test']);
    }

    public function testOrdersListThroughWcGetOrders(): void
    {
        $this->seedOrders();

        $list = self::call('wppilot/woocommerce-list-orders', ['status' => 'wc-processing', 'limit' => 500]);

        self::assertSame(1, $list['total']);
        self::assertSame(100, $list['limit'], 'clamped to 100');
        self::assertSame('processing', Store::$orderQueries[0]['status']);
        self::assertTrue(Store::$orderQueries[0]['paginate']);
        self::assertSame(['id' => 100, 'status' => 'processing', 'refunded_total' => '5', 'date_modified' => null], array_intersect_key($list['orders'][0], ['id' => 0, 'status' => 0, 'refunded_total' => 0, 'date_modified' => 0]));
        self::assertArrayNotHasKey('items', $list['orders'][0]);

        self::assertSame(2, self::call('wppilot/woocommerce-list-orders', [])['total']);
        self::assertSame('wc_invalid_input', self::code(self::call('wppilot/woocommerce-list-orders', ['status' => 'shipped'])));
    }

    public function testOneOrderInFull(): void
    {
        $this->seedOrders();

        $order = self::call('wppilot/woocommerce-get-order', ['order_id' => 100]);

        self::assertSame(['first_name' => 'Ada', 'city' => 'Paris'], $order['billing']);
        self::assertSame([3 => '1.50'], $order['items'][0]['taxes']);
        self::assertSame(['id' => 101, 'order_id' => 100, 'amount' => '5.00', 'reason' => 'Damaged', 'date_created' => '2026-09-01T10:00:00+00:00', 'refunded_by' => 1], $order['refunds'][0]);
        self::assertSame('wc_not_found', self::code(self::call('wppilot/woocommerce-get-order', ['order_id' => 999])));
    }

    public function testCustomersListAndReadOne(): void
    {
        $this->seedOrders();

        $list = self::call('wppilot/woocommerce-list-customers', ['search' => 'bob']);
        self::assertSame(1, $list['total']);
        self::assertSame(['id' => 21, 'email' => 'bob@example.test', 'orders_count' => 1], array_intersect_key($list['customers'][0], ['id' => 0, 'email' => 0, 'orders_count' => 0]));
        self::assertSame(2, self::call('wppilot/woocommerce-list-customers', [])['total'], 'administrators are not customers');

        $customer = self::call('wppilot/woocommerce-get-customer', ['customer_id' => 20]);
        self::assertSame(['city' => 'Paris', 'email' => 'ada@example.test'], $customer['billing']);
        self::assertSame('42.00', $customer['total_spent']);
        self::assertSame('wc_not_found', self::code(self::call('wppilot/woocommerce-get-customer', ['customer_id' => 999])));
        self::assertSame(404, self::call('wppilot/woocommerce-get-customer', ['customer_id' => 0])->get_error_data()['status']);
    }

    // Editing.

    public function testEditChangesPriceAndStockThroughWooCommerce(): void
    {
        $this->seedCatalog();

        $result = self::call(self::EDIT, ['id' => 10, 'regular_price' => '12.50', 'sale_price' => null, 'stock_quantity' => 0]);

        self::assertTrue($result['success']);
        self::assertSame(['12.50', null, '12.50', 0, 'outofstock'], [
            $result['product']['regular_price'],
            $result['product']['sale_price'],
            $result['product']['price'],
            $result['product']['stock_quantity'],
            $result['product']['stock_status'],
        ]);
        self::assertSame('12.50', Store::$products[10]['regular_price']);

        Store::$products[13]['description'] = 'Holds 1\\2 litre';
        $renamed = self::call(self::EDIT, ['slug' => 'draft-mug', 'post_title' => "Mug\x07 \\o/", 'manage_stock' => true, 'stock_quantity' => 3]);
        self::assertSame('Mug \\o/', $renamed['product']['name'], 'control characters are stripped; a backslash survives the save');
        self::assertSame('Holds 1\\2 litre', Store::$products[13]['description'], 'so does one in a field the edit did not touch');
        self::assertSame([true, 3, 'instock'], [$renamed['product']['manage_stock'], $renamed['product']['stock_quantity'], $renamed['product']['stock_status']]);
    }

    public function testEditRefusesWhatTheBasicEditorDoesNotDo(): void
    {
        $this->seedCatalog();

        self::assertSame('wc_invalid_input', self::code(self::call(self::EDIT, ['id' => 11, 'regular_price' => '5'])), 'prices live on variations');
        self::assertSame('wc_invalid_input', self::code(self::call(self::EDIT, ['id' => 11, 'stock_quantity' => 5])), 'stock lives on variations');
        self::assertSame('wc_invalid_input', self::code(self::call(self::EDIT, ['id' => 10, 'sku' => 'NEW'])), 'unknown fields are refused, not ignored');
        self::assertSame('wc_invalid_input', self::code(self::call(self::EDIT, ['id' => 10, 'regular_price' => '1e3'])));
        self::assertSame('wc_invalid_input', self::code(self::call(self::EDIT, ['id' => 10, 'sale_price' => '-1'])));
        self::assertSame('wc_invalid_input', self::code(self::call(self::EDIT, ['id' => 10, 'manage_stock' => 'true'])));
        self::assertSame('wc_invalid_input', self::code(self::call(self::EDIT, ['id' => 10, 'stock_quantity' => 1.5])));
        self::assertSame('wc_invalid_input', self::code(self::call(self::EDIT, ['name' => 'x'])));
        self::assertSame('wc_not_found', self::code(self::call(self::EDIT, ['id' => 404, 'name' => 'x'])));
        self::assertSame('8', Store::$products[10]['sale_price'], 'nothing was saved');

        $renamed = self::call(self::EDIT, ['id' => 11, 'name' => 'Tee (organic)']);
        self::assertSame('Tee (organic)', $renamed['product']['name'], 'a variable product\'s name can be edited');
    }

    public function testEditAndUndoRoundTripKeepsBackslashes(): void
    {
        $this->seedCatalog();
        $original = Store::$products[10];
        $input = ['id' => 10, 'description' => 'New copy with C:\\new and \\"q\\"', 'sale_price' => ''];

        $before = (self::$capture)($input);
        self::assertSame(WooBasics\STRATEGY, $before['type']);
        self::assertSame(['description', 'regular_price', 'sale_price'], $before['fields']);
        self::assertArrayNotHasKey('name', $before['values'], 'only what the edit touches is kept');

        $edited = self::call(self::EDIT, $input);
        self::assertSame('New copy with C:\\new and \\"q\\"', $edited['product']['description'], 'the edit itself keeps backslashes');
        self::assertSame('New copy with C:\\new and \\"q\\"', Store::$posts[10]->post_content);

        // Someone renames it meanwhile; the undo must not rewind that.
        Store::$products[10]['name'] = 'Renamed since';

        $undo = (self::$strategies[WooBasics\STRATEGY])(['snapshot' => json_decode((string) json_encode($before), true)], []);

        self::assertTrue($undo['verified'], implode(',', $undo['mismatched']));
        self::assertSame($original['description'], Store::$products[10]['description']);
        self::assertSame('Path C:\\x and a \\"quote\\"', Store::$posts[10]->post_content);
        self::assertSame(['8', '8'], [Store::$products[10]['sale_price'], Store::$products[10]['price']]);
        self::assertSame('Renamed since', Store::$products[10]['name']);
        self::assertSame('Fits C:\\ drives', Store::$products[10]['short_description'], 'the untouched post field WooCommerce resends keeps its backslash');
    }

    public function testUndoPutsStockBackIncludingWhatWooCommerceResets(): void
    {
        $this->seedCatalog();
        Store::$products[10]['backorders'] = 'notify';
        Store::$products[10]['low_stock_amount'] = 2;
        $input = ['id' => 10, 'manage_stock' => false];
        $before = (self::$capture)($input);

        self::call(self::EDIT, $input);
        self::assertSame([null, 'no'], [Store::$products[10]['stock_quantity'], Store::$products[10]['backorders']]);

        $undo = (self::$strategies[WooBasics\STRATEGY])(['snapshot' => $before], []);

        self::assertTrue($undo['verified']);
        self::assertSame([true, 4, 'notify', 2, 'instock'], [
            Store::$products[10]['manage_stock'],
            Store::$products[10]['stock_quantity'],
            Store::$products[10]['backorders'],
            Store::$products[10]['low_stock_amount'],
            Store::$products[10]['stock_status'],
        ]);
    }

    public function testUndoInsideSavePostDoesNotDoubleSlash(): void
    {
        $this->seedCatalog();
        $before = (self::$capture)(['id' => 10, 'name' => 'x']);
        Store::$products[10]['name'] = 'changed';
        Store::$products[10]['description'] = 'untouched \\ here';
        $before['values']['name'] = 'Back\\slash';
        Store::$doingSavePost = true;

        $undo = (self::$strategies[WooBasics\STRATEGY])(['snapshot' => $before], []);

        self::assertTrue($undo['verified']);
        self::assertSame('Back\\slash', Store::$products[10]['name']);
    }

    public function testSessionStateIsTheEditedFieldsNowAndARedoGoesBackThroughTheUndo(): void
    {
        $this->seedCatalog();
        $input = ['id' => 10, 'regular_price' => '21'];
        $before = (self::$capture)($input);
        self::call(self::EDIT, $input);
        $restore = self::$strategies[WooBasics\STRATEGY];

        $after = WooBasics\current_state($before);
        self::assertSame(WooBasics\STRATEGY, $after['type']);
        self::assertSame($before['fields'], $after['fields'], 'the same fields as the before-image, so the two compare');
        self::assertSame('21', $after['values']['regular_price']);
        self::assertSame('10:regular_price,sale_price', WooBasics\state_target($before));

        self::assertTrue($restore(['snapshot' => $before], [])['verified']);
        self::assertSame($before['values'], WooBasics\current_state($before)['values'], 'after the undo the state is the before-image again');

        $redo = $restore(['snapshot' => $after], []);
        self::assertTrue($redo['verified'], 'a redo is the undo run with the state the undo replaced');
        self::assertSame('21', Store::$products[10]['regular_price']);

        Store::$posts[10]->post_status = 'trash';
        self::assertSame(['type' => 'absent'], WooBasics\current_state($before), 'a trashed product is gone, which is a conflict');
        self::assertNull(WooBasics\current_state(['product_id' => 10, 'fields' => []]));
        self::assertSame('', WooBasics\state_target(['product_id' => 0, 'fields' => ['name']]));
    }

    public function testUndoRefusesAGoneOrRetypedProduct(): void
    {
        $this->seedCatalog();
        $before = (self::$capture)(['id' => 10, 'regular_price' => '1']);
        $restore = self::$strategies[WooBasics\STRATEGY];

        Store::$products[10]['type'] = 'variable';
        self::assertSame('kit_woo_product_type_changed', self::code($restore(['snapshot' => $before], [])));

        Store::$products[10]['type'] = 'simple';
        Store::$posts[10]->post_status = 'trash';
        self::assertSame('kit_woo_product_gone', self::code($restore(['snapshot' => $before], [])));

        unset(Store::$products[10], Store::$posts[10]);
        self::assertSame('kit_woo_product_gone', self::code($restore(['snapshot' => $before], [])));
        self::assertSame('kit_woo_undo_payload', self::code($restore(['snapshot' => []], [])));
    }

    public function testNoBeforeImageWithoutAProductOrAField(): void
    {
        $this->seedCatalog();

        self::assertNull((self::$capture)(['id' => 404, 'name' => 'x']));
        self::assertNull((self::$capture)(['id' => 10]));
        self::assertSame(['name'], (self::$capture)(['slug' => 'linen-shirt', 'post_title' => 'x'])['fields']);
    }

    private static function host(): Host
    {
        $ledger = new class implements Ledger {
            public function capture_for(string $ability_name, callable $capture): void
            {
                WooBasicsTest::$captures[$ability_name] = $capture;
            }

            public function record_items(string $ability_name, array $items, ?string $group = null): array
            {
                return ['group' => 'g', 'change_ids' => [], 'without_before_image' => 0];
            }

            public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
            {
                WooBasicsTest::$strategies[$type] = $restore;
                return true;
            }

            public function query(array $filters = []): array
            {
                return [];
            }

            public function export_row(array $entry): array
            {
                return $entry;
            }

            public function snapshot_budget(): int
            {
                return 1_048_576;
            }

            public function download_url(): string
            {
                return '';
            }
        };
        return new class ($ledger) implements Host {
            public function __construct(private Ledger $ledger)
            {
            }

            public function id(): string
            {
                return 'test';
            }

            public function can_manage(): bool
            {
                return true;
            }

            public function is_enabled(): bool
            {
                return WooBasicsTest::$enabled;
            }

            public function safety_profile(): string
            {
                return 'production';
            }

            public function ledger(): Ledger
            {
                return $this->ledger;
            }

            public function jobs(): Jobs
            {
                throw new \LogicException('not used');
            }

            public function extension(string $point): mixed
            {
                return null;
            }

            public function admin_parent_slug(): string
            {
                return 'tools.php';
            }

            public function confirm_guard(string $ability_name, array $input): bool|WP_Error
            {
                return true;
            }
        };
    }
}
