<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Preview\Gate;

use WP_Ability;
use WP_Error;
use WPPilot\Preview;
use WPPilot\Preview\Projectors;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The optional "require a reviewed preview before agent writes" rule.
 *
 * Off by default and never flipped by an upgrade, for the same reason Pro's
 * approval queue ships off: turning it on changes the contract every connected
 * agent is working to, and that is the site owner's call rather than a default.
 *
 * Scope is named honestly in the setting label. It covers the MCP and REST
 * transports, which are the ones a remote agent uses. The built-in Chat screen
 * has passed through the same gate pipeline since 1.14.0, but this rule stands
 * aside for it: gating Chat properly means rendering diffs inside the Chat UI —
 * a separate feature, not a line in this file — and until then Chat's own
 * approval card is the review a person sees before the write.
 */

const OPTION = 'wppilot_require_preview_before_write';

/**
 * Whether the rule is switched on.
 *
 * Both '1' and true are accepted, the same test wppilot_is_enabled() makes.
 * update_option() stores a boolean, but it reads back as the string '1' once it
 * has been through the database on a later request — so a strict `=== true`
 * holds within the request that wrote it and quietly fails on every request
 * after, which is the shape of bug that only shows up in a real install.
 */
function required(): bool
{
    /** @var mixed $value */
    $value = get_option(OPTION, default_value: false);

    return $value === '1' || $value === true;
}

/**
 * Decide whether a call may proceed.
 *
 * Returns null to allow. The rule deliberately does not apply to abilities
 * preview cannot describe: "you must preview this first" combined with "this
 * cannot be previewed" is a deadlock, and a site owner who switched this on to
 * gain review would instead lose the ability entirely.
 *
 * @param array<string, mixed>|mixed $input
 */
function check(WP_Ability $ability, mixed $input): ?WP_Error
{
    if (!required()) {
        return null;
    }

    // The apply path executes the underlying ability on purpose.
    if (Preview\apply_in_progress()) {
        return null;
    }

    $name = $ability->get_name();

    if (\wppilot_ability_is_readonly($ability)) {
        return null;
    }
    if (str_starts_with($name, 'wppilot/preview-ability') || str_starts_with($name, 'wppilot/apply-preview')) {
        return null;
    }
    if (!Projectors\has_projector($name)) {
        return null;
    }

    return new WP_Error(
        'wppilot_preview_required',
        sprintf(
            'This site requires a reviewed preview before writes. Call wppilot/preview-ability with ability_name '
                . '"%s" and the same input, show the user the diff, then apply it with wppilot/apply-preview.',
            $name,
        ),
        ['status' => 409, 'ability' => $name, 'preview_ability' => 'wppilot/preview-ability'],
    );
}

/**
 * REST shim filter. Returning a WP_Error short-circuits execution.
 *
 * @param mixed $input
 * @return mixed
 */
function filter_pre_ability_execute(mixed $input, WP_Ability $ability, string $transport): mixed
{
    if ($transport === 'chat') {
        return $input;
    }
    $error = check($ability, $input);
    return $error ?? $input;
}

/**
 * Legacy MCP adapter filter, which routes every call through the
 * execute-ability meta-tool rather than the ability's own name.
 *
 * @param mixed $args
 * @return mixed
 */
function filter_pre_mcp_tool_call(mixed $args, string $tool_name): mixed
{
    if ($tool_name !== 'mcp-adapter-execute-ability' || !is_array($args)) {
        return $args;
    }

    $ability_name = (string) ($args['ability_name'] ?? '');
    if ($ability_name === '') {
        return $args;
    }

    $ability = Preview\resolve_ability($ability_name);
    if (!$ability instanceof WP_Ability) {
        return $args;
    }

    /** @var array<string, mixed> $parameters */
    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    $error = check($ability, $parameters);

    return $error ?? $args;
}

/**
 * Contribute the toggle to the Settings screen, which owns its own option.
 *
 * @param mixed $sections
 * @return mixed
 */
function register_setting(mixed $sections): mixed
{
    if (!is_array($sections)) {
        return $sections;
    }

    $sections[] = [
        'id' => 'wppilot-preview',
        'title' => __('Preview before write', domain: 'wppilot'),
        'description' => __(
            'Agents can always call GoBuildWP Preview on their own. This makes it mandatory for the writes it covers.',
            domain: 'wppilot',
        ),
        'fields' => [
            [
                'type' => 'toggle',
                'name' => OPTION,
                'label' => __('Require a reviewed preview before agent writes (MCP and REST)', domain: 'wppilot'),
                'help' => __(
                    'A write that GoBuildWP can preview is refused unless it goes through wppilot/preview-ability and wppilot/apply-preview, so a person sees the diff first. Abilities GoBuildWP cannot preview are unaffected, rather than being blocked with no way through. The built-in Chat screen is not covered — it has its own approval step.',
                    domain: 'wppilot',
                ),
                'value' => required(),
                'state' => required() ? 'armed' : 'ready',
            ],
        ],
        'save' => static function (array $post): void {
            update_option(OPTION, isset($post[OPTION]), autoload: true);
        },
    ];

    return $sections;
}
