<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\FormsBasics;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Forminator forms and their entries, through Forminator's own models (verified against
 * Forminator 1.57.3).
 *
 * A form is a `forminator_forms` post whose `forminator_form_meta` holds its fields and settings;
 * Forminator_Base_Form_Model::get_model() reads it the way Forminator's admin does. Entries live in
 * Forminator's own tables, read with Forminator_Form_Entry_Model::query_entries(). The form list is
 * the same answer GoBuildWP Pro 1.10.0's forminator-list-forms gives.
 */

const FORMINATOR_POST_TYPE = 'forminator_forms';

const FORMINATOR_LIST_DEFAULT = 20;

const FORMINATOR_LIST_MAX = 100;

/** Field types that hold no answer: layout, markup and anti-spam. */
const FORMINATOR_LAYOUT_TYPES = ['html', 'page-break', 'section', 'captcha', 'honeypot'];

/** Forminator field types mapped onto the redaction vocabulary. */
const FORMINATOR_TYPE_MAP = [
    'upload' => 'file',
    'stripe' => 'creditcard',
    'stripe-ocs' => 'creditcard',
    'paypal' => 'creditcard',
];

/**
 * Whether Forminator is installed and active, for the loader.
 *
 * Forminator loads its models (Forminator_Base_Form_Model, Forminator_Form_Entry_Model) on `init`,
 * after the kit loader decides which files to register on plugins_loaded, so only its API class,
 * which its main file requires, can be seen that early. forminator_available() checks the rest
 * when an ability runs.
 */
function forminator_installed(): bool
{
    return class_exists('Forminator_API');
}

function forminator_available(): bool
{
    return class_exists('Forminator_API') && class_exists('Forminator_Base_Form_Model') && class_exists('Forminator_Form_Entry_Model');
}

/**
 * The capability Forminator itself asks for on its admin screens.
 */
function forminator_capability(): string
{
    return function_exists('forminator_get_admin_cap') ? (string) forminator_get_admin_cap() : 'manage_options';
}

function forminator_unavailable(): WP_Error
{
    return new WP_Error('forminator_unavailable', __('Forminator is not active on this site.', domain: 'wppilot'));
}

/**
 * The form model for an id, or an error naming why there is none.
 */
function forminator_model(int $form_id): object
{
    if ($form_id <= 0 || get_post_type($form_id) !== FORMINATOR_POST_TYPE) {
        return new WP_Error('forminator_form_not_found', sprintf(
            /* translators: %d: form id */
            __('No Forminator form with id %d. Call wppilot/forminator-list-forms for the ids.', domain: 'wppilot'),
            $form_id,
        ));
    }
    /** @var mixed $model */
    $model = \Forminator_Base_Form_Model::get_model($form_id);
    if (!is_object($model)) {
        return new WP_Error('forminator_form_unreadable', __('Forminator could not load this form.', domain: 'wppilot'));
    }
    return $model;
}

/**
 * The name Forminator shows for a form: settings.formName. The post title is not it (a form made
 * through Forminator_API::add_form() gets a slug there), so it is only the fallback.
 */
function forminator_title(object $model): string
{
    $name = is_array($model->settings) ? (string) ($model->settings['formName'] ?? '') : '';
    return $name !== '' ? $name : (string) $model->name;
}

/**
 * A field's stored settings, through the model's public to_array().
 *
 * @return array<string, mixed>
 */
function forminator_field_data(object $field): array
{
    /** @var mixed $data */
    $data = method_exists($field, 'to_array') ? $field->to_array() : [];
    return is_array($data) ? $data : [];
}

/**
 * Fields that take an answer, in form order.
 *
 * @return list<object>
 */
function forminator_answer_fields(object $model): array
{
    $fields = [];
    foreach (is_array($model->fields) ? $model->fields : [] as $field) {
        if (!is_object($field)) {
            continue;
        }
        $type = (string) (forminator_field_data($field)['type'] ?? '');
        if ($type !== '' && !in_array($type, FORMINATOR_LAYOUT_TYPES, strict: true)) {
            $fields[] = $field;
        }
    }
    return $fields;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function forminator_list_forms(array $input): array|WP_Error
{
    if (!forminator_available()) {
        return forminator_unavailable();
    }
    $limit = max(1, min(FORMINATOR_LIST_MAX, (int) ($input['limit'] ?? FORMINATOR_LIST_DEFAULT)));
    $page = max(1, (int) ($input['page'] ?? 1));
    $status = (string) ($input['status'] ?? 'any');
    $status = in_array($status, ['publish', 'draft'], strict: true) ? $status : '';

    /** @var mixed $paged */
    $paged = \Forminator_Form_Model::model()->get_all_paged($page, $limit, $status);
    $models = is_array($paged['models'] ?? null) ? $paged['models'] : [];
    $forms = [];
    foreach ($models as $model) {
        if (!is_object($model)) {
            continue;
        }
        $id = (int) $model->id;
        $forms[] = [
            'id' => $id,
            'title' => forminator_title($model),
            'status' => (string) $model->status,
            'field_count' => count(forminator_answer_fields($model)),
            'entry_count' => (int) \Forminator_Form_Entry_Model::count_entries($id),
            'shortcode' => sprintf('[forminator_form id="%d"]', $id),
        ];
    }
    return [
        'total' => (int) ($paged['foundPosts'] ?? count($forms)),
        'page' => $page,
        'forms' => $forms,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function forminator_list_entries(array $input): array|WP_Error
{
    if (!forminator_available()) {
        return forminator_unavailable();
    }
    $model = forminator_model((int) ($input['form_id'] ?? 0));
    if ($model instanceof WP_Error) {
        return $model;
    }
    $from = forminator_date($input['date_from'] ?? '');
    $to = forminator_date($input['date_to'] ?? '');
    if ($from instanceof WP_Error) {
        return $from;
    }
    if ($to instanceof WP_Error) {
        return $to;
    }
    $limit = max(1, min(FORMINATOR_LIST_MAX, (int) ($input['limit'] ?? FORMINATOR_LIST_DEFAULT)));
    $offset = max(0, (int) ($input['offset'] ?? 0));

    $args = [
        'form_id' => (int) $model->id,
        'is_spam' => 0,
        'status' => 'active',
        'per_page' => $limit,
        'offset' => $offset,
        'order_by' => 'entries.date_created',
        'order' => 'DESC',
    ];
    if ($from !== '' || $to !== '') {
        // Forminator filters on both ends or neither; it widens the end date to 23:59 itself.
        $args['date_created'] = [$from !== '' ? $from : '1970-01-01', $to !== '' ? $to : '9999-12-31'];
    }
    /** @var mixed $result */
    $result = \Forminator_Form_Entry_Model::query_entries($args, true);
    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];

    $fields = [];
    foreach (forminator_answer_fields($model) as $field) {
        $data = forminator_field_data($field);
        $fields[(string) $field->slug] = ['type' => (string) ($data['type'] ?? ''), 'label' => (string) ($data['field_label'] ?? '')];
    }
    $entries = [];
    foreach ($rows as $entry) {
        if (is_object($entry)) {
            $entries[] = forminator_entry($entry, $fields);
        }
    }
    return [
        'form_id' => (int) $model->id,
        'total' => (int) ($result['count'] ?? count($entries)),
        'returned' => count($entries),
        'offset' => $offset,
        'redaction' => REDACTION_MODE,
        'entries' => $entries,
    ];
}

/**
 * @param array<string, array{type: string, label: string}> $fields
 * @return array<string, mixed>
 */
function forminator_entry(object $entry, array $fields): array
{
    $meta = is_array($entry->meta_data) ? $entry->meta_data : [];
    $answers = [];
    foreach ($fields as $slug => $field) {
        // Compound fields (name, address) store their parts as `<slug>` or `<slug>-<part>`.
        $value = null;
        foreach ($meta as $key => $row) {
            if ($key === $slug || str_starts_with((string) $key, $slug . '-')) {
                $part = is_array($row) ? ($row['value'] ?? null) : null;
                $value = $key === $slug ? $part : array_merge(is_array($value) ? $value : [], [(string) $key => $part]);
            }
        }
        if ($value === null) {
            continue;
        }
        $answers[] = answer($slug, $field['label'], $field['type'], FORMINATOR_TYPE_MAP[$field['type']] ?? $field['type'], $value);
    }
    $ip = is_array($meta['_forminator_user_ip'] ?? null) ? ($meta['_forminator_user_ip']['value'] ?? '') : '';
    return [
        'id' => (int) $entry->entry_id,
        'date_created' => (string) $entry->date_created_sql,
        'status' => (string) $entry->status,
        'answers' => $answers,
        'ip' => redact('ip', '', $ip)['value'],
    ];
}

/**
 * A bare YYYY-MM-DD in the site's timezone, as Forminator stores entry dates; '' when absent.
 */
function forminator_date(mixed $raw): string|WP_Error
{
    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }
    $raw = trim($raw);
    $date = \DateTime::createFromFormat('!Y-m-d', $raw);
    if ($date === false || $date->format('Y-m-d') !== $raw) {
        return new WP_Error('forminator_bad_date', sprintf(
            /* translators: %s: the rejected date */
            __('Unrecognised date "%s". Use YYYY-MM-DD.', domain: 'wppilot'),
            $raw,
        ));
    }
    return $raw;
}
