<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use Kit_Test_Site;
use PHPUnit\Framework\TestCase;
use WP_Ability;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/kit-site.php';

/**
 * The declarative ledger map: before-images and undo for abilities GoBuildWP did not write.
 *
 * Each test drives the ledger the way an ability execution does — the before hook,
 * the write, the after hook — then undoes the row, so what is proved is the
 * row the Changes screen would show and the undo it would offer.
 */
final class LedgerMapTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, mixed> */
    private array $savedFilters = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedFilters = $GLOBALS['wp_filter'] ?? [];
        unset(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION]);
        Kit_Test_Site::reset();
        // Another test may have cleared this hook; the map must be the only listener here.
        remove_all_filters('wppilot_capture_before_image');
        add_filter('wppilot_capture_before_image', 'wppilot_ledger_map_capture', priority: 20, accepted_args: 3);
        wppilot_ledger_map_register_strategies();
    }

    protected function tearDown(): void
    {
        Kit_Test_Site::reset();
        WPPilot_Test_State::$options = $this->savedOptions;
        $GLOBALS['wp_filter'] = $this->savedFilters;
    }

    public function testAnUnmappedThirdPartyWriteStillHasNoBeforeImage(): void
    {
        $row = $this->run_write('acme/unknown', ['id' => 1], static function (): void {});

        self::assertFalse($row['rollback']['reversible']);
        self::assertSame('No supported before-image.', $row['rollback']['reason']);
    }

    /**
     * A core-namespaced settings ability, declared through the filter, recorded and undone.
     * (WordPress 7.1's own core/* abilities are all reads, so the ability here is a double.)
     */
    public function testOptionStrategyCapturesAndRestoresIncludingAnOptionThatDidNotExist(): void
    {
        $this->map('core/update-reading-settings', ['strategy' => 'option', 'option' => ['posts_per_page', 'core_new_flag']]);
        WPPilot_Test_State::$options['posts_per_page'] = 10;

        $row = $this->run_write('core/update-reading-settings', ['posts_per_page' => 25], static function (): void {
            WPPilot_Test_State::$options['posts_per_page'] = 25;
            WPPilot_Test_State::$options['core_new_flag'] = 'on';
        });

        self::assertTrue($row['rollback']['reversible']);
        self::assertSame(WPPILOT_LEDGER_MAP_OPTION_TYPE, $row['rollback']['type']);

        $result = wppilot_rollback_change((string) $row['id']);
        self::assertIsArray($result);
        self::assertTrue($result['rolled_back']);
        self::assertSame(10, WPPilot_Test_State::$options['posts_per_page']);
        self::assertArrayNotHasKey('core_new_flag', WPPilot_Test_State::$options);
    }

    public function testTheShippedRankMathEntryIsAnOptionSnapshot(): void
    {
        WPPilot_Test_State::$options['rank-math-options-general'] = ['breadcrumbs' => 'off'];

        $before = wppilot_capture_before_image('rank-math/set-breadcrumb-settings', ['enabled' => true]);

        self::assertSame(WPPILOT_LEDGER_MAP_OPTION_TYPE, $before['type']);
        self::assertSame(['rank-math-options-general' => ['breadcrumbs' => 'off']], $before['values']);
    }

    public function testPostPartialRestoresOnlyTheDeclaredMetaKeys(): void
    {
        $post_id = Kit_Test_Site::insert(['post_title' => 'Hello', 'post_type' => 'post']);
        Kit_Test_Site::set_meta($post_id, '_seopress_titles_title', 'Old title');
        Kit_Test_Site::set_meta($post_id, 'unrelated', 'keep me');

        $row = $this->run_write(
            'seopress/update-post-title-description',
            ['post_id' => $post_id, 'title' => 'New', 'description' => 'Fresh'],
            static function () use ($post_id): void {
                update_post_meta($post_id, '_seopress_titles_title', 'New');
                update_post_meta($post_id, '_seopress_titles_desc', 'Fresh');
            },
        );
        self::assertTrue($row['rollback']['reversible']);
        self::assertSame(\WPPilot\Kits\Runtime\PostPartial\TYPE, $row['rollback']['type']);

        // A person edits something the ability never touched; the undo must leave it.
        update_post_meta($post_id, 'unrelated', 'edited since');

        $result = wppilot_rollback_change((string) $row['id']);
        self::assertIsArray($result);
        self::assertTrue($result['rolled_back']);
        self::assertSame('Old title', get_post_meta($post_id, '_seopress_titles_title', single: true));
        self::assertFalse(metadata_exists('post', $post_id, '_seopress_titles_desc'));
        self::assertSame('edited since', get_post_meta($post_id, 'unrelated', single: true));
    }

    /**
     * Elementor keeps global classes on its active kit post, which the input does not name: the
     * shipped entry reads the kit's ID from the `elementor_active_kit` option.
     */
    public function testAnElementorGlobalClassesWriteIsUndoneOnTheActiveKit(): void
    {
        $kit = Kit_Test_Site::insert(['post_title' => 'Default Kit', 'post_type' => 'elementor_library']);
        WPPilot_Test_State::$options['elementor_active_kit'] = (string) $kit;
        Kit_Test_Site::set_meta($kit, '_elementor_global_classes', '{"items":{"g-1":{"label":"hero"}}}');

        $row = $this->run_write('elementor/manage-classes', ['operations' => [['op' => 'create', 'label' => 'cta']]], static function () use ($kit): void {
            update_post_meta($kit, '_elementor_global_classes', '{"items":{"g-1":{"label":"hero"},"g-2":{"label":"cta"}}}');
        });
        self::assertTrue($row['rollback']['reversible']);

        $result = wppilot_rollback_change((string) $row['id']);
        self::assertIsArray($result);
        self::assertTrue($result['rolled_back']);
        self::assertSame('{"items":{"g-1":{"label":"hero"}}}', get_post_meta($kit, '_elementor_global_classes', single: true));
    }

    public function testAnElementorLayoutWriteSnapshotsTheDocumentMeta(): void
    {
        $page = Kit_Test_Site::insert(['post_title' => 'Home', 'post_type' => 'page']);
        Kit_Test_Site::set_meta($page, '_elementor_data', '[{"id":"a"}]');

        $row = $this->run_write('elementor/manage-elements', ['post_id' => $page, 'operations' => []], static function () use ($page): void {
            update_post_meta($page, '_elementor_data', '[{"id":"a"},{"id":"b"}]');
        });
        self::assertTrue($row['rollback']['reversible']);
        wppilot_rollback_change((string) $row['id']);
        self::assertSame('[{"id":"a"}]', get_post_meta($page, '_elementor_data', single: true));
    }

    public function testWooCommerceOrderWritesSayWhyTheyCannotBeUndone(): void
    {
        $row = $this->run_write('woocommerce/order-update-status', ['id' => 9, 'status' => 'completed'], static function (): void {});

        self::assertFalse($row['rollback']['reversible']);
        self::assertStringContainsString('email the customer', (string) $row['rollback']['reason']);
    }

    public function testATranslatePressLanguageChangeIsUndoneOnItsSettings(): void
    {
        WPPilot_Test_State::$options['trp_settings'] = ['default-language' => 'en_US', 'translation-languages' => ['en_US']];

        $row = $this->run_write('translatepress/add-language', ['language_code' => 'es_ES'], static function (): void {
            update_option('trp_settings', ['default-language' => 'en_US', 'translation-languages' => ['en_US', 'es_ES']]);
        });
        self::assertTrue($row['rollback']['reversible']);

        wppilot_rollback_change((string) $row['id']);
        self::assertSame(['en_US'], get_option('trp_settings')['translation-languages']);
    }

    public function testATranslatePressLicenceKeyIsNeverCopiedIntoTheLedger(): void
    {
        $row = $this->run_write('translatepress/set-license-key', ['license_key' => 'secret-key'], static function (): void {});

        self::assertFalse($row['rollback']['reversible']);
        self::assertStringContainsString('secret', (string) $row['rollback']['reason']);
    }

    public function testAnEddProductUpdateIsUndoneAndItsOrderWritesSayWhyNot(): void
    {
        $download = Kit_Test_Site::insert(['post_title' => 'E-book', 'post_type' => 'download']);
        Kit_Test_Site::set_meta($download, 'edd_price', '9.00');

        $row = $this->run_write('edd/product-update', ['product_id' => $download, 'price' => '12.00'], static function () use ($download): void {
            update_post_meta($download, 'edd_price', '12.00');
        });
        self::assertTrue($row['rollback']['reversible']);
        wppilot_rollback_change((string) $row['id']);
        self::assertSame('9.00', get_post_meta($download, 'edd_price', single: true));

        $order = $this->run_write('edd/order-update-status', ['order_id' => 3, 'status' => 'refunded'], static function (): void {});
        self::assertFalse($order['rollback']['reversible']);
        self::assertStringContainsString('own tables', (string) $order['rollback']['reason']);
    }

    public function testATargetMissingFromTheInputRecordsNoBeforeImage(): void
    {
        $row = $this->run_write('seopress/update-post-title-description', ['title' => 'x'], static function (): void {});

        self::assertFalse($row['rollback']['reversible']);
    }

    public function testIrreversibleEntriesStateTheirReason(): void
    {
        $this->map('acme/send-campaign', ['strategy' => 'irreversible', 'reason' => 'The emails were sent.']);

        $row = $this->run_write('acme/send-campaign', ['campaign' => 3], static function (): void {});

        self::assertFalse($row['rollback']['reversible']);
        self::assertSame('The emails were sent.', $row['rollback']['reason']);
    }

    public function testACredentialLookingOptionIsNeverCopiedIntoTheLedger(): void
    {
        $this->map('acme/save-keys', ['strategy' => 'option', 'option' => 'acme_api_key']);
        WPPilot_Test_State::$options['acme_api_key'] = 'sk-live-123';

        $row = $this->run_write('acme/save-keys', [], static function (): void {});

        self::assertFalse($row['rollback']['reversible']);
        self::assertStringNotContainsString('sk-live-123', (string) json_encode($row));
    }

    public function testIncompleteEntriesAreIgnored(): void
    {
        $this->map('acme/broken', ['strategy' => 'post']);
        $this->map('acme/unknown-strategy', ['strategy' => 'teleport', 'target' => 'input.id']);

        self::assertArrayNotHasKey('acme/broken', wppilot_ability_ledger_map());
        self::assertArrayNotHasKey('acme/unknown-strategy', wppilot_ability_ledger_map());
    }

    public function testACodeLevelCaptureWinsOverTheMap(): void
    {
        $this->map('acme/save', ['strategy' => 'irreversible', 'reason' => 'declared']);
        add_filter(
            'wppilot_capture_before_image',
            static fn(mixed $before, string $name): mixed => $name === 'acme/save' ? ['type' => 'acme/state'] : $before,
            accepted_args: 2,
        );

        self::assertSame(['type' => 'acme/state'], wppilot_capture_before_image('acme/save', []));
    }

    public function testNestedAndAlternativeTargetPaths(): void
    {
        self::assertSame(5, wppilot_ledger_map_target_id(['input.post.id', 'input.id'], ['post' => ['id' => 5]]));
        self::assertSame(8, wppilot_ledger_map_target_id(['input.post_id', 'id'], ['id' => '8']));
        self::assertSame(0, wppilot_ledger_map_target_id(['input.post_id'], ['post_id' => 'abc']));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function map(string $ability, array $entry): void
    {
        add_filter('wppilot_ability_ledger_map', static function (array $map) use ($ability, $entry): array {
            $map[$ability] = $entry;
            return $map;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function run_write(string $name, array $input, \Closure $write): array
    {
        $ability = new WP_Ability($name);
        wppilot_change_before($name, $input, $ability);
        $write();
        wppilot_change_after($name, $input, ['ok' => true], $ability);

        $log = wppilot_get_change_log();
        return end($log);
    }
}
