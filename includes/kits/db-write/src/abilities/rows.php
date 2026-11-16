<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\DbWrite;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

const COLUMN_VALUES = [
    'type' => 'object',
    'minProperties' => 1,
    'additionalProperties' => ['type' => ['string', 'number', 'integer', 'boolean', 'null']],
];

const TABLE_PROPERTY = [
    'type' => 'string',
    'pattern' => '^[A-Za-z0-9_]{1,64}$',
    'description' => 'Full table name with the site prefix, for example wp_myplugin_items. Core WordPress tables, this plugin\'s own tables and credential tables are refused.',
];

const WHERE_PROPERTY = COLUMN_VALUES + [
    'description' => 'column => value pairs, all of which must match (equality only; null matches IS NULL). Credential-like columns cannot be filtered on.',
];

const DRY_RUN_PROPERTY = [
    'type' => 'boolean',
    'default' => false,
    'description' => 'true to check the table and count the rows this would touch, without writing. Returns primary keys and column names, never values.',
];

wp_register_ability('wppilot/db-insert-row', [
    'label' => __('Insert a Database Row', domain: 'wppilot'),
    'description' => __(
        'Inserts one row into a plugin\'s own table (one with a single-column primary key), reads it back, and records it in the change log so it can be undone. Refused for WordPress core tables (posts, terms, comments, options, users and their meta: use the dedicated abilities), this plugin\'s own tables, credential tables, views and tables the site protects. Use wppilot/database-tables first to see the columns. Developer profile only.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'table' => TABLE_PROPERTY,
            'values' => COLUMN_VALUES + ['description' => 'column => value for the new row. Give the primary key unless the database fills it in.'],
            'dry_run' => DRY_RUN_PROPERTY,
            'confirm' => ['type' => 'boolean', 'description' => 'true once the user has approved inserting this row.'],
        ],
        'required' => ['table', 'values'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => insert_row($input),
    'permission_callback' => static fn(): bool|WP_Error => Runtime\can_run() ? Runtime\require_profile('wppilot/db-insert-row') : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
        'safety' => ['min_profile' => 'developer'],
    ],
]);

wp_register_ability('wppilot/db-update-rows', [
    'label' => __('Update Database Rows', domain: 'wppilot'),
    'description' => sprintf(
        /* translators: %d: most rows one call may change. */
        __(
            'Sets columns on the rows of a plugin\'s own table that match an equality where, at most %d rows. expected_count must equal the number of rows the where matches, or nothing is changed. Each row\'s previous values are recorded in the change log, and undoing it is refused if the row changed again since. The primary key cannot be set. Same table rules as wppilot/db-insert-row; run with dry_run first. Developer profile only.',
            domain: 'wppilot',
        ),
        MAX_UPDATE,
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'table' => TABLE_PROPERTY,
            'set' => COLUMN_VALUES + ['description' => 'column => new value.'],
            'where' => WHERE_PROPERTY,
            'expected_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_UPDATE, 'description' => 'How many rows the where matches. A different count changes nothing.'],
            'dry_run' => DRY_RUN_PROPERTY,
            'confirm' => ['type' => 'boolean', 'description' => 'true once the user has approved changing these rows.'],
        ],
        'required' => ['table', 'set', 'where', 'expected_count'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => update_rows($input),
    'permission_callback' => static fn(): bool|WP_Error => Runtime\can_run() ? Runtime\require_profile('wppilot/db-update-rows') : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
        'safety' => ['min_profile' => 'developer'],
    ],
]);

wp_register_ability('wppilot/db-delete-rows', [
    'label' => __('Delete Database Rows', domain: 'wppilot'),
    'description' => sprintf(
        /* translators: %d: most rows one call may delete. */
        __(
            'Deletes the rows of a plugin\'s own table that match an equality where, at most %d rows. expected_count must equal the number matched, or nothing is deleted. Each whole row is recorded in the change log first, so undoing it puts the row back with its primary key. Same table rules as wppilot/db-insert-row; run with dry_run first. Destructive: requires explicit confirmation. Developer profile only.',
            domain: 'wppilot',
        ),
        MAX_DELETE,
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'table' => TABLE_PROPERTY,
            'where' => WHERE_PROPERTY,
            'expected_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_DELETE, 'description' => 'How many rows the where matches. A different count deletes nothing.'],
            'dry_run' => DRY_RUN_PROPERTY,
            'confirm' => ['type' => 'boolean', 'description' => 'true once the user has approved deleting these rows.'],
        ],
        'required' => ['table', 'where', 'expected_count'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => delete_rows($input),
    'permission_callback' => static fn(): bool|WP_Error => Runtime\can_run() ? Runtime\require_profile('wppilot/db-delete-rows') : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
        'safety' => ['min_profile' => 'developer'],
    ],
]);
