<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: REST route registration and request handlers.
 *
 * Handlers stay thin. Anything a handler needs beyond unwrapping the request
 * and shaping the response belongs in session.php, tool-calls.php, or ai.php.
 */

if (!defined('ABSPATH')) {
    exit();
}

function wppilot_register_chat_routes(): void
{
    if (!wppilot_chat_is_enabled()) {
        return;
    }

    register_rest_route(route_namespace: WPPILOT_CHAT_REST_NAMESPACE, route: '/chat/status', args: [
        'methods' => WP_REST_Server::READABLE,
        'callback' => static fn(): array => wppilot_chat_status(),
        'permission_callback' => 'wppilot_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: WPPILOT_CHAT_REST_NAMESPACE, route: '/chat/sessions', args: [
        [
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'wppilot_chat_rest_list_sessions',
            'permission_callback' => 'wppilot_chat_rest_permission',
        ],
        [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'wppilot_chat_rest_create_session',
            'permission_callback' => 'wppilot_chat_rest_permission',
        ],
    ]);

    register_rest_route(
        route_namespace: WPPILOT_CHAT_REST_NAMESPACE,
        route: '/chat/sessions/(?P<id>[a-zA-Z0-9_-]+)',
        args: [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => 'wppilot_chat_rest_get_session',
                'permission_callback' => 'wppilot_chat_rest_permission',
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => 'wppilot_chat_rest_update_session',
                'permission_callback' => 'wppilot_chat_rest_permission',
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => 'wppilot_chat_rest_delete_session',
                'permission_callback' => 'wppilot_chat_rest_permission',
            ],
        ],
    );

    register_rest_route(route_namespace: WPPILOT_CHAT_REST_NAMESPACE, route: '/chat/model-step', args: [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'wppilot_chat_rest_model_step',
        'permission_callback' => 'wppilot_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: WPPILOT_CHAT_REST_NAMESPACE, route: '/chat/tools', args: [
        'methods' => WP_REST_Server::READABLE,
        'callback' => static fn(): array => ['tools' => wppilot_chat_discover_tools()],
        'permission_callback' => 'wppilot_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: WPPILOT_CHAT_REST_NAMESPACE, route: '/chat/models', args: [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'wppilot_chat_rest_list_models',
        'permission_callback' => 'wppilot_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: WPPILOT_CHAT_REST_NAMESPACE, route: '/chat/consent', args: [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'wppilot_chat_rest_record_consent',
        'permission_callback' => 'wppilot_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: WPPILOT_CHAT_REST_NAMESPACE, route: '/chat/tools/execute', args: [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'wppilot_chat_rest_execute_tool',
        'permission_callback' => 'wppilot_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: WPPILOT_CHAT_REST_NAMESPACE, route: '/chat/approvals', args: [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'wppilot_chat_rest_approval',
        'permission_callback' => 'wppilot_chat_rest_permission',
    ]);
}

function wppilot_chat_rest_permission(): bool|WP_Error
{
    if (!wppilot_current_user_can_manage()) {
        return new WP_Error('wppilot_chat_forbidden', __('Permission denied.', domain: 'wppilot'), [
            'status' => 403,
        ]);
    }

    return true;
}

/**
 * Record that the current user accepted the one-time GoBuildWP Chat cost notice.
 *
 * @return array{consented: bool}
 */
function wppilot_chat_rest_record_consent(): array
{
    update_user_meta(get_current_user_id(), WPPILOT_CHAT_CONSENT_META, meta_value: '1');

    return ['consented' => true];
}

/**
 * @return array<string, mixed>
 */
function wppilot_chat_rest_list_sessions(): array
{
    $sessions = array_values(wppilot_chat_get_sessions());
    usort(
        $sessions,
        static fn(array $a, array $b): int => (int) ($b['updated_at'] ?? 0) <=> (int) ($a['updated_at'] ?? 0),
    );

    return ['sessions' => $sessions];
}

function wppilot_chat_rest_create_session(WP_REST_Request $request): array|WP_Error
{
    $params = $request->get_json_params() ?? [];
    $message = is_string($params['message'] ?? null) ? sanitize_textarea_field($params['message']) : '';
    $attachments = wppilot_chat_request_attachments($params);
    if (is_wp_error($attachments)) {
        return $attachments;
    }
    if ($message === '' && $attachments === []) {
        return new WP_Error('wppilot_chat_missing_message', __('Message is required.', domain: 'wppilot'), [
            'status' => 400,
        ]);
    }

    $provider = is_string($params['provider'] ?? null) ? sanitize_key($params['provider']) : '';
    $model = is_string($params['model'] ?? null) ? sanitize_text_field($params['model']) : '';
    $selection = wppilot_chat_normalize_model_selection($provider, $model);
    if (is_wp_error($selection)) {
        return $selection;
    }

    $now = time();
    $session = [
        'id' => wp_generate_uuid4(),
        'provider' => $selection['provider'],
        'model' => $selection['model'],
        'status' => 'idle',
        'created_at' => $now,
        'updated_at' => $now,
        'messages' => [
            [
                'id' => wp_generate_uuid4(),
                'role' => 'user',
                'content' => $message,
                'attachments' => $attachments,
                'created_at' => $now,
            ],
        ],
        'tool_calls' => [],
        'allowlist' => [],
        'error' => '',
    ];

    wppilot_chat_save_session($session);

    return ['session' => $session];
}

function wppilot_chat_rest_list_models(): array|WP_Error
{
    return wppilot_chat_list_text_models();
}

function wppilot_chat_rest_get_session(WP_REST_Request $request): array|WP_Error
{
    $session = wppilot_chat_get_session((string) $request['id']);
    if ($session === null) {
        return wppilot_chat_not_found();
    }

    return ['session' => $session];
}

function wppilot_chat_rest_delete_session(WP_REST_Request $request): array|WP_Error
{
    $session_id = (string) $request['id'];
    if (wppilot_chat_get_session($session_id) === null) {
        return wppilot_chat_not_found();
    }

    wppilot_chat_delete_session($session_id);

    return ['deleted' => true];
}

function wppilot_chat_rest_update_session(WP_REST_Request $request): array|WP_Error
{
    $session = wppilot_chat_get_session((string) $request['id']);
    if ($session === null) {
        return wppilot_chat_not_found();
    }

    $params = $request->get_json_params() ?? [];
    $message = is_string($params['message'] ?? null) ? sanitize_textarea_field($params['message']) : '';
    $edit_message_id = is_string($params['message_id'] ?? null) ? sanitize_text_field($params['message_id']) : '';
    if ($edit_message_id !== '') {
        return wppilot_chat_rest_edit_message($session, $edit_message_id, $message, $params);
    }

    $session = wppilot_chat_apply_model_change($session, $params);
    if (is_wp_error($session)) {
        return $session;
    }

    $attachments = wppilot_chat_request_attachments($params);
    if (is_wp_error($attachments)) {
        return $attachments;
    }
    if ($message === '' && $attachments === []) {
        $session['updated_at'] = time();
        wppilot_chat_save_session($session);

        return ['session' => $session];
    }

    $now = time();
    $messages = wppilot_chat_session_list($session, key: 'messages');
    $messages[] = [
        'id' => wp_generate_uuid4(),
        'role' => 'user',
        'content' => $message,
        'attachments' => $attachments,
        'created_at' => $now,
    ];
    $session['messages'] = $messages;
    $session['status'] = 'idle';
    $session['updated_at'] = $now;

    wppilot_chat_save_session($session);

    return ['session' => $session];
}

/**
 * @param array<string, mixed> $session
 * @param mixed $params
 */
function wppilot_chat_rest_edit_message(
    array $session,
    string $message_id,
    string $message,
    mixed $params,
): array|WP_Error {
    $messages = wppilot_chat_session_list($session, key: 'messages');
    $message_index = wppilot_chat_find_message_index($messages, $message_id);
    if ($message_index === null) {
        return new WP_Error('wppilot_chat_message_not_found', __('Message not found.', domain: 'wppilot'), [
            'status' => 404,
        ]);
    }

    if (($messages[$message_index]['role'] ?? '') !== 'user') {
        return new WP_Error(
            'wppilot_chat_message_not_editable',
            __('Only user messages can be edited.', domain: 'wppilot'),
            ['status' => 409],
        );
    }

    $attachments = wppilot_chat_message_attachments($messages[$message_index]);
    if (is_array($params) && array_key_exists('attachments', $params)) {
        $attachments = wppilot_chat_request_attachments($params);
        if (is_wp_error($attachments)) {
            return $attachments;
        }
    }

    if ($message === '' && $attachments === []) {
        return new WP_Error('wppilot_chat_missing_message', __('Message is required.', domain: 'wppilot'), [
            'status' => 400,
        ]);
    }

    $now = time();
    $messages[$message_index]['content'] = $message;
    $messages[$message_index]['attachments'] = $attachments;
    $session['messages'] = array_slice($messages, offset: 0, length: $message_index + 1);
    $session['tool_calls'] = wppilot_chat_tool_calls_for_messages(
        $session,
        wppilot_chat_message_ids($session['messages']),
        (int) ($messages[$message_index]['created_at'] ?? 0),
    );
    $session['status'] = 'idle';
    $session['error'] = '';
    $session['updated_at'] = $now;

    wppilot_chat_save_session($session);

    return ['session' => $session];
}

function wppilot_chat_rest_model_step(WP_REST_Request $request): array|WP_Error
{
    $status = wppilot_chat_status();
    if (!$status['available']) {
        return new WP_Error('wppilot_chat_unavailable', $status['message'], ['status' => 503]);
    }

    $session = wppilot_chat_request_session($request);
    if (is_wp_error($session)) {
        return $session;
    }

    $session['status'] = 'running';
    $session['updated_at'] = time();
    wppilot_chat_save_session($session);

    try {
        $tools = wppilot_chat_discover_tools();
        $parsed = wppilot_chat_generate_native_step($session, $tools);
    } catch (Throwable $e) {
        return wppilot_chat_fail_session($session, $e->getMessage());
    }
    if (is_wp_error($parsed)) {
        // A usage limit is not a model failure: the session stays usable once the allowance
        // resets, and the caller gets the 429 and its usage data rather than a generic 500.
        if ($parsed->get_error_code() === 'wppilot_chat_usage_limit') {
            $session['status'] = 'idle';
            $session['updated_at'] = time();
            wppilot_chat_save_session($session);

            return $parsed;
        }

        return wppilot_chat_fail_session($session, $parsed->get_error_message());
    }

    $step = wppilot_chat_append_model_step($session, $parsed, $tools);
    $session = $step['session'];
    wppilot_chat_save_session($session);

    return [
        'session' => $session,
        'message' => $step['message'],
        'tool_calls' => $step['tool_calls'],
    ];
}

function wppilot_chat_rest_approval(WP_REST_Request $request): array|WP_Error
{
    $session = wppilot_chat_request_session($request);
    if (is_wp_error($session)) {
        return $session;
    }

    $params = $request->get_json_params() ?? [];
    $call_id = is_string($params['tool_call_id'] ?? null) ? sanitize_text_field($params['tool_call_id']) : '';
    $decision = is_string($params['decision'] ?? null) ? sanitize_key($params['decision']) : '';
    if ($call_id === '' || !in_array($decision, ['approve', 'deny', 'allow_session', 'yolo'], strict: true)) {
        return wppilot_chat_bad_request();
    }

    $session = wppilot_chat_approve_tool_call($session, $call_id, $decision);
    if (is_wp_error($session)) {
        return $session;
    }

    wppilot_chat_save_session($session);

    return ['session' => $session];
}

function wppilot_chat_rest_execute_tool(WP_REST_Request $request): array|WP_Error
{
    $session = wppilot_chat_request_session($request);
    if (is_wp_error($session)) {
        return $session;
    }

    $params = $request->get_json_params() ?? [];
    $call_id = is_string($params['tool_call_id'] ?? null) ? sanitize_text_field($params['tool_call_id']) : '';
    $call_index = wppilot_chat_find_tool_call_index($session, $call_id);
    if ($call_index === null) {
        return new WP_Error('wppilot_chat_call_not_found', __('Tool call not found.', domain: 'wppilot'), [
            'status' => 404,
        ]);
    }

    if (($session['tool_calls'][$call_index]['kind'] ?? '') === 'meta') {
        return wppilot_chat_execute_meta_call($session, $call_index);
    }

    $prepared = wppilot_chat_prepare_tool_execution($session, $call_index, ($params['yolo'] ?? false) === true);
    if (is_wp_error($prepared)) {
        return $prepared;
    }

    $tool_calls = wppilot_chat_session_list($session, key: 'tool_calls');
    $tool_calls[$call_index]['status'] = 'running';
    $tool_calls[$call_index]['updated_at'] = time();
    $session['tool_calls'] = $tool_calls;
    $session['status'] = 'running';
    wppilot_chat_save_session($session);

    $arguments = wppilot_chat_execution_arguments($prepared['tool_call']);
    // Chat's approve button, or the operator's own allowlist or YOLO switch, is
    // the human confirmation, so the model is not asked for confirm=true.
    // Everything else still applies: the profile, the rate limit, the design and
    // preview gates, and any control a companion plugin attaches. Chat used to
    // call execute() directly and skip all of them.
    // @mago-expect analysis:mixed-assignment
    $gated = wppilot_gate_ability_call(
        $prepared['ability'],
        $arguments,
        transport: 'chat',
        context: ['human_approved' => true],
    );
    // @mago-expect analysis:mixed-assignment
    $result = $gated instanceof WP_Error ? $gated : $prepared['ability']->execute($gated);
    if (is_wp_error($result)) {
        return wppilot_chat_record_tool_error($session, $call_index, $result->get_error_message());
    }
    // @mago-expect analysis:mixed-assignment
    $result = wppilot_chat_prepare_tool_result($prepared['ability_name'], $result);

    $now = time();
    $tool_calls = wppilot_chat_session_list($session, key: 'tool_calls');
    $tool_calls[$call_index]['status'] = 'succeeded';
    $tool_calls[$call_index]['result'] = $result;
    $tool_calls[$call_index]['updated_at'] = $now;
    $session['tool_calls'] = $tool_calls;
    $session['status'] = 'idle';
    $session['updated_at'] = $now;
    wppilot_chat_save_session($session);

    return ['session' => $session, 'tool_call' => $tool_calls[$call_index]];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function wppilot_chat_request_session(WP_REST_Request $request): array|WP_Error
{
    $params = $request->get_json_params() ?? [];
    $session_id = is_string($params['session_id'] ?? null) ? sanitize_text_field($params['session_id']) : '';
    if ($session_id === '' && is_string($request['id'] ?? null)) {
        $session_id = sanitize_text_field($request['id']);
    }

    $session = wppilot_chat_get_session($session_id);
    return $session ?? wppilot_chat_not_found();
}

function wppilot_chat_not_found(): WP_Error
{
    return new WP_Error('wppilot_chat_not_found', __('Chat not found.', domain: 'wppilot'), [
        'status' => 404,
    ]);
}

function wppilot_chat_bad_request(): WP_Error
{
    return new WP_Error('wppilot_chat_bad_request', __('Invalid request.', domain: 'wppilot'), [
        'status' => 400,
    ]);
}
