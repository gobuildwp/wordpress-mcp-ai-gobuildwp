<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The site's Ed25519 key and the signed calls it makes to the Cloud, §5.
 *
 * The Cloud authenticates heartbeats and unlinks by this signature rather than
 * by a shared secret, so a leaked Cloud database cannot be used to impersonate
 * the site, and the site never needs a credential for the Cloud at all. The
 * public half was handed over in §3; the secret half never leaves this option.
 *
 * sodium is part of PHP since 7.2, and WordPress ships sodium_compat for the
 * hosts that build PHP without it, so the functions are present on every
 * supported install - but that is checked rather than assumed, because a
 * missing one would otherwise fatal inside a cron event nobody watches.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Whether Ed25519 signing is available, loading WordPress's polyfill if needed.
 */
function wppilot_cloud_sodium_available(): bool
{
    if (function_exists('sodium_crypto_sign_detached')) {
        return true;
    }

    $compat = ABSPATH . 'wp-includes/sodium_compat/autoload.php';
    if (is_readable($compat)) {
        require_once $compat;
    }

    return function_exists('sodium_crypto_sign_detached') && function_exists('sodium_crypto_sign_keypair');
}

/**
 * The site's keypair, created on first use (§3.2).
 *
 * Stored base64-encoded and not autoloaded: the secret key has no business in
 * the alloptions cache that every front-end request loads.
 *
 * @return array{public: string, secret: string}|WP_Error Base64 (standard, padded) of the 32-byte public and 64-byte secret key.
 */
function wppilot_cloud_keys(bool $create = true): array|WP_Error
{
    $keys = wppilot_cloud_parse_keys(get_option(WPPILOT_CLOUD_KEYS_OPTION, default_value: null));
    if ($keys !== null) {
        return $keys;
    }
    if (!$create) {
        return new WP_Error('keys', __('This site has no GoBuildWP Cloud signing key.', domain: 'wppilot'));
    }
    if (!wppilot_cloud_sodium_available()) {
        return new WP_Error('keys', __('This PHP build cannot create the Ed25519 key GoBuildWP Cloud needs (sodium is unavailable).', domain: 'wppilot'));
    }

    $pair = sodium_crypto_sign_keypair();
    $keys = [
        'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
    ];
    update_option(WPPILOT_CLOUD_KEYS_OPTION, $keys, autoload: false);

    return $keys;
}

/**
 * A stored keypair, or null when the option is missing or damaged.
 *
 * @return array{public: string, secret: string}|null
 */
function wppilot_cloud_parse_keys(mixed $stored): ?array
{
    if (!is_array($stored) || !is_string($stored['public'] ?? null) || !is_string($stored['secret'] ?? null)) {
        return null;
    }

    $public = base64_decode($stored['public'], strict: true);
    $secret = base64_decode($stored['secret'], strict: true);
    if (!is_string($public) || strlen($public) !== 32 || !is_string($secret) || strlen($secret) !== 64) {
        return null;
    }

    return ['public' => $stored['public'], 'secret' => $stored['secret']];
}

/**
 * The §5 signature header value: base64 of the detached Ed25519 signature
 * over exactly the bytes sent.
 */
function wppilot_cloud_sign(string $raw_body, string $secret_key_base64): string
{
    $secret = base64_decode($secret_key_base64, strict: true);
    if (!is_string($secret) || strlen($secret) !== 64) {
        return '';
    }

    return base64_encode(sodium_crypto_sign_detached($raw_body, $secret));
}

/**
 * The raw JSON body of a signed call: site_id, ts and nonce first, then the payload.
 *
 * Returned as the string that is both signed and sent, so the two cannot
 * drift apart through a second encoding.
 *
 * @param array<string, mixed> $payload
 */
function wppilot_cloud_signed_body(string|int $site_id, array $payload, ?int $now = null): string
{
    // array_merge, not a spread: a string-keyed spread is a fatal error on PHP 8.0.
    return wppilot_cloud_json(array_merge(
        [
            'site_id' => $site_id,
            'ts' => $now ?? time(),
            'nonce' => wppilot_cloud_b64url(random_bytes(16)),
        ],
        $payload,
    ));
}

/**
 * Sign and POST a §5 call.
 *
 * @param array<string, mixed> $payload Fields after site_id, ts and nonce.
 * @return array{status: int, body: array<array-key, mixed>}|WP_Error
 */
function wppilot_cloud_signed_post(string $base, string $path, string|int $site_id, array $payload): array|WP_Error
{
    $keys = wppilot_cloud_keys(create: false);
    if (is_wp_error($keys)) {
        return $keys;
    }
    if (!wppilot_cloud_sodium_available()) {
        return new WP_Error('keys', __('sodium is unavailable, so this site cannot sign GoBuildWP Cloud requests.', domain: 'wppilot'));
    }

    $body = wppilot_cloud_signed_body($site_id, $payload);
    $signature = wppilot_cloud_sign($body, $keys['secret']);
    if ($signature === '') {
        return new WP_Error('keys', __('This site has no GoBuildWP Cloud signing key.', domain: 'wppilot'));
    }

    return wppilot_cloud_post($base, $path, $body, ['X-WPPilot-Signature' => $signature]);
}
