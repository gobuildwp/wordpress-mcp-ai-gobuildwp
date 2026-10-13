<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot_Test_State;

/**
 * The adapter only hooks its get-ability-info and execute-ability abilities from its own init(),
 * so a plugin that reads the Abilities registry earlier leaves every GoBuildWP MCP server with
 * discover-abilities alone: a client can list abilities and never run one. The fallback must
 * fill that gap, and must stand aside when the adapter's own hook already ran.
 */
final class AdapterAbilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WPPilot_Test_State::reset();
    }

    public function test_registers_both_meta_tools_when_the_adapter_hook_missed_init(): void
    {
        WPPilot_Test_State::$registered_abilities = ['mcp-adapter/discover-abilities'];

        wppilot_ensure_mcp_adapter_abilities();

        self::assertContains('mcp-adapter/get-ability-info', WPPilot_Test_State::$registered_abilities);
        self::assertContains('mcp-adapter/execute-ability', WPPilot_Test_State::$registered_abilities);
    }

    public function test_registers_nothing_when_the_adapter_already_did(): void
    {
        WPPilot_Test_State::$registered_abilities = [
            'mcp-adapter/discover-abilities',
            'mcp-adapter/get-ability-info',
            'mcp-adapter/execute-ability',
        ];

        wppilot_ensure_mcp_adapter_abilities();

        self::assertSame([], WPPilot_Test_State::$registrations);
    }

    public function test_registers_the_category_only_when_missing(): void
    {
        wppilot_ensure_mcp_adapter_category();
        wppilot_ensure_mcp_adapter_category();

        self::assertSame(['mcp-adapter'], WPPilot_Test_State::$registered_categories);
    }
}
