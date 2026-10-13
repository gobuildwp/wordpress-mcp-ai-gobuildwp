<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The schema for an ability that takes no input.
 *
 * Declaring this is not the same as omitting `input_schema`, and the difference
 * is load-bearing. WP_Ability::execute() refuses any non-null input when no
 * schema is declared — `ability_missing_input_schema`, HTTP 400 — so a caller
 * that sends `{}` to mean "no arguments" is rejected for saying it. MCP clients
 * routinely send `arguments: {}` rather than omitting the field, which turned an
 * ordinary call into an error on every ability that accepted no input.
 *
 * With this declared, an empty object validates and an unexpected property is
 * still refused, which is the contract those abilities always meant.
 *
 * This lives in its own file, required by every ability bootstrap that uses it,
 * rather than sitting at the top of one of them: the WordPress-core module is
 * loaded directly by the unit-test bootstrap, without the sibling that would
 * otherwise define this, so a constant declared next to the loader would exist
 * in production and be undefined under test.
 *
 * @var array<string, mixed>
 */
const WPPILOT_NO_INPUT_SCHEMA = [
    'type' => 'object',
    'properties' => [],
    'additionalProperties' => false,
];
