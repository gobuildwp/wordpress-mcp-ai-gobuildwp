<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Ability;
use WPPilot_Test_State;

/**
 * meta.safety: the per-ability policy a portable kit carries with it.
 */
final class AbilitySafetyPolicyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        unset(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION]);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
    }

    /**
     * A read-only ability always passed the profile check before 1.14.0,
     * because the `readonly` annotation made its risk `read` before the
     * critical-category check ran.
     */
    #[DataProvider('profiles')]
    public function testMinProfileKeepsASensitiveReadOffLessPermissiveProfiles(string $profile, bool $allowed): void
    {
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = $profile;

        self::assertSame($allowed, wppilot_safety_profile_allows_ability($this->sqlRead()));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function profiles(): array
    {
        return [
            'read only' => ['readonly', false],
            'production safe' => ['production', false],
            'developer' => ['developer', true],
        ];
    }

    /**
     * Still a read, so it is never put behind the confirm prompt.
     */
    public function testMinProfileLeavesTheRiskClassAlone(): void
    {
        self::assertSame('read', wppilot_ability_risk($this->sqlRead()));
        self::assertFalse(wppilot_ability_requires_confirmation($this->sqlRead()));
    }

    public function testUnknownMinProfileIsIgnored(): void
    {
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
        $ability = new WP_Ability('wppilot/get-post', [
            'annotations' => ['readonly' => true],
            'safety' => ['min_profile' => 'root'],
        ]);

        self::assertSame('', wppilot_ability_safety_policy($ability)['min_profile']);
        self::assertTrue(wppilot_safety_profile_allows_ability($ability));
    }

    public function testAuditedReadIsRecordedWithoutItsResult(): void
    {
        $ability = $this->sqlRead();
        $input = ['query' => 'SELECT ID FROM wp_posts', 'api_key' => 'sk-123'];

        wppilot_change_before('wppilot/database-query', $input, $ability);
        wppilot_change_after(
            'wppilot/database-query',
            $input,
            ['rows' => [['ID' => 1, 'post_title' => 'Private'], ['ID' => 2, 'post_title' => 'Draft']], 'count' => 2],
            $ability,
        );

        $log = wppilot_get_change_log();
        self::assertCount(1, $log);
        $row = $log[0];
        self::assertSame('audit-read', $row['kind']);
        self::assertSame('read', $row['risk']);
        self::assertSame('SELECT ID FROM wp_posts', $row['input']['query']);
        self::assertSame('[redacted]', $row['input']['api_key']);
        self::assertSame(['rows' => 2], $row['result']['row_counts']);
        self::assertStringNotContainsString('Private', (string) json_encode($row));
        self::assertFalse($row['rollback']['reversible']);
    }

    public function testUnauditedReadIsNotRecorded(): void
    {
        $ability = new WP_Ability('wppilot/get-post', ['annotations' => ['readonly' => true]]);

        wppilot_change_before('wppilot/get-post', ['post_id' => 1], $ability);
        wppilot_change_after('wppilot/get-post', ['post_id' => 1], ['id' => 1], $ability);

        self::assertSame([], wppilot_get_change_log());
    }

    private function sqlRead(): WP_Ability
    {
        return new WP_Ability('wppilot/database-query', [
            'annotations' => ['readonly' => true],
            'safety' => ['min_profile' => 'developer', 'audit_reads' => true],
        ]);
    }
}
