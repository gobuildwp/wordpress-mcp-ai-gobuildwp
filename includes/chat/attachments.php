<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: image attachments on user messages.
 *
 * Validates and normalizes what the client sends, and rebuilds attachments
 * when replaying a session to the model.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @param mixed $params
 * @return list<array{id: string, name: string, mime_type: string, data: string, size: int}>|WP_Error
 */
function wppilot_chat_request_attachments(mixed $params): array|WP_Error
{
    if (!is_array($params) || !is_array($params['attachments'] ?? null)) {
        return [];
    }

    $attachments = [];
    $items = array_values($params['attachments']);
    if (count($items) > WPPILOT_CHAT_MAX_ATTACHMENTS) {
        return new WP_Error(
            'wppilot_chat_too_many_attachments',
            sprintf(
                /* translators: %d: Maximum number of attachments. */
                __('Attach up to %d images per message.', domain: 'wppilot'),
                WPPILOT_CHAT_MAX_ATTACHMENTS,
            ),
            ['status' => 400],
        );
    }

    // @mago-expect analysis:mixed-assignment
    foreach ($items as $item) {
        if (!is_array($item)) {
            return wppilot_chat_bad_attachment();
        }

        $attachment = wppilot_chat_normalize_attachment($item);
        if (is_wp_error($attachment)) {
            return $attachment;
        }
        $attachments[] = $attachment;
    }

    return $attachments;
}

/**
 * @param array<array-key, mixed> $item
 * @return array{id: string, name: string, mime_type: string, data: string, size: int}|WP_Error
 */
function wppilot_chat_normalize_attachment(array $item): array|WP_Error
{
    $name = is_string($item['name'] ?? null) ? sanitize_file_name($item['name']) : '';
    $mime_type = is_string($item['mime_type'] ?? null) ? strtolower(sanitize_mime_type($item['mime_type'])) : '';
    $data = is_string($item['data'] ?? null) ? trim($item['data']) : '';
    $size = is_numeric($item['size'] ?? null) ? (int) $item['size'] : 0;

    if ($name === '') {
        $name = __('Attached image', domain: 'wppilot');
    }

    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime_type, $allowed_mime_types, strict: true)) {
        return new WP_Error(
            'wppilot_chat_unsupported_attachment',
            __('Only JPEG, PNG, WebP, and GIF image attachments are supported.', domain: 'wppilot'),
            ['status' => 400],
        );
    }

    $parsed = wppilot_chat_parse_attachment_data($data, $mime_type, $size);
    if (is_wp_error($parsed)) {
        return $parsed;
    }

    return [
        'id' => (string) wp_generate_uuid4(),
        'name' => $name,
        'mime_type' => $mime_type,
        'data' => 'data:' . $mime_type . ';base64,' . $parsed['base64'],
        'size' => $parsed['size'],
    ];
}

/**
 * @return array{base64: string, size: int}|WP_Error
 */
function wppilot_chat_parse_attachment_data(string $data, string $mime_type, int $size): array|WP_Error
{
    $pattern = '#^data:(' . preg_quote($mime_type, delimiter: '#') . ');base64,([A-Za-z0-9+/]*={0,2})$#';
    $matches = null;
    // @mago-expect analysis:redundant-type-comparison
    if (preg_match($pattern, $data, $matches) !== 1 || !is_string($matches[2] ?? null)) {
        return wppilot_chat_bad_attachment();
    }

    $base64 = $matches[2];
    $decoded = base64_decode($base64, strict: true);
    if (!is_string($decoded)) {
        return wppilot_chat_bad_attachment();
    }

    $decoded_size = strlen($decoded);
    if ($decoded_size < 1 || $decoded_size > WPPILOT_CHAT_MAX_IMAGE_BYTES) {
        return wppilot_chat_attachment_too_large();
    }
    if ($size > 0 && $size !== $decoded_size) {
        return wppilot_chat_attachment_too_large();
    }

    return [
        'base64' => $base64,
        'size' => $decoded_size,
    ];
}

function wppilot_chat_attachment_too_large(): WP_Error
{
    return new WP_Error(
        'wppilot_chat_attachment_too_large',
        __('Each image attachment must be 3 MB or smaller.', domain: 'wppilot'),
        ['status' => 400],
    );
}

function wppilot_chat_bad_attachment(): WP_Error
{
    return new WP_Error('wppilot_chat_bad_attachment', __('Invalid image attachment.', domain: 'wppilot'), [
        'status' => 400,
    ]);
}

/**
 * @param array<string, mixed> $message
 * @return list<array{id: string, name: string, mime_type: string, data: string, size: int}>
 */
function wppilot_chat_message_attachments(array $message): array
{
    $items = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
    $attachments = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $attachment = wppilot_chat_message_attachment($item);
        if ($attachment === null) {
            continue;
        }
        $attachments[] = $attachment;
    }

    return $attachments;
}

/**
 * @param array<array-key, mixed> $item
 * @return array{id: string, name: string, mime_type: string, data: string, size: int}|null
 */
function wppilot_chat_message_attachment(array $item): ?array
{
    $name = is_string($item['name'] ?? null) ? $item['name'] : '';
    $mime_type = is_string($item['mime_type'] ?? null) ? $item['mime_type'] : '';
    $data = is_string($item['data'] ?? null) ? $item['data'] : '';
    $id = is_string($item['id'] ?? null) ? $item['id'] : '';
    $size = is_numeric($item['size'] ?? null) ? (int) $item['size'] : 0;
    if ($id === '' || $mime_type === '' || $data === '' || $size < 1) {
        return null;
    }

    return [
        'id' => $id,
        'name' => $name,
        'mime_type' => $mime_type,
        'data' => $data,
        'size' => $size,
    ];
}
