<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp\SkillResources;

use WP_REST_Request;
use WP_REST_Response;

use function WPPilot\Mcp\error_response;
use function WPPilot\Mcp\success;

/**
 * GoBuildWP's skills and industry briefs as MCP resources, per the Skills extension.
 *
 * Implements SEP-2640 (Final): each skill is a directory whose `SKILL.md` is the resource
 * `skill://<skill-path>/SKILL.md`, served as `text/markdown`, enumerated by `skills/list`,
 * described by `skills/get`, and read through plain `resources/read`. GoBuildWP skills are single
 * files, so every entry lists exactly one resource with its SHA-256 digest and byte size.
 *
 *   skill://<slug>/SKILL.md                     a skill from any catalog source (built-in, kits,
 *                                               the site's own, a companion plugin's)
 *   skill://industry-briefs/<slug>/SKILL.md     a prompt-library industry brief
 *
 * Only what the agent may already see is served. Skills come from Sources\discoverable('agentic'),
 * the same list the discover-abilities catalog prints: published, described, non-empty and not
 * opted out of agentic discovery. Briefs marked Pro are left out unless Pro is active, matching
 * the Prompts screen that locks them. Everything here runs inside the MCP route after its own
 * permission check, so no anonymous caller reaches it.
 *
 * @link https://modelcontextprotocol.io/seps/2640-skills-extension
 */

if (!defined('ABSPATH')) {
    exit();
}

/** The capability key a server declares the extension under, inside `capabilities.extensions`. */
const EXTENSION_ID = 'io.modelcontextprotocol/skills';

const SCHEME = 'skill://';

const MIME_TYPE = 'text/markdown';

/** Organisational prefix for the prompt-library briefs, so a brief never shadows a skill slug. */
const BRIEF_PREFIX = 'industry-briefs';

/** `_meta` key naming which catalog a resource came from. SEP-2640 reserves this prefix for skills. */
const META_SOURCE = 'io.modelcontextprotocol.skills/source';

/**
 * Whether a name satisfies the Agent Skills naming rules SEP-2640 requires of the final URI segment:
 * 1-64 lowercase letters, digits and single hyphens, not starting or ending with one.
 *
 * WordPress slugs can carry percent-encoded Unicode and underscores; a skill with such a slug is left
 * out rather than served under a URI whose last segment differs from its declared name, which a
 * conforming host must reject anyway.
 */
function is_valid_skill_name(string $name): bool
{
    return strlen($name) <= 64 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) === 1;
}

/**
 * Render a SKILL.md and the JSON form of its frontmatter from the same values.
 *
 * SEP-2640 requires the listing's `frontmatter` to be identical in content to the file's, and a host
 * compares them field by field. The description is written as a JSON string, which is also a valid
 * YAML double-quoted scalar: the Skills module's own renderer writes it bare, and a description
 * containing `: ` or starting with a quote would parse differently on the host and fail verification.
 *
 * @param array<string, string|bool> $frontmatter Ordered; values are strings or booleans only.
 * @return array{text: string, frontmatter: array<string, string|bool>}
 */
function render_skill_file(array $frontmatter, string $body): array
{
    $lines = ['---'];
    foreach ($frontmatter as $key => $value) {
        if (is_bool($value)) {
            $lines[] = $key . ': ' . ($value ? 'true' : 'false');
            continue;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $lines[] = $key . ': ' . (is_string($encoded) ? $encoded : '""');
    }
    $lines[] = '---';

    return [
        'text' => implode("\n", $lines) . "\n\n" . trim($body) . "\n",
        'frontmatter' => $frontmatter,
    ];
}

/**
 * Build the served entries from catalog records. Pure, so the visibility rules are testable.
 *
 * @param list<array<string, mixed>> $skills Records from Sources\discoverable('agentic').
 * @param list<array{slug: string, title: string, description: string, text: string}> $briefs Composed briefs.
 * @return list<array{uri: string, name: string, title: string, description: string, source: string, frontmatter: array<string, string|bool>, text: string}>
 */
function build_entries(array $skills, array $briefs): array
{
    $entries = [];
    $seen = [];

    foreach ($skills as $skill) {
        $name = is_string($skill['slug'] ?? null) ? $skill['slug'] : '';
        $description = trim(str_replace(["\r", "\n"], ' ', (string) ($skill['description'] ?? '')));
        $body = (string) ($skill['content'] ?? '');
        if (!is_valid_skill_name($name) || $description === '' || trim($body) === '') {
            continue;
        }
        $uri = SCHEME . $name . '/SKILL.md';
        // Sources are priority-ordered and skill-get resolves a slug to the first match, so the
        // resource does the same instead of serving a second, shadowed copy under one URI.
        if (isset($seen[$uri])) {
            continue;
        }
        $seen[$uri] = true;
        $file = render_skill_file([
            'name' => $name,
            'description' => $description,
            'enable_prompt' => (bool) ($skill['enable_prompt'] ?? false),
            'enable_agentic' => (bool) ($skill['enable_agentic'] ?? true),
        ], $body);
        $entries[] = [
            'uri' => $uri,
            'name' => $name,
            'title' => (string) ($skill['name'] ?? $name),
            'description' => $description,
            'source' => (string) ($skill['source'] ?? ''),
            'frontmatter' => $file['frontmatter'],
            'text' => $file['text'],
        ];
    }

    foreach ($briefs as $brief) {
        $name = str_replace('_', '-', $brief['slug']);
        $description = trim(str_replace(["\r", "\n"], ' ', $brief['description'] !== '' ? $brief['description'] : $brief['title']));
        if (!is_valid_skill_name($name) || $description === '' || trim($brief['text']) === '') {
            continue;
        }
        $uri = SCHEME . BRIEF_PREFIX . '/' . $name . '/SKILL.md';
        if (isset($seen[$uri])) {
            continue;
        }
        $seen[$uri] = true;
        $file = render_skill_file(['name' => $name, 'description' => $description], $brief['text']);
        $entries[] = [
            'uri' => $uri,
            'name' => $name,
            'title' => $brief['title'],
            'description' => $description,
            'source' => 'prompt-library',
            'frontmatter' => $file['frontmatter'],
            'text' => $file['text'],
        ];
    }

    return $entries;
}

/**
 * The entries this site serves right now.
 *
 * @return list<array{uri: string, name: string, title: string, description: string, source: string, frontmatter: array<string, string|bool>, text: string}>
 */
function entries(): array
{
    $skills = function_exists('WPPilot\\Skills\\Sources\\discoverable')
        ? \WPPilot\Skills\Sources\discoverable('agentic')
        : [];

    $entries = build_entries($skills, runtime_briefs());

    /**
     * Filter the skill resources served over MCP.
     *
     * Removing an entry hides it from resources/list, skills/list and every read. Adding one is
     * possible but the entry must keep the documented shape; malformed entries are dropped.
     *
     * @param list<array<string, mixed>> $entries
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_mcp_skill_resources', $entries);
    if (!is_array($filtered)) {
        return $entries;
    }

    $clean = [];
    /** @var mixed $entry */
    foreach ($filtered as $entry) {
        if (
            is_array($entry)
            && is_string($entry['uri'] ?? null)
            && str_starts_with($entry['uri'], SCHEME)
            && is_string($entry['name'] ?? null)
            && is_string($entry['text'] ?? null)
            && is_array($entry['frontmatter'] ?? null)
        ) {
            $clean[] = [
                'uri' => $entry['uri'],
                'name' => $entry['name'],
                'title' => is_string($entry['title'] ?? null) ? $entry['title'] : $entry['name'],
                'description' => is_string($entry['description'] ?? null) ? $entry['description'] : '',
                'source' => is_string($entry['source'] ?? null) ? $entry['source'] : '',
                'frontmatter' => $entry['frontmatter'],
                'text' => $entry['text'],
            ];
        }
    }

    return $clean;
}

/**
 * The prompt-library briefs an agent may read, composed for the site's default builder.
 *
 * @return list<array{slug: string, title: string, description: string, text: string}>
 */
function runtime_briefs(): array
{
    if (!function_exists('WPPilot\\PromptLibrary\\briefs')) {
        return [];
    }

    // Same signal the Prompts screen uses to lock Pro briefs (WPPilot\PromptLibrary\Admin\pro_active),
    // read directly because that file is admin UI and may not define it in every context.
    $pro = is_array(apply_filters('wppilot_pro_status', null));
    $builder = \WPPilot\PromptLibrary\default_builder();

    $briefs = [];
    foreach (\WPPilot\PromptLibrary\briefs() as $brief) {
        if ($brief['pro'] && !$pro) {
            continue;
        }
        $briefs[] = [
            'slug' => $brief['slug'],
            'title' => $brief['title'],
            'description' => $brief['description'],
            'text' => \WPPilot\PromptLibrary\compose($brief, $builder),
        ];
    }

    return $briefs;
}

/**
 * @param array{uri: string, name: string, title: string, description: string, source: string, frontmatter: array<string, string|bool>, text: string} $entry
 * @return array<string, mixed>
 */
function resource_descriptor(array $entry): array
{
    $descriptor = [
        'uri' => $entry['uri'],
        'name' => $entry['name'],
        'title' => $entry['title'],
        'description' => $entry['description'],
        'mimeType' => MIME_TYPE,
        'size' => strlen($entry['text']),
    ];
    if ($entry['source'] !== '') {
        $descriptor['_meta'] = [META_SOURCE => $entry['source']];
    }

    return $descriptor;
}

/**
 * One SEP-2640 skill entry: the SKILL.md URI, its frontmatter, and its single-file resource set.
 *
 * @param array{uri: string, name: string, title: string, description: string, source: string, frontmatter: array<string, string|bool>, text: string} $entry
 * @return array<string, mixed>
 */
function skill_entry(array $entry): array
{
    return [
        'uri' => $entry['uri'],
        'frontmatter' => $entry['frontmatter'],
        'resources' => [[
            'uri' => $entry['uri'],
            'digest' => 'sha256:' . hash('sha256', $entry['text']),
            'size' => strlen($entry['text']),
        ]],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function list_resources(): array
{
    return array_map(__NAMESPACE__ . '\\resource_descriptor', entries());
}

/**
 * @return array{uri: string, name: string, title: string, description: string, source: string, frontmatter: array<string, string|bool>, text: string}|null
 */
function find(string $uri): ?array
{
    foreach (entries() as $entry) {
        if ($entry['uri'] === $uri) {
            return $entry;
        }
    }

    return null;
}

/**
 * The `contents` of a resources/read result, or null for a URI this server does not serve.
 *
 * @return list<array{uri: string, mimeType: string, text: string}>|null
 */
function read_contents(string $uri): ?array
{
    $entry = find($uri);
    if ($entry === null) {
        return null;
    }

    return [['uri' => $entry['uri'], 'mimeType' => MIME_TYPE, 'text' => $entry['text']]];
}

/**
 * Number of resources served, for the capability advertisement.
 */
function resource_count(): int
{
    return count(entries());
}

/*
 * Modern transport (2026-07-28). Each returns the dispatcher's {status, body} outcome.
 */

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function handle_resources_list(mixed $id): array
{
    return success(['resources' => list_resources()], $id, 'resources/list');
}

/**
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function handle_resources_read(array $params, mixed $id): array
{
    $uri = is_string($params['uri'] ?? null) ? trim($params['uri']) : '';
    $contents = $uri === '' ? null : read_contents($uri);
    if ($contents === null) {
        // Modern moved not-found onto -32602; see not_found_error().
        return error_response(\WPPilot\Mcp\ERROR_INVALID_PARAMS, sprintf('Unknown resource: %s', $uri), 200, $id);
    }

    return success(['contents' => $contents], $id, 'resources/read');
}

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function handle_skills_list(mixed $id): array
{
    return success(['skills' => array_map(__NAMESPACE__ . '\\skill_entry', entries())], $id, 'skills/list');
}

/**
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function handle_skills_get(array $params, mixed $id): array
{
    $uri = is_string($params['uri'] ?? null) ? trim($params['uri']) : '';
    $entry = $uri === '' ? null : find($uri);
    if ($entry === null) {
        return error_response(\WPPilot\Mcp\ERROR_INVALID_PARAMS, sprintf('Unknown skill: %s', $uri), 200, $id);
    }

    return success(['skill' => skill_entry($entry)], $id, 'skills/get');
}

/**
 * Route one of the extension's methods, or return null for any other method.
 *
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}|null
 */
function dispatch(string $method, array $params, mixed $id): ?array
{
    return match ($method) {
        'resources/list' => handle_resources_list($id),
        'resources/read' => handle_resources_read($params, $id),
        'skills/list' => handle_skills_list($id),
        'skills/get' => handle_skills_get($params, $id),
        default => null,
    };
}

/*
 * Legacy transport (the bundled adapter, 2025-11-25 and earlier).
 */

/**
 * Whether an adapter server is one of GoBuildWP's, by its REST route.
 *
 * The adapter runs this filter for every server on the site, and since Elementor 4.3 the default
 * server can be Elementor's; its clients must not start seeing WordPress skills they never asked for.
 */
function is_wppilot_route(string $route): bool
{
    if (in_array($route, ['/mcp/wppilot', '/mcp/wppilot-oauth'], strict: true)) {
        return true;
    }

    return $route === '/mcp/mcp-adapter-default-server'
        && function_exists('wppilot_owns_mcp_adapter')
        && \wppilot_owns_mcp_adapter();
}

/**
 * Append the skill resources to the adapter's resources/list.
 *
 * The adapter accepts plain arrays here: ListResourcesResult::fromArray() converts each through
 * Resource::fromArray().
 *
 * @param mixed $resources
 * @return mixed
 */
function legacy_resources_list(mixed $resources, mixed $server = null): mixed
{
    if (!is_array($resources) || !is_object($server) || !method_exists($server, 'get_server_route')) {
        return $resources;
    }
    $namespace = method_exists($server, 'get_server_route_namespace') ? (string) $server->get_server_route_namespace() : 'mcp';
    if (!is_wppilot_route('/' . $namespace . '/' . (string) $server->get_server_route())) {
        return $resources;
    }

    foreach (list_resources() as $descriptor) {
        $resources[] = $descriptor;
    }

    return $resources;
}

/**
 * Answer a legacy resources/read for a skill URI the adapter could not find.
 *
 * The adapter resolves reads only against resources registered as abilities and answers anything
 * else with -32002 before any filter runs, so the read is completed here, on the way out, and only
 * after the adapter has already validated the session and the route's permission callback has
 * passed. Only that exact error for a `skill://` URI on a single request is rewritten; batches pass
 * through untouched.
 */
function legacy_resource_read(mixed $response, mixed $server, mixed $request): mixed
{
    if (!$response instanceof WP_REST_Response || !$request instanceof WP_REST_Request) {
        return $response;
    }
    if (!is_wppilot_route($request->get_route()) || $request->get_method() !== 'POST') {
        return $response;
    }

    $body = $request->get_json_params();
    if (!is_array($body) || ($body['method'] ?? null) !== 'resources/read') {
        return $response;
    }
    $uri = is_array($body['params'] ?? null) && is_string($body['params']['uri'] ?? null) ? trim($body['params']['uri']) : '';
    if (!str_starts_with($uri, SCHEME)) {
        return $response;
    }

    $data = $response->get_data();
    if (!is_array($data) || !is_array($data['error'] ?? null) || ($data['error']['code'] ?? null) !== -32002) {
        return $response;
    }

    $contents = read_contents($uri);
    if ($contents === null) {
        return $response;
    }

    $response->set_data(['jsonrpc' => '2.0', 'id' => $data['id'] ?? ($body['id'] ?? null), 'result' => ['contents' => $contents]]);
    $response->set_status(200);

    return $response;
}

function register(): void
{
    add_filter('mcp_adapter_resources_list', __NAMESPACE__ . '\\legacy_resources_list', 10, 2);
    // After the schema repair (30); it only ever replaces one specific error body.
    add_filter('rest_post_dispatch', __NAMESPACE__ . '\\legacy_resource_read', 35, 3);
}
