<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp\Apps;

use function WPPilot\Mcp\error_response;
use function WPPilot\Mcp\success;

/**
 * MCP Apps: the preview card a host can render next to a wppilot/preview-ability result.
 *
 * The MCP Apps extension (`io.modelcontextprotocol/ui`) lets a tool name a `ui://` resource in its
 * definition's `_meta.ui.resourceUri`. A host that supports it reads that resource, renders the
 * HTML in a sandboxed iframe, and talks to it over postMessage JSON-RPC: the card sends
 * `ui/initialize`, the host answers with its capabilities, then delivers the tool call's result
 * as `ui/notifications/tool-result`. A host that proxies server tools lets the card call
 * `tools/call` itself, which is how the Apply button works; where it does not, the card says how
 * to apply instead. Hosts without the extension ignore the `_meta` and show the text result.
 *
 * The card is one static document. The preview itself arrives at runtime, from the host, and is
 * only ever put on the page through textContent, so nothing in a diff (post content, a title
 * holding markup) can become markup or script in the card. The few server values baked in (the
 * site name in the header, the apply tool's name and translated labels in a config block) are
 * escaped for their context: esc_html() in markup, and JSON with every HTML-significant character
 * hex-escaped.
 *
 * No external scripts, styles, fonts or connections: the resource declares an empty CSP allowance,
 * and everything is inline, which the Apps sandbox permits.
 *
 * Served on the modern transport only. The legacy adapter reaches abilities through its
 * execute-ability meta-tool, so there is no preview-ability tool definition there to link from.
 *
 * @link https://github.com/modelcontextprotocol/ext-apps
 */

if (!defined('ABSPATH')) {
    exit();
}

const EXTENSION_ID = 'io.modelcontextprotocol/ui';

const SCHEME = 'ui://';

const MIME_TYPE = 'text/html;profile=mcp-app';

const PREVIEW_CARD_URI = 'ui://wppilot/preview-card';

/** The Apps protocol revision the card asks for; a host answers with the one it speaks. */
const APPS_PROTOCOL_VERSION = '2026-01-26';

/**
 * The `_meta` a tool definition carries to link this card.
 *
 * Both spellings: `ui.resourceUri` is the current one, and `ui/resourceUri` is what hosts built
 * against the extension's earlier draft still read.
 *
 * @return array<string, mixed>
 */
function preview_tool_meta(): array
{
    return [
        'ui' => ['resourceUri' => PREVIEW_CARD_URI],
        'ui/resourceUri' => PREVIEW_CARD_URI,
    ];
}

/**
 * The resource-level UI metadata: no network, no external resources, a bordered card.
 *
 * @return array<string, mixed>
 */
function resource_ui_meta(): array
{
    return [
        'ui' => [
            'csp' => ['connectDomains' => [], 'resourceDomains' => []],
            'prefersBorder' => true,
        ],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function list_resources(): array
{
    return [[
        'uri' => PREVIEW_CARD_URI,
        'name' => 'wppilot-preview-card',
        'title' => 'GoBuildWP preview card',
        'description' => 'Renders a wppilot/preview-ability result: the target, what will change, and an Apply '
            . 'button where the host lets the card call tools.',
        'mimeType' => MIME_TYPE,
        '_meta' => resource_ui_meta(),
    ]];
}

function resource_count(): int
{
    return count(list_resources());
}

/**
 * The `contents` of a resources/read, or null for a URI this module does not serve.
 *
 * @return list<array<string, mixed>>|null
 */
function read_contents(string $uri): ?array
{
    if ($uri !== PREVIEW_CARD_URI) {
        return null;
    }

    return [[
        'uri' => PREVIEW_CARD_URI,
        'mimeType' => MIME_TYPE,
        'text' => preview_card_html(site_name()),
        '_meta' => resource_ui_meta(),
    ]];
}

/**
 * Route resources/list (skill resources plus these) and resources/read for a `ui://` URI; null
 * for anything else, which leaves skill resources to their own module.
 *
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}|null
 */
function dispatch(string $method, array $params, mixed $id): ?array
{
    if ($method === 'resources/list') {
        $skills = function_exists('WPPilot\\Mcp\\SkillResources\\list_resources')
            ? \WPPilot\Mcp\SkillResources\list_resources()
            : [];

        return success(['resources' => array_merge($skills, list_resources())], $id, 'resources/list');
    }

    if ($method !== 'resources/read') {
        return null;
    }
    $uri = is_string($params['uri'] ?? null) ? trim($params['uri']) : '';
    if (!str_starts_with($uri, SCHEME)) {
        return null;
    }
    $contents = read_contents($uri);
    if ($contents === null) {
        return error_response(\WPPilot\Mcp\ERROR_INVALID_PARAMS, sprintf('Unknown resource: %s', $uri), 200, $id);
    }

    return success(['contents' => $contents], $id, 'resources/read');
}

/**
 * Encode a value for a `<script type="application/json">` block.
 *
 * Hex-escaping `<`, `>`, `&`, `'` and `"` means no value, however hostile, can close the script
 * element or open a comment inside it.
 */
function json_for_script(mixed $value): string
{
    $encoded = wp_json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    return is_string($encoded) ? $encoded : '{}';
}

/**
 * The card's baked-in configuration.
 *
 * @return array<string, mixed>
 */
function card_config(): array
{
    return [
        'appsProtocolVersion' => APPS_PROTOCOL_VERSION,
        'version' => defined('WPPILOT_VERSION') ? (string) constant('WPPILOT_VERSION') : '0.0.0',
        'applyTool' => \WPPilot\Mcp\tool_name('wppilot/apply-preview'),
        'strings' => [
            'waiting' => __('Waiting for the preview…', domain: 'wppilot'),
            'noPreview' => __('This result is not a GoBuildWP preview.', domain: 'wppilot'),
            'unsupported' => __('No preview for this call', domain: 'wppilot'),
            'wouldFail' => __('This call would be refused', domain: 'wppilot'),
            'target' => __('Target', domain: 'wppilot'),
            'ability' => __('Ability', domain: 'wppilot'),
            'changes' => __('Changes', domain: 'wppilot'),
            'noChanges' => __('Nothing would change.', domain: 'wppilot'),
            'destroys' => __('This removes content.', domain: 'wppilot'),
            'more' => __('%d more change(s) not shown.', domain: 'wppilot'),
            'truncated' => __('The diff was truncated.', domain: 'wppilot'),
            'unpredicted' => __('Not predicted', domain: 'wppilot'),
            'sideEffects' => __('Side effects', domain: 'wppilot'),
            'warnings' => __('Warnings', domain: 'wppilot'),
            'expires' => __('Expires', domain: 'wppilot'),
            'before' => __('Before', domain: 'wppilot'),
            'after' => __('After', domain: 'wppilot'),
            'redacted' => __('[hidden]', domain: 'wppilot'),
            'empty' => __('(none)', domain: 'wppilot'),
            'apply' => __('Apply this change', domain: 'wppilot'),
            'confirm' => __('Confirm: apply now', domain: 'wppilot'),
            'cancel' => __('Cancel', domain: 'wppilot'),
            'applying' => __('Applying…', domain: 'wppilot'),
            'applied' => __('Applied. The Changes screen in wp-admin lists it for review or undo.', domain: 'wppilot'),
            'applyFailed' => __('Not applied:', domain: 'wppilot'),
            'review' => __('Review in wp-admin', domain: 'wppilot'),
            'howTo' => __('To apply it, tell the assistant you approve preview %s, or review it in wp-admin.', domain: 'wppilot'),
            'notPending' => __('This preview is %s and can no longer be applied.', domain: 'wppilot'),
        ],
    ];
}

function site_name(): string
{
    $name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';

    return function_exists('wp_specialchars_decode') ? wp_specialchars_decode($name, ENT_QUOTES) : $name;
}

/**
 * The card document, for a site name as plain text (not HTML-escaped).
 */
function preview_card_html(string $site_name): string
{
    $config = json_for_script(card_config());
    $site = esc_html($site_name);
    $title = esc_html(__('GoBuildWP preview', domain: 'wppilot'));

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
:root{color-scheme:light dark;--bg:#fff;--fg:#1d2327;--muted:#646970;--line:#dcdcde;--add:#e7f6ec;--del:#fcf0f1;--accent:#2271b1;--warn:#996800}
@media (prefers-color-scheme:dark){:root{--bg:#1e1e1e;--fg:#f0f0f1;--muted:#a7aaad;--line:#3c434a;--add:#1c3326;--del:#3a1f22;--accent:#72aee6;--warn:#dba617}}
*{box-sizing:border-box}
body{margin:0;padding:12px 14px;background:var(--bg);color:var(--fg);font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
header{display:flex;justify-content:space-between;gap:8px;align-items:baseline;margin-bottom:8px}
h1{font-size:15px;margin:0}
.site,.muted{color:var(--muted);font-size:12px}
dl{display:grid;grid-template-columns:max-content 1fr;gap:2px 10px;margin:0 0 8px}
dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}
ul{margin:4px 0 8px;padding-left:18px}
.entry{border:1px solid var(--line);border-radius:6px;padding:6px 8px;margin:6px 0;list-style:none}
.path{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.op{font-size:11px;text-transform:uppercase;color:var(--muted);margin-left:6px}
.val{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;white-space:pre-wrap;overflow-wrap:anywhere;padding:3px 6px;border-radius:4px;margin-top:4px}
.del{background:var(--del)}.add{background:var(--add)}
.warn{color:var(--warn)}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
button{font:inherit;padding:6px 12px;border-radius:4px;border:1px solid var(--accent);background:var(--accent);color:#fff;cursor:pointer}
button.secondary{background:transparent;color:var(--accent)}
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
  var nextId = 1, pending = {}, host = null, preview = null;
  var MAX_VALUE = 400, MAX_ENTRIES = 12;

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
  function clip(value) {
    var text = value === null || value === undefined ? S.empty : String(value);
    return text.length > MAX_VALUE ? text.slice(0, MAX_VALUE) + '…' : text;
  }
  function fmt(template, value) { return String(template).replace('%s', value).replace('%d', value); }
  function isObj(v) { return v !== null && typeof v === 'object' && !Array.isArray(v); }

  function extract(result) {
    if (!isObj(result)) { return null; }
    var data = result.structuredContent;
    if (!isObj(data) && Array.isArray(result.content)) {
      for (var i = 0; i < result.content.length; i++) {
        var block = result.content[i];
        if (isObj(block) && block.type === 'text') {
          try { data = JSON.parse(block.text); } catch (e) { data = null; }
          break;
        }
      }
    }
    if (isObj(data) && data.success === true && isObj(data.data)) { data = data.data; }
    return isObj(data) ? data : null;
  }

  function row(dl, label, value) {
    if (value === undefined || value === null || value === '') { return; }
    dl.appendChild(el('dt', '', label));
    dl.appendChild(el('dd', '', value));
  }
  function list(title, items, cls) {
    if (!Array.isArray(items) || items.length === 0) { return null; }
    var wrap = el('section');
    wrap.appendChild(el('strong', cls || '', title));
    var ul = el('ul');
    items.forEach(function (item) {
      ul.appendChild(el('li', cls || '', isObj(item) ? (item.path_label || '') + (item.reason ? ' — ' + item.reason : '') : item));
    });
    wrap.appendChild(ul);
    return wrap;
  }

  function render(result) {
    preview = extract(result);
    card.textContent = '';
    statusEl.textContent = '';
    if (!preview) { card.appendChild(el('p', 'muted', S.noPreview)); return; }

    if (preview.supported === false) {
      card.appendChild(el('strong', '', S.unsupported));
      card.appendChild(el('p', '', preview.message || preview.reason || ''));
      return;
    }
    if (preview.would_fail === true) {
      card.appendChild(el('strong', 'warn', S.wouldFail));
      var err = isObj(preview.error) ? preview.error : {};
      card.appendChild(el('p', '', err.message || err.code || ''));
      return;
    }

    var dl = el('dl');
    var target = isObj(preview.target) ? preview.target : {};
    var label = [target.label, target.post_type || target.kind, target.id ? '#' + target.id : ''].filter(Boolean).join(' · ');
    row(dl, S.target, label);
    row(dl, S.ability, preview.ability);
    row(dl, S.expires, preview.expires_at);
    card.appendChild(dl);

    var diff = isObj(preview.diff) ? preview.diff : {};
    var entries = Array.isArray(diff.entries) ? diff.entries : [];
    var heading = el('strong', '', S.changes + ' (' + (diff.changed_count || entries.length) + ')');
    card.appendChild(heading);
    if (diff.destroys === true) { card.appendChild(el('p', 'warn', S.destroys)); }
    if (entries.length === 0) { card.appendChild(el('p', 'muted', S.noChanges)); }
    var ul = el('ul');
    entries.slice(0, MAX_ENTRIES).forEach(function (entry) {
      if (!isObj(entry)) { return; }
      var li = el('li', 'entry');
      li.appendChild(el('span', 'path', entry.path_label || ''));
      li.appendChild(el('span', 'op', entry.op || ''));
      if (entry.redacted === true) {
        li.appendChild(el('div', 'val', S.redacted));
      } else {
        if (entry.op !== 'added') { li.appendChild(el('div', 'val del', S.before + ': ' + clip(entry.before))); }
        if (entry.op !== 'removed') { li.appendChild(el('div', 'val add', S.after + ': ' + clip(entry.after))); }
      }
      ul.appendChild(li);
    });
    card.appendChild(ul);
    var hidden = entries.length - Math.min(entries.length, MAX_ENTRIES) + (diff.dropped_count || 0);
    if (hidden > 0) { card.appendChild(el('p', 'muted', fmt(S.more, hidden))); }
    if (diff.truncated === true && hidden === 0) { card.appendChild(el('p', 'muted', S.truncated)); }

    [list(S.unpredicted, diff.unpredicted, 'muted'), list(S.sideEffects, preview.side_effects), list(S.warnings, preview.warnings, 'warn')]
      .forEach(function (section) { if (section) { card.appendChild(section); } });

    actions();
  }

  function canCallTools() { return !!(host && host.hostCapabilities && host.hostCapabilities.serverTools); }
  function canOpenLinks() { return !!(host && host.hostCapabilities && host.hostCapabilities.openLinks); }
  function safeUrl(url) { return typeof url === 'string' && /^https?:\/\//i.test(url) ? url : ''; }

  function actions() {
    var old = document.getElementById('actions');
    if (old) { old.remove(); }
    if (!preview || typeof preview.preview_id !== 'string') { return; }
    var box = el('div', 'actions');
    box.id = 'actions';
    card.appendChild(box);

    if (preview.status && preview.status !== 'pending') {
      box.appendChild(el('p', 'muted', fmt(S.notPending, preview.status)));
      return;
    }
    var url = safeUrl(preview.preview_url);
    if (canCallTools()) {
      var apply = el('button', '', S.apply);
      apply.type = 'button';
      apply.addEventListener('click', function () { confirmStep(box); });
      box.appendChild(apply);
    } else {
      box.appendChild(el('p', '', fmt(S.howTo, preview.preview_id)));
    }
    if (url && canOpenLinks()) {
      var open = el('button', 'secondary', S.review);
      open.type = 'button';
      open.addEventListener('click', function () { request('ui/open-link', { url: url }).catch(function () {}); });
      box.appendChild(open);
    } else if (url) {
      box.appendChild(el('p', 'muted path', url));
    }
  }

  // Two clicks, so a stray click on the card never writes to the site.
  function confirmStep(box) {
    box.textContent = '';
    var yes = el('button', '', S.confirm);
    yes.type = 'button';
    var no = el('button', 'secondary', S.cancel);
    no.type = 'button';
    no.addEventListener('click', actions);
    yes.addEventListener('click', function () {
      yes.disabled = true; no.disabled = true;
      statusEl.textContent = S.applying;
      request('tools/call', { name: cfg.applyTool, arguments: { preview_id: preview.preview_id, confirm: true } })
        .then(function (result) {
          var data = extract(result);
          if (isObj(result) && result.isError !== true && data && data.applied === true) {
            statusEl.textContent = S.applied;
            box.textContent = '';
            preview.status = 'applied';
            return;
          }
          var text = '';
          if (isObj(result) && Array.isArray(result.content) && isObj(result.content[0])) { text = result.content[0].text || ''; }
          statusEl.textContent = S.applyFailed + ' ' + clip(text || (data && data.message) || '');
          actions();
        })
        .catch(function (error) {
          statusEl.textContent = S.applyFailed + ' ' + clip(error && error.message ? error.message : '');
          actions();
        });
    });
    box.appendChild(yes);
    box.appendChild(no);
  }

  function size() {
    // The body, not the document: a document is never shorter than the iframe it sits in, so it
    // could only ever ask the host to grow.
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
    appInfo: { name: 'wppilot-preview-card', version: cfg.version },
    appCapabilities: {}
  }).then(function (result) {
    host = isObj(result) ? result : {};
    notify('ui/notifications/initialized', {});
    if (preview) { actions(); }
    size();
  }).catch(function () {
    host = {};
    if (preview) { actions(); }
  });
})();
</script>
</body>
</html>
HTML;
}
