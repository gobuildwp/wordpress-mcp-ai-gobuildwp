<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/preview/store.php';

use function WPPilot\Abilities\WordPress\wordpress_update_site_settings;
use function WPPilot\Abilities\WordPress\wordpress_upsert_menu_item;
use function WPPilot\Abilities\WordPress\wordpress_validate_site_settings;
use function WPPilot\Preview\Store\decode_input;
use function WPPilot\Preview\Store\encode_input;

function regression_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

WPPilot_Test_State::reset();
WPPilot_Test_State::$options = [];

$exact = ['content' => str_repeat('x', 700), 'api_key' => 'secret-value', 'nested' => ['token' => 'abc']];
$payload = encode_input($exact);
regression_check(is_string($payload), 'preview inputs must encrypt');
regression_check(!str_contains($payload, 'secret-value'), 'encrypted preview payload leaked plaintext');
regression_check(decode_input($payload) === $exact, 'encrypted preview input did not round-trip exactly');

$envelope = json_decode($payload, true);
$envelope['ciphertext'][0] = $envelope['ciphertext'][0] === 'A' ? 'B' : 'A';
$tampered = decode_input((string) json_encode($envelope));
regression_check($tampered instanceof WP_Error, 'tampered preview input was accepted');

$id = '11111111-1111-4111-8111-111111111111';
WPPilot_Test_State::$options[\WPPilot\Preview\Store\option_name($id)] = [
    'preview_id' => $id,
    'status' => \WPPilot\Preview\Store\STATUS_APPLYING,
    'applying_at' => gmdate('c', time() - \WPPilot\Preview\Store\APPLY_STALE_SECONDS - 5),
];
WPPilot_Test_State::$options[\WPPilot\Preview\Store\LOCK_PREFIX . $id] = (string) (time() - 4000);
$recovered = \WPPilot\Preview\Store\get($id);
regression_check(($recovered['status'] ?? '') === \WPPilot\Preview\Store\STATUS_FAILED, 'stale apply was not failed');
regression_check(!isset(WPPilot_Test_State::$options[\WPPilot\Preview\Store\LOCK_PREFIX . $id]), 'stale lock survived');

WPPilot_Test_State::$options = [
    'blogname' => 'Original',
    'show_on_front' => 'posts',
    'page_on_front' => 0,
    'page_for_posts' => 0,
];
$invalid = wordpress_update_site_settings(['blogname' => 'Changed', 'page_on_front' => 404]);
regression_check($invalid instanceof WP_Error, 'missing front page was accepted');
regression_check(WPPilot_Test_State::$options['blogname'] === 'Original', 'settings partially wrote before validation');

$page = new WP_Post();
$page->ID = 21;
$page->post_type = 'page';
$page->post_status = 'publish';
WPPilot_Test_State::$posts[21] = $page;
regression_check(
    wordpress_validate_site_settings(['show_on_front' => 'page', 'page_on_front' => 21]) === null,
    'published front page was rejected',
);

$item = new WP_Post();
$item->ID = 33;
$item->post_type = 'nav_menu_item';
$item->post_status = 'draft';
$item->post_title = "Owner's link";
$item->menu_order = 7;
WPPilot_Test_State::$posts[33] = $item;
WPPilot_Test_State::$nav_menus = [4, 5];
WPPilot_Test_State::$nav_menu_memberships[33] = [4];
WPPilot_Test_State::$post_meta[33] = [
    '_menu_item_object_id' => ['12'],
    '_menu_item_object' => ['page'],
    '_menu_item_menu_item_parent' => ['0'],
    '_menu_item_type' => ['post_type'],
    '_menu_item_url' => ['https://example.test/original'],
    '_menu_item_target' => ['_blank'],
    '_menu_item_classes' => [['featured', 'nav-item']],
    '_menu_item_xfn' => ['friend'],
];

$updated = wordpress_upsert_menu_item(['menu_id' => 4, 'item_id' => 33, 'title' => 'Renamed']);
regression_check(is_array($updated), 'same-menu partial update failed');
$args = WPPilot_Test_State::$nav_menu_updates[0]['args'] ?? [];
regression_check(($args['menu-item-url'] ?? '') === 'https://example.test/original', 'partial update clobbered URL');
regression_check(($args['menu-item-status'] ?? '') === 'draft', 'partial update published a draft item');
regression_check(($args['menu-item-classes'] ?? '') === 'featured nav-item', 'classes were not passed as a string');

$wrong_menu = wordpress_upsert_menu_item(['menu_id' => 5, 'item_id' => 33, 'title' => 'Move']);
regression_check($wrong_menu instanceof WP_Error, 'cross-menu item update was accepted');
regression_check(count(WPPilot_Test_State::$nav_menu_updates) === 1, 'cross-menu refusal still wrote');

$registrations = array_values(array_filter(
    WPPILOT_TEST_BOOT_REGISTRATIONS,
    static fn(array $registration): bool => $registration['name'] === 'wppilot/upsert-menu-item',
));
$status_schema = $registrations[0]['args']['input_schema']['properties']['status'] ?? [];
regression_check(!array_key_exists('default', $status_schema), 'menu update schema still forces publish');

$rest_source = (string) file_get_contents(dirname(__DIR__) . '/includes/abilities/gutenberg/rest.php');
regression_check(
    str_contains($rest_source, 'get_batches([STATUS_READY, STATUS_FAILED, STATUS_RUNNING], posts_per_page: -1)'),
    'claim-next still scans only a bounded first page',
);

$transport_source = (string) file_get_contents(dirname(__DIR__) . '/includes/mcp/transport.php');
regression_check(
    // No closing parenthesis: the call also passes the elicitation context (includes/mcp/confirmation.php).
    str_contains($transport_source, "wppilot_gate_ability_call(\$ability, \$arguments, transport: 'mcp'"),
    'modern MCP transport does not run the shared gate pipeline',
);
// Chat called execute() directly until 1.14.0 and so skipped the rate limit,
// the design and preview gates and Pro's controls.
$chat_source = (string) file_get_contents(dirname(__DIR__) . '/includes/chat/rest.php');
regression_check(
    str_contains($chat_source, "transport: 'chat'")
        && !str_contains($chat_source, '$prepared[\'ability\']->execute($arguments)'),
    'Chat executes abilities without the shared gate pipeline',
);
foreach (['includes/rest/shim.php', 'includes/rest/core-run-gate.php'] as $gated_path) {
    $gated_source = (string) file_get_contents(dirname(__DIR__) . '/' . $gated_path);
    regression_check(
        str_contains($gated_source, "wppilot_gate_ability_call(\$ability, \$input, transport: 'rest')"),
        "{$gated_path} does not run the shared gate pipeline",
    );
}

fwrite(STDOUT, "Free regression smoke checks passed.\n");
