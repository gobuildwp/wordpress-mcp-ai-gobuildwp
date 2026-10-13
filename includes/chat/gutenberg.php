<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: Gutenberg-specific tool result handling.
 *
 * Block editing is finalized in the browser, not server-side, so these
 * results carry the extra instructions that tell the user (and the model)
 * what still has to happen in the editor.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @param array<array-key, mixed> $result
 * @return array<array-key, mixed>
 */
function wppilot_chat_prepare_gutenberg_tool_result(array $result): array
{
    // @mago-expect analysis:mixed-assignment
    foreach ($result as $key => $value) {
        if (!is_array($value)) {
            continue;
        }

        $result[$key] = wppilot_chat_prepare_gutenberg_tool_result($value);
    }

    if (array_key_exists('finalization_url', $result) && is_string($result['finalization_url'])) {
        $result['finalization_url'] = wppilot_chat_url();
    }

    if (!is_array($result['finalizer_runtime'] ?? null)) {
        return $result;
    }

    $runtime = wppilot_chat_assoc_array($result['finalizer_runtime']);
    $runtime['dashboard_url'] = wppilot_chat_url();
    $result['finalizer_runtime'] = $runtime;
    $result['user_instruction'] = wppilot_chat_gutenberg_user_instruction($result, $runtime);

    return $result;
}

/**
 * @param array<array-key, mixed> $result
 * @param array<string, mixed> $runtime
 */
function wppilot_chat_gutenberg_user_instruction(array $result, array $runtime): string
{
    $batch_id = wppilot_chat_gutenberg_result_batch_id($result);
    $batch_label = wppilot_chat_gutenberg_result_batch_label($result);
    $watch = wppilot_chat_gutenberg_watch_instruction($runtime);
    $online = ($runtime['online'] ?? false) === true;
    $can_finalize = ($runtime['can_finalize_batch'] ?? null) !== false;

    if (!$online) {
        return sprintf(
            'The GoBuildWP Chat page embeds the Gutenberg finalizer runtime, but it is currently offline. Ask the user to reload %s before treating queued Gutenberg changes as live. %s',
            wppilot_chat_url(),
            $watch,
        );
    }

    if ($batch_id <= 0) {
        return sprintf(
            'The GoBuildWP Chat page embeds the Gutenberg finalizer runtime and it is online. Use the Gutenberg queue tools normally; after enabling a batch, check the batch status until finalization completes. %s',
            $watch,
        );
    }

    if (!$can_finalize) {
        return sprintf(
            'The GoBuildWP Chat page is online, but the current browser user may not be able to finalize Gutenberg batch #%d%s. Ask the user to reload GoBuildWP Chat as a user who can edit every target. Do not treat queued Gutenberg changes as live until finalization completes. %s',
            $batch_id,
            $batch_label !== '' ? ': ' . $batch_label : '',
            $watch,
        );
    }

    return sprintf(
        'The GoBuildWP Chat page embeds the Gutenberg finalizer runtime and should automatically finalize Gutenberg batch #%d%s. Do not ask the user to open a separate Block Editor Queue page unless the embedded runtime goes offline. Do not treat queued Gutenberg changes as live until the batch reports finalized. %s',
        $batch_id,
        $batch_label !== '' ? ': ' . $batch_label : '',
        $watch,
    );
}

/**
 * @param array<array-key, mixed> $result
 */
function wppilot_chat_gutenberg_result_batch_id(array $result): int
{
    foreach (['batch_id', 'id'] as $key) {
        if (is_scalar($result[$key] ?? null)) {
            return (int) $result[$key];
        }
    }

    return 0;
}

/**
 * @param array<array-key, mixed> $result
 */
function wppilot_chat_gutenberg_result_batch_label(array $result): string
{
    foreach (['label', 'batch_label'] as $key) {
        if (is_scalar($result[$key] ?? null)) {
            return trim((string) $result[$key]);
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $runtime
 */
function wppilot_chat_gutenberg_watch_instruction(array $runtime): string
{
    unset($runtime);

    return 'Use wppilot/gutenberg-get-pending-batch or wppilot/gutenberg-list-pending-batches to verify the batch reaches finalized, failed, conflicted, canceled, or stale.';
}

function wppilot_chat_gutenberg_tool_description(string $description): string
{
    return str_replace(
        [
            'an open Block Editor Queue page completes it',
            'If the Block Editor Queue page is open, it can pick up the batch automatically; otherwise the response tells the agent to ask the user to open the generic Block Editor Queue page.',
            'The response also includes token-gated SSE and poll URLs agents can watch with curl.',
            'reports the Block Editor Queue runtime with curl SSE/poll URLs',
            'curl SSE/poll URLs',
            'This also reports the Block Editor Queue runtime plus curl SSE/poll URLs so agents can ask the user to open the queue page before queueing static/native block changes.',
            'Reports whether the GoBuildWP Block Editor Queue admin page is open and heartbeating, including token-gated SSE and poll URLs that agents can watch with curl.',
            'Returns compact status, target summaries, validation errors, Block Editor Queue runtime status, and curl SSE/poll URLs for one pending batch.',
            'current Block Editor Queue runtime status and curl SSE/poll URLs',
            'generic Block Editor Queue admin page URL',
            'generic Block Editor Queue page URL',
            'send the finalization link to the user',
        ],
        [
            'the embedded GoBuildWP Chat finalizer runtime completes it',
            'If the embedded GoBuildWP Chat finalizer runtime is online, it can pick up the batch automatically.',
            'GoBuildWP Chat verifies completion with Gutenberg pending-batch status tools.',
            'reports the Gutenberg finalizer runtime status',
            'Gutenberg finalizer runtime status',
            'This also reports the Gutenberg finalizer runtime status before queueing static/native block changes.',
            'Reports whether the GoBuildWP Gutenberg finalizer runtime is open and heartbeating.',
            'Returns compact status, target summaries, validation errors, and Gutenberg finalizer runtime status for one pending batch.',
            'current Gutenberg finalizer runtime status',
            'GoBuildWP Chat admin page URL',
            'GoBuildWP Chat admin page URL',
            'let the embedded GoBuildWP Chat finalizer runtime complete the queued batch',
        ],
        $description,
    );
}

function wppilot_chat_is_gutenberg_ability(string $name): bool
{
    return str_starts_with($name, 'wppilot/gutenberg-');
}
