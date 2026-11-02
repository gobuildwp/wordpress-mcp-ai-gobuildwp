<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The rule pack: extra suspicious-code rules and known-bad file hashes that GoBuildWP Cloud sends
 * between plugin releases (POST gobuildwp/v1/cloud/rules).
 *
 * A pack is taken only from a request whose GoBuildWP Cloud signature this site verified; never
 * from an MCP tool's arguments, an unsigned call or any other credential. Every rule is checked
 * on arrival and a bad one is dropped, never fatal: the built-in rules always run, and a built-in
 * rule keeps its id when a pack names the same one. The last accepted pack is kept in one option
 * that is not autoloaded, so a page view never loads it.
 */

const PACK_OPTION = 'gobuildwp_kit_si_rule_pack';
const PACK_MAX_RULES = 200;
const PACK_MAX_HASHES = 5000;
const PACK_MAX_PATTERN = 2000;
const PACK_SEVERITIES = ['low', 'medium', 'high', 'critical'];
const PACK_APPLIES = ['php', 'js', 'any'];
const PACK_RULE_ID = '/^susp\.[a-z0-9_]{1,40}$/';
const PACK_VERSION = '/^[A-Za-z0-9._:-]{1,64}$/';

/** A file whose sha256 is on the pack's known-bad list. */
const KNOWN_BAD_RULE = 'susp.known_bad_hash';

/**
 * Check a pack as Cloud sends it: `{version, rules: [{id, pattern, severity, applies_to, raw?}], hashes: [sha256]}`.
 * Null when the pack itself is unusable (no version, rules or hashes not lists); otherwise the
 * rules and hashes that passed, and how many were dropped.
 *
 * @return array{version: string, rules: array<string, array{0: string, 1: string, 2: string, 3: bool}>, hashes: list<string>, dropped: int}|null
 */
function validate_pack(mixed $raw): ?array
{
    if (!is_array($raw) || !is_string($raw['version'] ?? null) || preg_match(PACK_VERSION, $raw['version']) !== 1) {
        return null;
    }
    $rules_in = $raw['rules'] ?? null;
    $hashes_in = $raw['hashes'] ?? null;
    if (!is_array($rules_in) || !is_array($hashes_in)) {
        return null;
    }
    $dropped = max(0, count($rules_in) - PACK_MAX_RULES);
    $rules = [];
    foreach (array_slice(array_values($rules_in), 0, PACK_MAX_RULES) as $rule) {
        $checked = validate_rule($rule);
        if ($checked === null || isset($rules[$checked[0]])) {
            $dropped++;
            continue;
        }
        $rules[$checked[0]] = $checked[1];
    }
    $hashes = [];
    foreach ($hashes_in as $hash) {
        if (is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1) {
            $hashes[$hash] = true;
        }
        if (count($hashes) >= PACK_MAX_HASHES) {
            break;
        }
    }
    return ['version' => $raw['version'], 'rules' => $rules, 'hashes' => array_keys($hashes), 'dropped' => $dropped];
}

/**
 * One rule, or null when any part of it is wrong. The pattern must compile: a PCRE error or a
 * modifier PHP no longer has (`e`) makes preg_match() answer false, and the rule is dropped.
 *
 * @return array{0: string, 1: array{0: string, 1: string, 2: string, 3: bool}}|null
 */
function validate_rule(mixed $rule): ?array
{
    if (!is_array($rule)) {
        return null;
    }
    $id = $rule['id'] ?? null;
    $pattern = $rule['pattern'] ?? null;
    $severity = $rule['severity'] ?? null;
    $applies = $rule['applies_to'] ?? 'any';
    $raw = $rule['raw'] ?? false;
    if (!is_string($id) || preg_match(PACK_RULE_ID, $id) !== 1 || $id === KNOWN_BAD_RULE
        || !is_string($pattern) || $pattern === '' || strlen($pattern) > PACK_MAX_PATTERN || str_contains($pattern, "\0")
        || !in_array($severity, PACK_SEVERITIES, strict: true)
        || !in_array($applies, PACK_APPLIES, strict: true)
        || !is_bool($raw)) {
        return null;
    }
    if (@preg_match($pattern, '') === false) {
        return null;
    }
    return [$id, [$pattern, $severity, $applies, $raw]];
}

/**
 * Take a pack from a Cloud request. Refused unless this request carried a GoBuildWP Cloud
 * signature that verified; an unchanged version is acknowledged without re-checking. An offer of a
 * version alone (no rules, no hashes) only asks whether the site needs it: 'unchanged' or 'wanted'.
 *
 * @return array<string, mixed>|\WP_Error
 */
function accept_pack(mixed $body): array|\WP_Error
{
    if (!function_exists('gobuildwp_cloud_request_verified') || !\gobuildwp_cloud_request_verified()) {
        return new \WP_Error('gobuildwp_rules_unsigned', 'Only a signed GoBuildWP Cloud request may change the rule pack.', ['status' => 403]);
    }
    $offered = is_array($body) ? ($body['pack'] ?? null) : null;
    $stored = pack();
    if (is_array($offered) && ($offered['version'] ?? null) === $stored['version'] && $stored['version'] !== '') {
        return ['state' => 'unchanged', 'version' => $stored['version'], 'rules' => count($stored['rules']), 'hashes' => count($stored['hashes'])];
    }
    if (is_array($offered) && !array_key_exists('rules', $offered) && !array_key_exists('hashes', $offered)) {
        return ['state' => 'wanted', 'version' => $stored['version']];
    }
    $pack = validate_pack($offered);
    if ($pack === null) {
        return new \WP_Error('gobuildwp_rules_invalid', 'The rule pack could not be read.', ['status' => 400]);
    }
    update_option(PACK_OPTION, ['version' => $pack['version'], 'rules' => $pack['rules'], 'hashes' => $pack['hashes'], 'at' => time()], false);
    pack_reset();
    return ['state' => 'stored', 'version' => $pack['version'], 'rules' => count($pack['rules']), 'hashes' => count($pack['hashes']), 'dropped' => $pack['dropped']];
}

/**
 * POST gobuildwp/v1/cloud/rules.
 *
 * @param mixed $request
 * @return mixed array|\WP_Error
 */
function rest_rules(mixed $request): mixed
{
    /** @var mixed $body */
    $body = is_object($request) && method_exists($request, 'get_json_params') ? $request->get_json_params() : null;
    return accept_pack($body);
}

/**
 * The stored pack, read once per request. Shapes are checked again on read; a pattern that fails
 * at match time counts as no hit.
 *
 * @return array{version: string, rules: array<string, array{0: string, 1: string, 2: string, 3: bool}>, hashes: array<string, true>}
 */
function pack(): array
{
    $cached = &pack_cache();
    if ($cached !== null) {
        return $cached;
    }
    /** @var mixed $raw */
    $raw = get_option(PACK_OPTION, null);
    $cached = ['version' => '', 'rules' => [], 'hashes' => []];
    if (!is_array($raw) || !is_string($raw['version'] ?? null)) {
        return $cached;
    }
    $cached['version'] = $raw['version'];
    foreach (is_array($raw['rules'] ?? null) ? array_slice($raw['rules'], 0, PACK_MAX_RULES, true) : [] as $id => $rule) {
        if (is_string($id) && preg_match(PACK_RULE_ID, $id) === 1 && is_array($rule) && is_string($rule[0] ?? null)
            && in_array($rule[1] ?? null, PACK_SEVERITIES, strict: true) && in_array($rule[2] ?? null, PACK_APPLIES, strict: true)) {
            $cached['rules'][$id] = [$rule[0], $rule[1], $rule[2], ($rule[3] ?? false) === true];
        }
    }
    foreach (is_array($raw['hashes'] ?? null) ? array_slice($raw['hashes'], 0, PACK_MAX_HASHES) : [] as $hash) {
        if (is_string($hash)) {
            $cached['hashes'][$hash] = true;
        }
    }
    return $cached;
}

/** Forget the per-request copy (after a new pack is stored, and between tests). */
function pack_reset(): void
{
    $cached = &pack_cache();
    $cached = null;
}

/** @return array<string, mixed>|null */
function &pack_cache(): ?array
{
    static $cache = null;
    return $cache;
}

/** The stored pack's version, '' when there is none. */
function pack_version(): string
{
    return pack()['version'];
}

/** Whether this sha256 is on the known-bad list. */
function known_bad(string $sha256): bool
{
    return isset(pack()['hashes'][$sha256]);
}

/**
 * The file's sha256 when it is on the known-bad list, else null. Uses the text already read when
 * that is the whole file; a larger file is hashed only when there is a list to check it against.
 */
function known_bad_file(string $abs, string $text, Budget $budget): ?string
{
    if (pack()['hashes'] === []) {
        return null;
    }
    $size = @filesize($abs);
    if ($size === false) {
        return null;
    }
    if ($size === strlen($text)) {
        $hash = hash('sha256', $text);
    } elseif ($size <= $budget->limits['hash_size']) {
        $hash = @hash_file('sha256', $abs);
        $budget->bytes += $size;
    } else {
        return null;
    }
    return is_string($hash) && known_bad($hash) ? $hash : null;
}
