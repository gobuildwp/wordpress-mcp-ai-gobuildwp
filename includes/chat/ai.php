<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: talking to the model.
 *
 * Builds the message history in the AI Client's shape, runs a generation
 * step, and parses the response back into Chat's own message/tool-call
 * structures.
 */

if (!defined('ABSPATH')) {
    exit();
}

function wppilot_chat_system_instruction(): string
{
    $lines = [
        'You are GoBuildWP Chat inside WordPress admin.',
        'Your abilities are not all declared up front. Discover them first: call discover-abilities to get the list of ability names, then call get-ability-info with an exact ability_name to read its input schema. Then call execute-ability with that exact ability_name and parameters matching the schema.',
        'Copy ability names verbatim from discover-abilities. Never construct a name from a category or guess it; if execute-ability reports an unknown ability, call discover-abilities again and use an exact name.',
        'Prefer a specific, scoped ability (for example updating a post or a product) over general code execution. Reach for code execution only when no scoped ability fits the task.',
        'Do not emit JSON-encoded tool calls in text.',
        'execute-ability must include confirmation_reason: a very short, single-line question for the user, ideally under 12 words. Discovery calls do not need it.',
        'This GoBuildWP Chat page embeds the Gutenberg finalizer runtime needed to serialize native/static Gutenberg blocks. For Gutenberg queue abilities, do not ask the user to open or keep a separate Block Editor Queue/finalization page while this dashboard is open. If finalizer_runtime.online is false, ask the user to reload GoBuildWP Chat; after enabling a batch, use Gutenberg pending-batch status abilities until it is finalized, failed, or conflicted.',
        'If the task is complete, answer normally in text.',
    ];

    // The environment/skills/Context guidance is not repeated here: like an MCP
    // server (which carries no init instructions), it reaches the model through the
    // discover-abilities result, keeping a single copy.
    return implode("\n", $lines);
}

/**
 * The shared GoBuildWP server instructions (environment, WordPress-native guidance,
 * available skills, and the administrator Context), as sent to MCP agents. Returns
 * an empty string when the builder is unavailable.
 */
function wppilot_chat_server_instructions(): string
{
    if (!function_exists('wppilot_build_server_instructions')) {
        return '';
    }

    /** @var mixed $instructions */
    $instructions = apply_filters('wppilot_discover_abilities_instructions', wppilot_build_server_instructions());

    return is_string($instructions) ? trim($instructions) : '';
}

/**
 * @param array<string, mixed> $session
 * /**
 * @param list<array<string, mixed>> $tools
 * @return array{content: string, complete: bool, tool_calls: list<array<string, mixed>>}|WP_Error
 */
function wppilot_chat_generate_native_step(array $session, array $tools): array|WP_Error
{
    if (!function_exists('wp_ai_client_prompt')) {
        return new WP_Error(
            'wppilot_chat_missing_ai_client',
            __('WordPress AI text generation is not available.', domain: 'wppilot'),
            ['status' => 503],
        );
    }

    // A limit stops the call before the provider bills it.
    $allowed = wppilot_chat_usage_allows(get_current_user_id());
    if ($allowed instanceof WP_Error) {
        return $allowed;
    }

    $declarations = wppilot_chat_build_function_declarations($tools);
    if (is_wp_error($declarations)) {
        return $declarations;
    }

    $provider = is_string($session['provider'] ?? null) ? sanitize_key($session['provider']) : '';
    $model = is_string($session['model'] ?? null) ? sanitize_text_field($session['model']) : '';
    $selection = wppilot_chat_normalize_model_selection($provider, $model);
    if (is_wp_error($selection)) {
        return $selection;
    }

    $messages = wppilot_chat_build_ai_history($session);
    if ($messages === []) {
        return new WP_Error('wppilot_chat_empty_history', __('The session has no prompt history.', domain: 'wppilot'), [
            'status' => 400,
        ]);
    }

    // The MCP server supports WordPress 6.9, while Chat requires the AI Client
    // available in WordPress 7.0. A missing or disabled function therefore
    // degrades Chat to a clean 503 without disabling the MCP server.
    /** @var mixed $builder */
    // @mago-expect analysis:mixed-assignment
    $builder = call_user_func('wp_ai_client_prompt', $messages);
    if (!is_object($builder)) {
        return new WP_Error(
            'wppilot_chat_bad_ai_builder',
            __('WordPress AI Client did not return a prompt builder.', domain: 'wppilot'),
            ['status' => 500],
        );
    }

    // The WP AI Client prompt builder exposes its fluent using_* methods through
    // __call, so their return type cannot be inferred (each returns mixed); the
    // configured builder is validated as an object below.
    /** @var mixed $configured */
    // @mago-expect analysis:mixed-assignment
    $configured = $builder
        // @mago-expect analysis:ambiguous-object-method-access
        ->using_provider($selection['provider'])
        // @mago-expect analysis:mixed-method-access
        ->using_model_preference([$selection['provider'], $selection['model']])
        // @mago-expect analysis:mixed-method-access
        ->using_system_instruction(wppilot_chat_system_instruction())
        // @mago-expect analysis:mixed-method-access
        ->using_function_declarations(...$declarations);
    if (!is_object($configured)) {
        return new WP_Error(
            'wppilot_chat_bad_ai_builder',
            __('WordPress AI Client prompt builder cannot configure native tools.', domain: 'wppilot'),
            ['status' => 500],
        );
    }

    /** @var mixed $supported */
    // @mago-expect analysis:mixed-assignment
    // @mago-expect analysis:ambiguous-object-method-access
    $supported = $configured->is_supported_for_text_generation();
    if (is_wp_error($supported)) {
        return $supported;
    }
    if ($supported !== true) {
        return new WP_Error(
            'wppilot_chat_native_tools_unsupported',
            __(
                'The selected model cannot handle this request. Try a different model, or remove any attached image.',
                domain: 'wppilot',
            ),
            ['status' => 400],
        );
    }

    /** @var mixed $result */
    // @mago-expect analysis:mixed-assignment
    // @mago-expect analysis:ambiguous-object-method-access
    $result = $configured->generate_text_result();
    if (is_wp_error($result)) {
        return $result;
    }
    if (!is_object($result)) {
        return new WP_Error(
            'wppilot_chat_bad_model_response',
            __('The model did not return a native AI Client result.', domain: 'wppilot'),
            ['status' => 500],
        );
    }

    // Counted whatever the answer turns out to be: the provider billed it either way.
    $tokens = wppilot_chat_result_tokens($result);
    if ($tokens !== null) {
        wppilot_chat_record_usage(get_current_user_id(), $tokens['prompt'], $tokens['completion']);
    }

    return wppilot_chat_parse_native_result($result);
}

/**
 * @param array<string, mixed> $session
 * @return list<object>
 */
function wppilot_chat_build_ai_history(array $session): array
{
    $events = array_merge(wppilot_chat_build_ai_text_events($session), wppilot_chat_build_ai_tool_events($session));
    usort($events, static function (array $a, array $b): int {
        $time_order = (int) $a['created_at'] <=> (int) $b['created_at'];
        if ($time_order !== 0) {
            return $time_order;
        }

        return (int) $a['order'] <=> (int) $b['order'];
    });

    $messages = [];
    foreach ($events as $event) {
        $messages[] = $event['message'];
    }

    return $messages;
}

/**
 * @param array<string, mixed> $session
 * @return list<array{created_at: int, order: int, message: object}>
 */
function wppilot_chat_build_ai_text_events(array $session): array
{
    $events = [];
    foreach (wppilot_chat_session_list($session, key: 'messages') as $message) {
        $role = is_string($message['role'] ?? null) ? $message['role'] : '';
        if ($role === 'tool') {
            continue;
        }
        $content = is_string($message['content'] ?? null) ? $message['content'] : '';
        $attachments = wppilot_chat_message_attachments($message);
        if ($content === '' && $attachments === []) {
            continue;
        }
        $events[] = [
            'created_at' => (int) ($message['created_at'] ?? 0),
            'order' => $role === 'assistant' ? 1 : 0,
            'message' => wppilot_chat_text_message($role, $content, $attachments),
        ];
    }

    return $events;
}

/**
 * @param array<string, mixed> $session
 * @return list<array{created_at: int, order: int, message: object}>
 */
function wppilot_chat_build_ai_tool_events(array $session): array
{
    $events = [];
    foreach (wppilot_chat_session_list($session, key: 'tool_calls') as $tool_call) {
        $function_call_message = wppilot_chat_tool_call_function_message($tool_call);
        if ($function_call_message === null) {
            continue;
        }
        $events[] = [
            'created_at' => (int) ($tool_call['created_at'] ?? 0),
            'order' => 2,
            'message' => $function_call_message,
        ];

        $function_response_message = wppilot_chat_tool_call_response_message($tool_call);
        if ($function_response_message === null) {
            continue;
        }
        $events[] = [
            'created_at' => (int) ($tool_call['updated_at'] ?? $tool_call['created_at'] ?? 0),
            'order' => 3,
            'message' => $function_response_message,
        ];
    }

    return $events;
}

/**
 * @param array<string, mixed> $tool_call
 */
function wppilot_chat_tool_call_function_message(array $tool_call): ?object
{
    $call_id = is_string($tool_call['id'] ?? null) ? $tool_call['id'] : '';
    $ability = is_string($tool_call['ability'] ?? null) ? $tool_call['ability'] : '';
    if ($call_id === '' || $ability === '') {
        return null;
    }

    $function_name = is_string($tool_call['function_name'] ?? null)
        ? $tool_call['function_name']
        : wppilot_chat_ability_to_function_name($ability);
    $arguments = wppilot_chat_model_arguments($tool_call);

    return new \WordPress\AiClient\Messages\DTO\Message(\WordPress\AiClient\Messages\Enums\MessageRoleEnum::model(), [new \WordPress\AiClient\Messages\DTO\MessagePart(
        new \WordPress\AiClient\Tools\DTO\FunctionCall($call_id, $function_name, $arguments !== [] ? $arguments : null),
    )]);
}

/**
 * @param array<string, mixed> $tool_call
 */
function wppilot_chat_tool_call_response_message(array $tool_call): ?object
{
    $call_id = is_string($tool_call['id'] ?? null) ? $tool_call['id'] : '';
    if ($call_id === '') {
        return null;
    }

    $ability = is_string($tool_call['ability'] ?? null) ? $tool_call['ability'] : '';
    if ($ability === '') {
        return null;
    }

    $status = is_string($tool_call['status'] ?? null) ? $tool_call['status'] : '';
    if (!wppilot_chat_tool_call_has_response($status)) {
        return null;
    }

    $function_name = is_string($tool_call['function_name'] ?? null)
        ? $tool_call['function_name']
        : wppilot_chat_ability_to_function_name($ability);

    return new \WordPress\AiClient\Messages\DTO\Message(\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(), [new \WordPress\AiClient\Messages\DTO\MessagePart(
        new \WordPress\AiClient\Tools\DTO\FunctionResponse(
            $call_id,
            $function_name,
            wppilot_chat_tool_call_response_payload($tool_call, $status),
        ),
    )]);
}

function wppilot_chat_tool_call_has_response(string $status): bool
{
    return in_array($status, ['succeeded', 'failed', 'denied'], strict: true);
}

/**
 * @param array<string, mixed> $tool_call
 */
function wppilot_chat_tool_call_response_payload(array $tool_call, string $status): mixed
{
    if ($status === 'succeeded') {
        return $tool_call['result'] ?? null;
    }

    $error = is_string($tool_call['error'] ?? null) ? $tool_call['error'] : '';

    return [
        'error' => $error !== '' ? $error : __('Tool execution did not complete.', domain: 'wppilot'),
    ];
}

/**
 * @param list<array{id: string, name: string, mime_type: string, data: string, size: int}> $attachments
 */
function wppilot_chat_text_message(string $role, string $content, array $attachments = []): object
{
    $message_role = $role === 'assistant'
        ? \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model()
        : \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user();

    $parts = [];
    if ($content !== '') {
        $parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart($content);
    }
    if ($role !== 'assistant') {
        foreach ($attachments as $attachment) {
            $parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart(
                new \WordPress\AiClient\Files\DTO\File($attachment['data'], $attachment['mime_type']),
            );
        }
    }

    return new \WordPress\AiClient\Messages\DTO\Message($message_role, $parts);
}

/**
 * @return array{content: string, complete: bool, tool_calls: list<array<string, mixed>>}|WP_Error
 */
function wppilot_chat_parse_native_result(object $result): array|WP_Error
{
    if (!method_exists($result, 'toMessages') && !method_exists($result, 'toMessage')) {
        return new WP_Error(
            'wppilot_chat_bad_model_response',
            __('The AI Client result cannot expose native messages.', domain: 'wppilot'),
            ['status' => 500],
        );
    }

    /** @var list<object> $messages */
    // @mago-expect analysis:ambiguous-object-method-access
    $messages = method_exists($result, 'toMessages') ? $result->toMessages() : [$result->toMessage()];
    $content = [];
    $tool_calls = [];
    foreach ($messages as $message) {
        $parsed = wppilot_chat_parse_native_message($message);
        $content = array_merge($content, $parsed['content']);
        $tool_calls = array_merge($tool_calls, $parsed['tool_calls']);
    }

    $text = trim(implode("\n\n", $content));
    if ($text === '' && $tool_calls === []) {
        return new WP_Error(
            'wppilot_chat_empty_model_response',
            __('The model returned no text or native tool calls.', domain: 'wppilot'),
            ['status' => 500],
        );
    }

    return [
        'content' => $text,
        'complete' => $tool_calls === [],
        'tool_calls' => $tool_calls,
    ];
}

/**
 * @return array{content: list<string>, tool_calls: list<array<string, mixed>>}
 */
function wppilot_chat_parse_native_message(object $message): array
{
    if (!method_exists($message, 'getParts')) {
        return ['content' => [], 'tool_calls' => []];
    }

    $content = [];
    $tool_calls = [];
    /** @var iterable<mixed> $parts */
    // @mago-expect analysis:mixed-assignment
    $parts = $message->getParts();
    foreach ($parts as $part) {
        if (!is_object($part)) {
            continue;
        }
        $parsed = wppilot_chat_parse_native_part($part);
        if (is_string($parsed)) {
            $content[] = $parsed;
            continue;
        }
        if (is_array($parsed)) {
            $tool_calls[] = $parsed;
        }
    }

    return ['content' => $content, 'tool_calls' => $tool_calls];
}

/**
 * @return string|array<string, mixed>|null
 */
function wppilot_chat_parse_native_part(object $part): string|array|null
{
    if (!method_exists($part, 'getType')) {
        return null;
    }

    $type = $part->getType();
    if (!is_object($type)) {
        return null;
    }

    $text = wppilot_chat_parse_native_text_part($part, $type);
    if ($text !== null) {
        return $text;
    }

    return wppilot_chat_parse_native_function_call_part($part, $type);
}

function wppilot_chat_parse_native_text_part(object $part, object $type): ?string
{
    if (wppilot_chat_native_part_type($type) !== 'text' || !method_exists($part, 'getText')) {
        return null;
    }

    $text = $part->getText();
    return is_string($text) && $text !== '' ? $text : null;
}

function wppilot_chat_native_part_type(object $type): string
{
    if (method_exists($type, 'jsonSerialize')) {
        $serialized = $type->jsonSerialize();
        if (is_string($serialized)) {
            return $serialized;
        }
    }

    if (method_exists($type, '__toString')) {
        return (string) $type;
    }

    return '';
}

/**
 * @return array<string, mixed>|null
 */
function wppilot_chat_parse_native_function_call_part(object $part, object $type): ?array
{
    if (wppilot_chat_native_part_type($type) !== 'function_call' || !method_exists($part, 'getFunctionCall')) {
        return null;
    }

    // @mago-expect analysis:mixed-assignment
    $call = $part->getFunctionCall();
    if (!is_object($call) || !method_exists($call, 'getName')) {
        return null;
    }
    // @mago-expect analysis:mixed-assignment
    $function_name = $call->getName();
    if (!is_string($function_name) || $function_name === '') {
        return null;
    }

    // @mago-expect analysis:mixed-assignment
    $args = method_exists($call, 'getArgs') ? $call->getArgs() : [];
    // @mago-expect analysis:mixed-assignment
    $call_id = method_exists($call, 'getId') ? $call->getId() : '';

    return [
        'id' => is_string($call_id) && $call_id !== '' ? $call_id : wp_generate_uuid4(),
        'name' => wppilot_chat_function_name_to_ability($function_name),
        'function_name' => $function_name,
        'arguments' => is_array($args) ? $args : [],
    ];
}
