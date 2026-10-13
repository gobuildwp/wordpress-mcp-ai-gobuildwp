<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WordPress core's own ability runner, POST /wp-abilities/v1/abilities/{name}/run.
 *
 * Core routes it straight to WP_Ability::execute(). The safety profile reaches it
 * through `wp_ability_permission_result` on 7.1, but nothing else GoBuildWP enforces
 * did: the confirmation gate, the write rate limit, the preview and design gates,
 * and Pro's approval queue all sit on GoBuildWP's own transports. An Application
 * Password could therefore run a destructive ability without `confirm`, at any
 * rate, past a queue the site had switched on.
 *
 * `rest_dispatch_request` fires after core has matched the route and run its
 * permission check, and before the controller executes. Answering there with the
 * same pipeline GoBuildWP's own REST route runs closes the gap without replacing
 * core's route or its permission decision.
 */

/**
 * Tells Pro that this build gates core's runner, so Pro's own hold on that route
 * stands down instead of queueing the same write a second time.
 */
function wppilot_core_rest_run_is_gated(): bool
{
    return true;
}

/**
 * Run a core ability-runner request through GoBuildWP's controls, or leave it alone.
 *
 * @param mixed $result Response from an earlier filter, or null.
 * @param array<string, mixed> $handler
 */
function wppilot_gate_core_rest_run(mixed $result, WP_REST_Request $request, string $route, array $handler): mixed
{
    unset($route, $handler);

    if ($result !== null || !function_exists('wp_get_ability')) {
        return $result;
    }

    // The matched route is core's pattern; the request carries the real path.
    if (preg_match('#^/wp-abilities/v1/abilities/(?P<name>[a-z0-9-]+(?:/[a-z0-9-]+)+)/run$#', $request->get_route(), $matches) !== 1) {
        return $result;
    }

    $ability = wp_get_ability($matches['name']);
    if (!$ability instanceof WP_Ability) {
        // Core answers an unknown ability itself.
        return $result;
    }

    // @mago-expect analysis:mixed-assignment -- Ability input is opaque until the ability validates it.
    $input = $request->get_param('input');

    // @mago-expect analysis:mixed-assignment -- Filters preserve the Ability's declared input type or return WP_Error.
    $input = wppilot_gate_ability_call($ability, $input, transport: 'rest');
    if ($input instanceof WP_Error) {
        return wppilot_core_rest_run_confirmation_hint($input, $ability);
    }

    // @mago-expect analysis:mixed-assignment
    $output = $ability->execute(wppilot_normalize_empty_ability_input($ability, $input));
    if ($output instanceof WP_Error) {
        return wppilot_rest_classify_ability_error($output);
    }

    return rest_ensure_response($output);
}

/**
 * Point a refused confirmation at a route that can carry it.
 *
 * Core validates `input` against the ability's schema before any plugin sees the
 * request, so on this route `confirm` is rejected as an unknown property by every
 * ability that does not declare it. Without this, the refusal would ask for a
 * flag the caller has no way to send here.
 */
function wppilot_core_rest_run_confirmation_hint(WP_Error $error, WP_Ability $ability): WP_Error
{
    if ($error->get_error_code() !== 'wppilot_confirmation_required' || function_exists('wppilot_ability_schema_has_property') && wppilot_ability_schema_has_property($ability, 'confirm')) {
        return $error;
    }

    return new WP_Error(
        'wppilot_confirmation_required',
        sprintf(
            /* translators: %s: the GoBuildWP REST route that accepts confirm. */
            __('This ability needs confirm=true, and WordPress core\'s ability runner rejects confirm for abilities that do not declare it. Send the confirmed call to %s instead.', domain: 'wppilot'),
            rest_url('wppilot/v1/abilities/' . $ability->get_name() . '/run'),
        ),
        $error->get_error_data(),
    );
}

add_filter('rest_dispatch_request', 'wppilot_gate_core_rest_run', priority: 8, accepted_args: 4);
