<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp\ApprovalCard;

use WP_Error;

use function WPPilot\Mcp\Apps\json_for_script;
use function WPPilot\Mcp\Apps\resource_ui_meta;
use function WPPilot\Mcp\Apps\site_name;
use function WPPilot\Mcp\error_response;
use function WPPilot\Mcp\success;
use function WPPilot\Mcp\tool_error;

/**
 * The in-chat approval card: a person approves a destructive call inside an MCP Apps host.
 *
 * With "A person approves" on, a destructive call from a client without form elicitation gets a
 * wp-admin approval link (wppilot_confirmation_approval_url_error()). When the client declares the
 * MCP Apps extension, that same refusal also links this card and carries the pending request, so
 * the person can approve it where they are reading the chat instead of switching to wp-admin.
 * Either way it is the one pending request in confirmation.php that gets decided, and the agent's
 * identical retry spends it exactly as it spends a wp-admin approval.
 *
 * WHO CAN DECIDE. The card's buttons call an app-only tool (DECIDE_TOOL) through the host. That
 * call arrives on the same connection as the agent's, so the server cannot see who clicked. What
 * keeps the agent from approving itself:
 *
 *   - The decide tool needs a card token. The token is put only in the refusal's `_meta`, which
 *     MCP Apps hosts hand to the card and keep out of the model's context, never in the text block
 *     or structuredContent the model reads.
 *   - The token is HMAC-signed with the confirmation key and bound to one request id, ability,
 *     input hash, user and connection credential, expires with the request, and is single use.
 *   - The tool is marked `visibility: ['app']`, so a host that honours it never offers it to the
 *     model at all.
 *   - A decision only ever approves that one exact call once. It never grants a session approval,
 *     which stays a wp-admin choice.
 *
 * A host that leaked `_meta` to the model would weaken this to the level of elicitation, where the
 * server equally trusts the client to have asked its user. Sites that do not accept that keep the
 * wp-admin link, which is always offered on the card and in the text.
 *
 * Modern transport only, like the preview card.
 */

if (!defined('ABSPATH')) {
    exit();
}

const CARD_URI = 'ui://wppilot/approval-card';

/** Not an ability: it cannot be reached through execute-ability, REST or Chat. */
const DECIDE_TOOL = 'gobuildwp_approval_card_decide';

/** Where in a refusal's `_meta` the card finds its token. */
const META_KEY = 'gobuildwp/approval_card';

const TOKEN_VERSION = 'c1';

/**
 * Whether a client declared MCP Apps in its per-request capabilities.
 *
 * @param array<string, mixed> $capabilities
 */
function client_supports_apps(array $capabilities): bool
{
    $extensions = $capabilities['extensions'] ?? null;

    return is_array($extensions) && is_array($extensions[\WPPilot\Mcp\Apps\EXTENSION_ID] ?? null);
}

/**
 * @param array<string, mixed> $params A tools/call's params.
 */
function call_supports_apps(array $params): bool
{
    $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
    $capabilities = is_array($meta[\WPPilot\Mcp\META_CLIENT_CAPABILITIES] ?? null) ? $meta[\WPPilot\Mcp\META_CLIENT_CAPABILITIES] : [];

    return client_supports_apps($capabilities);
}

/**
 * @return list<array<string, mixed>>
 */
function list_resources(): array
{
    return [[
        'uri' => CARD_URI,
        'name' => 'wppilot-approval-card',
        'title' => 'GoBuildWP approval card',
        'description' => 'Shows a destructive call waiting for a person: the ability, its exact input with secrets '
            . 'masked, and Approve, Deny and Open in wp-admin buttons.',
        'mimeType' => \WPPilot\Mcp\Apps\MIME_TYPE,
        '_meta' => resource_ui_meta(),
    ]];
}

/**
 * @return list<array<string, mixed>>|null
 */
function read_contents(string $uri): ?array
{
    if ($uri !== CARD_URI) {
        return null;
    }

    return [[
        'uri' => CARD_URI,
        'mimeType' => \WPPilot\Mcp\Apps\MIME_TYPE,
        'text' => card_html(site_name()),
        '_meta' => resource_ui_meta(),
    ]];
}

/**
 * The app-only decide tool's definition.
 *
 * @return array<string, mixed>
 */
function decide_tool(): array
{
    return [
        'name' => DECIDE_TOOL,
        'title' => 'Approval card decision',
        'description' => 'Used only by the GoBuildWP approval card when a person clicks Approve or Deny. It needs a '
            . 'token the card holds and the model never sees, so calling it from a chat does nothing.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'request_id' => ['type' => 'string'],
                'decision' => ['type' => 'string', 'enum' => ['approve', 'deny']],
                'card_token' => ['type' => 'string'],
            ],
            'required' => ['request_id', 'decision', 'card_token'],
            'additionalProperties' => false,
        ],
        'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        '_meta' => ['ui' => ['visibility' => ['app']]],
    ];
}

/**
 * The credential a request is bound to, as stored on it.
 */
function current_credential(): string
{
    $agent = function_exists('wppilot_current_agent') ? wppilot_current_agent() : [];

    return is_string($agent['credential'] ?? null) ? $agent['credential'] : '';
}

/**
 * Mint the card token for one pending request.
 *
 * @param array<string, mixed> $request
 */
function issue_token(array $request): string
{
    $claims = [
        'r' => (string) ($request['id'] ?? ''),
        'a' => (string) ($request['ability'] ?? ''),
        'h' => (string) ($request['input_sha256'] ?? ''),
        'u' => (int) ($request['user_id'] ?? 0),
        'c' => hash('sha256', (string) ($request['credential'] ?? '')),
        'e' => (int) ($request['expires_at'] ?? 0),
        'n' => bin2hex(random_bytes(16)),
    ];
    $payload = wppilot_confirmation_b64((string) wp_json_encode($claims));

    return TOKEN_VERSION . '.' . $payload . '.' . wppilot_confirmation_b64(sign($payload));
}

function sign(string $payload): string
{
    // Domain-separated from the elicitation tokens, which share the key: neither verifies as the other.
    return hash_hmac('sha256', TOKEN_VERSION . '.approval-card.' . $payload, wppilot_confirmation_key(), binary: true);
}

/**
 * Check a card token against the request it is presented for. Does not spend it.
 *
 * @param array<string, mixed> $request
 * @return array{n: string, e: int}|null
 */
function verify_token(string $token, array $request, ?int $now = null): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3 || $parts[0] !== TOKEN_VERSION) {
        return null;
    }
    $mac = wppilot_confirmation_unb64($parts[2]);
    if ($mac === false || !hash_equals(sign($parts[1]), $mac)) {
        return null;
    }
    $json = wppilot_confirmation_unb64($parts[1]);
    /** @var mixed $claims */
    $claims = $json === false ? null : json_decode($json, associative: true);
    if (!is_array($claims)) {
        return null;
    }
    foreach (['r', 'a', 'h', 'c', 'n'] as $key) {
        if (!is_string($claims[$key] ?? null)) {
            return null;
        }
    }
    if (!is_int($claims['u'] ?? null) || !is_int($claims['e'] ?? null)) {
        return null;
    }

    $now ??= time();
    if (
        !hash_equals((string) ($request['id'] ?? ''), $claims['r'])
        || !hash_equals((string) ($request['ability'] ?? ''), $claims['a'])
        || !hash_equals((string) ($request['input_sha256'] ?? ''), $claims['h'])
        || !hash_equals(hash('sha256', (string) ($request['credential'] ?? '')), $claims['c'])
        || $claims['u'] !== (int) ($request['user_id'] ?? 0)
        || $claims['e'] < $now
    ) {
        return null;
    }

    return ['n' => $claims['n'], 'e' => $claims['e']];
}

/**
 * Add the card to a refusal that carries a wp-admin approval link, for a client that shows Apps.
 *
 * The text block is left as it was, so a host that ignores the card still shows the link.
 *
 * @param array{status: int, body: array<string, mixed>} $outcome tool_error()'s response.
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function decorate_refusal(array $outcome, WP_Error $error, array $params): array
{
    if ($error->get_error_code() !== 'wppilot_human_confirmation_required' || !call_supports_apps($params)) {
        return $outcome;
    }
    $data = $error->get_error_data();
    $url = is_array($data) && is_string($data['approval_url'] ?? null) ? $data['approval_url'] : '';
    $id = request_id_from_url($url);
    $request = $id === '' ? null : wppilot_confirmation_get_request($id);
    if (!is_array($request) || ($request['status'] ?? null) !== 'pending' || !is_array($outcome['body']['result'] ?? null)) {
        return $outcome;
    }

    $result = $outcome['body']['result'];
    $structured = is_array($result['structuredContent'] ?? null) ? $result['structuredContent'] : [];
    $structured['approval'] = request_view($request, $url);
    $result['structuredContent'] = $structured;
    $meta = is_array($result['_meta'] ?? null) ? $result['_meta'] : [];
    $meta['ui'] = ['resourceUri' => CARD_URI];
    $meta['ui/resourceUri'] = CARD_URI;
    $meta[META_KEY] = ['token' => issue_token($request)];
    $result['_meta'] = $meta;
    $outcome['body']['result'] = $result;

    return $outcome;
}

function request_id_from_url(string $url): string
{
    $query = (string) wp_parse_url($url, PHP_URL_QUERY);
    parse_str($query, $args);
    $id = is_string($args['request'] ?? null) ? $args['request'] : '';

    return preg_match('/^[a-f0-9]{32}$/', $id) === 1 ? $id : '';
}

/**
 * What the card shows. Everything here the model may read too: no token.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function request_view(array $request, string $url): array
{
    return [
        'request_id' => (string) ($request['id'] ?? ''),
        'ability' => (string) ($request['ability'] ?? ''),
        'label' => (string) ($request['label'] ?? ''),
        'risk' => (string) ($request['risk'] ?? ''),
        'input' => (string) ($request['input_json'] ?? ''),
        'requested_as' => (string) ($request['user_login'] ?? ''),
        'agent' => (string) ($request['agent'] ?? ''),
        'expires_at' => gmdate('c', (int) ($request['expires_at'] ?? 0)),
        'approval_url' => $url,
    ];
}

/**
 * Answer a tools/call for the decide tool, or null for any other tool.
 *
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}|null
 */
function call(string $name, array $params, mixed $id): ?array
{
    if ($name !== DECIDE_TOOL) {
        return null;
    }
    $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
    $request_id = is_string($arguments['request_id'] ?? null) ? $arguments['request_id'] : '';
    $decision = $arguments['decision'] ?? null;
    $token = is_string($arguments['card_token'] ?? null) ? $arguments['card_token'] : '';
    if ($request_id === '' || $token === '' || ($decision !== 'approve' && $decision !== 'deny')) {
        return error_response(\WPPilot\Mcp\ERROR_INVALID_PARAMS, 'request_id, decision (approve or deny) and card_token are required.', 200, $id);
    }

    $outcome = decide($request_id, $decision === 'approve', $token);
    if ($outcome instanceof WP_Error) {
        return tool_error($outcome, $id);
    }

    return success([
        'content' => [['type' => 'text', 'text' => $outcome['message']]],
        'structuredContent' => $outcome,
        'isError' => false,
    ], $id, 'tools/call');
}

/**
 * Decide one pending request from the card.
 *
 * @return array{decided: bool, decision: string, request_id: string, message: string}|WP_Error
 */
function decide(string $request_id, bool $approve, string $token): array|WP_Error
{
    $refused = new WP_Error(
        'wppilot_approval_card_refused',
        __('This approval card cannot decide that request. Approve it in wp-admin instead.', domain: 'wppilot'),
        ['status' => 403],
    );
    $user_id = get_current_user_id();
    $request = wppilot_confirmation_get_request($request_id);
    if (
        $user_id <= 0
        || !is_array($request)
        || ($request['status'] ?? null) !== 'pending'
        || (int) ($request['user_id'] ?? 0) !== $user_id
        || !hash_equals((string) ($request['credential'] ?? ''), current_credential())
    ) {
        return $refused;
    }
    $claims = verify_token($token, $request);
    if ($claims === null || !wppilot_confirmation_claim_nonce('card:' . $claims['n'], $claims['e'])) {
        return $refused;
    }
    if (!wppilot_confirmation_decide($request_id, $approve, $user_id, log: false)) {
        return $refused;
    }
    if (function_exists('wppilot_approval_log_record')) {
        wppilot_approval_log_record($approve ? 'card-approved' : 'card-denied', [
            'ability' => (string) ($request['ability'] ?? ''),
            'user_id' => $user_id,
            'actor_id' => $user_id,
            'agent' => (string) ($request['agent'] ?? ''),
            'input_sha256' => (string) ($request['input_sha256'] ?? ''),
        ]);
    }

    return [
        'decided' => true,
        'decision' => $approve ? 'approve' : 'deny',
        'request_id' => $request_id,
        'message' => $approve
            ? __('Approved once. The assistant can now retry the identical call within 5 minutes.', domain: 'wppilot')
            : __('Denied. The assistant is told not to retry this call.', domain: 'wppilot'),
    ];
}

/**
 * @return array<string, mixed>
 */
function card_config(): array
{
    return [
        'appsProtocolVersion' => \WPPilot\Mcp\Apps\APPS_PROTOCOL_VERSION,
        'version' => defined('WPPILOT_VERSION') ? (string) constant('WPPILOT_VERSION') : '0.0.0',
        'decideTool' => DECIDE_TOOL,
        'metaKey' => META_KEY,
        'strings' => [
            'waiting' => __('Waiting for the request…', domain: 'wppilot'),
            'noRequest' => __('Nothing here needs your approval.', domain: 'wppilot'),
            'heading' => __('An AI agent wants to run this', domain: 'wppilot'),
            'ability' => __('Ability', domain: 'wppilot'),
            'risk' => __('Risk', domain: 'wppilot'),
            'as' => __('Runs as', domain: 'wppilot'),
            'agent' => __('Agent', domain: 'wppilot'),
            'expires' => __('Expires', domain: 'wppilot'),
            'input' => __('Exact input', domain: 'wppilot'),
            'hint' => __('Approve only if you asked for this. It approves this exact call once.', domain: 'wppilot'),
            'approve' => __('Approve', domain: 'wppilot'),
            'confirm' => __('Confirm: approve once', domain: 'wppilot'),
            'deny' => __('Deny', domain: 'wppilot'),
            'cancel' => __('Cancel', domain: 'wppilot'),
            'working' => __('Sending…', domain: 'wppilot'),
            'failed' => __('Not decided:', domain: 'wppilot'),
            'admin' => __('Open in wp-admin', domain: 'wppilot'),
            'adminOnly' => __('This host cannot send the decision from the card. Approve it in wp-admin:', domain: 'wppilot'),
        ],
    ];
}

function card_html(string $site_name): string
{
    $config = json_for_script(card_config());
    $site = esc_html($site_name);
    $title = esc_html(__('GoBuildWP approval', domain: 'wppilot'));

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
:root{color-scheme:light dark;--bg:#fff;--fg:#1d2327;--muted:#646970;--line:#dcdcde;--accent:#2271b1;--warn:#996800;--bad:#b32d2e}
@media (prefers-color-scheme:dark){:root{--bg:#1e1e1e;--fg:#f0f0f1;--muted:#a7aaad;--line:#3c434a;--accent:#72aee6;--warn:#dba617;--bad:#f86368}}
*{box-sizing:border-box}
body{margin:0;padding:12px 14px;background:var(--bg);color:var(--fg);font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
header{display:flex;justify-content:space-between;gap:8px;align-items:baseline;margin-bottom:8px}
h1{font-size:15px;margin:0}
.site,.muted{color:var(--muted);font-size:12px}
dl{display:grid;grid-template-columns:max-content 1fr;gap:2px 10px;margin:0 0 8px}
dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}
pre{font:12px/1.4 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid var(--line);border-radius:6px;padding:6px 8px;max-height:260px;overflow:auto;margin:4px 0 8px}
.warn{color:var(--warn)}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
button{font:inherit;padding:6px 12px;border-radius:4px;border:1px solid var(--accent);background:var(--accent);color:#fff;cursor:pointer}
button.secondary{background:transparent;color:var(--accent)}
button.deny{background:transparent;color:var(--bad);border-color:var(--bad)}
button[disabled]{opacity:.6;cursor:default}
#status{margin-top:8px}
</style>
</head>
<body>
<header><h1>{$title}</h1><span class="site">{$site}</span></header>
<main id="card"><p class="muted" id="waiting"></p></main>
<div id="status" role="status" aria-live="polite"></div>
<script type="application/json" id="wppilot-card-config">{$config}</script>
<script>
(function () {
  'use strict';
  var cfg = JSON.parse(document.getElementById('wppilot-card-config').textContent);
  var S = cfg.strings;
  var card = document.getElementById('card');
  var statusEl = document.getElementById('status');
  var nextId = 1, pending = {}, host = null, approval = null, token = '';

  document.getElementById('waiting').textContent = S.waiting;

  function send(message) { window.parent.postMessage(message, '*'); }
  function request(method, params) {
    return new Promise(function (resolve, reject) {
      var id = nextId++;
      pending[id] = { resolve: resolve, reject: reject };
      send({ jsonrpc: '2.0', id: id, method: method, params: params || {} });
    });
  }
  function notify(method, params) { send({ jsonrpc: '2.0', method: method, params: params || {} }); }
  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    return node;
  }
  function isObj(v) { return v !== null && typeof v === 'object' && !Array.isArray(v); }
  function safeUrl(url) { return typeof url === 'string' && /^https?:\/\//i.test(url) ? url : ''; }
  function canCallTools() { return !!(host && host.hostCapabilities && host.hostCapabilities.serverTools); }
  function canOpenLinks() { return !!(host && host.hostCapabilities && host.hostCapabilities.openLinks); }
  function row(dl, label, value) {
    if (!value) { return; }
    dl.appendChild(el('dt', '', label));
    dl.appendChild(el('dd', '', value));
  }

  function render(result) {
    approval = null; token = '';
    if (isObj(result) && isObj(result.structuredContent) && isObj(result.structuredContent.approval)) {
      approval = result.structuredContent.approval;
    }
    if (isObj(result) && isObj(result._meta) && isObj(result._meta[cfg.metaKey]) && typeof result._meta[cfg.metaKey].token === 'string') {
      token = result._meta[cfg.metaKey].token;
    }
    card.textContent = '';
    statusEl.textContent = '';
    if (!approval || typeof approval.request_id !== 'string') { card.appendChild(el('p', 'muted', S.noRequest)); return; }

    card.appendChild(el('strong', 'warn', S.heading));
    var dl = el('dl');
    row(dl, S.ability, [approval.label, approval.ability].filter(Boolean).join(' · '));
    row(dl, S.risk, approval.risk);
    row(dl, S.as, approval.requested_as);
    row(dl, S.agent, approval.agent);
    row(dl, S.expires, approval.expires_at);
    card.appendChild(dl);
    card.appendChild(el('strong', '', S.input));
    card.appendChild(el('pre', '', approval.input));
    card.appendChild(el('p', 'muted', S.hint));
    actions();
  }

  function adminButton(box) {
    var url = safeUrl(approval.approval_url);
    if (!url) { return; }
    if (canOpenLinks()) {
      var open = el('button', 'secondary', S.admin);
      open.type = 'button';
      open.addEventListener('click', function () { request('ui/open-link', { url: url }).catch(function () {}); });
      box.appendChild(open);
    } else {
      box.appendChild(el('p', 'muted', url));
    }
  }

  function actions() {
    var old = document.getElementById('actions');
    if (old) { old.remove(); }
    if (!approval) { return; }
    var box = el('div', 'actions');
    box.id = 'actions';
    card.appendChild(box);
    if (token && canCallTools()) {
      var yes = el('button', '', S.approve);
      yes.type = 'button';
      yes.addEventListener('click', function () { confirmStep(box, 'approve'); });
      var no = el('button', 'deny', S.deny);
      no.type = 'button';
      no.addEventListener('click', function () { confirmStep(box, 'deny'); });
      box.appendChild(yes);
      box.appendChild(no);
    } else {
      box.appendChild(el('p', '', S.adminOnly));
    }
    adminButton(box);
  }

  // Two clicks to approve, so a stray click never approves anything.
  function confirmStep(box, decision) {
    if (decision === 'deny') { decide(box, decision); return; }
    box.textContent = '';
    var yes = el('button', '', S.confirm);
    yes.type = 'button';
    var no = el('button', 'secondary', S.cancel);
    no.type = 'button';
    no.addEventListener('click', actions);
    yes.addEventListener('click', function () { decide(box, decision); });
    box.appendChild(yes);
    box.appendChild(no);
  }

  function decide(box, decision) {
    Array.prototype.forEach.call(box.querySelectorAll('button'), function (b) { b.disabled = true; });
    statusEl.textContent = S.working;
    request('tools/call', { name: cfg.decideTool, arguments: { request_id: approval.request_id, decision: decision, card_token: token } })
      .then(function (result) {
        var text = isObj(result) && Array.isArray(result.content) && isObj(result.content[0]) ? String(result.content[0].text || '') : '';
        if (isObj(result) && result.isError !== true && isObj(result.structuredContent) && result.structuredContent.decided === true) {
          token = '';
          box.textContent = '';
          statusEl.textContent = text;
          return;
        }
        statusEl.textContent = S.failed + ' ' + text;
        token = '';
        actions();
      })
      .catch(function (error) {
        statusEl.textContent = S.failed + ' ' + (error && error.message ? String(error.message) : '');
        actions();
      });
  }

  function size() {
    var box = document.body.getBoundingClientRect();
    notify('ui/notifications/size-changed', { width: Math.ceil(box.width), height: Math.ceil(box.height) });
  }

  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) { return; }
    var msg = event.data;
    if (!isObj(msg) || msg.jsonrpc !== '2.0') { return; }
    if (msg.id !== undefined && msg.method === undefined) {
      var waiter = pending[msg.id];
      if (!waiter) { return; }
      delete pending[msg.id];
      if (msg.error) { waiter.reject(msg.error); } else { waiter.resolve(msg.result); }
      return;
    }
    if (msg.method === 'ui/notifications/tool-result') { render(msg.params); size(); return; }
    if (msg.method === 'ui/resource-teardown' && msg.id !== undefined) { send({ jsonrpc: '2.0', id: msg.id, result: {} }); return; }
    if (msg.method === 'ui/notifications/host-context-changed') { return; }
    if (msg.id !== undefined && msg.method) {
      send({ jsonrpc: '2.0', id: msg.id, error: { code: -32601, message: 'Method not found' } });
    }
  });

  request('ui/initialize', {
    protocolVersion: cfg.appsProtocolVersion,
    appInfo: { name: 'wppilot-approval-card', version: cfg.version },
    appCapabilities: {}
  }).then(function (result) {
    host = isObj(result) ? result : {};
    notify('ui/notifications/initialized', {});
    if (approval) { actions(); }
    size();
  }).catch(function () {
    host = {};
    if (approval) { actions(); }
  });
})();
</script>
</body>
</html>
HTML;
}
