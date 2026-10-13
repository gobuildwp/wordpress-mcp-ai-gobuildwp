<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: provider and model discovery.
 *
 * Wraps the AI Client registry so the rest of Chat sees a flat list of
 * selectable models rather than provider-specific metadata objects.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @return array{providers: list<array{id: string, name: string, configured: bool, models: list<array{id: string, name: string, supports_image_input: bool}>}>, default: array{provider: string, model: string}}|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
function wppilot_chat_list_text_models(): array|WP_Error
{
    if (
        !class_exists('WordPress\\AiClient\\AiClient')
        || !class_exists('WordPress\\AiClient\\Messages\\DTO\\Message')
        || !class_exists('WordPress\\AiClient\\Messages\\DTO\\MessagePart')
        || !class_exists('WordPress\\AiClient\\Files\\DTO\\File')
        || !class_exists('WordPress\\AiClient\\Messages\\Enums\\MessageRoleEnum')
        || !class_exists('WordPress\\AiClient\\Providers\\Models\\DTO\\ModelConfig')
        || !class_exists('WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements')
        || !class_exists('WordPress\\AiClient\\Providers\\Models\\Enums\\CapabilityEnum')
        || !wppilot_chat_native_tools_available()
    ) {
        return new WP_Error(
            'wppilot_chat_missing_ai_client',
            __('WordPress AI Client native function calling model discovery is not available.', domain: 'wppilot'),
            ['status' => 503],
        );
    }

    try {
        $registry = \WordPress\AiClient\AiClient::defaultRegistry();
        if (!method_exists($registry, 'getRegisteredProviderIds')) {
            return new WP_Error(
                'wppilot_chat_missing_provider_registry',
                __('WordPress AI Client provider discovery is not available.', domain: 'wppilot'),
                ['status' => 503],
            );
        }

        $requirements = wppilot_chat_text_model_requirements();
        $image_requirements = wppilot_chat_image_model_requirements();

        $providers = [];
        $default_provider = '';
        $default_model = '';
        foreach ($registry->getRegisteredProviderIds() as $provider_id) {
            if ($provider_id === '') {
                continue;
            }

            $configured = method_exists($registry, 'isProviderConfigured')
                ? $registry->isProviderConfigured($provider_id)
                : true;
            $provider_name = wppilot_chat_provider_name($registry, $provider_id);

            $models = [];
            if ($configured) {
                $models = wppilot_chat_provider_model_rows($registry, $provider_id, $requirements, $image_requirements);
            }

            if ($default_provider === '' && $models !== []) {
                $default_provider = $provider_id;
                $default_model = $models[0]['id'];
            }

            $providers[] = [
                'id' => $provider_id,
                'name' => $provider_name,
                'configured' => $configured,
                'models' => $models,
            ];
        }

        return [
            'providers' => $providers,
            'default' => [
                'provider' => $default_provider,
                'model' => $default_model,
            ],
        ];
    } catch (Throwable $e) {
        return new WP_Error('wppilot_chat_model_discovery_failed', $e->getMessage(), ['status' => 500]);
    }
}

function wppilot_chat_text_model_requirements(): object
{
    $message =
        new \WordPress\AiClient\Messages\DTO\Message(\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(), [new \WordPress\AiClient\Messages\DTO\MessagePart(
            'test',
        )]);

    return \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
        \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
        [$message],
        wppilot_chat_tool_model_config(),
    );
}

function wppilot_chat_image_model_requirements(): object
{
    $message =
        new \WordPress\AiClient\Messages\DTO\Message(\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(), [
            new \WordPress\AiClient\Messages\DTO\MessagePart('test'),
            new \WordPress\AiClient\Messages\DTO\MessagePart(new \WordPress\AiClient\Files\DTO\File(
                'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
                'image/png',
            )),
        ]);

    return \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
        \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
        [$message],
        wppilot_chat_tool_model_config(),
    );
}

function wppilot_chat_tool_model_config(): \WordPress\AiClient\Providers\Models\DTO\ModelConfig
{
    $config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
    $config->setFunctionDeclarations([wppilot_chat_support_function_declaration()]);

    return $config;
}

function wppilot_chat_provider_name(mixed $registry, string $provider_id): string
{
    if (!is_object($registry) || !method_exists($registry, 'getProviderClassName')) {
        return $provider_id;
    }

    // @mago-expect analysis:mixed-assignment
    $class_name = $registry->getProviderClassName($provider_id);
    if (!is_string($class_name) || !method_exists($class_name, 'metadata')) {
        return $provider_id;
    }

    // @mago-expect analysis:mixed-assignment
    $metadata = $class_name::metadata();
    if (!is_object($metadata) || !method_exists($metadata, 'getName')) {
        return $provider_id;
    }

    // @mago-expect analysis:mixed-assignment
    $name = $metadata->getName();

    return is_string($name) && $name !== '' ? $name : $provider_id;
}

/**
 * @return list<array{id: string, name: string, supports_image_input: bool}>
 */
function wppilot_chat_provider_model_rows(
    mixed $registry,
    string $provider_id,
    object $requirements,
    object $image_requirements,
): array {
    $image_model_ids = wppilot_chat_provider_model_ids($registry, $provider_id, $image_requirements);
    $models = [];
    foreach (wppilot_chat_provider_model_metadata($registry, $provider_id, $requirements) as $model_metadata) {
        $model_id = wppilot_chat_model_metadata_id($model_metadata);
        if ($model_id === '') {
            continue;
        }

        $models[] = [
            'id' => $model_id,
            'name' => wppilot_chat_model_metadata_name($model_metadata, $model_id),
            'supports_image_input' => in_array($model_id, $image_model_ids, strict: true),
        ];
    }

    return $models;
}

/**
 * @return list<string>
 */
function wppilot_chat_provider_model_ids(mixed $registry, string $provider_id, object $requirements): array
{
    $model_ids = [];
    foreach (wppilot_chat_provider_model_metadata($registry, $provider_id, $requirements) as $model_metadata) {
        $model_id = wppilot_chat_model_metadata_id($model_metadata);
        if ($model_id !== '') {
            $model_ids[] = $model_id;
        }
    }

    return $model_ids;
}

/**
 * @return list<object>
 */
function wppilot_chat_provider_model_metadata(mixed $registry, string $provider_id, object $requirements): array
{
    if (!is_object($registry) || !method_exists($registry, 'findProviderModelsMetadataForSupport')) {
        return [];
    }

    $metadata_items = [];
    /** @var iterable<mixed> $found_metadata */
    // @mago-expect analysis:mixed-assignment
    $found_metadata = $registry->findProviderModelsMetadataForSupport($provider_id, $requirements);
    foreach ($found_metadata as $model_metadata) {
        if (!is_object($model_metadata)) {
            continue;
        }
        $metadata_items[] = $model_metadata;
    }

    return $metadata_items;
}

function wppilot_chat_model_metadata_id(object $model_metadata): string
{
    if (!method_exists($model_metadata, 'getId')) {
        return '';
    }

    // @mago-expect analysis:mixed-assignment
    $model_id = $model_metadata->getId();

    return is_string($model_id) ? $model_id : '';
}

function wppilot_chat_model_metadata_name(object $model_metadata, string $fallback): string
{
    if (!method_exists($model_metadata, 'getName')) {
        return $fallback;
    }

    // @mago-expect analysis:mixed-assignment
    $name = $model_metadata->getName();

    return is_string($name) && $name !== '' ? $name : $fallback;
}

/**
 * @return array{provider: string, model: string}|WP_Error
 */
function wppilot_chat_normalize_model_selection(string $provider, string $model): array|WP_Error
{
    $catalog = wppilot_chat_list_text_models();
    if (is_wp_error($catalog)) {
        return $catalog;
    }

    if ($provider === '') {
        $provider = $catalog['default']['provider'];
    }
    if ($model === '') {
        $model = $provider === $catalog['default']['provider'] ? $catalog['default']['model'] : '';
    }

    foreach ($catalog['providers'] as $provider_entry) {
        // @mago-expect analysis:redundant-null-coalesce
        if (($provider_entry['id'] ?? '') !== $provider) {
            continue;
        }
        foreach ($provider_entry['models'] as $model_entry) {
            // @mago-expect analysis:redundant-null-coalesce
            if (($model_entry['id'] ?? '') === $model) {
                return [
                    'provider' => $provider,
                    'model' => $model,
                ];
            }
        }
    }

    return new WP_Error(
        'wppilot_chat_invalid_model',
        __('Select a configured provider and model that supports native function calling.', domain: 'wppilot'),
        ['status' => 400],
    );
}
