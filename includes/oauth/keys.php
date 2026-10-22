<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\OAuth\Keys;

use League\OAuth2\Server\CryptKey;
use OpenSSLAsymmetricKey;

if (!defined('ABSPATH')) {
    exit();
}

const PRIVATE_KEY_OPTION = 'wppilot_oauth_private_key';

const ENCRYPTION_KEY_OPTION = 'wppilot_oauth_encryption_key';

/**
 * Raised when OAuth key material cannot be created, persisted or read back.
 *
 * Extends RuntimeException so existing handlers keep catching it, but is nameable on its own so a
 * caller can tell a key-bootstrap failure apart from any other runtime error and report the one
 * remedy that applies. Messages carry operation names, OpenSSL error strings and configuration
 * paths only: never key material, and never the encryption key.
 */
// @mago-expect lint:file-name -- this file is the key module, and its only exception type belongs
// with the functions that throw it.
final class KeyBootstrapError extends \RuntimeException {}

/**
 * Returns the RSA private key (for signing), its derived public key (for verification),
 * and the symmetric encryption key. Generates and persists the secrets on first call.
 *
 * Only the private key and the encryption key are stored; the public key is derived from
 * the private key on read, so the signer and verifier can never drift out of sync (there
 * is no separate public-key option that a concurrent write could leave mismatched). Both
 * secrets are written with add_option, which does not overwrite an existing value, so two
 * concurrent first-use requests converge on a single persisted secret instead of racing
 * last-write-wins.
 *
 * @return array{private: CryptKey, public: CryptKey, encryption: string}
 */
function get(): array
{
    $private = (string) get_option(PRIVATE_KEY_OPTION, default_value: '');
    if ($private === '') {
        add_option(PRIVATE_KEY_OPTION, generate_private_key(), autoload: false);
        $private = (string) get_option(PRIVATE_KEY_OPTION, default_value: '');
    }
    if ($private === '') {
        throw new KeyBootstrapError('failed to persist OAuth private key');
    }

    $encryption = (string) get_option(ENCRYPTION_KEY_OPTION, default_value: '');
    if ($encryption === '') {
        add_option(ENCRYPTION_KEY_OPTION, base64_encode(random_bytes(32)), autoload: false);
        $encryption = (string) get_option(ENCRYPTION_KEY_OPTION, default_value: '');
    }
    if ($encryption === '') {
        throw new KeyBootstrapError('failed to persist OAuth encryption key');
    }

    return [
        'private' => new CryptKey($private, passPhrase: null, keyPermissionsCheck: false),
        'public' => new CryptKey(derive_public_key($private), passPhrase: null, keyPermissionsCheck: false),
        'encryption' => $encryption,
    ];
}

function generate_private_key(): string
{
    clear_openssl_errors();
    $key = openssl_pkey_new(key_arguments());
    if ($key !== false) {
        return export_private_key($key, config: null);
    }

    $errors = drain_openssl_errors();

    // OpenSSL reads OPENSSL_CONF once, while the PHP process starts, so a process that came up
    // without a usable configuration cannot repair itself with putenv(). The `config` argument of
    // openssl_pkey_new() *is* honoured per call, so a configuration file this process can still
    // read rescues the generation. Only the failure path pays for the lookup.
    $tried = [];
    foreach (discover_openssl_configs() as $config) {
        $tried[] = $config;
        clear_openssl_errors();
        $key = openssl_pkey_new(key_arguments($config));
        if ($key !== false) {
            return export_private_key($key, $config);
        }
        $errors = array_merge($errors, drain_openssl_errors());
    }

    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output. The only catch site (includes/oauth/consent.php) logs this message behind WP_DEBUG and shows a fixed escaped string to the user; the OpenSSL reason never reaches the page.
    throw new KeyBootstrapError(generation_failure_message($errors, $tried));
}

function derive_public_key(string $private_pem): string
{
    clear_openssl_errors();
    $key = openssl_pkey_get_private($private_pem);
    if ($key === false) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output. The only catch site (includes/oauth/consent.php) logs this message behind WP_DEBUG and shows a fixed escaped string to the user; the OpenSSL reason never reaches the page.
        throw new KeyBootstrapError(failure_message('openssl_pkey_get_private', drain_openssl_errors()));
    }

    $details = openssl_pkey_get_details($key);
    if ($details === false) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output. The only catch site (includes/oauth/consent.php) logs this message behind WP_DEBUG and shows a fixed escaped string to the user; the OpenSSL reason never reaches the page.
        throw new KeyBootstrapError(failure_message('openssl_pkey_get_details', drain_openssl_errors()));
    }

    // @mago-expect analysis:possibly-undefined-string-array-index
    return (string) $details['key'];
}

/**
 * Export a freshly generated key, reusing the configuration that produced it: an environment that
 * needed an explicit `config` to generate needs it to export as well.
 */
function export_private_key(OpenSSLAsymmetricKey $key, ?string $config): string
{
    clear_openssl_errors();
    $private = '';
    $options = $config === null ? null : ['config' => $config];
    $exported = openssl_pkey_export($key, $private, passphrase: null, options: $options);

    if ($exported === false || $private === '') {
        $tried = $config === null ? [] : [$config];
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output. The only catch site (includes/oauth/consent.php) logs this message behind WP_DEBUG and shows a fixed escaped string to the user; the OpenSSL reason never reaches the page.
        throw new KeyBootstrapError(failure_message('openssl_pkey_export', drain_openssl_errors(), $tried));
    }

    return $private;
}

/**
 * Arguments for one 2048-bit RSA key, optionally pinned to a configuration file.
 *
 * @return array<string, mixed>
 */
function key_arguments(?string $config = null): array
{
    $arguments = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    if ($config !== null) {
        $arguments['config'] = $config;
    }

    return $arguments;
}

/**
 * Drop whatever an earlier, unrelated OpenSSL call left queued, so a failure reported below is
 * attributed to its own reasons only. Costs one queue read on the happy path.
 */
function clear_openssl_errors(): void
{
    drain_openssl_errors();
}

/**
 * openssl_error_string() pops one entry per call: loop until the queue is empty so every reason
 * behind a failure is reported, not only the last one OpenSSL pushed.
 *
 * @return list<string>
 */
function drain_openssl_errors(): array
{
    $errors = [];
    while (($error = openssl_error_string()) !== false) {
        if ($error === '') {
            continue;
        }
        $errors[] = $error;
    }

    return $errors;
}

/**
 * The OpenSSL configuration paths named by the process environment, readable or not.
 *
 * @return array<string, string> variable name => value, for the variables that carry one
 */
function openssl_config_environment(): array
{
    $found = [];
    foreach (['OPENSSL_CONF', 'SSLEAY_CONF'] as $name) {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A server-environment filesystem path (not request input), only checked with is_readable() and handed to OpenSSL as its config file; stripslashes() or sanitize_text_field() would corrupt a Windows path.
            $from_server = $_SERVER[$name] ?? null;
            $value = is_string($from_server) ? $from_server : '';
        }

        $value = trim($value);
        if ($value !== '') {
            $found[$name] = $value;
        }
    }

    return $found;
}

/**
 * Readable OpenSSL configuration files this process could hand to openssl_pkey_new().
 *
 * Ordered by intent: what the environment names first, then the places the OpenSSL and PHP builds
 * ship one. Windows PHP keeps it under extras/ssl next to the interpreter, which is where LocalWP
 * has it even when nothing exported OPENSSL_CONF to the web process.
 *
 * @return list<string>
 */
function discover_openssl_configs(): array
{
    $candidates = array_values(openssl_config_environment());

    $locations = openssl_get_cert_locations();
    // PHP has reported this under both names across versions.
    foreach (['default_cert_area', 'default_default_cert_area'] as $index) {
        // An absent area yields "/openssl.cnf", which the readability filter below discards.
        $candidates[] = rtrim($locations[$index] ?? '', characters: '/\\') . '/openssl.cnf';
    }

    foreach ([PHP_BINDIR, dirname(PHP_BINARY)] as $directory) {
        if ($directory === '.') {
            continue;
        }
        $directory = rtrim($directory, characters: '/\\');
        $candidates[] = $directory . '/extras/ssl/openssl.cnf';
        $candidates[] = $directory . '/openssl.cnf';
    }

    $readable = [];
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $readable, strict: true)) {
            continue;
        }
        if (!is_file($candidate) || !is_readable($candidate)) {
            continue;
        }
        $readable[] = $candidate;
    }

    return $readable;
}

/**
 * Message for a failure to create the key: the configuration explanation always applies here,
 * because a generation that got this far has already exhausted every configuration on the box.
 *
 * @param list<string> $errors
 * @param list<string> $configs_tried
 */
function generation_failure_message(array $errors, array $configs_tried): string
{
    return compose_failure_message('openssl_pkey_new', $errors, configuration_hint($configs_tried));
}

/**
 * Message for any other OpenSSL failure, explaining the configuration only when it is implicated:
 * a malformed key must not be reported as an environment problem.
 *
 * @param list<string> $errors
 * @param list<string> $configs_tried
 */
function failure_message(string $operation, array $errors, array $configs_tried = []): string
{
    $hint = $configs_tried !== [] || mentions_configuration($errors) ? configuration_hint($configs_tried) : '';

    return compose_failure_message($operation, $errors, $hint);
}

/**
 * Credential-free failure text: the operation that failed, the hint when there is one, and every
 * reason OpenSSL queued.
 *
 * @param list<string> $errors
 */
function compose_failure_message(string $operation, array $errors, string $hint): string
{
    $parts = [$operation . ' failed.'];
    if ($hint !== '') {
        $parts[] = $hint;
    }

    $unique = array_values(array_unique($errors));
    $parts[] = $unique === []
        ? 'OpenSSL reported no further detail.'
        : 'OpenSSL reported: ' . implode('; ', $unique) . '.';

    return implode(' ', $parts);
}

/**
 * @param list<string> $errors
 */
function mentions_configuration(array $errors): bool
{
    foreach ($errors as $error) {
        if (stripos($error, needle: 'config') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Actionable explanation of a missing or unusable OpenSSL configuration, naming the variable, the
 * only places that can set it in time, and the supported recovery command. Paths only, no secrets.
 *
 * @param list<string> $configs_tried
 */
function configuration_hint(array $configs_tried): string
{
    $sentences = ['OpenSSL could not use a configuration file.'];

    $environment = openssl_config_environment();
    $unreadable = [];
    foreach ($environment as $name => $value) {
        if (is_file($value) && is_readable($value)) {
            continue;
        }
        $unreadable[] = $name . '="' . $value . '"';
    }

    if ($environment === []) {
        $sentences[] = 'Neither OPENSSL_CONF nor SSLEAY_CONF is set in this PHP process.';
    }
    if ($unreadable !== []) {
        $sentences[] = 'The PHP process cannot read the file named by ' . implode(', ', $unreadable) . '.';
    }

    if ($configs_tried !== []) {
        $sentences[] = 'Generation was retried with ' . implode(', ', $configs_tried) . ' and failed as well.';
    }

    $sentences[] =
        'OpenSSL reads OPENSSL_CONF when the PHP process starts, so putenv() from PHP is too late: '
        . 'set it in the environment PHP is started with and restart PHP. php.ini has no directive '
        . 'for this - use env[OPENSSL_CONF] = "/path/to/openssl.cnf" in the PHP-FPM pool '
        . 'configuration, SetEnv OPENSSL_CONF "/path/to/openssl.cnf" under Apache mod_php, or the '
        . 'environment of the service that starts PHP.';
    $sentences[] = 'To create the keys now from a PHP environment that does work, run: wp wppilot oauth-keys generate.';

    return implode(' ', $sentences);
}
