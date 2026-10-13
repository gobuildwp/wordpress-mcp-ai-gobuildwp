<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\DbRead;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/database-tables', [
    'label' => __('List Database Tables', domain: 'wppilot'),
    'description' => __(
        'Lists this site\'s database tables (those carrying its table prefix) with the storage engine, an estimated row count, data and index sizes, and each column\'s name, type, nullability and key. Each table says whether wppilot/database-query may read it and, if not, why: users, usermeta, options, sitemeta, signups and WooCommerce API-key and payment-token tables are refused, as are views and other network sites\' tables. Columns whose values are redacted in query results are marked. Use it before writing a query; it reads information_schema for this database only and returns no row data. Developer profile only; each call is recorded in the change log.',
        domain: 'wppilot',
    ),
    'category' => 'diagnostics',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'search' => ['type' => 'string', 'description' => 'Only tables whose name contains this text.'],
            'include_columns' => ['type' => 'boolean', 'default' => true, 'description' => 'Include each table\'s columns.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'prefix' => ['type' => 'string'],
            'tables' => ['type' => 'array', 'items' => ['type' => 'object']],
            'count' => ['type' => 'integer'],
            'other_tables_hidden' => ['type' => 'integer'],
            'server' => ['type' => 'object'],
            'denied_tables' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static fn(array $input = []): array|WP_Error => list_tables($input),
    'permission_callback' => static fn(): bool|WP_Error => Runtime\can_run() ? Runtime\require_profile('wppilot/database-tables') : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        'safety' => ['min_profile' => 'developer', 'audit_reads' => true],
    ],
]);
