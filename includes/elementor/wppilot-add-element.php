<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor: the add-element ability.
 *
 * The implementation is split by concern in the add-element/ directory beside
 * this file; callers require only this one file:
 *
 *   wppilot-ability.php                   entry points and the shared insert-and-write path
 *   wppilot-input-parsing.php             parsing and validating ability input
 *   wppilot-boxed-containers.php          splitting a boxed container into outer and inner
 *   wppilot-v3-container-translation.php  translating v3 container settings to v4
 *   wppilot-style-variants.php            style variants across breakpoints
 *   wppilot-style-classes.php             style class ids on an element
 *   wppilot-dynamic-tags.php              dynamic tag values in element settings
 *   wppilot-error-responses.php           building the error response
 *
 * Every symbol is a plain namespaced function or constant, so load order
 * does not matter.
 */

/*
 * The registration lives here rather than in add-element/wppilot-ability.php
 * because this file is the module's only entry point — the loader requires it
 * and nothing else in the directory. It was missing entirely: every function
 * below was implemented, loaded and reachable in PHP, and no MCP client could
 * call any of it, while wppilot/elementor-get-schema and
 * wppilot/elementor-edit-element both told agents to use it by name.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/add-element/wppilot-ability.php';
require_once __DIR__ . '/add-element/wppilot-input-parsing.php';
require_once __DIR__ . '/add-element/wppilot-boxed-containers.php';
require_once __DIR__ . '/add-element/wppilot-v3-container-translation.php';
require_once __DIR__ . '/add-element/wppilot-style-variants.php';
require_once __DIR__ . '/add-element/wppilot-style-classes.php';
require_once __DIR__ . '/add-element/wppilot-dynamic-tags.php';
require_once __DIR__ . '/add-element/wppilot-error-responses.php';

wp_register_ability('wppilot/elementor-add-element', [
    'label' => __('Add Elementor Element', domain: 'wppilot'),
    'description' => __(
        'Inserts a new widget or container into an Elementor document, or grafts a pre-built subtree onto it. Use this instead of reading the whole document, editing the tree by hand and writing it back with wppilot/elementor-set-content: only the new element is described, ids are generated, and the rest of the page is untouched. Four shapes: element_type="widget" with widget_type (a v3 widget such as heading, or a v4 atomic one such as e-heading); element_type="container" for a v3 container; element_type="e-flexbox" or "e-div-block" for a v4 atomic container; or tree={...} to insert a whole prepared subtree, whose ids are regenerated so it can be inserted repeatedly without collisions. parent_id selects the container to insert into and defaults to the document root; position is a zero-based index among that parent\'s children and defaults to the end. element_id sets a semantic id, which is what per-element style classes are named after, so it is worth setting on anything you intend to style later. Validation runs server-side: an unknown control id or an invalid enum value aborts the insert and returns the compact schema of the affected widget inline, so a correction is one roundtrip away.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Document to insert into.'],
            'element_type' => [
                'type' => 'string',
                'description' => 'widget | container | e-flexbox | e-div-block. Required unless "tree" is used.',
            ],
            'widget_type' => [
                'type' => 'string',
                'description' => 'Widget name when element_type is "widget", e.g. heading, image, e-heading.',
            ],
            'settings' => ['type' => 'object', 'description' => 'Settings for the new element.'],
            'styles' => ['type' => 'object', 'description' => 'Per-element styles; v4 atomic elements only.'],
            'tree' => [
                'type' => 'object',
                'description' => 'A complete element subtree to insert. Its ids are regenerated on insert.',
            ],
            'parent_id' => [
                'type' => 'string',
                'description' => 'Container to insert into. Omit for the document root.',
            ],
            'position' => [
                'type' => 'integer',
                'description' => 'Zero-based index among the parent\'s children. Omit to append.',
            ],
            'element_id' => [
                'type' => 'string',
                'description' => 'Semantic id for the new element. Generated when omitted.',
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'element_id' => ['type' => 'string'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_add_element',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => false,
            'destructive' => false,
            // Inserting the same element twice produces two elements.
            'idempotent' => false,
        ],
    ],
]);
