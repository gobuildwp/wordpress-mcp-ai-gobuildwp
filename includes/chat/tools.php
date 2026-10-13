<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: exposing abilities to the model as callable tools.
 *
 * Covers discovery, the risk classification that decides what needs
 * approval, and the ability-name <-> function-name mapping the provider
 * APIs require.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @return list<array<string, mixed>>
 */
function wppilot_chat_discover_tools(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $tools = [];
    foreach (wp_get_abilities() as $ability) {
        $meta = wppilot_ability_meta($ability);
        if (!wppilot_ability_is_exposed($meta)) {
            continue;
        }
        if (wppilot_ability_mcp_type($meta) !== 'tool') {
            continue;
        }
        if (wppilot_ability_is_hub_hidden($ability->get_name())) {
            continue;
        }
        if (wppilot_chat_is_hidden_tool($ability->get_name())) {
            continue;
        }

        $category_slug = $ability->get_category();
        $category = $category_slug !== '' ? wp_get_ability_category($category_slug) : null;
        $tools[] = [
            'name' => $ability->get_name(),
            'label' => $ability->get_label(),
            'description' => wppilot_chat_tool_description($ability),
            'category' => $category !== null ? $category->get_label() : $category_slug,
            'input_schema' => $ability->get_input_schema(),
            'output_schema' => $ability->get_output_schema(),
            'risk' => wppilot_chat_ability_risk($ability),
        ];
    }

    usort($tools, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    return $tools;
}

function wppilot_chat_is_hidden_tool(string $ability_name): bool
{
    return $ability_name === 'wppilot/create-admin-access-link';
}

function wppilot_chat_tool_description(WP_Ability $ability): string
{
    $description = $ability->get_description();
    if (!wppilot_chat_is_gutenberg_ability($ability->get_name())) {
        return $description;
    }
    $description = wppilot_chat_gutenberg_tool_description($description);

    return trim(
        $description . ' '
            . implode(' ', [
                'GoBuildWP Chat embeds the Gutenberg finalizer runtime on this page.',
                'Do not ask the user to open or keep a separate Block Editor Queue/finalization page while this dashboard is open.',
                'After enabling a batch, use Gutenberg pending-batch status tools until the batch is finalized, failed, or conflicted.',
                'If finalizer_runtime.online is false, ask the user to reload GoBuildWP Chat.',
            ]),
    );
}

/**
 * @return array{readonly: bool, destructive: bool, code_execution: bool, filesystem_write: bool, unknown: bool, requires_approval: bool, reason: string}
 */
function wppilot_chat_ability_risk(WP_Ability $ability): array
{
    $name = $ability->get_name();
    $category = $ability->get_category();
    $meta = $ability->get_meta();
    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
    $readonly = ($annotations['readonly'] ?? null) === true;
    $destructive = ($annotations['destructive'] ?? null) === true;
    $code_execution = wppilot_chat_is_code_execution_ability($name, $category);
    $filesystem_write = wppilot_chat_is_filesystem_write_ability($name);
    $unknown = ($annotations['readonly'] ?? null) !== true && ($annotations['readonly'] ?? null) !== false;
    $requires_approval = !$readonly || $destructive || $code_execution || $filesystem_write;

    return [
        'readonly' => $readonly,
        'destructive' => $destructive,
        'code_execution' => $code_execution,
        'filesystem_write' => $filesystem_write,
        'unknown' => $unknown,
        'requires_approval' => $requires_approval,
        'reason' => wppilot_chat_risk_reason(
            $requires_approval,
            $code_execution,
            $filesystem_write,
            $destructive,
            $unknown,
        ),
    ];
}

function wppilot_chat_is_code_execution_ability(string $name, string $category): bool
{
    return $category === 'code-execution' || str_contains($name, 'execute') || str_contains($name, 'wp-cli');
}

function wppilot_chat_is_filesystem_write_ability(string $name): bool
{
    foreach (['write', 'edit', 'delete', 'enable-file', 'disable-file'] as $needle) {
        if (str_contains($name, $needle)) {
            return true;
        }
    }

    return false;
}

// Risk is a set of independent boolean flags; passing them individually is the
// natural signature for turning them into a human-readable reason.
function wppilot_chat_risk_reason(
    bool $requires_approval,
    bool $code_execution,
    bool $filesystem_write,
    bool $destructive,
    bool $unknown,
): string {
    return match (true) {
        !$requires_approval => __('Read-only ability.', domain: 'wppilot'),
        $code_execution => __('Code execution or WP-CLI ability.', domain: 'wppilot'),
        $filesystem_write => __('Filesystem or content write ability.', domain: 'wppilot'),
        $destructive => __('Destructive ability.', domain: 'wppilot'),
        $unknown => __('Unknown risk metadata.', domain: 'wppilot'),
        default => __('Non-read ability.', domain: 'wppilot'),
    };
}

/**
 * @return array{readonly: bool, destructive: bool, code_execution: bool, filesystem_write: bool, unknown: bool, requires_approval: bool, reason: string}
 */
function wppilot_chat_unknown_risk(): array
{
    return [
        'readonly' => false,
        'destructive' => false,
        'code_execution' => false,
        'filesystem_write' => false,
        'unknown' => true,
        'requires_approval' => true,
        'reason' => __('Unknown ability.', domain: 'wppilot'),
    ];
}

/**
 * @return array{readonly: bool, destructive: bool, code_execution: bool, filesystem_write: bool, unknown: bool, requires_approval: bool, reason: string}
 */
function wppilot_chat_readonly_risk(): array
{
    return [
        'readonly' => true,
        'destructive' => false,
        'code_execution' => false,
        'filesystem_write' => false,
        'unknown' => false,
        'requires_approval' => false,
        'reason' => '',
    ];
}

/**
 * @param list<array<string, mixed>> $tools
 * @return array<string, mixed>|null
 */
function wppilot_chat_find_tool(string $name, array $tools): ?array
{
    foreach ($tools as $tool) {
        if (($tool['name'] ?? null) === $name) {
            return $tool;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $session
 * @param array<string, mixed> $risk
 */
// @mago-expect lint:no-boolean-flag-parameter
function wppilot_chat_tool_needs_approval(array $session, string $ability_name, array $risk, bool $yolo): bool
{
    if (($risk['requires_approval'] ?? true) !== true) {
        return false;
    }
    if ($yolo) {
        return false;
    }
    $allowlist = is_array($session['allowlist'] ?? null) ? $session['allowlist'] : [];

    return !in_array($ability_name, $allowlist, strict: true);
}

/**
 * @param array<string, mixed> $session
 * @return int|null
 */
function wppilot_chat_find_tool_call_index(array $session, string $call_id): ?int
{
    if ($call_id === '' || !is_array($session['tool_calls'] ?? null)) {
        return null;
    }
    // @mago-expect analysis:mixed-assignment
    foreach ($session['tool_calls'] as $index => $tool_call) {
        if (is_array($tool_call) && ($tool_call['id'] ?? null) === $call_id) {
            return (int) $index;
        }
    }

    return null;
}

function wppilot_chat_native_tools_available(): bool
{
    return (
        class_exists('WordPress\\AiClient\\Tools\\DTO\\FunctionDeclaration')
        && class_exists('WordPress\\AiClient\\Tools\\DTO\\FunctionCall')
        && class_exists('WordPress\\AiClient\\Tools\\DTO\\FunctionResponse')
        && class_exists('WordPress\\AiClient\\Messages\\DTO\\Message')
        && class_exists('WordPress\\AiClient\\Messages\\DTO\\MessagePart')
        && class_exists('WordPress\\AiClient\\Messages\\Enums\\MessageRoleEnum')
    );
}

/**
 * @return \WordPress\AiClient\Tools\DTO\FunctionDeclaration
 */
function wppilot_chat_support_function_declaration(): object
{
    return new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
        'wppilot_chat_support_check',
        'Checks whether the selected model supports native function calling.',
        [
            'type' => 'object',
            'properties' => [
                'ok' => ['type' => 'boolean'],
            ],
            'required' => ['ok'],
        ],
    );
}

/**
 * @param list<array<string, mixed>> $tools
 * @return list<\WordPress\AiClient\Tools\DTO\FunctionDeclaration>|WP_Error
 */
function wppilot_chat_build_function_declarations(array $tools): array|WP_Error
{
    if (!wppilot_chat_native_tools_available()) {
        return new WP_Error(
            'wppilot_chat_missing_native_tool_calling',
            __('WordPress AI Client native function calling support is required.', domain: 'wppilot'),
            ['status' => 503],
        );
    }

    // Abilities are not declared one-by-one (there can be hundreds, which is costly to
    // send every turn and overwhelms the model). Instead expose a small, fixed set of
    // progressive-disclosure meta-tools: the model browses categories, lists abilities,
    // reads a schema, then runs the specific ability it needs.
    unset($tools);

    $declarations = [];
    foreach (wppilot_chat_meta_tool_specs() as $spec) {
        $declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
            $spec['name'],
            $spec['description'],
            $spec['schema'],
        );
    }

    return $declarations;
}

/**
 * OpenAI's native tool schema support rejects top-level composition keywords,
 * while WP abilities may use them for alias constraints such as target_id/post_id.
 * Keep execution validation on the original ability schema; this only prepares
 * the provider declaration.
 *
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function wppilot_chat_prepare_function_schema(array $schema): array
{
    unset($schema['oneOf'], $schema['anyOf'], $schema['allOf'], $schema['enum'], $schema['not']);

    if (($schema['type'] ?? null) !== 'object') {
        $schema['type'] = 'object';
    }
    if (!is_array($schema['properties'] ?? null)) {
        $schema['properties'] = [];
    }
    if ($schema['properties'] === []) {
        $schema['properties'] = new stdClass();
    }

    return $schema;
}

/**
 * @param array<string, mixed>|null $schema
 * @return array<string, mixed>
 */
function wppilot_chat_schema_with_confirmation_reason(?array $schema): array
{
    if ($schema === null) {
        $schema = [
            'type' => 'object',
            'properties' => [],
        ];
    }

    if (($schema['type'] ?? null) !== 'object') {
        $schema['type'] = 'object';
    }
    if (!is_array($schema['properties'] ?? null)) {
        $schema['properties'] = [];
    }

    /** @var array<string, mixed> $properties */
    $properties = $schema['properties'];
    $properties['confirmation_reason'] = [
        'type' => 'string',
        'description' => 'Required approval reason. Phrase it as a very short single-line question to the user, such as "May I update this file now?"',
        'minLength' => 1,
    ];
    $schema['properties'] = $properties;

    $required = is_array($schema['required'] ?? null)
        ? array_values(array_filter($schema['required'], static fn(mixed $item): bool => is_string($item)))
        : [];
    $required[] = 'confirmation_reason';
    $schema['required'] = array_values(array_unique($required));

    return $schema;
}

function wppilot_chat_ability_to_function_name(string $ability_name): string
{
    return 'wpab__' . str_replace(search: '/', replace: '__', subject: $ability_name);
}

function wppilot_chat_function_name_to_ability(string $function_name): string
{
    $prefix = 'wpab__';
    if (!str_starts_with($function_name, $prefix)) {
        return $function_name;
    }

    return str_replace(search: '__', replace: '/', subject: substr($function_name, strlen($prefix)));
}
