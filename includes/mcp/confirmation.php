<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp;

use WP_Error;

/**
 * Human confirmation over the modern revision: `input_required` carrying an elicitation.
 *
 * The decision itself is made in wppilot_confirm_ability_call() for every transport. This module
 * does only the two protocol-specific parts: reading what the client can do and what it answered,
 * and rendering the refusal as an `input_required` result instead of a tool error.
 *
 * WIRE SHAPE — an assumption, stated so it can be checked against the published schema. The
 * 2026-07-28 revision is stateless, so a server cannot send `elicitation/create` mid-call and wait;
 * it answers the call with an interim result and the client retries (the multi round-trip request
 * design, SEP-2322). No copy of that schema is vendored here (vendor/wordpress/php-mcp-schema stops
 * at 2025-11-25), so this implements the shape as it is most widely documented:
 *
 *     result: {
 *       resultType: "input_required",
 *       inputRequests: { "<key>": { method: "elicitation/create", params: <ElicitRequestFormParams> } },
 *       requestState: "<opaque string>"
 *     }
 *
 * and the retry repeats the same `tools/call` with `params.inputResponses: { "<key>": <ElicitResult> }`
 * and `params.requestState` echoed back. The elicitation params and result themselves are the
 * 2025-11-25 types (form mode, a flat `requestedSchema`, `action` accept/decline/cancel), which are
 * vendored and unchanged. If the published names differ, only this file needs to change.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Whether a client declared form-mode elicitation in its per-request capabilities.
 *
 * Under 2025-11-25 an empty `elicitation` object means form mode (the only mode before URL mode
 * was added); one that lists modes must name `form`. A client declaring only `url` cannot show the
 * boolean prompt, so it is given the approval link like a client with no elicitation at all.
 *
 * @param array<string, mixed> $capabilities
 */
function client_supports_form_elicitation(array $capabilities): bool
{
    $elicitation = $capabilities['elicitation'] ?? null;
    if (!is_array($elicitation)) {
        return false;
    }

    return $elicitation === [] || array_key_exists('form', $elicitation);
}

/**
 * The gate context for one modern `tools/call`.
 *
 * @param array<string, mixed> $params
 * @return array{elicitation: array{supported: bool, state: string, response: mixed}}
 */
function confirmation_context(array $params): array
{
    $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
    $capabilities = is_array($meta[META_CLIENT_CAPABILITIES] ?? null) ? $meta[META_CLIENT_CAPABILITIES] : [];
    $responses = is_array($params['inputResponses'] ?? null) ? $params['inputResponses'] : [];
    $key = defined('WPPILOT_CONFIRMATION_INPUT_KEY') ? (string) constant('WPPILOT_CONFIRMATION_INPUT_KEY') : 'wppilot_confirmation';

    return [
        'elicitation' => [
            'supported' => client_supports_form_elicitation($capabilities),
            'state' => is_string($params['requestState'] ?? null) ? $params['requestState'] : '',
            'response' => $responses[$key] ?? null,
        ],
    ];
}

/**
 * The input_required body a gate refusal carries, or null when the refusal is an ordinary one.
 *
 * @return array{inputRequests: array<string, mixed>, requestState: string}|null
 */
function input_required_payload(WP_Error $error): ?array
{
    if ($error->get_error_code() !== 'wppilot_confirmation_input_required') {
        return null;
    }
    $data = $error->get_error_data();
    $payload = is_array($data) && is_array($data['input_required'] ?? null) ? $data['input_required'] : null;
    if (
        $payload === null
        || !is_array($payload['inputRequests'] ?? null)
        || !is_string($payload['requestState'] ?? null)
    ) {
        return null;
    }

    return ['inputRequests' => $payload['inputRequests'], 'requestState' => $payload['requestState']];
}

/**
 * Render an input_required interim result.
 *
 * decorate_modern_result() stamps every result `complete`, which is right for everything else this
 * server returns, so the type is set again afterwards rather than teaching the shared decorator a
 * special case.
 *
 * @param array{inputRequests: array<string, mixed>, requestState: string} $payload
 * @return array{status: int, body: array<string, mixed>}
 */
function input_required_response(array $payload, mixed $id): array
{
    $outcome = success($payload, $id, 'tools/call');
    if (is_array($outcome['body']['result'] ?? null)) {
        $outcome['body']['result']['resultType'] = RESULT_INPUT_REQUIRED;
    }

    return $outcome;
}
