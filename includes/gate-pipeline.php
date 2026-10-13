<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Run every control an ability call must pass before WP_Ability::execute().
 *
 * Each execution path used to carry its own copy of this sequence: the modern MCP transport, the
 * REST shim, the core-run gate and Pro's approval replay each reproduced the profile check, the
 * confirmation contract and the confirm strip by hand, and the modern transport then called the
 * rate limiter and preview gate directly because it had no refusable filter. Chat carried none of
 * it and called execute() straight away, so a Chat write skipped the rate limit, the design and
 * preview gates and anything a companion plugin had added. Every copy was a place for the next
 * control to be forgotten, which is how Chat fell behind.
 *
 * The order is fixed:
 *
 * 1. Safety profile. Refused before anything else, so a call the profile forbids never reaches a
 *    rate bucket or an approval queue.
 * 2. Confirmation. A destructive or critical ability needs `confirm: true`, unless the caller says
 *    a human already approved this exact call in wp-admin (`human_approved`) — Chat's approve
 *    button and Pro's approval queue. A model can set `confirm` itself; it cannot set that context.
 *    In the `human` confirmation mode `confirm` stops counting, and the call needs an elicitation
 *    answer or a wp-admin approval instead (wppilot_confirm_ability_call()).
 * 3. Confirm strip. `confirm` is a transport control, not ability input, and is removed unless the
 *    ability declares its own property of that name, because schema validation rejects unknown
 *    members.
 * 4. `wppilot_pre_ability_execute`. Rate limit (6), design gate (7), preview gate (8) and Pro's
 *    approval and lapsed-licence holds (9) all attach here, on every transport.
 *
 * The ability's own permission callback is not called here: execute() runs it, and running it
 * twice would drift from core's contract the moment core changed it.
 *
 * @param string                     $transport `rest`, `mcp`, `chat`, `approval` or `nested` (an
 *                                              ability a kit ability runs on the caller's behalf, e.g.
 *                                              on another network site). Filters use it to decide
 *                                              what applies; the approval queue, for one, only holds
 *                                              `rest` and `mcp` calls.
 * @param array{human_approved?: bool, elicitation?: array{supported?: bool, state?: string, response?: mixed}} $context
 *        `elicitation` is set by the modern MCP transport only; see wppilot_confirm_ability_call().
 * @return mixed The input to pass to execute(), or a WP_Error explaining the refusal.
 */
function wppilot_gate_ability_call(WP_Ability $ability, mixed $input, string $transport, array $context = []): mixed
{
    $allowed = wppilot_safety_check_ability($ability);
    if ($allowed instanceof WP_Error) {
        return $allowed;
    }

    // In the default `argument` mode this is the old contract exactly; in `human` mode `confirm`
    // is no longer enough, and a person must approve through elicitation or a wp-admin link.
    // See includes/confirmation.php.
    $confirmed = wppilot_confirm_ability_call($ability, $input, $transport, $context);
    if ($confirmed instanceof WP_Error) {
        return $confirmed;
    }
    // Handed to the ledger, which records how each write was confirmed.
    wppilot_confirmation_note($ability->get_name(), $confirmed);

    if (
        is_array($input)
        && array_key_exists(key: 'confirm', array: $input)
        && !wppilot_ability_schema_has_property($ability, property: 'confirm')
    ) {
        unset($input['confirm']);
    }

    /**
     * Give transport-neutral execution controls one final chance to refuse or transform the call.
     *
     * Runs on every GoBuildWP execution path — the REST shim, WordPress core's run endpoint, the
     * modern MCP transport, Chat and Pro's approval replay — after the safety profile and the
     * confirmation contract. The legacy MCP adapter keeps its own `mcp_adapter_pre_tool_call`,
     * because it receives an adapter envelope rather than ability input.
     *
     * @param mixed                        $input     Validated ability input, `confirm` removed.
     * @param WP_Ability                   $ability   Ability about to execute.
     * @param string                       $transport Execution transport identifier.
     * @param array{human_approved?: bool} $context   What the caller vouches for.
     */
    // @mago-expect analysis:mixed-assignment -- Filters preserve the Ability's declared input type or return WP_Error.
    // @mago-expect lint:literal-named-argument -- WordPress filter arguments after value are variadic.
    $input = apply_filters('wppilot_pre_ability_execute', $input, $ability, $transport, $context);
    if (!$input instanceof WP_Error) {
        // execute() runs next; the gate for other MCP servers must not hold it a second time.
        wppilot_gate_mark_passed($ability->get_name());
    }

    return $input;
}

/**
 * Record that GoBuildWP's gate passed a call to this ability, so its execute() is not gated again by
 * the gate for other MCP servers (includes/foreign-gate.php).
 *
 * A count per name, not a flag: one request can run the same ability twice (a bulk replay), and
 * each pass is used up by exactly one execute().
 */
function wppilot_gate_mark_passed(string $ability_name): void
{
    $GLOBALS['wppilot_gate_passes'][$ability_name] = ($GLOBALS['wppilot_gate_passes'][$ability_name] ?? 0) + 1;
}

function wppilot_gate_take_pass(string $ability_name): bool
{
    $left = (int) ($GLOBALS['wppilot_gate_passes'][$ability_name] ?? 0);
    if ($left <= 0) {
        return false;
    }
    $GLOBALS['wppilot_gate_passes'][$ability_name] = $left - 1;

    return true;
}
