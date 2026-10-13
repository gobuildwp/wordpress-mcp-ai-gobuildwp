<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * A declarative ledger map for abilities GoBuildWP did not write.
 *
 * Builders, SEO plugins, WooCommerce and core now register their own abilities.
 * When an agent runs one through GoBuildWP it already passes the safety profile,
 * the confirmation contract and the Hub rules, but the ledger had nothing to
 * snapshot, so every such write was filed "No supported before-image" and could
 * not be undone. Writing a PHP capture callback per third-party ability does not
 * scale, and most of them change something the ledger already knows how to
 * restore: a post, a few meta keys, an option, a term.
 *
 * So a map says which, per ability name:
 *
 *     add_filter('wppilot_ability_ledger_map', function (array $map): array {
 *         $map['acme/update-headline'] = [
 *             'strategy'  => 'post-partial',
 *             'target'    => 'input.post_id',          // or a list of paths, first non-empty wins;
 *                                                        // 'option.name' reads an ID from a site option
 *             'fields'    => ['post_title'],
 *             'meta_keys' => ['_acme_headline'],
 *         ];
 *         $map['acme/save-settings'] = ['strategy' => 'option', 'option' => 'acme_settings'];
 *         $map['acme/rename-tag']    = ['strategy' => 'term', 'target' => 'input.term_id', 'taxonomy' => 'post_tag'];
 *         $map['acme/send-campaign'] = ['strategy' => 'irreversible', 'reason' => 'Emails were sent.'];
 *         return $map;
 *     });
 *
 * - `post` keeps a whole-post before-image (fields, meta, terms) and restores it all.
 * - `post-partial` keeps only the named `fields` and `meta_keys`, so an undo a week
 *   later does not also revert what people edited since.
 * - `option` keeps one or more options (`option` is a name or a list); an option
 *   that did not exist is deleted again on undo.
 * - `term` keeps a term's name, slug, description and parent; `taxonomy` is a
 *   literal or an `input.` path.
 * - `irreversible` records the write with the stated `reason` instead of the
 *   generic one, so the Changes screen says why it cannot be undone.
 *
 * The map is read by a `wppilot_capture_before_image` listener at priority 20, so
 * GoBuildWP's own captures and any code-level capture (priority 10) always win over
 * a declaration. Reads are never recorded, so a read-only ability needs no entry.
 */

if (!defined('ABSPATH')) {
    exit();
}

// The partial post snapshot and its restore live in the kit runtime; required here
// so the map works whether or not any kit has booted.
require_once __DIR__ . '/kits/_runtime/ledger/post-partial.php';

const WPPILOT_LEDGER_MAP_OPTION_TYPE = 'ledger-map/option';

const WPPILOT_LEDGER_MAP_IRREVERSIBLE_TYPE = 'ledger-map/irreversible';

/**
 * The map GoBuildWP ships.
 *
 * WordPress 7.1 core registers three abilities — `core/get-site-info`,
 * `core/get-user-info` and `core/get-environment-info` — and all three are
 * read-only, so core needs no entry: the ledger does not record reads. What is
 * here is third-party writes whose storage was read from the plugin's own source,
 * never guessed at:
 *
 * - Rank Math's settings abilities each rewrite one of its three option arrays
 *   through Helper::update_all_settings(). `set-homepage-seo` is left out: it
 *   writes post meta or an option depending on show_on_front, which one static
 *   entry cannot describe honestly.
 * - SEOPress `update-post-title-description` writes exactly two post meta keys.
 *   Its robots sibling is left out because it can also rewrite post_modified
 *   directly in the database, which a meta snapshot would not put back.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_default_ability_ledger_map(): array
{
    $rank_math = [
        'rank-math/set-breadcrumb-settings' => 'rank-math-options-general',
        'rank-math/set-link-settings' => 'rank-math-options-general',
        'rank-math/set-global-seo-settings' => 'rank-math-options-titles',
        'rank-math/set-post-type-seo-settings' => 'rank-math-options-titles',
        'rank-math/set-website-identity' => 'rank-math-options-titles',
        'rank-math/set-sitemap-settings' => 'rank-math-options-sitemap',
    ];

    $map = [];
    foreach ($rank_math as $ability => $option) {
        $map[$ability] = ['strategy' => 'option', 'option' => $option];
    }

    $map['seopress/update-post-title-description'] = [
        'strategy' => 'post-partial',
        'target' => 'input.post_id',
        'meta_keys' => ['_seopress_titles_title', '_seopress_titles_desc'],
    ];

    // Elementor 4.3's own MCP abilities (modules/mcp). A document's layout lives in four meta
    // keys; global classes and variables in meta of the active kit, whose ID is an option.
    $document = ['_elementor_data', '_elementor_page_settings', '_elementor_edit_mode', '_elementor_version'];
    foreach (['elementor/manage-elements', 'elementor/build-composition', 'elementor/update-page-settings'] as $ability) {
        $map[$ability] = ['strategy' => 'post-partial', 'target' => 'input.post_id', 'meta_keys' => $document];
    }
    $map['elementor/publish-document'] = ['strategy' => 'post-partial', 'target' => 'input.post_id', 'fields' => ['post_status', 'post_title', 'post_content'], 'meta_keys' => $document];
    $classes = ['_elementor_global_classes', '_elementor_global_classes_order', '_elementor_global_classes_labels', '_elementor_global_classes_preview', '_elementor_global_classes_order_preview', '_elementor_global_classes_labels_preview'];
    $map['elementor/manage-classes'] = ['strategy' => 'post-partial', 'target' => 'option.elementor_active_kit', 'meta_keys' => $classes];
    $map['elementor/reorder-classes'] = ['strategy' => 'post-partial', 'target' => 'option.elementor_active_kit', 'meta_keys' => $classes];
    $map['elementor/manage-global-variable'] = ['strategy' => 'post-partial', 'target' => 'option.elementor_active_kit', 'meta_keys' => ['_elementor_global_variables']];
    $map['elementor/create-page'] = ['strategy' => 'irreversible', 'reason' => 'Elementor created a new page. Undo by trashing it.'];
    $map['elementor/manage-component'] = ['strategy' => 'irreversible', 'reason' => 'Elementor components span several posts; GoBuildWP cannot put one back as it was.'];
    $map['elementor/manage-default-styles'] = ['strategy' => 'irreversible', 'reason' => 'Elementor stores default styles where GoBuildWP cannot snapshot them.'];

    // WooCommerce 10.3's own abilities. A product is a post with meta and terms; an order is not
    // always a post (HPOS), and changing its status or adding a note can email the customer.
    $map['woocommerce/product-update'] = ['strategy' => 'post', 'target' => 'input.id'];
    $map['woocommerce/product-delete'] = ['strategy' => 'post', 'target' => 'input.id'];
    $map['woocommerce/product-create'] = ['strategy' => 'irreversible', 'reason' => 'WooCommerce created a new product. Undo by trashing it.'];
    $map['woocommerce/order-update-status'] = ['strategy' => 'irreversible', 'reason' => 'An order status change can email the customer and move stock; set the status back by hand if needed.'];
    $map['woocommerce/order-add-note'] = ['strategy' => 'irreversible', 'reason' => 'An order note may already have been emailed to the customer.'];

    // TranslatePress 3.3's own abilities (includes/class-abilities.php) rewrite its settings arrays.
    foreach (['translatepress/add-language', 'translatepress/remove-language', 'translatepress/update-language', 'translatepress/set-default-language'] as $ability) {
        $map[$ability] = ['strategy' => 'option', 'option' => 'trp_settings'];
    }
    $map['translatepress/enable-automatic-translation'] = ['strategy' => 'option', 'option' => 'trp_machine_translation_settings'];
    $map['translatepress/set-license-key'] = ['strategy' => 'irreversible', 'reason' => 'A licence key is a secret, so it is never copied into the change log. Re-enter the old key by hand.'];

    // Easy Digital Downloads 3.7's own abilities. A download is a post; orders, customers, notes
    // and discounts live in EDD's own tables, and receipts are emailed.
    $map['edd/product-update'] = ['strategy' => 'post', 'target' => 'input.product_id'];
    $map['edd/product-create'] = ['strategy' => 'irreversible', 'reason' => 'EDD created a new download. Undo by trashing it.'];
    $edd_tables = 'EDD keeps this in its own tables, which GoBuildWP does not snapshot. Change it back in Downloads.';
    foreach (['edd/order-create', 'edd/order-update-status', 'edd/customer-create', 'edd/customer-update', 'edd/discount-create', 'edd/discount-update', 'edd/discount-delete'] as $ability) {
        $map[$ability] = ['strategy' => 'irreversible', 'reason' => $edd_tables];
    }
    foreach (['edd/order-resend-receipt', 'edd/order-note-add', 'edd/customer-note-add'] as $ability) {
        $map[$ability] = ['strategy' => 'irreversible', 'reason' => 'An email or a note was sent or recorded; it cannot be taken back.'];
    }

    return $map;
}

/**
 * The effective map: the shipped defaults plus whatever `wppilot_ability_ledger_map` adds.
 *
 * Entries that do not describe a complete strategy are dropped rather than half
 * applied — a `post` entry with no target would otherwise snapshot post 0 and
 * record a reversible row that restores nothing.
 *
 * @return array<string, array{strategy: string, target: list<string>, fields: list<string>, meta_keys: list<string>, options: list<string>, taxonomy: string, reason: string}>
 */
function wppilot_ability_ledger_map(): array
{
    /**
     * Declare how the ledger captures and undoes a third-party ability.
     *
     * @param array<string, array<string, mixed>> $map Ability name => entry. See includes/ledger-map.php.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_ability_ledger_map', wppilot_default_ability_ledger_map());
    if (!is_array($filtered)) {
        return [];
    }

    $map = [];
    /** @var mixed $entry */
    foreach ($filtered as $ability => $entry) {
        if (!is_string($ability) || !is_array($entry)) {
            continue;
        }
        $normalized = wppilot_normalize_ledger_map_entry($entry);
        if ($normalized !== null) {
            $map[$ability] = $normalized;
        }
    }

    return $map;
}

/**
 * @param array<mixed> $entry
 * @return array{strategy: string, target: list<string>, fields: list<string>, meta_keys: list<string>, options: list<string>, taxonomy: string, reason: string}|null
 */
function wppilot_normalize_ledger_map_entry(array $entry): ?array
{
    $strategy = is_string($entry['strategy'] ?? null) ? $entry['strategy'] : '';
    $normalized = [
        'strategy' => $strategy,
        'target' => wppilot_ledger_map_strings($entry['target'] ?? []),
        'fields' => wppilot_ledger_map_strings($entry['fields'] ?? []),
        'meta_keys' => wppilot_ledger_map_strings($entry['meta_keys'] ?? []),
        'options' => wppilot_ledger_map_strings($entry['option'] ?? $entry['options'] ?? []),
        'taxonomy' => is_string($entry['taxonomy'] ?? null) ? $entry['taxonomy'] : '',
        'reason' => is_string($entry['reason'] ?? null) ? trim($entry['reason']) : '',
    ];

    $complete = match ($strategy) {
        'post' => $normalized['target'] !== [],
        'post-partial' => $normalized['target'] !== [] && ($normalized['fields'] !== [] || $normalized['meta_keys'] !== []),
        'term' => $normalized['target'] !== [] && $normalized['taxonomy'] !== '',
        'option' => $normalized['options'] !== [],
        'irreversible' => $normalized['reason'] !== '',
        default => false,
    };

    return $complete ? $normalized : null;
}

/**
 * @return list<string>
 */
function wppilot_ledger_map_strings(mixed $value): array
{
    if (is_string($value)) {
        $value = [$value];
    }
    if (!is_array($value)) {
        return [];
    }

    $strings = [];
    /** @var mixed $item */
    foreach ($value as $item) {
        if (is_string($item) && trim($item) !== '') {
            $strings[] = trim($item);
        }
    }

    return $strings;
}

/**
 * Read a value out of the ability input by an `input.a.b` path (the `input.` prefix is optional),
 * or out of a site option by `option.name`.
 *
 * @param array<string, mixed> $input
 */
function wppilot_ledger_map_resolve(string $path, array $input): mixed
{
    // `option.name` reads a site option instead, for writes to something the input does not name
    // but the site knows: Elementor's global classes live on the kit post `elementor_active_kit` names.
    if (str_starts_with($path, 'option.')) {
        return get_option(substr($path, offset: strlen('option.')), null);
    }
    $path = str_starts_with($path, 'input.') ? substr($path, offset: strlen('input.')) : $path;
    /** @var mixed $value */
    $value = $input;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        /** @var mixed $value */
        $value = $value[$segment];
    }

    return $value;
}

/**
 * The first positive integer id the entry's target paths find in the input, or 0.
 *
 * @param list<string>         $paths
 * @param array<string, mixed> $input
 */
function wppilot_ledger_map_target_id(array $paths, array $input): int
{
    foreach ($paths as $path) {
        $value = wppilot_ledger_map_resolve($path, $input);
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
            return (int) $value;
        }
    }

    return 0;
}

/**
 * `wppilot_capture_before_image` listener: take the before-image a map entry describes.
 *
 * Returns what an earlier listener supplied untouched. Returns null — the honest
 * "no before-image" — when the entry's target cannot be found, rather than a
 * snapshot of the wrong object.
 *
 * @param mixed $before
 * @param mixed $input
 * @return mixed
 */
function wppilot_ledger_map_capture(mixed $before, string $ability_name, mixed $input): mixed
{
    if ($before !== null) {
        return $before;
    }

    $entry = wppilot_ability_ledger_map()[$ability_name] ?? null;
    if ($entry === null) {
        return null;
    }

    /** @var array<string, mixed> $values */
    $values = is_array($input) ? $input : [];

    return match ($entry['strategy']) {
        'post' => wppilot_ledger_map_capture_post($entry, $values),
        'post-partial' => wppilot_ledger_map_capture_post_partial($entry, $values),
        'term' => wppilot_ledger_map_capture_term($entry, $values),
        'option' => wppilot_ledger_map_snapshot_options($entry['options']),
        'irreversible' => ['type' => WPPILOT_LEDGER_MAP_IRREVERSIBLE_TYPE, 'reason' => $entry['reason']],
        default => null,
    };
}

/**
 * @param array{target: list<string>} $entry
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function wppilot_ledger_map_capture_post(array $entry, array $input): ?array
{
    $post_id = wppilot_ledger_map_target_id($entry['target'], $input);

    return $post_id > 0 ? wppilot_snapshot_post($post_id) : null;
}

/**
 * @param array{target: list<string>, fields: list<string>, meta_keys: list<string>} $entry
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function wppilot_ledger_map_capture_post_partial(array $entry, array $input): ?array
{
    $post_id = wppilot_ledger_map_target_id($entry['target'], $input);
    if ($post_id <= 0) {
        return null;
    }

    // The same privacy rule as the whole-post snapshot: a key that looks like it holds a
    // credential is never copied into the ledger, which every GoBuildWP admin can read.
    $meta_keys = array_values(array_filter(
        $entry['meta_keys'],
        static fn(string $key): bool => !wppilot_change_key_is_sensitive($key),
    ));
    if ($entry['fields'] === [] && $meta_keys === []) {
        return null;
    }

    return \WPPilot\Kits\Runtime\PostPartial\capture($post_id, $entry['fields'], $meta_keys);
}

/**
 * @param array{target: list<string>, taxonomy: string} $entry
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function wppilot_ledger_map_capture_term(array $entry, array $input): ?array
{
    $term_id = wppilot_ledger_map_target_id($entry['target'], $input);
    $taxonomy = $entry['taxonomy'];
    if (str_starts_with($taxonomy, 'input.')) {
        $resolved = wppilot_ledger_map_resolve($taxonomy, $input);
        $taxonomy = is_string($resolved) ? $resolved : '';
    }

    return $term_id > 0 && $taxonomy !== '' ? wppilot_snapshot_term($term_id, $taxonomy) : null;
}

/**
 * Snapshot options, remembering which did not exist so the undo can remove them again.
 *
 * @param list<string> $options
 * @return array<string, mixed>|null
 */
function wppilot_ledger_map_snapshot_options(array $options): ?array
{
    $values = [];
    $absent = [];
    $missing = new stdClass();
    foreach (array_values(array_unique($options)) as $option) {
        if (wppilot_change_key_is_sensitive($option)) {
            // Snapshotting a secret would copy it into a log every admin can read and export.
            return [
                'type' => WPPILOT_LEDGER_MAP_IRREVERSIBLE_TYPE,
                'reason' => sprintf('The option "%s" looks like it holds a credential, so no copy of it was kept.', $option),
            ];
        }
        /** @var mixed $value */
        $value = get_option($option, $missing);
        if ($value === $missing) {
            $absent[] = $option;
            continue;
        }
        $values[$option] = $value;
    }

    if ($values === [] && $absent === []) {
        return null;
    }

    return [
        'type' => WPPILOT_LEDGER_MAP_OPTION_TYPE,
        'values' => $values,
        'absent' => $absent,
        'fingerprint' => wppilot_snapshot_fingerprint(['values' => $values, 'absent' => $absent]),
    ];
}

/**
 * Put the snapshotted options back and verify them by re-reading.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function wppilot_ledger_map_restore_options(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $absent = wppilot_string_list($snapshot['absent'] ?? null);
    if ($values === [] && $absent === []) {
        return new WP_Error('wppilot_rollback_empty', __('This change recorded no options to restore.', domain: 'wppilot'));
    }

    /** @var mixed $value */
    foreach ($values as $option => $value) {
        update_option($option, $value);
    }
    foreach ($absent as $option) {
        delete_option($option);
    }

    $observed = wppilot_ledger_map_snapshot_options(array_merge(array_keys($values), $absent));
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = is_array($observed) ? (string) ($observed['fingerprint'] ?? '') : '';

    return [
        'options' => array_merge(array_keys($values), $absent),
        'expected_fingerprint' => $expected,
        'observed_fingerprint' => $actual,
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/**
 * Register the restore paths the map's before-images name.
 *
 * `kits/post-partial` is normally registered when the kit runtime boots; it is
 * registered here too when missing, so a declared partial capture never becomes a
 * row whose undo reports an unknown strategy.
 */
function wppilot_ledger_map_register_strategies(): void
{
    wppilot_register_rollback_strategy(
        WPPILOT_LEDGER_MAP_OPTION_TYPE,
        static fn(array $payload): array|WP_Error => wppilot_ledger_map_restore_options($payload),
    );

    wppilot_register_rollback_strategy(
        WPPILOT_LEDGER_MAP_IRREVERSIBLE_TYPE,
        static fn(): WP_Error => new WP_Error(
            'wppilot_rollback_not_reversible',
            __('This change was declared irreversible.', domain: 'wppilot'),
        ),
        static fn(array $before): array => [
            'reversible' => false,
            'reason' => (string) ($before['reason'] ?? 'This change cannot be undone.'),
        ],
    );

    if (wppilot_get_rollback_strategy(\WPPilot\Kits\Runtime\PostPartial\TYPE) === null) {
        wppilot_register_rollback_strategy(
            \WPPilot\Kits\Runtime\PostPartial\TYPE,
            static fn(array $payload): array|WP_Error => \WPPilot\Kits\Runtime\PostPartial\restore($payload),
        );
    }
}

wppilot_ledger_map_register_strategies();

add_filter('wppilot_capture_before_image', callback: 'wppilot_ledger_map_capture', priority: 20, accepted_args: 3);
