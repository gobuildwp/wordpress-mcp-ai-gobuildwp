<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\FormsBasics;

use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Contact Form 7 (5.8.0 and later), with its entries read from Flamingo.
 *
 * A form is a `wpcf7_contact_form` post. CF7 sends mail and forgets: it stores no submission
 * anywhere. Flamingo, the companion plugin by CF7's author, stores each one as a
 * `flamingo_inbound` post tagged with a `flamingo_inbound_channel` term that CF7 creates per form
 * and remembers in the form's `_flamingo` post meta; entries are read through
 * Flamingo_Inbound_Message::find() / count() (verified against Flamingo 2.6.4). Without Flamingo,
 * cf7-list-entries still answers, with what the user needs to know: there are no stored entries,
 * and what to install. The form list is the same answer GoBuildWP Pro 1.10.0's cf7-list-forms gives.
 */

const CF7_MIN_VERSION = '5.8.0';

const FLAMINGO_MIN_VERSION = '2.4';

function cf7_available(): bool
{
    return defined('WPCF7_VERSION')
        && class_exists('WPCF7_ContactForm')
        && version_compare((string) constant('WPCF7_VERSION'), CF7_MIN_VERSION, '>=');
}

function cf7_flamingo_available(): bool
{
    if (!class_exists('Flamingo_Inbound_Message')
        || !method_exists('Flamingo_Inbound_Message', 'find')
        || !method_exists('Flamingo_Inbound_Message', 'count')) {
        return false;
    }
    $version = defined('FLAMINGO_VERSION') ? (string) constant('FLAMINGO_VERSION') : '';
    return $version !== '' && version_compare($version, FLAMINGO_MIN_VERSION, '>=');
}

/**
 * @param array<string, mixed> $input
 * @return array{forms: list<array<string, mixed>>, total: int}
 */
function cf7_list_forms(array $input): array
{
    $limit = isset($input['limit']) ? max(1, min(500, (int) $input['limit'])) : 50;
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;
    $status_filter = (string) ($input['status'] ?? '');
    $locale_filter = (string) ($input['locale'] ?? '');
    $search = isset($input['search']) ? strtolower(trim((string) $input['search'])) : '';

    // An unrecognised status would make WP_Query drop the clause and return trashed forms too.
    if ($status_filter !== '' && !in_array($status_filter, ['publish', 'draft', 'pending', 'private', 'trash'], strict: true)) {
        return ['forms' => [], 'total' => 0];
    }

    // Light post rows first (status and locale filtered in SQL, the search in PHP), then full
    // form objects only for the page returned.
    $post_args = [
        'post_type' => 'wpcf7_contact_form',
        'post_status' => $status_filter !== '' ? $status_filter : ['publish', 'draft'],
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'orderby' => 'date',
        'order' => 'DESC',
    ];
    if ($locale_filter !== '') {
        $post_args['meta_query'] = [['key' => '_locale', 'value' => $locale_filter, 'compare' => '=']];
    }

    $matched = [];
    foreach (get_posts($post_args) as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }
        if ($search !== '' && !str_contains(strtolower($post->post_title . "\n" . $post->post_name), $search)) {
            continue;
        }
        $matched[] = (int) $post->ID;
    }

    $out = [];
    foreach (array_slice($matched, $offset, $limit) as $id) {
        $form = \WPCF7_ContactForm::get_instance($id);
        if ($form !== null) {
            $out[] = cf7_compact($form);
        }
    }
    return ['forms' => $out, 'total' => count($matched)];
}

/**
 * A form's summary without its template, mail settings and messages.
 *
 * @return array<string, mixed>
 */
function cf7_compact(object $form): array
{
    $post = get_post((int) $form->id());
    $template = (string) $form->prop('form');
    $found = preg_match_all('/\[(?!\/)([a-z][\w-]*)/i', $template);
    return [
        'id' => $form->id(),
        'title' => $form->title(),
        'slug' => $form->name(),
        'status' => $post instanceof WP_Post ? $post->post_status : 'publish',
        'locale' => $form->locale(),
        'hash' => $form->hash(),
        'tag_count' => $found === false ? 0 : $found,
        'modified' => $post instanceof WP_Post ? $post->post_modified_gmt : '',
    ];
}

function cf7_flamingo_inactive(): WP_Error
{
    if (defined('FLAMINGO_VERSION') && !cf7_flamingo_available()) {
        return new WP_Error('cf7_flamingo_inactive', sprintf(
            /* translators: 1: installed Flamingo version, 2: minimum supported version */
            __('Flamingo %1$s is active but older than %2$s, the oldest version this ability supports. Update Flamingo to read Contact Form 7 submissions.', domain: 'wppilot'),
            (string) constant('FLAMINGO_VERSION'),
            FLAMINGO_MIN_VERSION,
        ), ['status' => 424]);
    }
    return new WP_Error('cf7_flamingo_inactive', __(
        'Contact Form 7 does not store submissions — it only sends them by email. The Flamingo plugin (by the Contact Form 7 author: https://wordpress.org/plugins/flamingo/) is not active, so there are no entries to read. Install and activate Flamingo to store future submissions; ones sent before it was active exist only in the notification emails.',
        domain: 'wppilot',
    ), ['status' => 424]);
}

/**
 * The CF7 form for an id, rejecting trashed and unsaved ones.
 */
function cf7_form_or_error(int $id): object
{
    if ($id <= 0) {
        return new WP_Error('cf7_invalid_input', __('form_id must be a positive integer.', domain: 'wppilot'), ['status' => 400]);
    }
    $post = get_post($id);
    if (!$post instanceof WP_Post || $post->post_type !== 'wpcf7_contact_form' || in_array($post->post_status, ['trash', 'auto-draft', 'future', 'inherit'], strict: true)) {
        return new WP_Error('cf7_form_not_found', sprintf(
            /* translators: %d: form id */
            __('Contact Form 7 form with id %d not found.', domain: 'wppilot'),
            $id,
        ), ['status' => 404]);
    }
    $form = \WPCF7_ContactForm::get_instance($post);
    if ($form === null) {
        return new WP_Error('cf7_form_not_found', sprintf(
            /* translators: %d: form id */
            __('Contact Form 7 form with id %d could not be loaded.', domain: 'wppilot'),
            $id,
        ), ['status' => 404]);
    }
    return $form;
}

/**
 * The Flamingo channel CF7 records for a form, or 0 when the form has never stored a submission
 * (CF7 creates the channel on the first one). Forms older than the `_flamingo` meta are tagged by
 * their slug.
 */
function cf7_channel_id(object $form): int
{
    /** @var mixed $meta */
    $meta = get_post_meta((int) $form->id(), '_flamingo', true);
    if (is_array($meta) && isset($meta['channel']) && (int) $meta['channel'] > 0) {
        return (int) $meta['channel'];
    }
    /** @var mixed $term */
    $term = get_term_by('slug', (string) $form->name(), 'flamingo_inbound_channel');
    return is_object($term) && isset($term->term_id) ? (int) $term->term_id : 0;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function cf7_list_entries(array $input): array|WP_Error
{
    if (!cf7_flamingo_available()) {
        return cf7_flamingo_inactive();
    }
    // Flamingo's own Inbound Messages screen is gated on this capability (edit_users by default);
    // reading its messages here must not reach further than that screen.
    if (!current_user_can('flamingo_edit_inbound_messages')) {
        return new WP_Error('cf7_capability_missing', __("Current user lacks the 'flamingo_edit_inbound_messages' capability needed to read Flamingo messages.", domain: 'wppilot'), ['status' => 403]);
    }
    $form = cf7_form_or_error(is_numeric($input['form_id'] ?? null) ? (int) $input['form_id'] : 0);
    if ($form instanceof WP_Error) {
        return $form;
    }
    $from = date_bound($input['date_from'] ?? '', false);
    $to = date_bound($input['date_to'] ?? '', true);
    if ($from instanceof WP_Error) {
        return $from;
    }
    if ($to instanceof WP_Error) {
        return $to;
    }
    $limit = entries_limit($input['limit'] ?? null);
    $offset = entries_offset($input['offset'] ?? null);
    $result = ['form_id' => (int) $form->id(), 'total' => 0, 'limit' => $limit, 'offset' => $offset, 'redaction' => REDACTION_MODE, 'entries' => []];
    $channel = cf7_channel_id($form);
    if ($channel <= 0) {
        return $result;
    }

    $statuses = ['inbox' => 'publish', 'spam' => 'flamingo-spam', 'trash' => 'trash'];
    $status = is_string($input['status'] ?? null) ? $input['status'] : 'inbox';
    $args = [
        'channel_id' => $channel,
        'post_status' => $statuses[$status] ?? array_values($statuses),
        'orderby' => 'date',
        'order' => 'DESC',
        'posts_per_page' => $limit,
        'offset' => $offset,
    ];
    if ($from !== '' || $to !== '') {
        $args['date_query'] = [array_merge(
            $from !== '' ? ['after' => $from] : [],
            $to !== '' ? ['before' => $to] : [],
            ['inclusive' => true],
        )];
    }
    /** @var mixed $messages */
    $messages = \Flamingo_Inbound_Message::find($args);
    $total = (int) \Flamingo_Inbound_Message::count();

    $tags = cf7_tag_types($form);
    $entries = [];
    foreach (is_array($messages) ? $messages : [] as $message) {
        if (is_object($message)) {
            $entries[] = cf7_entry($message, $tags);
        }
    }
    return array_merge($result, ['total' => $total, 'entries' => $entries]);
}

/**
 * Each named form-tag's base type (email, tel, file, text…), by name. CF7 fields have no label
 * apart from the markup around them, so the tag name is the label, as in Flamingo's own export.
 *
 * @return array<string, string>
 */
function cf7_tag_types(object $form): array
{
    $types = [];
    /** @var mixed $tags */
    $tags = method_exists($form, 'scan_form_tags') ? $form->scan_form_tags() : [];
    foreach (is_array($tags) ? $tags : [] as $tag) {
        $name = is_object($tag) && is_string($tag->name ?? null) ? $tag->name : '';
        $type = is_object($tag) && is_string($tag->basetype ?? null) ? $tag->basetype : '';
        if ($name !== '' && !in_array($type, ['submit', 'captchac', 'captchar', 'quiz'], strict: true) && !isset($types[$name])) {
            $types[$name] = $type;
        }
    }
    return $types;
}

/**
 * One stored message: id, date, status, the subject with contacts cut out, and each field.
 *
 * @param array<string, string> $tags
 * @return array<string, mixed>
 */
function cf7_entry(object $message, array $tags): array
{
    $id = method_exists($message, 'id') ? (int) $message->id() : 0;
    $post = $id > 0 ? get_post($id) : null;
    $post_status = $post instanceof WP_Post ? $post->post_status : '';
    $answers = [];
    $fields = isset($message->fields) && is_array($message->fields) ? $message->fields : [];
    foreach ($fields as $name => $value) {
        $name = (string) $name;
        // A field the form no longer has keeps no type; its name still decides by label.
        $type = $tags[$name] ?? '';
        $answers[] = answer($name, $name, $type, $type, $value);
    }
    $subject = is_string($message->subject ?? null) ? $message->subject : '';
    return [
        'id' => $id,
        'date' => $post instanceof WP_Post ? $post->post_date : '',
        'status' => $post_status === 'publish' ? 'inbox' : ($post_status === 'flamingo-spam' ? 'spam' : $post_status),
        'subject' => cap(mask_contacts($subject)),
        'answers' => $answers,
    ];
}
