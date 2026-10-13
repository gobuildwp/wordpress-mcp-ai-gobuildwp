<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp\Tasks;

use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Runner;

use function WPPilot\Mcp\error_response;
use function WPPilot\Mcp\success;

/**
 * MCP Tasks on the modern transport: a tools/call can hand back a task handle instead of a result.
 *
 * Wire shape. The 2026-07-28 revision moved Tasks out of the core schema into an extension, and
 * no copy of that extension's text is in this repository. What is here is the shape the
 * 2025-11-25 revision specified (SEP-1686), which the vendored php-mcp-schema models under
 * Client/Tasks and Common/Tasks and which the extension carried over:
 *
 *   tools/call  params.task = {ttl?}      -> {task: Task}              (CreateTaskResult)
 *   tasks/get   {taskId}                  -> Task                      (GetTaskResult)
 *   tasks/result {taskId}                 -> the CallToolResult, _meta related-task
 *   tasks/list  {cursor?}                 -> {tasks: Task[], nextCursor?}
 *   tasks/cancel {taskId}                 -> Task                      (CancelTaskResult)
 *   Task = {taskId, status, statusMessage?, createdAt, lastUpdatedAt, ttl, pollInterval?}
 *
 * The capability is declared both as `capabilities.tasks` (the 2025-11-25 location) and under
 * `capabilities.extensions["io.modelcontextprotocol/tasks"]` (where the 2026-07-28 revision's
 * extensions live), with the same value, so a client written against either finds it.
 *
 * Two kinds of task:
 *
 * - Job-backed. An ability that declares `meta.mcp.task_support` (optional|required) and
 *   `meta.mcp.task_input` runs with that input merged in, which makes it queue a job on the kit
 *   runtime's Runner and return its `job_id`. The task follows the job: queued/running is
 *   `working`, done `completed`, failed `failed`, cancelled `cancelled`; progress rides in
 *   statusMessage and `_meta["co.wppilot/progress"]`. When the job finishes, tasks/result runs
 *   `meta.mcp.task_result_ability` with `{job_id}` to build the result.
 * - Synchronous fallback. Any other ability runs in the request, exactly as a plain tools/call
 *   would, and the task is created already completed (or failed, for an isError result). A
 *   client that asks every call to be a task therefore still works against every tool.
 *
 * Every call still runs through WPPilot\Mcp\call_tool(): the safety profile, confirmation,
 * rate limit and ledger apply to a task-augmented call exactly as to a plain one.
 *
 * `input_required` is never produced: no GoBuildWP job pauses for input.
 *
 * Tasks are per-user. Every read and cancel checks the task's owner against the current user and
 * answers "not found" for anyone else's, so a task id is not an oracle for other users' work.
 *
 * @link https://modelcontextprotocol.io/specification/2025-11-25/basic/utilities/tasks
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Where the 2026-07-28 revision's extensions are declared. */
const EXTENSION_ID = 'io.modelcontextprotocol/tasks';

/** `_meta` key tying a tasks/result payload to its task. */
const META_RELATED_TASK = 'io.modelcontextprotocol/related-task';

/** `_meta` key on a CreateTaskResult giving the model something to say while the task runs. */
const META_MODEL_IMMEDIATE_RESPONSE = 'io.modelcontextprotocol/model-immediate-response';

/** GoBuildWP's own `_meta` key for a job's fractional progress (0..1). */
const META_PROGRESS = 'co.wppilot/progress';

const STATUS_WORKING = 'working';
const STATUS_INPUT_REQUIRED = 'input_required';
const STATUS_COMPLETED = 'completed';
const STATUS_FAILED = 'failed';
const STATUS_CANCELLED = 'cancelled';

/** @var list<string> */
const TERMINAL_STATUSES = [STATUS_COMPLETED, STATUS_FAILED, STATUS_CANCELLED];

const OPTION_PREFIX = 'wppilot_mcp_task_';
const INDEX_OPTION = 'wppilot_mcp_tasks';

/** Retention when the client asks for none: long enough to come back to a long job. */
const TTL_DEFAULT_MS = 3_600_000;

/** A client cannot ask for less; a shorter task would vanish between two polls. */
const TTL_MIN_MS = 60_000;

/** Nor more: task rows live in the options table, and nothing else ever cleans them up. */
const TTL_MAX_MS = 86_400_000;

/** The Runner ticks every five seconds while a job has work left; polling faster reads the same state. */
const POLL_INTERVAL_MS = 5000;

/** Tasks one user may hold; the oldest are dropped first. Bounds what one client can store. */
const MAX_PER_USER = 50;

/** Tasks the site holds across all users. */
const MAX_TOTAL = 500;

const PAGE_SIZE = 20;

/**
 * A stored task result is JSON in an option. A result above this is not kept: the work still ran,
 * and the task says so, but tasks/result hands back a note instead of the payload.
 */
const MAX_RESULT_BYTES = 1_048_576;

/** Implementation-defined JSON-RPC code (the -32000..-32019 block) for tasks/result on a task still working. */
const ERROR_TASK_NOT_READY = -32010;

/**
 * Whether the extension is served. When it is not, tasks/* answer method-not-found and a
 * `task` field on tools/call is ignored, which is what the spec asks of a receiver without tasks.
 */
function enabled(): bool
{
    /**
     * Filter whether MCP Tasks are served on the modern transport.
     *
     * @param bool $enabled Default true.
     */
    return (bool) apply_filters('wppilot_mcp_tasks_enabled', true);
}

/**
 * The capability value, used for both declarations.
 *
 * @return object
 */
function capability(): object
{
    return (object) [
        'list' => new \stdClass(),
        'cancel' => new \stdClass(),
        'requests' => (object) ['tools' => (object) ['call' => new \stdClass()]],
    ];
}

/**
 * Add the tasks capability to a discover capability map. Only when tools are served: tasks here
 * exist only for tools/call.
 *
 * @param array<string, mixed> $capabilities
 * @return array<string, mixed>
 */
function with_capability(array $capabilities): array
{
    if (!enabled() || !isset($capabilities['tools'])) {
        return $capabilities;
    }

    $capabilities['tasks'] = capability();
    $extensions = isset($capabilities['extensions']) && is_object($capabilities['extensions'])
        ? clone $capabilities['extensions']
        : new \stdClass();
    $extensions->{EXTENSION_ID} = capability();
    $capabilities['extensions'] = $extensions;

    return $capabilities;
}

/**
 * The task support an ability declares: `forbidden` unless its meta says otherwise.
 *
 * @param array<string, mixed> $meta
 */
function task_support(array $meta): string
{
    $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
    $value = $mcp['task_support'] ?? null;

    return in_array($value, ['optional', 'required'], strict: true) ? (string) $value : 'forbidden';
}

/**
 * The tool definition's `execution` object, or null for a tool that declares nothing.
 *
 * Only declared abilities advertise task support. The synchronous fallback still accepts a
 * task-augmented call to any tool, but advertising `optional` on all of them would invite clients
 * to make every call a task for no benefit.
 *
 * @param array<string, mixed> $meta
 * @return array{taskSupport: string}|null
 */
function tool_execution(array $meta): ?array
{
    if (!enabled()) {
        return null;
    }
    $support = task_support($meta);

    return $support === 'forbidden' ? null : ['taskSupport' => $support];
}

/*
 * Seams. The dispatcher resolves tools and runs them through the transport; tests swap both.
 */

/**
 * @param array{call?: callable, meta?: callable}|null $set
 * @return array{call: callable, meta: callable}
 */
function runtime(?array $set = null): array
{
    /** @var array{call: callable, meta: callable}|null $runtime */
    static $runtime = null;
    if ($set !== null) {
        $runtime = $set + (array) $runtime;
    }
    if ($runtime === null || !isset($runtime['call'], $runtime['meta'])) {
        $runtime = [
            'call' => $runtime['call'] ?? 'WPPilot\\Mcp\\call_tool',
            'meta' => $runtime['meta'] ?? __NAMESPACE__ . '\\meta_for_tool',
        ];
    }

    return $runtime;
}

/**
 * @return array<string, mixed>
 */
function meta_for_tool(string $tool): array
{
    $ability = \WPPilot\Mcp\ability_for_tool($tool);

    return $ability === null ? [] : \wppilot_ability_meta($ability);
}

function jobs(): ?Jobs
{
    if (!function_exists('WPPilot\\Kits\\Runtime\\has_host') || !Runtime\has_host()) {
        return null;
    }

    return Runtime\host()->jobs();
}

/*
 * Storage.
 */

function now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function iso(int $ms): string
{
    return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
}

function is_valid_id(string $id): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1;
}

/**
 * @return list<string>
 */
function index(): array
{
    wp_cache_delete(INDEX_OPTION, 'options');
    /** @var mixed $index */
    $index = get_option(INDEX_OPTION, []);

    return is_array($index) ? array_values(array_filter($index, 'is_string')) : [];
}

/**
 * @param list<string> $ids
 */
function write_index(array $ids): void
{
    update_option(INDEX_OPTION, array_values(array_unique($ids)), false);
}

/**
 * @return array<string, mixed>|null
 */
function load(string $id): ?array
{
    if (!is_valid_id($id)) {
        return null;
    }
    /** @var mixed $task */
    $task = get_option(OPTION_PREFIX . $id, null);

    return is_array($task) ? $task : null;
}

/**
 * @param array<string, mixed> $task
 */
function save(array $task): void
{
    update_option(OPTION_PREFIX . $task['id'], $task, false);
}

function forget(string $id): void
{
    delete_option(OPTION_PREFIX . $id);
    write_index(array_values(array_diff(index(), [$id])));
}

/**
 * @param array<string, mixed> $task
 */
function is_expired(array $task, ?int $now = null): bool
{
    return ($now ?? now_ms()) >= (int) $task['created_ms'] + (int) $task['ttl_ms'];
}

/**
 * A task the given user owns and may still see, or null. Expired tasks are deleted on sight.
 *
 * @return array<string, mixed>|null
 */
function owned(int $owner, string $id): ?array
{
    $task = load($id);
    if ($task === null) {
        return null;
    }
    if (is_expired($task)) {
        forget($id);
        return null;
    }
    // Not found, not forbidden: another user's task id must not be confirmable by probing.
    if ($owner <= 0 || (int) $task['owner'] !== $owner) {
        return null;
    }

    return $task;
}

/**
 * Drop expired tasks, then the oldest past the per-user and site caps.
 */
function prune(int $owner): void
{
    $now = now_ms();
    $kept = [];
    $per_user = [];
    // Newest first, so the caps drop the oldest.
    foreach (array_reverse(index()) as $id) {
        $task = load($id);
        if ($task === null || is_expired($task, $now)) {
            delete_option(OPTION_PREFIX . $id);
            continue;
        }
        $user = (int) $task['owner'];
        $per_user[$user] = ($per_user[$user] ?? 0) + 1;
        $cap = $user === $owner ? MAX_PER_USER - 1 : MAX_PER_USER;
        if ($per_user[$user] > $cap || count($kept) >= MAX_TOTAL - 1) {
            delete_option(OPTION_PREFIX . $id);
            continue;
        }
        $kept[] = $id;
    }
    write_index(array_reverse($kept));
}

/**
 * Create and store a task.
 *
 * @param array<string, mixed> $fields status, message, result, job_id, result_ability.
 * @return array<string, mixed>
 */
function create(int $owner, string $tool, int $ttl_ms, array $fields): array
{
    prune($owner);
    $now = now_ms();
    $task = [
        'id' => wp_generate_uuid4(),
        'owner' => $owner,
        'tool' => $tool,
        'status' => (string) ($fields['status'] ?? STATUS_WORKING),
        'message' => (string) ($fields['message'] ?? ''),
        'progress' => null,
        'created_ms' => $now,
        'updated_ms' => $now,
        'ttl_ms' => $ttl_ms,
        'job_id' => is_string($fields['job_id'] ?? null) ? $fields['job_id'] : '',
        'result_ability' => is_string($fields['result_ability'] ?? null) ? $fields['result_ability'] : '',
        'result' => null,
    ];
    if (is_array($fields['result'] ?? null)) {
        $task = with_result($task, $fields['result']);
    }
    save($task);
    $index = index();
    $index[] = (string) $task['id'];
    write_index($index);

    return $task;
}

/**
 * Keep a CallToolResult on the task, unless it is too large to store.
 *
 * @param array<string, mixed> $task
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function with_result(array $task, array $result): array
{
    $encoded = wp_json_encode($result);
    if (!is_string($encoded) || strlen($encoded) > MAX_RESULT_BYTES) {
        $result = [
            'content' => [[
                'type' => 'text',
                'text' => 'The call ran, but its result was too large to keep with the task. Call the tool '
                    . 'again without task augmentation to read it; any change it made is on the Changes screen.',
            ]],
            'isError' => ($result['isError'] ?? false) === true,
        ];
    }
    $task['result'] = $result;

    return $task;
}

/**
 * The requested retention, clamped.
 *
 * @param array<string, mixed> $params
 */
function requested_ttl(array $params): int
{
    $task = is_array($params['task'] ?? null) ? $params['task'] : [];
    $ttl = $task['ttl'] ?? null;
    if (!is_int($ttl) && !(is_float($ttl) && floor($ttl) === $ttl)) {
        return TTL_DEFAULT_MS;
    }

    return max(TTL_MIN_MS, min(TTL_MAX_MS, (int) $ttl));
}

/*
 * Job-backed tasks follow their job.
 */

/**
 * Bring a job-backed task up to date with its job. Returns the task, saved if it changed.
 *
 * @param array<string, mixed> $task
 * @return array<string, mixed>
 */
function refresh(array $task): array
{
    if ($task['job_id'] === '' || in_array($task['status'], TERMINAL_STATUSES, strict: true)) {
        return $task;
    }

    $jobs = jobs();
    $job = $jobs?->get((string) $task['job_id']);
    $before = [$task['status'], $task['message'], $task['progress']];

    if ($job === null || (int) ($job['owner'] ?? 0) !== (int) $task['owner']) {
        $task['status'] = STATUS_FAILED;
        $task['message'] = $jobs === null
            ? 'The background jobs runner is not available on this request.'
            : 'The background job behind this task no longer exists.';
    } else {
        $progress = min(1.0, max(0.0, (float) ($job['progress'] ?? 0.0)));
        $message = trim((string) ($job['message'] ?? ''));
        $task['progress'] = $progress;
        $task['status'] = match ((string) ($job['status'] ?? '')) {
            'done' => STATUS_COMPLETED,
            'failed' => STATUS_FAILED,
            'cancelled' => STATUS_CANCELLED,
            default => STATUS_WORKING,
        };
        $task['message'] = match ($task['status']) {
            STATUS_WORKING => sprintf('%d%% done.', (int) floor($progress * 100)) . ($message !== '' ? ' ' . $message : ''),
            STATUS_FAILED => $message !== '' ? $message : 'The background job failed.',
            STATUS_CANCELLED => 'Cancelled. Work finished before the cancel stays done and stays undoable from the Changes screen.',
            default => $message,
        };
    }

    if ([$task['status'], $task['message'], $task['progress']] !== $before) {
        $task['updated_ms'] = now_ms();
        save($task);
    }

    return $task;
}

/**
 * The Task object clients see.
 *
 * @param array<string, mixed> $task
 * @return array<string, mixed>
 */
function wire(array $task): array
{
    $wire = [
        'taskId' => (string) $task['id'],
        'status' => (string) $task['status'],
    ];
    if ((string) $task['message'] !== '') {
        $wire['statusMessage'] = (string) $task['message'];
    }
    $wire['createdAt'] = iso((int) $task['created_ms']);
    $wire['lastUpdatedAt'] = iso((int) $task['updated_ms']);
    $wire['ttl'] = (int) $task['ttl_ms'];
    if (!in_array($task['status'], TERMINAL_STATUSES, strict: true)) {
        $wire['pollInterval'] = POLL_INTERVAL_MS;
    }

    return $wire;
}

/**
 * @param array<string, mixed> $task
 * @return array<string, mixed>
 */
function wire_with_progress(array $task): array
{
    $wire = wire($task);
    if ($task['progress'] !== null) {
        $wire['_meta'] = [META_PROGRESS => ['progress' => (float) $task['progress'], 'total' => 1.0]];
    }

    return $wire;
}

/*
 * Methods.
 */

/**
 * Route a tasks method, or a task-augmented tools/call; null for anything else.
 *
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}|null
 */
function dispatch(string $method, array $params, mixed $id): ?array
{
    if (!enabled()) {
        return null;
    }

    return match ($method) {
        'tools/call' => is_array($params['task'] ?? null) ? call_as_task($params, $id) : null,
        'tasks/get' => handle_get($params, $id),
        'tasks/result' => handle_result($params, $id),
        'tasks/list' => handle_list($params, $id),
        'tasks/cancel' => handle_cancel($params, $id),
        default => null,
    };
}

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function not_found(mixed $id): array
{
    return error_response(\WPPilot\Mcp\ERROR_INVALID_PARAMS, 'Failed to retrieve task: Task not found', 200, $id);
}

/**
 * Strip what the transport's decorator added, leaving the CallToolResult itself.
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function bare_result(array $result): array
{
    unset($result['resultType']);
    if (is_array($result['_meta'] ?? null)) {
        unset($result['_meta'][\WPPilot\Mcp\META_SERVER_INFO]);
        if ($result['_meta'] === []) {
            unset($result['_meta']);
        }
    }

    return $result;
}

/**
 * A tools/call with `params.task`: run it, then answer with a task.
 *
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function call_as_task(array $params, mixed $id): array
{
    $owner = get_current_user_id();
    if ($owner <= 0) {
        return error_response(\WPPilot\Mcp\ERROR_INVALID_PARAMS, 'Tasks need a signed-in user.', 200, $id);
    }

    $runtime = runtime();
    $tool = is_string($params['name'] ?? null) ? $params['name'] : '';
    /** @var mixed $meta */
    $meta = $tool === '' ? [] : ($runtime['meta'])($tool);
    $meta = is_array($meta) ? $meta : [];
    $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
    $support = task_support($meta);

    $inner = $params;
    unset($inner['task']);
    $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
    if ($support !== 'forbidden' && is_array($mcp['task_input'] ?? null)) {
        // The ability's own switch into background mode, e.g. search-replace-apply's background=true.
        $arguments = array_merge($arguments, $mcp['task_input']);
    }
    $inner['arguments'] = $arguments;

    /** @var array{status: int, body: array<string, mixed>} $outcome */
    $outcome = ($runtime['call'])($inner, $id);
    $result = $outcome['body']['result'] ?? null;
    // A JSON-RPC error (unknown tool, bad params) is answered as such: there is no task to poll.
    // So is any result that is not a finished one, such as a request for input.
    if (!is_array($result) || ($result['resultType'] ?? \WPPilot\Mcp\RESULT_COMPLETE) !== \WPPilot\Mcp\RESULT_COMPLETE) {
        return $outcome;
    }
    $result = bare_result($result);
    $is_error = ($result['isError'] ?? false) === true;
    $ttl = requested_ttl($params);

    $job_id = job_id_from($result, $owner, $support);
    if ($job_id !== null) {
        $task = create($owner, $tool, $ttl, [
            'status' => STATUS_WORKING,
            'message' => 'Queued.',
            'job_id' => $job_id,
            'result_ability' => is_string($mcp['task_result_ability'] ?? null) ? $mcp['task_result_ability'] : '',
        ]);
        $task = refresh($task);

        return success([
            'task' => wire($task),
            '_meta' => [
                META_MODEL_IMMEDIATE_RESPONSE => 'The work is running in the background. Poll tasks/get with this taskId '
                    . 'every few seconds, then read tasks/result once it is completed; tasks/cancel stops it.',
            ],
        ], $id, 'tools/call');
    }

    $task = create($owner, $tool, $ttl, [
        'status' => $is_error ? STATUS_FAILED : STATUS_COMPLETED,
        'message' => $is_error ? 'The tool reported an error; read it with tasks/result.' : '',
        'result' => $result,
    ]);

    return success(['task' => wire($task)], $id, 'tools/call');
}

/**
 * The job an ability queued for this call, when it is a task-capable ability that did.
 *
 * @param array<string, mixed> $result
 */
function job_id_from(array $result, int $owner, string $support): ?string
{
    if ($support === 'forbidden' || ($result['isError'] ?? false) === true) {
        return null;
    }
    $structured = is_array($result['structuredContent'] ?? null) ? $result['structuredContent'] : [];
    $job_id = $structured['job_id'] ?? null;
    if (!is_string($job_id) || $job_id === '') {
        return null;
    }
    $job = jobs()?->get($job_id);
    // A job id that is not a job of this user's is treated as ordinary output, never followed.
    if ($job === null || (int) ($job['owner'] ?? 0) !== $owner) {
        return null;
    }

    return $job_id;
}

/**
 * @param array<string, mixed> $params
 */
function requested_id(array $params): string
{
    return is_string($params['taskId'] ?? null) ? $params['taskId'] : '';
}

/**
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function handle_get(array $params, mixed $id): array
{
    $task = owned(get_current_user_id(), requested_id($params));
    if ($task === null) {
        return not_found($id);
    }

    return success(wire_with_progress(refresh($task)), $id, 'tasks/get');
}

/**
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function handle_list(array $params, mixed $id): array
{
    $owner = get_current_user_id();
    $offset = 0;
    if (array_key_exists('cursor', $params)) {
        $decoded = is_string($params['cursor']) ? base64_decode($params['cursor'], strict: true) : false;
        if (!is_string($decoded) || preg_match('/^o:(\d{1,6})$/', $decoded, $match) !== 1) {
            return error_response(\WPPilot\Mcp\ERROR_INVALID_PARAMS, 'Invalid cursor.', 200, $id);
        }
        $offset = (int) $match[1];
    }

    $mine = [];
    // Newest first.
    foreach (array_reverse(index()) as $task_id) {
        $task = owned($owner, $task_id);
        if ($task !== null) {
            $mine[] = $task;
        }
    }

    $page = array_slice($mine, $offset, PAGE_SIZE);
    $result = ['tasks' => array_map(static fn(array $task): array => wire(refresh($task)), $page)];
    if ($offset + PAGE_SIZE < count($mine)) {
        $result['nextCursor'] = base64_encode('o:' . ($offset + PAGE_SIZE));
    }

    return success($result, $id, 'tasks/list');
}

/**
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function handle_cancel(array $params, mixed $id): array
{
    $task = owned(get_current_user_id(), requested_id($params));
    if ($task === null) {
        return not_found($id);
    }
    $task = refresh($task);
    if (in_array($task['status'], TERMINAL_STATUSES, strict: true)) {
        return error_response(
            \WPPilot\Mcp\ERROR_INVALID_PARAMS,
            sprintf('Cannot cancel task: already in terminal status "%s".', (string) $task['status']),
            200,
            $id,
        );
    }

    // Only job-backed tasks are ever non-terminal. The Runner re-reads the job between steps, so a
    // step already running finishes its batch; nothing after it starts.
    jobs()?->cancel((string) $task['job_id']);
    $task['status'] = STATUS_CANCELLED;
    $task['message'] = 'Cancelled. Work finished before the cancel stays done and stays undoable from the Changes screen.';
    $task['updated_ms'] = now_ms();
    save($task);

    return success(wire($task), $id, 'tasks/cancel');
}

/**
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function handle_result(array $params, mixed $id): array
{
    $owner = get_current_user_id();
    $task = owned($owner, requested_id($params));
    if ($task === null) {
        return not_found($id);
    }
    $task = refresh($task);

    if (!in_array($task['status'], TERMINAL_STATUSES, strict: true)) {
        // The spec has tasks/result wait for the task. A PHP request cannot wait on WP-Cron, so it
        // does the waiting itself: one Runner tick, bounded by the Runner's step budget, which also
        // moves the job along on sites whose cron only runs on visits.
        $jobs = jobs();
        if ($jobs instanceof Runner) {
            $jobs->tick();
        }
        $task = refresh($task);
    }
    if (!in_array($task['status'], TERMINAL_STATUSES, strict: true)) {
        return error_response(
            ERROR_TASK_NOT_READY,
            'Task is still working. Poll tasks/get and call tasks/result once its status is completed.',
            200,
            $id,
            ['taskId' => (string) $task['id'], 'status' => (string) $task['status']],
        );
    }

    if ($task['result'] === null) {
        $task = with_result($task, final_result($task, $id));
        save($task);
    }
    $result = (array) $task['result'];
    $meta = is_array($result['_meta'] ?? null) ? $result['_meta'] : [];
    $meta[META_RELATED_TASK] = ['taskId' => (string) $task['id']];
    $result['_meta'] = $meta;

    return success($result, $id, 'tasks/result');
}

/**
 * The CallToolResult of a job-backed task that has reached a terminal status.
 *
 * @param array<string, mixed> $task
 * @return array<string, mixed>
 */
function final_result(array $task, mixed $id): array
{
    if ($task['status'] === STATUS_CANCELLED || $task['status'] === STATUS_FAILED) {
        $text = (string) $task['message'];
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => ['error' => 'task_' . $task['status'], 'message' => $text, 'job_id' => (string) $task['job_id']],
            'isError' => true,
        ];
    }

    if ($task['result_ability'] !== '') {
        /** @var array{status: int, body: array<string, mixed>} $outcome */
        $outcome = (runtime()['call'])([
            'name' => \WPPilot\Mcp\tool_name((string) $task['result_ability']),
            'arguments' => ['job_id' => (string) $task['job_id']],
        ], $id);
        $result = $outcome['body']['result'] ?? null;
        if (is_array($result)) {
            return bare_result($result);
        }
    }

    $job = jobs()?->get((string) $task['job_id']);
    $summary = [
        'job_id' => (string) $task['job_id'],
        'status' => 'done',
        'message' => (string) ($job['message'] ?? $task['message']),
        'state' => is_array($job['state'] ?? null) ? $job['state'] : [],
    ];

    return [
        'content' => [['type' => 'text', 'text' => \WPPilot\Mcp\encode_result($summary)]],
        'structuredContent' => $summary,
        'isError' => false,
    ];
}
