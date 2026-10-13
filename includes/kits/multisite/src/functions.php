<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Multisite;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

const RUN_ABILITY = 'wppilot/network-run-ability';

/** The outer call's ledger row: routed here only to say where the undo lives. */
const STRATEGY = 'kits/network-run';

/**
 * The network the abilities act on. Replaceable so tests can hand in a fake.
 */
function network(?Network $set = null): Network
{
    /** @var Network|null $network */
    static $network = null;
    if ($set !== null) {
        $network = $set;
    }
    return $network ??= new WpNetwork();
}

/**
 * wppilot/network-list-sites.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function list_sites(array $input): array
{
    $args = [
        'search' => trim((string) ($input['search'] ?? '')),
        'limit' => max(1, min(100, (int) ($input['limit'] ?? 50))),
        'offset' => max(0, (int) ($input['offset'] ?? 0)),
        'include_deleted' => ($input['include_deleted'] ?? false) === true,
    ];
    $net = network();
    $sites = $net->sites($args);
    $total = $net->count($args);
    $next = $args['offset'] + count($sites);
    return [
        'sites' => $sites,
        'total' => $total,
        'offset' => $args['offset'],
        'next_offset' => $next < $total && $sites !== [] ? $next : null,
        'current_site_id' => $net->current_site_id(),
    ];
}

/**
 * wppilot/network-run-ability: run one ability on one site of the network.
 *
 * The site is switched to, the ability runs through the host's controls there
 * (Runtime\run_ability()), and the original site is restored whatever happens — a refusal, an
 * error, an exception. Everything site-scoped the inner ability touches is that site's: its
 * options (so its safety profile and agent switch), its content, the user's role there, and its
 * change ledger, which is where the inner change is recorded and undone.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function run(array $input): array|WP_Error
{
    $net = network();
    $site_id = (int) ($input['site_id'] ?? 0);
    $name = (string) ($input['ability'] ?? '');
    /** @var mixed $inner */
    $inner = $input['input'] ?? [];

    if (!$net->can('manage_network')) {
        return new WP_Error('kit_network_forbidden', 'Running abilities across the network needs the manage_network capability.', ['status' => 403]);
    }
    if ($name === RUN_ABILITY) {
        return new WP_Error('kit_network_nested', 'network-run-ability cannot run itself; call it once per site.', ['status' => 400]);
    }
    $site = $net->site($site_id);
    if ($site === null) {
        return new WP_Error('kit_network_site_not_found', 'No site on this network has that ID. List them with wppilot/network-list-sites.', ['status' => 404]);
    }
    if (($site['deleted'] ?? false) === true) {
        return new WP_Error('kit_network_site_deleted', 'That site is flagged deleted; restore it in Network Admin before working on it.', ['status' => 410]);
    }
    $ability = $net->ability($name);
    if ($ability === null) {
        return new WP_Error('kit_network_ability_not_found', sprintf('No ability named "%s" is registered.', $name), ['status' => 404]);
    }
    $readonly = ($ability->get_meta()['annotations']['readonly'] ?? false) === true;

    $origin = $net->current_site_id();
    $ledger = Runtime\host()->ledger();
    $net->switch_to($site_id);
    try {
        $before = $readonly ? [] : change_ids($ledger, $name);
        /** @var mixed $result */
        $result = Runtime\run_ability($ability, $inner);
        $recorded = $readonly || $result instanceof WP_Error ? [] : array_values(array_diff(change_ids($ledger, $name), $before));
    } finally {
        // Restored on every path: a request left on the wrong site writes everything after
        // this — the ledger row for this call included — into another site's tables.
        $net->restore();
    }
    if ($net->current_site_id() !== $origin) {
        return new WP_Error('kit_network_restore_failed', sprintf('The request did not return to site %d after running on site %d.', $origin, $site_id), ['status' => 500]);
    }

    if ($result instanceof WP_Error) {
        $data = $result->get_error_data();
        return new WP_Error(
            $result->get_error_code(),
            sprintf('On site %d (%s): %s', $site_id, (string) ($site['url'] ?? ''), $result->get_error_message()),
            (is_array($data) ? $data : []) + ['site_id' => $site_id],
        );
    }

    return [
        'site_id' => $site_id,
        'site_url' => (string) ($site['url'] ?? ''),
        'ability' => $name,
        'result' => $result,
        'change_record' => [
            'site_id' => $site_id,
            'change_ids' => $recorded,
            'note' => $readonly
                ? 'A read: nothing was recorded.'
                : 'Recorded in that site\'s own change record. To undo, run wppilot/network-run-ability on the same site_id with ability wppilot/rollback-change and the change_id.',
        ],
    ];
}

/**
 * IDs of the ledger rows for one ability on the current site, to tell which rows a call added.
 *
 * @return list<string>
 */
function change_ids(Ledger $ledger, string $ability): array
{
    return array_values(array_map(
        static fn(array $row): string => (string) ($row['id'] ?? ''),
        $ledger->query(['ability' => $ability]),
    ));
}

/**
 * The outer call's own row, on the site the call came from, says where its undo is: the inner
 * change is recorded on the target site, whose ledger this site cannot roll back.
 */
function register_ledger(Ledger $ledger): void
{
    $ledger->register_strategy(
        STRATEGY,
        static fn(array $payload): WP_Error => new WP_Error(
            'kit_network_undo_elsewhere',
            'This change was made on another site of the network; undo it from that site\'s change record.',
        ),
        static function (array $before, mixed $result): array {
            $record = is_array($result) && is_array($result['change_record'] ?? null) ? $result['change_record'] : [];
            $ids = is_array($record['change_ids'] ?? null) ? $record['change_ids'] : [];
            return [
                'reversible' => false,
                'reason' => sprintf(
                    'This ran %s on site %d, and that site\'s own change record holds the change%s. Undo it there: wppilot/network-run-ability with site_id %d, ability wppilot/rollback-change and the change_id.',
                    is_array($result) ? (string) ($result['ability'] ?? '') : '',
                    (int) ($record['site_id'] ?? 0),
                    $ids === [] ? '' : ' (' . implode(', ', array_map('strval', $ids)) . ')',
                    (int) ($record['site_id'] ?? 0),
                ),
            ];
        },
    );
    $ledger->capture_for(RUN_ABILITY, static fn(array $input): array => ['type' => STRATEGY]);
}
