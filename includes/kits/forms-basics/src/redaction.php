<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\FormsBasics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What a submitted answer may leave the site as, for every form plugin this kit reads.
 *
 * Two layers. The first withholds what GoBuildWP Pro's form-entry rule withholds by default:
 * passwords, card and payment data, uploaded files and signatures, told apart by the field's type
 * and, for a plain text field someone labelled "Card number" or "Password", by its label. The
 * second is stricter than Pro, because these abilities answer an agent rather than a person asking
 * for their own submissions, and the agent needs to know that a visitor left a way to reach them,
 * not what it was: an email or phone field is withheld whole, and an email address or phone
 * number typed into any other answer is cut out of it. The submitter's IP is never returned.
 *
 * There is no way to ask for the withheld values here. GoBuildWP Pro's own entry abilities return
 * them to a person who explicitly asks, and the form plugin's admin screens always show them.
 */

const REDACTED = '[REDACTED]';

/** How entries are redacted, as each list-entries answer reports it. */
const REDACTION_MODE = 'contacts-and-sensitive-withheld';

/** Types whose value is a moment or a quantity, where a run of digits is not a phone number. */
const NUMERIC_TYPES = ['date', 'datepicker', 'datetime', 'date-time', 'time', 'timepicker', 'number', 'number-slider', 'currency', 'calculation', 'rating', 'slider', 'range', 'price', 'quantity', 'total', 'payment-total', 'product', 'shipping'];

/** Field types that hold a contact detail, withheld whole. */
const CONTACT_TYPES = ['email' => 'email', 'phone' => 'phone', 'tel' => 'phone'];

/**
 * Why a field's answers are withheld by GoBuildWP Pro's shared rule, or null when they are not.
 *
 * The same classification Pro's form-entry export uses (sensitive_reason() in its
 * form-entries module), carried here so both withhold exactly the same fields.
 */
function sensitive_reason(string $type, string $label = ''): ?string
{
    $normalised = str_replace(['-', '_'], '', strtolower($type));
    if (str_starts_with($normalised, 'password')) {
        return 'password';
    }
    if (
        str_contains($normalised, 'creditcard')
        || in_array($normalised, ['stripecreditcard', 'authorizenet', 'square', 'paypalcommerce', 'cc'], strict: true)
    ) {
        return 'credit_card';
    }
    if (in_array($normalised, ['file', 'fileupload', 'camera', 'postimage'], strict: true)) {
        return 'file_upload';
    }
    if ($normalised === 'signature') {
        return 'signature';
    }
    $label = strtolower($label);
    foreach (['password', 'passwd', 'card number', 'card_number', 'cardnumber', 'cvv', 'cvc', 'iban'] as $needle) {
        if ($label !== '' && str_contains($label, $needle)) {
            return str_contains($needle, 'pass') ? 'password' : 'credit_card';
        }
    }
    return null;
}

/**
 * Why a field is withheld whole in this kit, or null: Pro's rule, then contact fields by type,
 * then a free-text field whose label asks for an email address or a phone number.
 */
function withheld_reason(string $type, string $label): ?string
{
    $type = strtolower($type);
    if (isset(CONTACT_TYPES[$type])) {
        return CONTACT_TYPES[$type];
    }
    if ($type === 'ip') {
        return 'ip';
    }
    $reason = sensitive_reason($type, $label);
    if ($reason !== null) {
        return $reason;
    }
    if (preg_match('/\b(e-?mail|email address)\b/i', $label) === 1) {
        return 'email';
    }
    if (preg_match('/\b(phone|telephone|mobile|cell|whatsapp)\b/i', $label) === 1) {
        return 'phone';
    }
    return null;
}

/**
 * One answer as it may be returned.
 *
 * `$type` is the vendor's field type mapped onto the shared vocabulary by the caller (an upload is
 * `file`, a payment field `creditcard`); `$label` is what the form shows beside the field.
 *
 * @return array{value: mixed, redacted: string|null}
 */
function redact(string $type, string $label, mixed $value): array
{
    if (is_empty($value)) {
        return ['value' => $value, 'redacted' => null];
    }
    $reason = withheld_reason($type, $label);
    if ($reason !== null) {
        return ['value' => REDACTED, 'redacted' => $reason];
    }
    if (in_array(strtolower($type), NUMERIC_TYPES, strict: true)) {
        return ['value' => cap($value), 'redacted' => null];
    }
    $masked = mask_contacts($value);
    return ['value' => cap($masked), 'redacted' => $masked === $value ? null : 'contact_in_text'];
}

/**
 * Cut email addresses and phone numbers out of free text, recursively through arrays.
 */
function mask_contacts(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map(__NAMESPACE__ . '\\mask_contacts', $value);
    }
    if (!is_string($value)) {
        return $value;
    }
    $value = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', REDACTED, $value);
    // Seven or more digits, allowing the separators people type in a number. Shorter runs are
    // order numbers and years, which the agent may need.
    return (string) preg_replace_callback(
        '/\+?\(?\d[\d\s().\-]{5,}\d/',
        static fn(array $m): string => preg_match_all('/\d/', $m[0]) >= 7 ? REDACTED : $m[0],
        $value,
    );
}

/** Longest answer text returned, in bytes; a list of entries is for reading, not for export. */
const MAX_VALUE_BYTES = 2000;

/**
 * A string answer cut to MAX_VALUE_BYTES on a character boundary, recursively through arrays.
 */
function cap(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map(__NAMESPACE__ . '\\cap', $value);
    }
    if (!is_string($value) || strlen($value) <= MAX_VALUE_BYTES) {
        return $value;
    }
    return mb_strcut($value, 0, MAX_VALUE_BYTES, 'UTF-8') . '…';
}

function is_empty(mixed $value): bool
{
    return $value === null || $value === '' || $value === [];
}

/**
 * One entry of `answers`, as every list-entries ability returns it.
 *
 * @return array<string, mixed>
 */
function answer(string $field_id, string $label, string $type, string $redaction_type, mixed $value): array
{
    $redacted = redact($redaction_type, $label, $value);
    return array_merge(
        ['field_id' => $field_id, 'label' => $label, 'type' => $type, 'value' => $redacted['value']],
        $redacted['redacted'] !== null ? ['redacted' => $redacted['redacted']] : [],
    );
}
