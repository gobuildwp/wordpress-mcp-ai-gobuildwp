<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The generated client configuration on the Connect screen.
 *
 * Every snippet here is pasted into a file some other program parses, so the
 * promises are about shape: the key each client documents, the transport
 * spelling it accepts, the file it actually reads. The per-client expectations
 * follow the vendor pages cited in the builders (checked 2026-09-30); when a
 * vendor changes its format, the builder and the assertion here change together.
 *
 * Each test runs in its own process and loads the builders in setUp(), which
 * PHPUnit runs in the child only: loading them defines
 * wppilot_oauth_transport_allowed(), and the connection doctor's tests rely on
 * that function being absent (OAuth "not available") in the shared process.
 */
#[RunTestsInSeparateProcesses]
final class ClientConfigsTest extends TestCase
{
    private const URL = 'https://example.test/wp-json/mcp/wppilot';
    private const NAME = 'gobuildwp-example';
    private const TOKEN = 'wpp_test-token';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/doubles/connect-environment.php';
        require_once dirname(__DIR__, 2) . '/includes/clients.php';
        require_once dirname(__DIR__, 2) . '/includes/admin/connect-methods.php';
        require_once dirname(__DIR__, 2) . '/includes/admin/connect/client-configs.php';
        require_once dirname(__DIR__, 2) . '/includes/admin/connect/token-configs.php';
        require_once dirname(__DIR__, 2) . '/includes/admin/connect/web-apps.php';
    }

    /** @return array<string, mixed> */
    private static function json(string $code): array
    {
        $decoded = json_decode($code, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, array<string, mixed>> */
    private static function token(): array
    {
        return wppilot_build_token_configs(self::URL, self::NAME, self::TOKEN);
    }

    /** @return array<string, array<string, mixed>> */
    private static function oauthPublic(): array
    {
        return wppilot_build_oauth_public_configs(self::URL, self::NAME);
    }

    /** @return array<string, array<string, mixed>> */
    private static function oauthBridge(): array
    {
        return wppilot_build_oauth_bridge_configs(self::URL, self::NAME, []);
    }

    /** @return array<string, array<string, mixed>> */
    private static function password(): array
    {
        return wppilot_build_configs(self::URL, 'admin', 'abcd efgh', self::NAME);
    }

    /**
     * Keys of the selectable clients that offer a method.
     *
     * @return list<string>
     */
    private static function clientsOffering(string $method): array
    {
        $keys = [];
        foreach (wppilot_selectable_clients() as $key => $client) {
            if (in_array($method, (array) ($client['methods'] ?? []), true)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    public function test_every_client_offering_a_method_has_an_entry_for_it(): void
    {
        $password = self::password();
        foreach (self::clientsOffering('password') as $key) {
            self::assertArrayHasKey($key, $password, "{$key} offers an application password but has no snippet");
        }

        $token = self::token();
        foreach (self::clientsOffering('token') as $key) {
            self::assertArrayHasKey($key, $token, "{$key} offers an access token but has no snippet");
        }

        // OAuth: the Claude web target is keyed claude-ai in the OAuth panel, and
        // the Codex app and Gemini CLI have no public-site entry yet (reported
        // separately rather than papered over here).
        $public = self::oauthPublic() + wppilot_build_oauth_web_ui_configs(self::URL, self::NAME);
        foreach (array_diff(self::clientsOffering('oauth'), ['claude-web', 'codex-app', 'gemini-cli']) as $key) {
            self::assertArrayHasKey($key, $public, "{$key} offers OAuth but has no public-site entry");
        }

        $web = array_keys(wppilot_clients_web_ui());
        foreach (array_diff(self::clientsOffering('oauth'), $web) as $key) {
            self::assertArrayHasKey($key, self::oauthBridge(), "{$key} offers OAuth but has no local-site entry");
        }
    }

    public function test_every_json_snippet_parses(): void
    {
        $sets = ['password' => self::password(), 'token' => self::token(), 'oauth-public' => self::oauthPublic(), 'oauth-bridge' => self::oauthBridge()];
        foreach ($sets as $set => $configs) {
            foreach ($configs as $key => $entry) {
                $code = (string) ($entry['code'] ?? '');
                if ($code === '' || ($entry['isShell'] ?? false) === true || str_starts_with($code, '[mcp_servers.')) {
                    continue;
                }
                self::assertNotNull(json_decode($code, true), "{$set}/{$key} is not valid JSON");
            }
        }
    }

    public function test_verified_clients_run_oauth_natively_and_only_roo_code_keeps_the_bridge(): void
    {
        $clients = wppilot_clients();
        foreach (['windsurf', 'zed', 'cline', 'openclaw', 'kilo-code', 'amazon-q', 'opencode'] as $key) {
            self::assertSame('native', $clients[$key]['oauth'], $key);
        }
        self::assertSame('proxy', $clients['roo-code']['oauth']);

        $public = self::oauthPublic();
        foreach (['zed', 'cline', 'kilo-code', 'opencode', 'amazon-q', 'openclaw', 'kimi-cli', 'qwen-code', 'factory-droid'] as $key) {
            self::assertStringNotContainsString('mcp-remote', (string) $public[$key]['code'], $key);
        }
        self::assertStringContainsString('mcp-remote', (string) $public['roo-code']['code']);
    }

    public function test_kimi_targets_kimi_code_not_the_archived_python_cli(): void
    {
        $token = self::token()['kimi-cli'];
        $server = self::json($token['code'])['mcpServers'][self::NAME];
        self::assertSame(['url' => self::URL, 'headers' => ['Authorization' => 'Bearer ' . self::TOKEN]], $server);

        foreach ([$token, self::password()['kimi-cli'], self::oauthPublic()['kimi-cli'], self::oauthBridge()['kimi-cli']] as $entry) {
            self::assertContains('~/.kimi-code/mcp.json', $entry['paths']);
            self::assertNotContains('~/.kimi/mcp.json', $entry['paths']);
        }
        self::assertStringNotContainsString('kimi mcp add', $token['hint']);
        self::assertStringContainsString('/mcp-config login', (string) self::oauthPublic()['kimi-cli']['note']);
    }

    public function test_copilot_snippets_use_the_copilot_cli_format(): void
    {
        $entries = [self::token()['github-copilot'], self::password()['github-copilot'], self::oauthPublic()['github-copilot'], self::oauthBridge()['github-copilot']];
        foreach ($entries as $entry) {
            $config = self::json($entry['code']);
            self::assertArrayNotHasKey('servers', $config, 'Copilot CLI rejects the servers key');
            self::assertSame(['*'], $config['mcpServers'][self::NAME]['tools']);
            self::assertContains('~/.copilot/mcp-config.json', $entry['paths']);
            self::assertNotContains('.github/copilot/mcp.json', $entry['paths']);
        }

        self::assertSame('http', self::json(self::token()['github-copilot']['code'])['mcpServers'][self::NAME]['type']);
        self::assertSame('local', self::json(self::password()['github-copilot']['code'])['mcpServers'][self::NAME]['type']);
        self::assertStringContainsString('cloud agent cannot use OAuth', (string) self::oauthPublic()['github-copilot']['note']);
    }

    public function test_devin_desktop_lists_its_current_path_and_the_windsurf_one(): void
    {
        foreach ([self::token()['windsurf'], self::password()['windsurf'], self::oauthPublic()['windsurf'], self::oauthBridge()['windsurf']] as $entry) {
            self::assertContains('~/.config/devin/mcp_config.json', $entry['paths']);
            self::assertContains('%APPDATA%\\devin\\mcp_config.json', $entry['paths']);
            self::assertContains('~/.codeium/windsurf/mcp_config.json', $entry['paths']);
        }
        self::assertSame(self::URL, self::json(self::token()['windsurf']['code'])['mcpServers'][self::NAME]['serverUrl']);
    }

    public function test_zed_connects_natively_without_the_deprecated_source_marker(): void
    {
        self::assertSame(
            ['url' => self::URL, 'headers' => ['Authorization' => 'Bearer ' . self::TOKEN]],
            self::json(self::token()['zed']['code'])['context_servers'][self::NAME],
        );
        self::assertSame(['url' => self::URL], self::json(self::oauthPublic()['zed']['code'])['context_servers'][self::NAME]);

        foreach ([self::password()['zed'], self::oauthBridge()['zed']] as $entry) {
            $server = self::json($entry['code'])['context_servers'][self::NAME];
            self::assertArrayNotHasKey('source', $server);
            self::assertSame('npx', $server['command']);
        }
    }

    public function test_cline_opencode_and_kilo_use_their_documented_remote_shapes(): void
    {
        self::assertSame(
            ['type' => 'streamableHttp', 'url' => self::URL],
            self::json(self::oauthPublic()['cline']['code'])['mcpServers'][self::NAME],
        );
        self::assertSame('streamableHttp', self::json(self::token()['cline']['code'])['mcpServers'][self::NAME]['type']);

        foreach (['opencode', 'kilo-code'] as $key) {
            $server = self::json(self::token()[$key]['code'])['mcp'][self::NAME];
            self::assertSame('remote', $server['type']);
            self::assertFalse($server['oauth'], 'a header-authenticated server should not start a sign-in');
            self::assertSame(['type' => 'remote', 'url' => self::URL], self::json(self::oauthPublic()[$key]['code'])['mcp'][self::NAME]);
            self::assertSame('local', self::json(self::password()[$key]['code'])['mcp'][self::NAME]['type']);
        }

        foreach ([self::token()['kilo-code'], self::password()['kilo-code'], self::oauthPublic()['kilo-code'], self::oauthBridge()['kilo-code']] as $entry) {
            self::assertContains('~/.config/kilo/kilo.jsonc', $entry['paths']);
            self::assertNotContains('.kilocode/mcp.json', $entry['paths']);
        }
    }

    public function test_openclaw_uses_mcp_servers_and_streamable_http(): void
    {
        $server = self::json(self::token()['openclaw']['code'])['mcp']['servers'][self::NAME];
        self::assertSame('streamable-http', $server['transport']);
        self::assertSame(self::URL, $server['url']);
        self::assertSame('Bearer ' . self::TOKEN, $server['headers']['Authorization']);

        $oauth = self::oauthPublic()['openclaw'];
        self::assertTrue($oauth['isShell']);
        self::assertStringContainsString('"transport":"streamable-http"', $oauth['code']);
        self::assertStringContainsString('"auth":"oauth"', $oauth['code']);
        self::assertStringContainsString('openclaw mcp login', $oauth['code']);

        self::assertSame('npx', self::json(self::password()['openclaw']['code'])['mcp']['servers'][self::NAME]['command']);
    }

    public function test_claude_desktop_token_entry_is_a_local_bridge_not_a_remote_server(): void
    {
        $server = self::json(self::token()['claude-desktop']['code'])['mcpServers'][self::NAME];
        self::assertArrayNotHasKey('url', $server);
        self::assertArrayNotHasKey('type', $server);
        self::assertSame('npx', $server['command']);
        self::assertSame(['-y', 'mcp-remote', self::URL, '--header', 'Authorization:${AUTH_HEADER}'], $server['args']);
        self::assertSame('Bearer ' . self::TOKEN, $server['env']['AUTH_HEADER']);
        foreach ($server['args'] as $arg) {
            self::assertStringNotContainsString(' ', $arg, 'Claude Desktop on Windows splits args on spaces');
        }
    }

    public function test_wording_fixes(): void
    {
        $token = self::token();
        self::assertStringNotContainsString('needing authentication', $token['claude-code']['hint']);
        self::assertStringNotContainsString('display bug', (string) wppilot_clients()['claude-code']['note']);
        self::assertStringNotContainsString('Customizations', $token['antigravity-ide']['hint']);
        self::assertStringContainsString('ZooCode', $token['roo-code']['hint']);
        self::assertStringContainsString('ZooCode', (string) wppilot_clients()['roo-code']['note']);
    }

    public function test_oauth_entries_exist_for_qwen_zcode_and_droid(): void
    {
        $public = self::oauthPublic();
        self::assertSame(
            ['httpUrl' => self::URL, 'oauth' => ['enabled' => true]],
            self::json($public['qwen-code']['code'])['mcpServers'][self::NAME],
        );
        self::assertNotEmpty($public['zcode']['steps']);
        self::assertSame(self::URL, $public['zcode']['steps'][0]['copy']);
        self::assertSame("droid mcp add 'gobuildwp-example' '" . self::URL . "' --type http", $public['factory-droid']['code']);
    }

    public function test_manus_takes_an_access_token(): void
    {
        self::assertContains('token', wppilot_clients()['manus']['methods']);
        $steps = wppilot_build_token_web_ui_configs(self::URL, self::TOKEN)['manus']['steps'];
        self::assertContains(self::URL, $steps);
        self::assertContains(self::TOKEN, $steps);
        self::assertSame('manus', wppilot_web_apps()['manus']['bearer_steps'] ?? null);
    }

    public function test_the_suggested_server_name_carries_the_product_name_within_25_characters(): void
    {
        $name = wppilot_get_mcp_server_name_default();

        self::assertSame('gobuildwp-example-test', $name);
        self::assertLessThanOrEqual(25, strlen($name));
    }
}
