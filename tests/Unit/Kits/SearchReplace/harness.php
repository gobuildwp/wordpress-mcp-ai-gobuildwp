<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SearchReplace;

use WP_Error;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;

require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
require_once dirname(__DIR__, 4) . '/includes/kits/search-replace/src/engine.php';

if (!class_exists('Kit_Test_Site')) {
    require_once dirname(__DIR__, 3) . '/doubles/kit-site.php';
}

/**
 * A $wpdb that answers the three queries the kit makes, from Kit_Test_Site.
 *
 * prepare() really substitutes and quotes, and the readers parse the resulting SQL back, so a
 * query built with the wrong placeholders or an unescaped LIKE fails here rather than on a site.
 */
final class FakeWpdb
{
    public string $posts = 'wp_posts';

    public string $postmeta = 'wp_postmeta';

    /** @var list<string> */
    public array $queries = [];

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $index = 0;
        return (string) preg_replace_callback('/%[sd]/', static function (array $match) use (&$index, $args): string {
            $value = $args[$index++] ?? null;
            return $match[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    /** @return list<string> */
    public function get_col(string $sql): array
    {
        $this->queries[] = $sql;
        $types = self::quoted(self::between($sql, 'post_type IN ('));
        $statuses = self::quoted(self::between($sql, 'post_status IN ('));
        preg_match('/ID > (\d+)/', $sql, $after);
        preg_match('/LIMIT (\d+)/', $sql, $limit);
        $only = str_contains($sql, 'AND ID IN (') ? array_map('intval', explode(',', self::between($sql, 'AND ID IN ('))) : null;
        $out = [];
        foreach (\Kit_Test_Site::post_ids() as $id) {
            $post = get_post($id);
            if ($id <= (int) $after[1] || !in_array($post->post_type, $types, true) || !in_array($post->post_status, $statuses, true)) {
                continue;
            }
            if ($only !== null && !in_array($id, $only, true)) {
                continue;
            }
            $out[] = (string) $id;
            if (count($out) >= (int) $limit[1]) {
                break;
            }
        }
        return $out;
    }

    /** @return list<array<string, string>> */
    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        $this->queries[] = $sql;
        preg_match('/post_id = (\d+)/', $sql, $post);
        preg_match_all("/meta_key LIKE '((?:[^'\\\\]|\\\\.)*)'/", $sql, $likes);
        $patterns = array_map(static fn(string $like): string => self::like_to_regex(stripslashes($like)), $likes[1]);
        $rows = [];
        foreach (\Kit_Test_Site::raw_meta((int) $post[1]) as $key => $values) {
            $wanted = false;
            foreach ($patterns as $pattern) {
                $wanted = $wanted || preg_match($pattern, (string) $key) === 1;
            }
            foreach ($wanted ? $values : [] as $value) {
                $rows[] = ['meta_key' => (string) $key, 'meta_value' => $value];
            }
        }
        return $rows;
    }

    public function get_var(string $sql): ?string
    {
        $this->queries[] = $sql;
        preg_match('/post_id = (\d+)/', $sql, $post);
        preg_match("/meta_key = '((?:[^'\\\\]|\\\\.)*)'/", $sql, $key);
        return \Kit_Test_Site::raw_meta((int) $post[1])[stripslashes($key[1])][0] ?? null;
    }

    private static function between(string $sql, string $open): string
    {
        $start = strpos($sql, $open);
        if ($start === false) {
            return '';
        }
        $start += strlen($open);
        return substr($sql, $start, (int) strpos($sql, ')', $start) - $start);
    }

    /** @return list<string> */
    private static function quoted(string $list): array
    {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $list, $matches);
        return array_map('stripslashes', $matches[1]);
    }

    private static function like_to_regex(string $like): string
    {
        $regex = '';
        for ($i = 0, $n = strlen($like); $i < $n; $i++) {
            $char = $like[$i];
            if ($char === '\\' && $i + 1 < $n) {
                $regex .= preg_quote($like[++$i], '/');
            } elseif ($char === '%') {
                $regex .= '.*';
            } elseif ($char === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }
        return '/^' . $regex . '$/';
    }
}

/**
 * A ledger that keeps every record_items() call, with a budget a test can shrink.
 */
final class FakeLedger implements Ledger
{
    /** @var list<array{ability: string, items: list<array<string, mixed>>, group: string|null}> */
    public array $calls = [];

    public int $budget = 1_048_576;

    public function capture_for(string $ability_name, callable $capture): void
    {
    }

    public function record_items(string $ability_name, array $items, ?string $group = null): array
    {
        $this->calls[] = ['ability' => $ability_name, 'items' => $items, 'group' => $group];
        $ids = [];
        foreach ($items as $index => $item) {
            $ids[] = 'change-' . count($this->calls) . '-' . $index;
        }
        return ['group' => (string) $group, 'change_ids' => $ids, 'without_before_image' => 0];
    }

    public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
    {
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
        return $this->budget;
    }

    public function download_url(): string
    {
        return '';
    }

    /** @return list<array<string, mixed>> Every recorded row, across calls. */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->calls as $call) {
            foreach ($call['items'] as $item) {
                $rows[] = $item + ['group' => $call['group']];
            }
        }
        return $rows;
    }
}

/**
 * Jobs that run when the test says so, as the user who queued them.
 */
final class FakeJobs implements Jobs
{
    /** @var array<string, callable> */
    public array $steps = [];

    /** @var array<string, array<string, mixed>> */
    public array $jobs = [];

    public function register(string $kind, callable $step): void
    {
        $this->steps[$kind] = $step;
    }

    public function enqueue(string $kind, array $payload): string|WP_Error
    {
        if (!isset($this->steps[$kind])) {
            return new WP_Error('kit_job_unknown_kind', 'unknown kind');
        }
        $id = 'job-' . (count($this->jobs) + 1);
        $this->jobs[$id] = [
            'id' => $id, 'kind' => $kind, 'payload' => $payload, 'state' => [], 'status' => 'queued',
            'progress' => 0.0, 'message' => '', 'owner' => get_current_user_id(), 'updated_at' => time(),
        ];
        return $id;
    }

    public function get(string $id): ?array
    {
        return $this->jobs[$id] ?? null;
    }

    /** As the Runner: only a job that has not finished can be cancelled. */
    public function cancel(string $id): bool
    {
        if (!isset($this->jobs[$id]) || in_array($this->jobs[$id]['status'], ['done', 'failed', 'cancelled'], true)) {
            return false;
        }
        $this->jobs[$id]['status'] = 'cancelled';
        return true;
    }

    /** Run steps until the job says it is done, at most $max steps. */
    public function run(string $id, int $max = 20): void
    {
        $job = &$this->jobs[$id];
        for ($i = 0; $i < $max && !in_array($job['status'], ['done', 'failed', 'cancelled'], true); $i++) {
            $outcome = ($this->steps[$job['kind']])($job['payload'], $job['state']);
            $job['state'] = $outcome['state'];
            $job['progress'] = $outcome['progress'] ?? $job['progress'];
            $job['message'] = $outcome['message'] ?? '';
            $job['status'] = ($outcome['done'] ?? false) === true ? 'done' : 'running';
        }
    }
}

final class FakeHost implements Host
{
    public FakeLedger $ledger;

    public FakeJobs $jobs;

    public function __construct()
    {
        $this->ledger = new FakeLedger();
        $this->jobs = new FakeJobs();
    }

    public function id(): string
    {
        return 'test';
    }

    public function can_manage(): bool
    {
        return true;
    }

    public function is_enabled(): bool
    {
        return true;
    }

    public function safety_profile(): string
    {
        return 'production';
    }

    public function ledger(): Ledger
    {
        return $this->ledger;
    }

    public function jobs(): Jobs
    {
        return $this->jobs;
    }

    public function extension(string $point): mixed
    {
        return null;
    }

    public function admin_parent_slug(): string
    {
        return 'tools.php';
    }

    /** As the standalone host: a destructive call needs confirm=true. */
    public function confirm_guard(string $ability_name, array $input): bool|WP_Error
    {
        return ($input['confirm'] ?? null) === true
            ? true
            : new WP_Error('kit_confirmation_required', 'confirm=true required', ['status' => 409]);
    }
}
