<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: the tool-call record and its approval lifecycle.
 *
 * A tool call moves pending -> approved/denied -> executed. The shape of
 * that record, and every transition on it, lives here.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @param array<string, mixed> $session
 * @param list<array<string, mixed>> $requested_calls
 * @param list<array<string, mixed>> $tools
 * @return list<array<string, mixed>>
 */
function wppilot_chat_build_tool_calls(
    array $session,
    array $requested_calls,
    array $tools,
    int $now,
    string $message_id,
): array {
    $created_calls = [];
    foreach ($requested_calls as $tool_call) {
        $created_calls[] = wppilot_chat_build_tool_call_record($session, $tool_call, $tools, $now, $message_id);
    }

    return $created_calls;
}

/**
 * Build one stored tool_call record from a model-requested call, translating the
 * progressive-disclosure meta-tools into either a read-only discovery call or a
 * concrete ability execution keyed on the inner ability name.
 *
 * @param array<string, mixed> $session
 * @param array<string, mixed> $tool_call
 * @param list<array<string, mixed>> $tools
 * @return array<string, mixed>
 */
function wppilot_chat_build_tool_call_record(
    array $session,
    array $tool_call,
    array $tools,
    int $now,
    string $message_id,
): array {
    $function_name = is_string($tool_call['function_name'] ?? null)
        ? $tool_call['function_name']
        : (string) ($tool_call['name'] ?? '');
    $model_arguments = is_array($tool_call['arguments'] ?? null)
        ? wppilot_chat_assoc_array($tool_call['arguments'])
        : [];
    $base = [
        'id' => (string) ($tool_call['id'] ?? wp_generate_uuid4()),
        'message_id' => $message_id,
        'function_name' => $function_name,
        'model_arguments' => $model_arguments,
        'created_at' => $now,
        'updated_at' => $now,
        'result' => null,
        'error' => '',
    ];

    if (wppilot_chat_is_discovery_meta($function_name)) {
        return $base
        + [
            'kind' => 'meta',
            'ability' => $function_name,
            'arguments' => $model_arguments,
            'status' => 'approved',
            'risk' => wppilot_chat_readonly_risk(),
            'reason' => '',
        ];
    }

    return $base
    + wppilot_chat_resolve_ability_call(
        $session,
        $function_name,
        $model_arguments,
        $tools,
        (string) ($tool_call['name'] ?? ''),
    );
}

/**
 * Resolve the ability-execution fields for an execute-ability call (or a legacy direct
 * ability call), computing risk and approval from the inner ability name.
 *
 * @param array<string, mixed> $session
 * @param array<string, mixed> $model_arguments
 * @param list<array<string, mixed>> $tools
 * @return array{kind: string, ability: string, arguments: array<string, mixed>, status: string, risk: array<string, mixed>, reason: string}
 */
function wppilot_chat_resolve_ability_call(
    array $session,
    string $function_name,
    array $model_arguments,
    array $tools,
    string $parsed_name,
): array {
    if ($function_name === 'execute-ability') {
        $ability_name = is_string($model_arguments['ability_name'] ?? null) ? $model_arguments['ability_name'] : '';
        $arguments = is_array($model_arguments['parameters'] ?? null)
            ? wppilot_chat_assoc_array($model_arguments['parameters'])
            : [];

        return wppilot_chat_ability_call_fields(
            $session,
            $ability_name,
            $arguments,
            wppilot_chat_confirmation_reason_from_arguments($model_arguments),
            $tools,
        );
    }

    // Legacy/direct ability call: the arguments carry confirmation_reason inline.
    $reason = wppilot_chat_confirmation_reason_from_arguments($model_arguments);
    unset($model_arguments['confirmation_reason']);

    return wppilot_chat_ability_call_fields($session, $parsed_name, $model_arguments, $reason, $tools);
}

/**
 * Assemble the shared ability-execution fields (risk + approval status).
 *
 * @param array<string, mixed> $session
 * @param array<string, mixed> $arguments
 * @param list<array<string, mixed>> $tools
 * @return array{kind: string, ability: string, arguments: array<string, mixed>, status: string, risk: array<string, mixed>, reason: string}
 */
function wppilot_chat_ability_call_fields(
    array $session,
    string $ability_name,
    array $arguments,
    string $reason,
    array $tools,
): array {
    $tool = wppilot_chat_find_tool($ability_name, $tools);
    $risk = $tool !== null && is_array($tool['risk'] ?? null)
        ? wppilot_chat_assoc_array($tool['risk'])
        : wppilot_chat_unknown_risk();

    return [
        'kind' => 'ability',
        'ability' => $ability_name,
        'arguments' => $arguments,
        'status' => wppilot_chat_tool_needs_approval($session, $ability_name, $risk, yolo: false)
            ? 'pending_approval'
            : 'approved',
        'risk' => $risk,
        'reason' => $reason,
    ];
}

/**
 * @param array<array-key, mixed> $arguments
 */
function wppilot_chat_confirmation_reason_from_arguments(array $arguments): string
{
    $reason = is_string($arguments['confirmation_reason'] ?? null) ? $arguments['confirmation_reason'] : '';
    $reason = sanitize_text_field($reason);
    $reason = preg_replace(pattern: '/\s+/', replacement: ' ', subject: trim($reason));

    return is_string($reason) ? $reason : '';
}

/**
 * @param array<string, mixed> $tool_call
 * @return array<string, mixed>
 */
function wppilot_chat_execution_arguments(array $tool_call): array
{
    $arguments = is_array($tool_call['arguments'] ?? null) ? wppilot_chat_assoc_array($tool_call['arguments']) : [];
    unset($arguments['confirmation_reason']);

    return $arguments;
}

/**
 * @param array<string, mixed> $tool_call
 * @return array<string, mixed>
 */
function wppilot_chat_model_arguments(array $tool_call): array
{
    // Meta-tools and execute-ability store the exact model-facing arguments so the
    // FunctionCall replayed in history matches what the model actually sent.
    if (is_array($tool_call['model_arguments'] ?? null)) {
        return wppilot_chat_assoc_array($tool_call['model_arguments']);
    }

    $arguments = is_array($tool_call['arguments'] ?? null) ? wppilot_chat_assoc_array($tool_call['arguments']) : [];
    $reason = is_string($tool_call['reason'] ?? null)
        ? sanitize_text_field($tool_call['reason'])
        : wppilot_chat_confirmation_reason_from_arguments($arguments);
    unset($arguments['confirmation_reason']);
    if ($reason !== '') {
        $arguments['confirmation_reason'] = $reason;
    }

    return $arguments;
}

/**
 * @param array<string, mixed> $session
 * @return array<string, mixed>|WP_Error
 */
function wppilot_chat_approve_tool_call(array $session, string $call_id, string $decision): array|WP_Error
{
    $call_index = wppilot_chat_find_tool_call_index($session, $call_id);
    if ($call_index === null) {
        return new WP_Error('wppilot_chat_call_not_found', __('Tool call not found.', domain: 'wppilot'), [
            'status' => 404,
        ]);
    }

    $tool_calls = wppilot_chat_session_list($session, key: 'tool_calls');
    if (($tool_calls[$call_index]['status'] ?? '') !== 'pending_approval') {
        return new WP_Error(
            'wppilot_chat_call_not_pending',
            __('This tool call is no longer pending approval.', domain: 'wppilot'),
            ['status' => 409],
        );
    }

    $session = wppilot_chat_apply_approval($session, $tool_calls[$call_index], $decision);
    $tool_calls[$call_index]['updated_at'] = time();
    $session['tool_calls'] = $tool_calls;
    $session['updated_at'] = time();

    return $session;
}

/**
 * @param array<string, mixed> $session
 * @param array<string, mixed> $tool_call
 * @return array<string, mixed>
 */
function wppilot_chat_apply_approval(array $session, array &$tool_call, string $decision): array
{
    if ($decision === 'deny') {
        $tool_call['status'] = 'denied';
        $tool_call['error'] = __('Tool execution was denied by the user.', domain: 'wppilot');
        $session['status'] = 'interrupted';
        return $session;
    }

    $tool_call['status'] = 'approved';
    if ($decision === 'allow_session') {
        $ability = (string) ($tool_call['ability'] ?? '');
        if ($ability !== '') {
            $allowlist = wppilot_chat_string_list($session, key: 'allowlist');
            $allowlist[] = $ability;
            $session['allowlist'] = array_values(array_unique($allowlist));
        }
    }
    return $session;
}

/**
 * @param array<string, mixed> $session
 * @return array{ability: WP_Ability, ability_name: string, tool_call: array<string, mixed>}|WP_Error
 */
function wppilot_chat_prepare_tool_execution(array $session, int $call_index, bool $yolo): array|WP_Error
{
    $tool_call = is_array($session['tool_calls'][$call_index] ?? null) ? $session['tool_calls'][$call_index] : [];
    $ability_name = (string) ($tool_call['ability'] ?? '');
    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    if (!$ability instanceof WP_Ability) {
        return new WP_Error('wppilot_chat_ability_unavailable', __('Ability is not available.', domain: 'wppilot'), [
            'status' => 404,
        ]);
    }

    $risk = is_array($tool_call['risk'] ?? null)
        ? wppilot_chat_assoc_array($tool_call['risk'])
        : wppilot_chat_ability_risk($ability);
    $approval_missing =
        wppilot_chat_tool_needs_approval($session, $ability_name, $risk, $yolo)
        && ($tool_call['status'] ?? '') !== 'approved';
    if ($approval_missing) {
        return new WP_Error(
            'wppilot_chat_approval_required',
            __('This tool call requires approval before execution.', domain: 'wppilot'),
            ['status' => 409],
        );
    }
    if (!in_array($tool_call['status'] ?? '', ['approved', 'pending_approval'], strict: true)) {
        return new WP_Error(
            'wppilot_chat_call_not_executable',
            __('This tool call cannot be executed in its current state.', domain: 'wppilot'),
            ['status' => 409],
        );
    }

    return [
        'ability' => $ability,
        'ability_name' => $ability_name,
        'tool_call' => wppilot_chat_assoc_array($tool_call),
    ];
}

function wppilot_chat_prepare_tool_result(string $ability_name, mixed $result): mixed
{
    if (!wppilot_chat_is_gutenberg_ability($ability_name) || !is_array($result)) {
        return $result;
    }

    return wppilot_chat_prepare_gutenberg_tool_result($result);
}
