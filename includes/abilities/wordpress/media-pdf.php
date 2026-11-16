<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit();
}

require_once dirname(__DIR__, 2) . '/pdf-text.php';

register_core_ability('wppilot/read-pdf', [
    'label' => __('Read PDF', domain: 'wppilot'),
    'description' => __(
        'Returns the text of a PDF in the Media Library, page by page. Read-only. Up to 10 MB and 200 pages per call (start_page reads on). Cannot read encrypted PDFs, scanned pages or text drawn as images (no OCR), or text inside form fields and annotations; the notes say what was missed.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer', 'minimum' => 1],
            'start_page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'max_pages' => ['type' => 'integer', 'minimum' => 1, 'maximum' => WPPILOT_PDF_MAX_PAGES, 'default' => 50],
        ],
        'required' => ['attachment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_read_pdf',
    'permission_callback' => static fn(): bool => current_user_can('upload_files'),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_read_pdf(array $input): array|WP_Error
{
    $attachment_id = (int) $input['attachment_id'];
    $attachment = get_post($attachment_id);
    if (!$attachment instanceof WP_Post || $attachment->post_type !== 'attachment') {
        return new WP_Error('wppilot_media_not_found', __('Media attachment not found.', domain: 'wppilot'));
    }
    // upload_files lets a contributor list the library; the file itself may belong to a
    // private post they cannot read.
    if (!current_user_can('read_post', $attachment_id)) {
        return new WP_Error('wppilot_forbidden', __('You cannot read this attachment.', domain: 'wppilot'), ['status' => 403]);
    }
    if ($attachment->post_mime_type !== 'application/pdf') {
        return new WP_Error('wppilot_pdf_not_pdf', sprintf(
            /* translators: %s: MIME type */
            __('Attachment is %s, not a PDF.', domain: 'wppilot'),
            $attachment->post_mime_type,
        ));
    }
    $path = get_attached_file($attachment_id);
    if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
        return new WP_Error('wppilot_pdf_missing', __(
            'The PDF is not on this server\'s disk (it may be offloaded to cloud storage), so it cannot be read.',
            domain: 'wppilot',
        ));
    }
    $size = (int) filesize($path);
    if ($size > WPPILOT_PDF_MAX_BYTES) {
        return new WP_Error('wppilot_pdf_too_large', sprintf(
            /* translators: 1: file size in MB, 2: limit in MB */
            __('The PDF is %1$s MB; only files up to %2$d MB are read.', domain: 'wppilot'),
            number_format($size / 1048576, 1),
            intdiv(WPPILOT_PDF_MAX_BYTES, 1048576),
        ), ['status' => 413]);
    }
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) {
        return new WP_Error('wppilot_pdf_missing', __('The PDF could not be read from disk.', domain: 'wppilot'));
    }

    $result = \wppilot_pdf_extract_text(
        $bytes,
        (int) ($input['start_page'] ?? 1),
        (int) ($input['max_pages'] ?? 50),
    );
    if ($result instanceof WP_Error) {
        return $result;
    }

    return ['attachment_id' => $attachment_id, 'title' => $attachment->post_title, 'bytes' => $size] + $result;
}
