<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\Check;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Library;
use WPPilot\Design\Parser;
use WPPilot\Design\Preflight;
use WPPilot\Design\Store;

if (!defined('ABSPATH')) {
    exit();
}

// @mago-expect lint:halstead
function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/check-design', [
        'label' => __('Check Design (Pre-flight)', domain: 'wppilot'),
        'description' => __(
            'Pre-flight a candidate page (its HTML/CSS or visible text) against the active design\'s tokens and Don\'t rules plus universal anti-slop checks (em-dash, AI-purple, Inter, filler copy, off-palette fonts/colors). Call before finalizing any visual output and fix every "fail" before shipping.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'output' => [
                    'type' => 'string',
                    'description' => 'The candidate output to check: the HTML/CSS you built, or its visible text.',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Optional design slug to check against; defaults to the active design.',
                ],
            ],
            'required' => ['output'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'ok' => ['type' => 'boolean'],
                'active' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'violations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'rule' => ['type' => 'string'],
                            'severity' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                            'evidence' => ['type' => 'string'],
                        ],
                    ],
                ],
                'checked' => ['type' => 'array', 'items' => ['type' => 'string']],
                'not_checked' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['ok', 'violations'],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $output = Parser\unescape_content((string) ($input['output'] ?? ''));
            if (strlen($output) > Parser\MAX_BYTES) {
                return new WP_Error('too_large', __('Output exceeds the size limit.', domain: 'wppilot'));
            }

            $explicit_slug = ($input['slug'] ?? null) !== null && $input['slug'] !== '';
            $slug = $explicit_slug ? Parser\normalize_slug((string) $input['slug']) : Store\get_active_slug();
            $record = $slug !== '' ? Library\find($slug) : null;
            if ($explicit_slug && $record === null) {
                return new WP_Error('unknown_design', __('No design with that slug exists.', domain: 'wppilot'));
            }

            $ctx = Preflight\context($record !== null ? $record['content'] : null);
            $violations = Preflight\violations($output, $ctx);

            $ok = true;
            foreach ($violations as $violation) {
                if ($violation['severity'] !== 'fail') {
                    continue;
                }
                $ok = false;
                break;
            }

            // Distinctiveness is deliberately not checked here. It is a fact
            // about the design, not about this page, and this ability runs once
            // per section of a build: reporting "your palette resembles Anchor"
            // against every hero and every footer would be the same unactionable
            // note a dozen times, and would re-parse the whole design library to
            // produce it. It is reported once, where the design is proposed —
            // see save-design and get-design.

            return [
                'ok' => $ok,
                'active' => $ctx['has_active'],
                'slug' => $ctx['has_active'] ? $slug : '',
                'violations' => $violations,
                'checked' => Preflight\checked($ctx['has_active']),
                'not_checked' => Preflight\STRUCTURAL_NOT_CHECKED,
            ];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
