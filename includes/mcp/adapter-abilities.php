<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Register the adapter's category when the adapter's own hook missed the registry's init.
 *
 * See wppilot_ensure_mcp_adapter_abilities().
 */
function wppilot_ensure_mcp_adapter_category(): void
{
    if (wp_has_ability_category('mcp-adapter')) {
        return;
    }

    wp_register_ability_category('mcp-adapter', [
        'label' => 'MCP Adapter',
        'description' => 'Abilities for the MCP Adapter',
    ]);
}

/**
 * Register the adapter's get-ability-info and execute-ability meta-tools when the adapter's own
 * hook missed the registry's init.
 *
 * The adapter (up to 0.6) only hooks its default abilities onto `wp_abilities_api_init` from its
 * own init(), which runs at `init` priority 20 or `rest_api_init`. The registry initializes on the
 * first wp_get_abilities()/wp_get_ability() call after `init`, so any plugin that reads it earlier
 * fires the action before the adapter is listening. Those two abilities then never exist, every
 * mirror server drops them, and a client sees only discover-abilities (GoBuildWP registers its own)
 * - it can list abilities but never run one. These hooks are added at plugin load, before `init`,
 * so they are always in time; on a site where the adapter's hook did run (priority 10) they find
 * both abilities registered and do nothing.
 */
function wppilot_ensure_mcp_adapter_abilities(): void
{
    if (!wp_has_ability('mcp-adapter/get-ability-info') && class_exists(\WP\MCP\Abilities\GetAbilityInfoAbility::class)) {
        \WP\MCP\Abilities\GetAbilityInfoAbility::register();
    }
    if (!wp_has_ability('mcp-adapter/execute-ability') && class_exists(\WP\MCP\Abilities\ExecuteAbilityAbility::class)) {
        \WP\MCP\Abilities\ExecuteAbilityAbility::register();
    }
}
