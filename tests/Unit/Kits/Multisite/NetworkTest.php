<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\Multisite;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Multisite;
use WPPilot\Kits\Runtime;

/**
 * Listing sites, and running an ability on one: refusals before any switch, the switch order,
 * the way home on every path, and where the change is recorded.
 */
final class NetworkTest extends TestCase
{
    private FakeNetwork $network;

    private PerSiteLedger $ledger;

    private NetworkHost $host;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 4);
        require_once $root . '/includes/kits/_runtime/runtime.php';
        // Not bootstrap.php: it returns a skip on a single site, which is what the unit suite is.
        require_once $root . '/includes/kits/multisite/src/Network.php';
        require_once $root . '/includes/kits/multisite/src/WpNetwork.php';
        require_once $root . '/includes/kits/multisite/src/functions.php';
        require_once __DIR__ . '/RecordingAbility.php';
        require_once __DIR__ . '/FakeNetwork.php';
        require_once $root . '/includes/kits/multisite/src/abilities/network-list-sites.php';
        require_once $root . '/includes/kits/multisite/src/abilities/network-run-ability.php';
    }

    protected function setUp(): void
    {
        $this->network = new FakeNetwork();
        foreach ([1, 2, 3] as $id) {
            $this->network->add_site($id);
        }
        $this->network->add_site(4, deleted: true);
        Multisite\network($this->network);
        $this->ledger = new PerSiteLedger($this->network);
        $this->host = new NetworkHost($this->ledger);
        Runtime\host($this->host);
    }

    public function testBothAbilitiesAreRegistered(): void
    {
        self::assertTrue(wp_has_ability('wppilot/network-list-sites'));
        self::assertTrue(wp_has_ability('wppilot/network-run-ability'));
    }

    public function testListingPagesAndLeavesDeletedSitesOutUnlessAsked(): void
    {
        $first = Multisite\list_sites(['limit' => 2]);

        self::assertSame([1, 2], array_column($first['sites'], 'id'));
        self::assertSame(3, $first['total']);
        self::assertSame(2, $first['next_offset']);
        self::assertSame(1, $first['current_site_id']);
        self::assertSame('https://s1.example.test', $first['sites'][0]['url']);

        $rest = Multisite\list_sites(['limit' => 2, 'offset' => 2]);
        self::assertSame([3], array_column($rest['sites'], 'id'));
        self::assertNull($rest['next_offset']);

        $all = Multisite\list_sites(['include_deleted' => true, 'limit' => 500]);
        self::assertSame([1, 2, 3, 4], array_column($all['sites'], 'id'));
        self::assertTrue($all['sites'][3]['deleted']);
        self::assertSame(100, $this->network->queries[2]['limit'], 'the page size is capped');
    }

    /**
     * Switch, run on the target through the host's runner, restore; the runner sees the target
     * site and the input exactly as sent, confirm included.
     */
    public function testRunSwitchesRunsThroughTheHostAndRestores(): void
    {
        $ability = $this->write('acme/update-thing');
        $this->host->runner = function (\WP_Ability $inner, mixed $input) use ($ability): mixed {
            $this->network->log[] = 'run@' . $this->network->current;
            self::assertSame($ability, $inner);
            self::assertSame(['id' => 9, 'confirm' => true], $input);
            $this->ledger->record('acme/update-thing');
            return ['updated' => 9];
        };

        $result = Multisite\run(['site_id' => 3, 'ability' => 'acme/update-thing', 'input' => ['id' => 9, 'confirm' => true]]);

        self::assertSame(['switch:3', 'ledger@3', 'run@3', 'ledger@3', 'restore'], $this->network->log);
        self::assertSame(1, $this->network->current);
        self::assertSame(['updated' => 9], $result['result']);
        self::assertSame(3, $result['site_id']);
        self::assertSame('https://s3.example.test', $result['site_url']);
        self::assertSame(['row-3-1'], $result['change_record']['change_ids'], 'recorded in the target site\'s own ledger');
        self::assertSame([], $this->ledger->rows[1] ?? [], 'nothing recorded on the calling site by the inner call');
    }

    public function testOnlyRowsTheCallAddedAreReported(): void
    {
        $this->write('acme/update-thing');
        $this->network->current = 2;
        $this->ledger->record('acme/update-thing');
        $this->network->current = 1;
        $this->host->runner = fn(): array => ['id' => $this->ledger->record('acme/update-thing')];

        $result = Multisite\run(['site_id' => 2, 'ability' => 'acme/update-thing']);

        self::assertSame(['row-2-2'], $result['change_record']['change_ids']);
    }

    public function testReadsSkipTheLedger(): void
    {
        $this->network->abilities['acme/list-things'] = new RecordingAbility('acme/list-things', ['annotations' => ['readonly' => true]]);
        $this->host->runner = static fn(): array => ['items' => []];

        $result = Multisite\run(['site_id' => 2, 'ability' => 'acme/list-things']);

        self::assertSame(['switch:2', 'restore'], $this->network->log);
        self::assertSame([], $result['change_record']['change_ids']);
    }

    public function testARefusalOnTheTargetComesBackNamedAndTheRequestGoesHome(): void
    {
        $this->write('acme/delete-thing');
        $this->host->runner = static fn(): WP_Error => new WP_Error('confirmation_required', 'Needs confirm.', ['status' => 409]);

        $result = Multisite\run(['site_id' => 2, 'ability' => 'acme/delete-thing']);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('confirmation_required', $result->get_error_code());
        self::assertStringStartsWith('On site 2 (https://s2.example.test): Needs confirm.', $result->get_error_message());
        self::assertSame(['status' => 409, 'site_id' => 2], $result->get_error_data());
        self::assertSame('restore', $this->network->log[count($this->network->log) - 1]);
        self::assertSame(1, $this->network->current);
    }

    public function testAnExceptionStillRestoresTheOriginalSite(): void
    {
        $this->write('acme/update-thing');
        $this->host->runner = static function (): array {
            throw new \RuntimeException('boom');
        };

        try {
            Multisite\run(['site_id' => 3, 'ability' => 'acme/update-thing']);
            self::fail('the exception should propagate');
        } catch (\RuntimeException $error) {
            self::assertSame('boom', $error->getMessage());
        }
        self::assertSame(['switch:3', 'ledger@3', 'restore'], $this->network->log);
        self::assertSame(1, $this->network->current);
    }

    public function testARestoreThatDidNotReturnHomeIsAFailure(): void
    {
        $this->write('acme/update-thing');
        $this->host->runner = static fn(): array => [];
        $this->network->broken_restore = true;

        $result = Multisite\run(['site_id' => 3, 'ability' => 'acme/update-thing']);

        self::assertSame('kit_network_restore_failed', $result->get_error_code());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2?: list<string>}>
     */
    public static function refusals(): array
    {
        return [
            'no manage_network' => [['site_id' => 2, 'ability' => 'acme/update-thing'], 'kit_network_forbidden', ['manage_sites']],
            'itself' => [['site_id' => 2, 'ability' => 'wppilot/network-run-ability'], 'kit_network_nested'],
            'no such site' => [['site_id' => 99, 'ability' => 'acme/update-thing'], 'kit_network_site_not_found'],
            'deleted site' => [['site_id' => 4, 'ability' => 'acme/update-thing'], 'kit_network_site_deleted'],
            'no such ability' => [['site_id' => 2, 'ability' => 'acme/nope'], 'kit_network_ability_not_found'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>|null $caps
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testRefusalsHappenBeforeAnySwitch(array $input, string $code, ?array $caps = null): void
    {
        $this->write('acme/update-thing');
        if ($caps !== null) {
            $this->network->caps = $caps;
        }
        $this->host->runner = static fn(): array => self::fail('must not run');

        $result = Multisite\run($input);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($code, $result->get_error_code());
        self::assertSame([], $this->network->log);
    }

    /**
     * On a host with no runner of its own, the runtime applies the confirm guard, strips a
     * confirm the ability does not declare, and passes null to an ability with no input schema.
     */
    public function testWithoutAHostRunnerTheConfirmGuardStillApplies(): void
    {
        $delete = $this->write('acme/delete-thing', ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]);
        $this->host->destructive = ['acme/delete-thing'];

        $refused = Multisite\run(['site_id' => 2, 'ability' => 'acme/delete-thing', 'input' => ['id' => 5]]);
        self::assertSame('kit_confirmation_required', $refused->get_error_code());
        self::assertSame([], $delete->calls);
        self::assertSame(1, $this->network->current);

        Multisite\run(['site_id' => 2, 'ability' => 'acme/delete-thing', 'input' => ['id' => 5, 'confirm' => true]]);
        self::assertSame([['id' => 5]], $delete->calls);
        self::assertSame(['acme/delete-thing', ['id' => 5, 'confirm' => true]], $this->host->guarded[1]);

        $bare = $this->write('acme/ping');
        Multisite\run(['site_id' => 2, 'ability' => 'acme/ping']);
        self::assertSame([null], $bare->calls);
    }

    /**
     * The outer call's row on the calling site says where the undo lives instead of offering one.
     */
    public function testTheOuterRowPointsAtTheTargetSitesLedger(): void
    {
        Multisite\register_ledger($this->ledger);
        $before = ($this->ledger->captures['wppilot/network-run-ability'])([]);
        $build = $this->ledger->strategies[(string) $before['type']]['build'];

        $payload = $build($before, ['ability' => 'acme/update-thing', 'change_record' => ['site_id' => 3, 'change_ids' => ['row-3-1']]], 'wppilot/network-run-ability');

        self::assertFalse($payload['reversible']);
        self::assertStringContainsString('on site 3', $payload['reason']);
        self::assertStringContainsString('(row-3-1)', $payload['reason']);
        self::assertStringContainsString('wppilot/rollback-change', $payload['reason']);
        self::assertInstanceOf(WP_Error::class, ($this->ledger->strategies[(string) $before['type']]['restore'])([], []));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function write(string $name, array $schema = []): RecordingAbility
    {
        $ability = new RecordingAbility($name, ['annotations' => ['readonly' => false]], $schema);
        $this->network->abilities[$name] = $ability;
        return $ability;
    }
}
