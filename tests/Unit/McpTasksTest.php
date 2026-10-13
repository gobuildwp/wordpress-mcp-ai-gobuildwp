<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Mcp\Tasks;
use WPPilot_Test_State;

use function WPPilot\Mcp\build_capabilities;
use function WPPilot\Mcp\decorate_tool;
use function WPPilot\Mcp\with_extension_capabilities;

require_once dirname(__DIR__, 2) . '/includes/kits/_runtime/runtime.php';
require_once dirname(__DIR__, 2) . '/includes/mcp/tasks.php';
require_once dirname(__DIR__, 2) . '/includes/mcp/apps.php';
require_once dirname(__DIR__, 2) . '/includes/mcp/extensions.php';

/**
 * Jobs that move only when the test says so, owned by whoever queued them.
 */
final class TasksFakeJobs implements Jobs
{
    /** @var array<string, array<string, mixed>> */
    public array $jobs = [];

    public function register(string $kind, callable $step): void
    {
    }

    public function enqueue(string $kind, array $payload): string|WP_Error
    {
        $id = 'job-' . (count($this->jobs) + 1);
        $this->jobs[$id] = ['id' => $id, 'kind' => $kind, 'payload' => $payload, 'state' => [], 'status' => 'queued',
            'progress' => 0.0, 'message' => '', 'owner' => get_current_user_id()];
        return $id;
    }

    public function get(string $id): ?array
    {
        return $this->jobs[$id] ?? null;
    }

    public function cancel(string $id): bool
    {
        if (!isset($this->jobs[$id]) || in_array($this->jobs[$id]['status'], ['done', 'failed', 'cancelled'], true)) {
            return false;
        }
        $this->jobs[$id]['status'] = 'cancelled';
        return true;
    }
}

final class TasksFakeHost implements Host
{
    public function __construct(public TasksFakeJobs $jobs)
    {
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
        throw new \LogicException('The tasks module never touches the ledger.');
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
        return '';
    }

    public function confirm_guard(string $ability_name, array $input): bool|WP_Error
    {
        return true;
    }
}

/**
 * MCP Tasks on the modern transport: task-augmented tools/call, tasks/get|result|list|cancel.
 *
 * The transport's call_tool() is replaced by a recorder, so these tests assert what the tasks
 * layer does with a call's outcome; the gate pipeline under that call has its own suite.
 */
final class McpTasksTest extends TestCase
{
    private TasksFakeJobs $jobs;

    /** @var list<array<string, mixed>> Every params the fake transport received. */
    private array $calls = [];

    /** @var array<string, array<string, mixed>> tool name => ability meta */
    private array $meta = [];

    /** @var array<string, array<string, mixed>> tool name => the result the fake call answers with */
    private array $answers = [];

    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, mixed> */
    private array $savedFilters = [];

    private int $savedUser = 0;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedFilters = $GLOBALS['wp_filter'] ?? [];
        $this->savedUser = WPPilot_Test_State::$current_user_id;
        WPPilot_Test_State::$current_user_id = 1;

        $this->jobs = new TasksFakeJobs();
        Runtime\host(new TasksFakeHost($this->jobs));

        Tasks\runtime([
            'call' => function (array $params, mixed $id): array {
                $this->calls[] = $params;
                $name = (string) $params['name'];
                if (!isset($this->answers[$name])) {
                    return \WPPilot\Mcp\error_response(\WPPilot\Mcp\ERROR_INVALID_PARAMS, 'Unknown tool: ' . $name, 200, $id);
                }
                $answer = $this->answers[$name];
                if (is_callable($answer)) {
                    $answer = $answer($params);
                }
                return \WPPilot\Mcp\success($answer, $id, 'tools/call');
            },
            'meta' => fn(string $tool): array => $this->meta[$tool] ?? [],
        ]);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        $GLOBALS['wp_filter'] = $this->savedFilters;
        WPPilot_Test_State::$current_user_id = $this->savedUser;
        Tasks\runtime(['call' => 'WPPilot\\Mcp\\call_tool', 'meta' => 'WPPilot\\Mcp\\Tasks\\meta_for_tool']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> The JSON-RPC body.
     */
    private static function rpc(string $method, array $params = []): array
    {
        $outcome = Tasks\dispatch($method, $params, 7);
        self::assertIsArray($outcome, $method . ' was not handled');
        return $outcome['body'];
    }

    /** @return array<string, mixed> */
    private static function ok(array $body): array
    {
        self::assertArrayNotHasKey('error', $body, json_encode($body['error'] ?? null) ?: '');
        return $body['result'];
    }

    private static function errorCode(array $body): int
    {
        self::assertArrayHasKey('error', $body);
        return (int) $body['error']['code'];
    }

    /** @return array<string, mixed> */
    private static function textResult(string $text, bool $error = false): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => ['text' => $text], 'isError' => $error];
    }

    private function backgroundTool(): void
    {
        $this->meta['wppilot_search_replace_apply'] = ['mcp' => [
            'public' => true,
            'task_support' => 'optional',
            'task_input' => ['background' => true],
            'task_result_ability' => 'wppilot/search-replace-status',
        ]];
        $this->answers['wppilot_search_replace_apply'] = function (array $params): array {
            $job = $this->jobs->enqueue('sr', ['plan_id' => $params['arguments']['plan_id']]);
            return ['content' => [['type' => 'text', 'text' => 'queued']], 'structuredContent' => ['job_id' => $job, 'status' => 'queued'], 'isError' => false];
        };
        $this->answers['wppilot_search_replace_status'] = fn(array $params): array => self::textResult('status of ' . $params['arguments']['job_id']);
    }

    /** @return array<string, mixed> The task handle. */
    private function startBackgroundTask(array $task = []): array
    {
        $this->backgroundTool();
        $created = self::ok(self::rpc('tools/call', [
            'name' => 'wppilot_search_replace_apply',
            'arguments' => ['plan_id' => 'p1', 'confirm' => true],
            'task' => $task,
        ]));
        return $created['task'];
    }

    public function testCapabilityIsDeclaredInBothPlacesWhenEnabledAndToolsAreServed(): void
    {
        $capabilities = with_extension_capabilities(build_capabilities(3, 0, 0));
        $expected = '{"list":{},"cancel":{},"requests":{"tools":{"call":{}}}}';

        self::assertSame($expected, json_encode($capabilities['tasks']));
        self::assertSame('{"io.modelcontextprotocol/tasks":' . $expected . '}', json_encode($capabilities['extensions'], JSON_UNESCAPED_SLASHES));
    }

    public function testCapabilityKeepsOtherExtensions(): void
    {
        $capabilities = build_capabilities(3, 0, 1);
        $capabilities['extensions'] = (object) ['io.modelcontextprotocol/skills' => new \stdClass()];

        $declared = with_extension_capabilities($capabilities);

        self::assertSame(
            ['io.modelcontextprotocol/skills', 'io.modelcontextprotocol/tasks'],
            array_keys(get_object_vars($declared['extensions'])),
        );
    }

    public function testNothingIsDeclaredOrServedWhenDisabledOrWithoutTools(): void
    {
        self::assertArrayNotHasKey('tasks', with_extension_capabilities(build_capabilities(0, 2, 0)));

        add_filter('wppilot_mcp_tasks_enabled', static fn(): bool => false);
        $capabilities = with_extension_capabilities(build_capabilities(3, 0, 0));

        self::assertArrayNotHasKey('tasks', $capabilities);
        self::assertArrayNotHasKey('extensions', $capabilities);
        self::assertNull(Tasks\dispatch('tasks/list', [], 1));
        // A receiver without tasks runs the call normally; the transport's own tools/call does that.
        self::assertNull(Tasks\dispatch('tools/call', ['name' => 'x', 'task' => []], 1));
        self::assertNull(Tasks\tool_execution(['mcp' => ['task_support' => 'optional']]));
    }

    public function testOnlyDeclaredAbilitiesAdvertiseTaskSupport(): void
    {
        $tool = ['name' => 't'];

        self::assertSame(['name' => 't'], decorate_tool($tool, ['mcp' => ['public' => true]]));
        self::assertSame(['taskSupport' => 'optional'], decorate_tool($tool, ['mcp' => ['task_support' => 'optional']])['execution']);
        self::assertSame(['taskSupport' => 'required'], decorate_tool($tool, ['mcp' => ['task_support' => 'required']])['execution']);
        self::assertArrayNotHasKey('execution', decorate_tool($tool, ['mcp' => ['task_support' => 'sometimes']]));
    }

    public function testSynchronousFallbackReturnsACompletedTaskAndItsResult(): void
    {
        $this->answers['wppilot_get_post'] = self::textResult('the post');

        $created = self::ok(self::rpc('tools/call', ['name' => 'wppilot_get_post', 'arguments' => ['id' => 3], 'task' => ['ttl' => 120000]]));

        self::assertSame(['name' => 'wppilot_get_post', 'arguments' => ['id' => 3]], $this->calls[0], 'the task field never reaches the call');
        $task = $created['task'];
        self::assertSame('completed', $task['status']);
        self::assertSame(120000, $task['ttl']);
        self::assertArrayNotHasKey('pollInterval', $task);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $task['createdAt']);

        $got = self::ok(self::rpc('tasks/get', ['taskId' => $task['taskId']]));
        self::assertSame('completed', $got['status']);

        $result = self::ok(self::rpc('tasks/result', ['taskId' => $task['taskId']]));
        self::assertSame('the post', $result['content'][0]['text']);
        self::assertFalse($result['isError']);
        self::assertSame(['taskId' => $task['taskId']], $result['_meta']['io.modelcontextprotocol/related-task']);
        self::assertSame('complete', $result['resultType']);
        self::assertCount(1, $this->calls, 'the result is stored, never recomputed');
    }

    public function testAToolErrorFailsTheTaskAndKeepsTheError(): void
    {
        $this->answers['wppilot_delete_post'] = self::textResult('refused: confirm', true);

        $task = self::ok(self::rpc('tools/call', ['name' => 'wppilot_delete_post', 'task' => []]))['task'];

        self::assertSame('failed', $task['status']);
        $result = self::ok(self::rpc('tasks/result', ['taskId' => $task['taskId']]));
        self::assertTrue($result['isError']);
        self::assertSame('refused: confirm', $result['content'][0]['text']);
    }

    public function testAJsonRpcErrorIsAnsweredDirectlyAndStoresNoTask(): void
    {
        $body = self::rpc('tools/call', ['name' => 'no_such_tool', 'task' => []]);

        self::assertSame(-32602, self::errorCode($body));
        self::assertSame([], self::ok(self::rpc('tasks/list'))['tasks']);
    }

    public function testATaskCapableCallRunsInTheBackgroundAndFollowsItsJob(): void
    {
        $task = $this->startBackgroundTask();

        self::assertSame(['plan_id' => 'p1', 'confirm' => true, 'background' => true], $this->calls[0]['arguments']);
        self::assertSame('working', $task['status']);
        self::assertSame(5000, $task['pollInterval']);

        $this->jobs->jobs['job-1']['status'] = 'running';
        $this->jobs->jobs['job-1']['progress'] = 0.4;
        $this->jobs->jobs['job-1']['message'] = '40 applied, 0 skipped, 60 remaining.';
        $got = self::ok(self::rpc('tasks/get', ['taskId' => $task['taskId']]));
        self::assertSame('working', $got['status']);
        self::assertSame('40% done. 40 applied, 0 skipped, 60 remaining.', $got['statusMessage']);
        self::assertSame(['progress' => 0.4, 'total' => 1.0], $got['_meta']['co.wppilot/progress']);

        // Still working: tasks/result says so instead of pretending to have a result.
        $early = self::rpc('tasks/result', ['taskId' => $task['taskId']]);
        self::assertSame(Tasks\ERROR_TASK_NOT_READY, self::errorCode($early));
        self::assertSame('working', $early['error']['data']['status']);

        $this->jobs->jobs['job-1']['status'] = 'done';
        $this->jobs->jobs['job-1']['progress'] = 1.0;
        self::assertSame('completed', self::ok(self::rpc('tasks/get', ['taskId' => $task['taskId']]))['status']);

        $result = self::ok(self::rpc('tasks/result', ['taskId' => $task['taskId']]));
        self::assertSame('status of job-1', $result['content'][0]['text']);
        self::assertSame(['name' => 'wppilot_search_replace_status', 'arguments' => ['job_id' => 'job-1']], end($this->calls));
        self::assertSame($task['taskId'], $result['_meta']['io.modelcontextprotocol/related-task']['taskId']);
    }

    public function testAFailedJobFailsTheTaskWithItsMessage(): void
    {
        $task = $this->startBackgroundTask();
        $this->jobs->jobs['job-1']['status'] = 'failed';
        $this->jobs->jobs['job-1']['message'] = 'Plan expired.';

        $got = self::ok(self::rpc('tasks/get', ['taskId' => $task['taskId']]));
        self::assertSame('failed', $got['status']);
        self::assertSame('Plan expired.', $got['statusMessage']);

        $result = self::ok(self::rpc('tasks/result', ['taskId' => $task['taskId']]));
        self::assertTrue($result['isError']);
        self::assertSame('task_failed', $result['structuredContent']['error']);
    }

    public function testCancelStopsTheJobAndATerminalTaskCannotBeCancelled(): void
    {
        $task = $this->startBackgroundTask();

        $cancelled = self::ok(self::rpc('tasks/cancel', ['taskId' => $task['taskId']]));

        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('cancelled', $this->jobs->jobs['job-1']['status']);
        self::assertSame(-32602, self::errorCode(self::rpc('tasks/cancel', ['taskId' => $task['taskId']])));
        $result = self::ok(self::rpc('tasks/result', ['taskId' => $task['taskId']]));
        self::assertTrue($result['isError']);
        self::assertSame('task_cancelled', $result['structuredContent']['error']);

        $this->answers['wppilot_get_post'] = self::textResult('x');
        $sync = self::ok(self::rpc('tools/call', ['name' => 'wppilot_get_post', 'task' => []]))['task'];
        self::assertSame(-32602, self::errorCode(self::rpc('tasks/cancel', ['taskId' => $sync['taskId']])));
    }

    public function testAJobCancelledElsewhereCancelsTheTask(): void
    {
        $task = $this->startBackgroundTask();
        $this->jobs->cancel('job-1');

        self::assertSame('cancelled', self::ok(self::rpc('tasks/get', ['taskId' => $task['taskId']]))['status']);
    }

    public function testTasksBelongToTheirUser(): void
    {
        $task = $this->startBackgroundTask();

        WPPilot_Test_State::$current_user_id = 2;
        foreach (['tasks/get', 'tasks/result', 'tasks/cancel'] as $method) {
            $body = self::rpc($method, ['taskId' => $task['taskId']]);
            self::assertSame(-32602, self::errorCode($body), $method);
            self::assertSame('Failed to retrieve task: Task not found', $body['error']['message'], $method);
        }
        self::assertSame([], self::ok(self::rpc('tasks/list'))['tasks']);
        self::assertSame('queued', $this->jobs->jobs['job-1']['status'], 'the other user could not cancel it');

        WPPilot_Test_State::$current_user_id = 1;
        self::assertSame([$task['taskId']], array_column(self::ok(self::rpc('tasks/list'))['tasks'], 'taskId'));
    }

    public function testAJobIdThatIsNotTheCallersJobIsNotFollowed(): void
    {
        $this->backgroundTool();
        WPPilot_Test_State::$current_user_id = 2;
        $this->jobs->enqueue('sr', []);
        WPPilot_Test_State::$current_user_id = 1;
        $this->answers['wppilot_search_replace_apply'] = ['content' => [], 'structuredContent' => ['job_id' => 'job-1'], 'isError' => false];

        $task = self::ok(self::rpc('tools/call', ['name' => 'wppilot_search_replace_apply', 'task' => []]))['task'];

        self::assertSame('completed', $task['status']);
    }

    public function testAnUndeclaredToolGetsNoTaskInputAndIsNeverFollowed(): void
    {
        $this->answers['wppilot_other'] = function (array $params): array {
            $job = $this->jobs->enqueue('sr', []);
            return ['content' => [], 'structuredContent' => ['job_id' => $job], 'isError' => false, 'args' => $params['arguments']];
        };

        $task = self::ok(self::rpc('tools/call', ['name' => 'wppilot_other', 'arguments' => ['a' => 1], 'task' => []]))['task'];

        self::assertSame(['a' => 1], $this->calls[0]['arguments']);
        self::assertSame('completed', $task['status']);
    }

    public function testExpiredTasksAreGoneAndTtlIsClamped(): void
    {
        $this->answers['wppilot_get_post'] = self::textResult('x');
        $task = self::ok(self::rpc('tools/call', ['name' => 'wppilot_get_post', 'task' => ['ttl' => 1]]))['task'];
        self::assertSame(Tasks\TTL_MIN_MS, $task['ttl']);

        $option = Tasks\OPTION_PREFIX . $task['taskId'];
        $stored = WPPilot_Test_State::$options[$option];
        $stored['created_ms'] -= Tasks\TTL_MIN_MS + 1;
        WPPilot_Test_State::$options[$option] = $stored;

        self::assertSame(-32602, self::errorCode(self::rpc('tasks/get', ['taskId' => $task['taskId']])));
        self::assertArrayNotHasKey($option, WPPilot_Test_State::$options);
        self::assertNotContains($task['taskId'], WPPilot_Test_State::$options[Tasks\INDEX_OPTION]);

        self::assertSame(Tasks\TTL_MAX_MS, Tasks\requested_ttl(['task' => ['ttl' => PHP_INT_MAX]]));
        self::assertSame(Tasks\TTL_DEFAULT_MS, Tasks\requested_ttl(['task' => ['ttl' => 'soon']]));
        self::assertSame(Tasks\TTL_DEFAULT_MS, Tasks\requested_ttl(['task' => []]));
    }

    public function testListPagesNewestFirstAndRejectsABadCursor(): void
    {
        $this->answers['wppilot_get_post'] = self::textResult('x');
        $ids = [];
        for ($i = 0; $i < Tasks\PAGE_SIZE + 2; $i++) {
            $ids[] = self::ok(self::rpc('tools/call', ['name' => 'wppilot_get_post', 'task' => []]))['task']['taskId'];
        }

        $first = self::ok(self::rpc('tasks/list'));
        self::assertCount(Tasks\PAGE_SIZE, $first['tasks']);
        self::assertSame(end($ids), $first['tasks'][0]['taskId']);
        $second = self::ok(self::rpc('tasks/list', ['cursor' => $first['nextCursor']]));
        self::assertCount(2, $second['tasks']);
        self::assertArrayNotHasKey('nextCursor', $second);

        self::assertSame(-32602, self::errorCode(self::rpc('tasks/list', ['cursor' => 'not-a-cursor'])));
    }

    public function testOneUserCannotStoreMoreThanTheCap(): void
    {
        $this->answers['wppilot_get_post'] = self::textResult('x');
        for ($i = 0; $i < Tasks\MAX_PER_USER + 5; $i++) {
            self::rpc('tools/call', ['name' => 'wppilot_get_post', 'task' => []]);
        }

        self::assertCount(Tasks\MAX_PER_USER, WPPilot_Test_State::$options[Tasks\INDEX_OPTION]);
    }

    public function testAnAnonymousCallerGetsNoTask(): void
    {
        WPPilot_Test_State::$current_user_id = 0;
        $this->answers['wppilot_get_post'] = self::textResult('x');

        self::assertSame(-32602, self::errorCode(self::rpc('tools/call', ['name' => 'wppilot_get_post', 'task' => []])));
        self::assertSame([], $this->calls);
    }
}
