<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\BlockNotes;

use WP_Error;
use WPPilot\Kits\BlockNotes\Store;
use WPPilot\Kits\Runtime\Ledger;

/**
 * Posts and notes in memory, with every write logged so a test can assert on exact payloads.
 */
final class FakeStore implements Store
{
    /** @var array<int, array{id: int, type: string, status: string, content: string, author: int}> */
    public array $posts = [];

    /** @var array<int, array<string, mixed>> */
    public array $notes = [];

    /** @var list<int> Posts the current user may edit. */
    public array $editable = [];

    /** @var list<string> */
    public array $note_types = ['post', 'page'];

    public bool $writes = true;

    public string $lock = '';

    public ?WP_Error $update_error = null;

    public ?WP_Error $insert_error = null;

    /** @var list<array<string, mixed>> */
    public array $inserted = [];

    /** @var list<string> */
    public array $log = [];

    /** @var list<int> */
    public array $notified = [];

    private int $next = 100;

    public function add_post(int $id, string $content, string $type = 'page'): void
    {
        $this->posts[$id] = ['id' => $id, 'type' => $type, 'status' => 'publish', 'content' => $content, 'author' => 1];
        $this->editable[] = $id;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function add_note(int $id, int $post_id, int $parent = 0, string $status = '0', array $extra = []): void
    {
        $this->notes[$id] = $extra + [
            'id' => $id, 'post_id' => $post_id, 'parent' => $parent, 'content' => 'Note ' . $id, 'status' => $status,
            'type' => 'note', 'author_id' => 2, 'author_name' => 'Editor', 'date_gmt' => '2026-09-0' . min(9, $id % 10 + 1) . ' 10:00:00',
            'note_status' => '', 'by_agent' => false,
        ];
    }

    public function post(int $id): ?array
    {
        return $this->posts[$id] ?? null;
    }

    public function can_edit(int $post_id): bool
    {
        return in_array($post_id, $this->editable, true);
    }

    public function writes_supported(): bool
    {
        return $this->writes;
    }

    public function supports_notes(string $post_type): bool
    {
        return in_array($post_type, $this->note_types, true);
    }

    public function notes(int $post_id): array
    {
        ksort($this->notes);
        return array_values(array_filter($this->notes, static fn(array $note): bool => $note['post_id'] === $post_id));
    }

    public function note(int $id): ?array
    {
        return $this->notes[$id] ?? null;
    }

    public function children(int $id): array
    {
        return array_values(array_map(
            static fn(array $note): int => $note['id'],
            array_filter($this->notes, static fn(array $note): bool => $note['parent'] === $id),
        ));
    }

    public function insert(array $note): int|WP_Error
    {
        if ($this->insert_error !== null) {
            return $this->insert_error;
        }
        $id = ++$this->next;
        $this->inserted[] = $note;
        $this->log[] = 'insert:' . $id;
        $this->add_note($id, $note['post_id'], $note['parent'], $note['approved'], [
            'content' => $note['content'],
            'note_status' => $note['note_status'] ?? '',
            'by_agent' => true,
            'author_name' => 'Ada (AI agent)',
            'author_id' => 5,
        ]);
        return $id;
    }

    public function set_status(int $id, string $approved): bool
    {
        $this->log[] = 'status:' . $id . '=' . $approved;
        if (!isset($this->notes[$id])) {
            return false;
        }
        $this->notes[$id]['status'] = $approved;
        return true;
    }

    public function delete(int $id): bool
    {
        $this->log[] = 'delete:' . $id;
        $existed = isset($this->notes[$id]);
        unset($this->notes[$id]);
        return $existed;
    }

    public function update_content(int $post_id, string $content): bool|WP_Error
    {
        $this->log[] = 'content:' . $post_id;
        if ($this->update_error !== null) {
            return $this->update_error;
        }
        $this->posts[$post_id]['content'] = $content;
        return true;
    }

    public function lock_holder(int $post_id): string
    {
        return $this->lock;
    }

    public function notify(int $note_id): void
    {
        $this->notified[] = $note_id;
    }
}

/**
 * A ledger that keeps what the kit registers, so a test can build and run an undo itself.
 */
final class RecordingLedger implements Ledger
{
    /** @var array<string, callable> */
    public array $captures = [];

    /** @var array<string, array{restore: callable, build: callable|null}> */
    public array $strategies = [];

    public function capture_for(string $ability_name, callable $capture): void
    {
        $this->captures[$ability_name] = $capture;
    }

    public function record_items(string $ability_name, array $items, ?string $group = null): array
    {
        return ['group' => '', 'change_ids' => [], 'without_before_image' => 0];
    }

    public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
    {
        $this->strategies[$type] = ['restore' => $restore, 'build' => $build];
        return true;
    }

    public function query(array $filters = []): array
    {
        return [];
    }

    public function export_row(array $entry): array
    {
        return $entry;
    }

    public function snapshot_budget(): int
    {
        return 0;
    }

    public function download_url(): string
    {
        return '';
    }

    /**
     * What the ledger stores for one call: the capture routes it to a strategy, whose build turns
     * the result into the payload the undo later receives.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function payload_for(string $ability, array $input, mixed $result): array
    {
        $before = ($this->captures[$ability])($input);
        $strategy = $this->strategies[(string) $before['type']];
        $built = $strategy['build'] === null ? ['snapshot' => $before] : ($strategy['build'])($before, $result, $ability);
        return ($built['reversible'] ?? true) === false ? $built : $built + ['reversible' => true, 'type' => $before['type']];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|WP_Error
     */
    public function undo(array $payload): array|WP_Error
    {
        return ($this->strategies[(string) $payload['type']]['restore'])($payload, []);
    }
}
