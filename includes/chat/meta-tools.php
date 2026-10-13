<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: the discovery meta-tools.
 *
 * Chat does not hand the model 1000 function declarations. It exposes a
 * small discovery pair the model calls to find abilities on demand, which
 * keeps the tool payload bounded regardless of how many abilities exist.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Declarations for the progressive-disclosure meta-tools.
 *
 * @return list<array{name: string, description: string, schema: array<string, mixed>}>
 */
function wppilot_chat_meta_tool_specs(): array
{
    return [
        [
            'name' => 'discover-abilities',
            'description' => 'Discover the WordPress abilities available in this system. Returns the list of ability names (which are descriptive, e.g. wppilot/update-post). Call this first to find the exact ability name for a task, then copy the name verbatim; use get-ability-info if a name is unclear.',
            'schema' => [
                'type' => 'object',
                'properties' => ['_' => ['type' => 'string', 'description' => 'Unused; pass an empty string.']],
            ],
        ],
        [
            'name' => 'get-ability-info',
            'description' => 'Get detailed information about one ability, including its description and full input schema, so you can build valid parameters for execute-ability.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'ability_name' => [
                        'type' => 'string',
                        'description' => 'The full ability name from discover-abilities, e.g. wppilot/update-post.',
                    ],
                ],
                'required' => ['ability_name'],
            ],
        ],
        [
            'name' => 'execute-ability',
            'description' => 'Execute one ability by its exact name with parameters matching its schema. Use only names returned by discover-abilities; do not construct names from categories. Prefer a specific, scoped ability over general code execution.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'ability_name' => [
                        'type' => 'string',
                        'description' => 'The exact ability name to execute, copied from discover-abilities.',
                    ],
                    'parameters' => [
                        'type' => 'object',
                        'description' => 'Parameters object matching the ability input schema.',
                    ],
                    'confirmation_reason' => [
                        'type' => 'string',
                        'description' => 'Required approval reason. A very short single-line question to the user, e.g. "May I update this post now?"',
                        'minLength' => 1,
                    ],
                ],
                'required' => ['ability_name', 'confirmation_reason'],
            ],
        ],
    ];
}

/**
 * The read-only discovery meta-tools (everything except execute-ability). These
 * execute server-side and never require approval.
 *
 * @return list<string>
 */
function wppilot_chat_discovery_meta_names(): array
{
    return ['discover-abilities', 'get-ability-info'];
}

function wppilot_chat_is_discovery_meta(string $function_name): bool
{
    return in_array($function_name, wppilot_chat_discovery_meta_names(), strict: true);
}

/**
 * Resolve a discovery meta-tool call against the ability catalog.
 *
 * @param array<string, mixed> $args
 * @param list<array<string, mixed>> $tools
 * @return array<string, mixed>
 */
function wppilot_chat_run_meta_discovery(string $function_name, array $args, array $tools): array
{
    if ($function_name === 'discover-abilities') {
        return wppilot_chat_meta_discover($tools);
    }
    if ($function_name === 'get-ability-info') {
        return wppilot_chat_meta_ability_info($args, $tools);
    }

    return ['error' => 'Unknown discovery tool.'];
}

/**
 * The discover-abilities result: the shared usage guidance plus a flat list of
 * ability names — the same delivery an MCP client gets (an MCP server carries no
 * init instructions; the guidance reaches the agent through discover-abilities).
 * Ability names follow a descriptive `plugin/action-object` convention, so names
 * alone are enough to pick one; get-ability-info returns the description and input
 * schema for a specific ability. Keeping the list name-only avoids resending
 * hundreds of full descriptions every turn.
 *
 * @param list<array<string, mixed>> $tools
 * @return array<string, mixed>
 */
function wppilot_chat_meta_discover(array $tools): array
{
    $abilities = [];
    foreach ($tools as $tool) {
        $name = (string) ($tool['name'] ?? '');
        if ($name !== '') {
            $abilities[] = $name;
        }
    }

    return [
        'wppilot_instructions' => wppilot_chat_server_instructions(),
        'abilities' => $abilities,
    ];
}

/**
 * @param array<string, mixed> $args
 * @param list<array<string, mixed>> $tools
 * @return array<string, mixed>
 */
function wppilot_chat_meta_ability_info(array $args, array $tools): array
{
    $name = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    $tool = wppilot_chat_find_tool($name, $tools);
    if ($tool === null) {
        return ['error' => sprintf('Unknown ability: %s. Call discover-abilities to get valid ability names.', $name)];
    }

    return [
        'ability_name' => $name,
        'description' => (string) ($tool['description'] ?? ''),
        'input_schema' => $tool['input_schema'] ?? null,
    ];
}

/**
 * Execute a read-only discovery meta-tool call (list categories/abilities, get schema).
 * No approval is needed; the result is stored and fed back to the model.
 *
 * @param array<string, mixed> $session
 * @return array<string, mixed>
 */
function wppilot_chat_execute_meta_call(array $session, int $call_index): array
{
    $tool_calls = wppilot_chat_session_list($session, key: 'tool_calls');
    $tool_call = $tool_calls[$call_index];
    $function_name = is_string($tool_call['function_name'] ?? null) ? $tool_call['function_name'] : '';
    $arguments = is_array($tool_call['arguments'] ?? null) ? wppilot_chat_assoc_array($tool_call['arguments']) : [];

    $result = wppilot_chat_run_meta_discovery($function_name, $arguments, wppilot_chat_discover_tools());

    $now = time();
    $tool_calls[$call_index]['status'] = 'succeeded';
    $tool_calls[$call_index]['result'] = $result;
    $tool_calls[$call_index]['updated_at'] = $now;
    $session['tool_calls'] = $tool_calls;
    $session['status'] = 'idle';
    $session['updated_at'] = $now;
    wppilot_chat_save_session($session);

    return ['session' => $session, 'tool_call' => $tool_calls[$call_index]];
}
