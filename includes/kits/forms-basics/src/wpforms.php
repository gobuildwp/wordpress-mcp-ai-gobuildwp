<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\FormsBasics;

use WP_Error;
use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WPForms (Lite and Pro, 1.8.0 and later): the form list, and entries where they exist.
 *
 * A form is a `wpforms` post whose post_content is the whole definition as JSON. Entries exist
 * only on WPForms Pro, which keeps them in its own `wpforms_entries` table behind
 * wpforms()->obj('entry') (WPForms_Entry_Handler); Lite stores none, and says so rather than
 * listing nothing. The form list is the same answer GoBuildWP Pro 1.10.0's wpforms-list-forms gives.
 */

const WPFORMS_MIN_VERSION = '1.8.0';

const WPFORMS_LIST_LIMIT_MAX = 500;

const WPFORMS_LIST_LIMIT_DEFAULT = 50;

function wpforms_available(): bool
{
    return defined('WPFORMS_VERSION')
        && function_exists('wpforms')
        && version_compare((string) constant('WPFORMS_VERSION'), WPFORMS_MIN_VERSION, '>=');
}

/**
 * Whether WPForms Pro is installed: Lite and Pro share the plugin's code, and Pro is marked by its
 * `pro/wpforms-pro.php` bootstrap, as the WPForms loader itself decides.
 */
function wpforms_pro_active(): bool
{
    if (!wpforms_available()) {
        return false;
    }
    if (defined('WPFORMS_PLUGIN_SLUG') && (string) constant('WPFORMS_PLUGIN_SLUG') === 'wpforms') {
        return true;
    }
    return defined('WPFORMS_PLUGIN_DIR') && is_file((string) constant('WPFORMS_PLUGIN_DIR') . 'pro/wpforms-pro.php');
}

function wpforms_not_active(): WP_Error
{
    return new WP_Error('wpforms_not_active', 'WPForms is not active or does not meet the minimum supported version.', ['status' => 400]);
}

function wpforms_pro_required(string $feature): WP_Error
{
    return new WP_Error('wpforms_pro_required', "{$feature} requires WPForms Pro.", ['status' => 400]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function wpforms_list_forms(array $input): array|WP_Error
{
    if (!wpforms_available()) {
        return wpforms_not_active();
    }

    $limit = isset($input['limit'])
        ? max(1, min(WPFORMS_LIST_LIMIT_MAX, (int) $input['limit']))
        : WPFORMS_LIST_LIMIT_DEFAULT;
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;
    $status = $input['status'] ?? 'any';
    if ($status === 'any') {
        $status = ['publish', 'draft', 'pending', 'private'];
    }
    $orderby_map = ['id' => 'ID', 'title' => 'title', 'date' => 'date', 'modified' => 'modified'];
    $orderby = $orderby_map[$input['orderby'] ?? 'modified'] ?? 'modified';
    $order = strtoupper((string) ($input['order'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';

    $args = [
        'post_type' => 'wpforms',
        'post_status' => $status,
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => $orderby,
        'order' => $order,
        'no_found_rows' => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        // List the installed form inventory across languages, independent of frontend query filters.
        // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- This administrative inventory intentionally includes all languages.
        'suppress_filters' => true,
    ];
    if (isset($input['search']) && $input['search'] !== '') {
        $args['s'] = $input['search'];
    }

    $query = new WP_Query($args);
    $posts = array_values(array_filter($query->posts, static fn($p): bool => $p instanceof WP_Post));

    // One GROUP BY for every row's count; null on Lite, where there is no entries table.
    $counts = wpforms_entry_counts(array_map(static fn(WP_Post $p): int => (int) $p->ID, $posts));

    $forms = [];
    foreach ($posts as $post) {
        $forms[] = [
            'id' => (int) $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'date_created' => $post->post_date_gmt,
            'date_modified' => $post->post_modified_gmt,
            'entry_count' => $counts === null ? null : ($counts[(int) $post->ID] ?? 0),
        ];
    }

    return [
        'total' => (int) $query->found_posts,
        'limit' => $limit,
        'offset' => $offset,
        'forms' => $forms,
    ];
}

/**
 * Entry counts per form id in one query, or null where entries are unavailable (Lite).
 *
 * @param list<int> $form_ids
 * @return array<int, int>|null
 */
function wpforms_entry_counts(array $form_ids): ?array
{
    if (!wpforms_pro_active()) {
        return null;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $form_ids), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    /** @var mixed $rows */
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT form_id, COUNT(*) AS c FROM {$wpdb->prefix}wpforms_entries WHERE form_id IN ({$placeholders}) GROUP BY form_id",
        $ids,
    ));
    if (!is_array($rows)) {
        return null;
    }
    $out = array_fill_keys($ids, 0);
    foreach ($rows as $row) {
        if (is_object($row)) {
            $out[(int) $row->form_id] = (int) $row->c;
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function wpforms_list_entries(array $input): array|WP_Error
{
    if (!wpforms_available()) {
        return wpforms_not_active();
    }
    if (!wpforms_pro_active()) {
        return wpforms_pro_required('entries');
    }
    // A concrete form id, so WPForms' per-form `wpforms_user_can` checks always run: without one
    // the check is skipped and one call could read every form's entries.
    $form_id = is_numeric($input['form_id'] ?? null) ? (int) $input['form_id'] : 0;
    if ($form_id <= 0) {
        return new WP_Error('wpforms_invalid_input', 'form_id is required.', ['status' => 400]);
    }
    $form = get_post($form_id);
    if (!$form instanceof WP_Post || $form->post_type !== 'wpforms') {
        return new WP_Error('wpforms_form_not_found', "WPForms form not found: {$form_id}", ['status' => 404]);
    }
    if ($form->post_status === 'trash' || $form->post_status === 'auto-draft') {
        return new WP_Error('wpforms_form_unavailable', "WPForms form {$form_id} is in the {$form->post_status} state.", ['status' => 400]);
    }
    if (!function_exists('wpforms_current_user_can') || !\wpforms_current_user_can('wpforms_view_entries', $form_id)) {
        return new WP_Error('wpforms_capability_missing', "Current user lacks the required 'wpforms_view_entries' capability.", ['status' => 403]);
    }
    /** @var mixed $handler */
    $handler = \wpforms()->obj('entry');
    if (!is_object($handler) || !method_exists($handler, 'get_entries')) {
        return wpforms_pro_required('entries');
    }
    $after = date_bound($input['date_after'] ?? '', false);
    $before = date_bound($input['date_before'] ?? '', true);
    if ($after instanceof WP_Error) {
        return $after;
    }
    if ($before instanceof WP_Error) {
        return $before;
    }

    $limit = entries_limit($input['limit'] ?? null);
    $offset = entries_offset($input['offset'] ?? null);
    $args = ['form_id' => $form_id, 'number' => $limit, 'offset' => $offset];
    $status = (string) ($input['status'] ?? 'any');
    if ($status !== 'any') {
        // WPForms stores a normal entry with an empty status; "publish" is the name offered for it.
        $args['status'] = $status === 'publish' ? '' : $status;
    }
    if ($after !== '' || $before !== '') {
        // The handler takes `date` as [start, end]; it has no date_after / date_before keys.
        $args['date'] = [$after !== '' ? $after : '1970-01-01 00:00:00', $before !== '' ? $before : '2999-12-31 23:59:59'];
    }
    foreach (['starred', 'viewed'] as $flag) {
        if (isset($input[$flag]) && is_bool($input[$flag])) {
            $args[$flag] = $input[$flag] ? '1' : '0';
        }
    }

    /** @var mixed $raw_total */
    $raw_total = $handler->get_entries($args, true);
    /** @var mixed $rows */
    $rows = $handler->get_entries($args, false);

    $entries = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_object($row)) {
            $entries[] = wpforms_entry($row);
        }
    }
    return [
        'form_id' => $form_id,
        'total' => is_numeric($raw_total) ? (int) $raw_total : count($entries),
        'limit' => $limit,
        'offset' => $offset,
        'redaction' => REDACTION_MODE,
        'entries' => $entries,
    ];
}

/**
 * One entry row: the fields GoBuildWP Pro's compact row carries (no IP), and the answers from the
 * entry's `fields` JSON, redacted. WPForms stores each answer with its field's id, type and label
 * (`name`) at submission time.
 *
 * @return array<string, mixed>
 */
function wpforms_entry(object $entry): array
{
    $answers = [];
    $fields = is_string($entry->fields ?? null) ? json_decode($entry->fields, true) : null;
    foreach (is_array($fields) ? $fields : [] as $key => $field) {
        if (!is_array($field)) {
            continue;
        }
        $type = is_string($field['type'] ?? null) ? $field['type'] : '';
        $label = is_string($field['name'] ?? null) ? $field['name'] : '';
        $value = $field['value'] ?? '';
        $answers[] = answer((string) ($field['id'] ?? $key), $label, $type, $type, is_scalar($value) || is_array($value) ? $value : '');
    }
    return [
        'entry_id' => (int) ($entry->entry_id ?? 0),
        'form_id' => (int) ($entry->form_id ?? 0),
        'status' => (string) ($entry->status ?? ''),
        'type' => (string) ($entry->type ?? ''),
        'viewed' => (bool) (int) ($entry->viewed ?? 0),
        'starred' => (bool) (int) ($entry->starred ?? 0),
        'date' => (string) ($entry->date ?? ''),
        'date_modified' => (string) ($entry->date_modified ?? ''),
        'user_id' => (int) ($entry->user_id ?? 0),
        'answers' => $answers,
    ];
}
