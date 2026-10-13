<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Runtime;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What a kit may ask of the plugin it is running inside.
 *
 * A kit is a feature folder that has to work in two places: inside GoBuildWP, where the gate
 * pipeline, the change ledger and the Changes screen already exist, and exported into another
 * plugin that has none of them. Everything a kit needs from its surroundings goes through this
 * interface, so the kit's own code never names a GoBuildWP function. hosts/wppilot.php implements
 * it with GoBuildWP's machinery; hosts/standalone.php implements it from nothing.
 */
interface Host
{
    /** `wppilot` inside GoBuildWP; the export prefix elsewhere. */
    public function id(): string;

    /** Whether the current user may run this host's abilities at all. */
    public function can_manage(): bool;

    /** The host's master switch for agent access. */
    public function is_enabled(): bool;

    /** `readonly`, `production` or `developer`. */
    public function safety_profile(): string;

    public function ledger(): Ledger;

    public function jobs(): Jobs;

    /**
     * A named extension point a richer host offers — Pro's SEO provider registry, the theme
     * bridge, the visual renderer — or null. A kit that joins one must still work when this is
     * null, which is what it always returns standalone.
     *
     * `ability-runner` is the host's way to run another ability through its controls; kits
     * reach it through Runtime\run_ability(), which falls back to the confirm guard.
     * `cloud-url` is the base URL of the host's cloud service (a string, '' when unusable), and
     * `cloud-sign` a callable that signs a payload for it ({base, body, headers}, or null when the
     * site is not paired).
     */
    public function extension(string $point): mixed;

    /** The admin menu a kit screen hangs under. */
    public function admin_parent_slug(): string;

    /**
     * Refuse a destructive call that was not confirmed.
     *
     * Inside GoBuildWP the gate pipeline already did this before execute() ran, and removed the
     * flag, so the answer is always true. Standalone there is no pipeline, so the kit's own
     * ability checks `confirm` here; its schema declares the property for that reason.
     *
     * Returns true or a WP_Error, never false; `bool` only because a `true` type needs PHP 8.2.
     *
     * @param array<string, mixed> $input
     */
    public function confirm_guard(string $ability_name, array $input): bool|WP_Error;
}

/**
 * A host that answers `meta.safety.min_profile` itself.
 *
 * Separate from Host so a host written against runtime 1.0 (another plugin's, or a test double)
 * still satisfies the contract; require_profile() falls back to comparing Host::safety_profile()
 * with the ability's own min_profile when the host does not implement this.
 */
interface ProfileGate
{
    /**
     * Whether the ability's `meta.safety.min_profile` is within this host's safety profile.
     *
     * Returns true or a WP_Error that names the profile needed, never false; `bool` only because
     * a `true` type needs PHP 8.2.
     */
    public function profile_allows(string $ability_name): bool|WP_Error;
}

/**
 * The change record a kit writes to, and reads back.
 *
 * Row shape is the host's; a kit builds rows only through record_items() and reads them only
 * through query() and export_row().
 */
interface Ledger
{
    /**
     * Supply the before-image for one of the kit's abilities. Called before the ability runs,
     * with its input; returns an array carrying a `type`, or null when there is nothing to keep.
     *
     * @param callable(array<string, mixed>): (array<string, mixed>|null) $capture
     */
    public function capture_for(string $ability_name, callable $capture): void;

    /**
     * One row per item of a bulk write, sharing one group; see wppilot_ledger_record_items().
     *
     * @param list<array<string, mixed>> $items
     * @return array{group: string, change_ids: list<string>, without_before_image: int}
     */
    public function record_items(string $ability_name, array $items, ?string $group = null): array;

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): (array<string, mixed>|WP_Error) $restore
     * @param (callable(array<string, mixed>, mixed, string): array<string, mixed>)|null $build
     */
    public function register_strategy(string $type, callable $restore, ?callable $build = null): bool;

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function query(array $filters = []): array;

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function export_row(array $entry): array;

    /** Snapshot bytes one bulk call may add. */
    public function snapshot_budget(): int;

    /** Where a person downloads a ledger export too large to return inline, or ''. */
    public function download_url(): string;
}

/**
 * A ledger that counts and pages in its own storage.
 *
 * Optional, and separate from Ledger so implementations written before it keep compiling: a kit
 * checks `instanceof PagedLedger` and otherwise pages what query() returns. GoBuildWP's ledger is a
 * table of up to ten thousand rows with their before-images, which query() would load whole.
 */
interface PagedLedger
{
    /**
     * One page of query(), newest first.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function query_page(array $filters, int $limit, int $offset): array;

    /**
     * How many rows query() would return.
     *
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int;
}

/**
 * A ledger that files writes under agent sessions and can undo or redo a whole session.
 *
 * Optional, like PagedLedger: a kit checks `instanceof SessionLedger` and otherwise registers
 * nothing more. Before undoing a session the host checks that nothing else changed each target
 * since the session wrote to it, and a redo puts back the state the undo replaced, so both need
 * to read a target's state now. The host can do that for its own restore types and for the
 * runtime's post-partial; a kit with a restore type of its own says how here, or its changes are
 * undone unchecked and cannot be redone.
 */
interface SessionLedger
{
    /**
     * @param callable(array<string, mixed>): (array<string, mixed>|null) $read Given a before-image
     *        of this type, the target's state now in the same shape and with the same `type`, so
     *        it can be handed back to the restore as a redo; `['type' => 'absent']` when the
     *        target is gone; null when it cannot be read.
     * @param callable(array<string, mixed>): string $target Given a before-image of this type, a
     *        key naming what it restores. Two before-images with the same key must describe the
     *        same fields of the same object. '' leaves the change out of the check.
     */
    public function register_state(string $type, callable $read, callable $target): void;
}

/**
 * Background work in leased, time-boxed steps; see jobs/Runner.php.
 */
interface Jobs
{
    /**
     * @param callable(array<string, mixed>, array<string, mixed>): array{state: array<string, mixed>, done: bool, progress?: float, message?: string} $step
     */
    public function register(string $kind, callable $step): void;

    /** @param array<string, mixed> $payload */
    public function enqueue(string $kind, array $payload): string|WP_Error;

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array;

    public function cancel(string $id): bool;
}
