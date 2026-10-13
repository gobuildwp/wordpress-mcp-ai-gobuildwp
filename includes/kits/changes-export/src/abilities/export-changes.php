<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ChangesExport;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/** Most rows one call returns inline. */
const MAX_ROWS = 500;

/** Most bytes of rows one call returns inline; a wide input can hit this before MAX_ROWS. */
const MAX_BYTES = 262_144;

/**
 * One page of the ledger, newest first, as flat export rows.
 *
 * Snapshots never leave: an export row carries what happened and whether it can be undone, not
 * the before-image, which is a full copy of the site's content. When a page fills, `next_offset`
 * says where the next call starts, and `download_url` names the screen a person can use to
 * download everything at once.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function export(array $input): array
{
    $ledger = Runtime\host()->ledger();
    $filters = array_filter(
        [
            'since' => (string) ($input['since'] ?? ''),
            'until' => (string) ($input['until'] ?? ''),
            'ability' => (string) ($input['ability'] ?? ''),
            'agent' => (string) ($input['agent'] ?? ''),
            'group' => (string) ($input['group'] ?? ''),
            'kind' => (string) ($input['kind'] ?? ''),
            'status' => (string) ($input['status'] ?? ''),
            'user_id' => (int) ($input['user_id'] ?? 0),
        ],
        static fn(mixed $value): bool => $value !== '' && $value !== 0,
    );
    $offset = max(0, (int) ($input['offset'] ?? 0));
    $limit = min(MAX_ROWS, max(1, (int) ($input['limit'] ?? MAX_ROWS)));
    // Paged in storage where the host can: loading every row to return one page would pull every
    // before-image on the site into memory.
    if ($ledger instanceof Runtime\PagedLedger) {
        $total = $ledger->count($filters);
        $slice = $ledger->query_page($filters, $limit, $offset);
    } else {
        $rows = $ledger->query($filters);
        $total = count($rows);
        $slice = array_slice($rows, $offset, $limit);
    }

    $page = [];
    $bytes = 0;
    foreach ($slice as $entry) {
        $row = $ledger->export_row($entry);
        $size = strlen((string) wp_json_encode($row));
        if ($page !== [] && $bytes + $size > MAX_BYTES) {
            break;
        }
        $bytes += $size;
        $page[] = $row;
    }
    $next = $offset + count($page);

    return [
        'changes' => $page,
        'count' => count($page),
        'total' => $total,
        'offset' => $offset,
        'next_offset' => $next < $total ? $next : null,
        'truncated' => $next < $total,
        'download_url' => $ledger->download_url(),
    ];
}

wp_register_ability('wppilot/export-changes', [
    'label' => __('Export Changes', domain: 'wppilot'),
    'description' => __(
        'Exports the change ledger newest first as flat rows: when, which ability, who and which agent, the batch it belonged to, whether it can still be undone and why not, and the redacted input. Before-images are never included. Filter by since/until (dates or ISO times, UTC), ability prefix, agent, user_id, group, kind (change or audit-read) and status (undoable, rolled-back, not-reversible). Returns at most 500 rows or 256 KB per call; when truncated is true, call again with offset set to next_offset. Use it for client reports and audits; use list-changes to decide what to undo.',
        domain: 'wppilot',
    ),
    'category' => 'changes',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'since' => ['type' => 'string', 'description' => 'Earliest time, inclusive. A bare date means the start of that day, UTC.'],
            'until' => ['type' => 'string', 'description' => 'Latest time, inclusive. A bare date means the end of that day, UTC.'],
            'ability' => ['type' => 'string', 'description' => 'Ability name or prefix, e.g. wppilot/update-.'],
            'agent' => ['type' => 'string', 'description' => 'Connection label or client name (substring), or a credential key (exact).'],
            'user_id' => ['type' => 'integer', 'minimum' => 1],
            'group' => ['type' => 'string', 'description' => 'One bulk call: the group id its rows share.'],
            'kind' => ['type' => 'string', 'enum' => ['change', 'audit-read']],
            'status' => ['type' => 'string', 'enum' => ['undoable', 'rolled-back', 'not-reversible']],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_ROWS, 'default' => MAX_ROWS],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'changes' => ['type' => 'array', 'items' => ['type' => 'object']],
            'count' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'offset' => ['type' => 'integer'],
            'next_offset' => ['type' => ['integer', 'null']],
            'truncated' => ['type' => 'boolean'],
            'download_url' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static fn(array $input = []): array => export($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
