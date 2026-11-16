<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Server-side download of a URL an agent supplied, without letting that URL reach the
 * site's own network.
 *
 * wp_http_validate_url() looks the host up once and only checks IPv4 private ranges. The
 * download then resolves the host again, so a name that answers with a public address for
 * the check and 127.0.0.1 or 169.254.169.254 for the fetch (DNS rebinding) got through, as
 * did any IPv6 address, and download_url() followed redirects to wherever they pointed.
 *
 * Here each hop is resolved once, every address the name advertises is checked against the
 * private, loopback, link-local, reserved and documentation ranges (IPv4 and IPv6, including
 * IPv4-mapped IPv6), the connection is pinned to the checked address with CURLOPT_RESOLVE so
 * cURL cannot resolve the name a second time, and redirects are followed by hand so every
 * Location goes through the same checks before it is fetched.
 *
 * Limits: pinning needs PHP's cURL extension, which WordPress uses whenever it is present.
 * Without it the download is refused rather than made unpinned. A proxy configured with
 * WP_PROXY_HOST resolves the name itself, so the pin cannot apply there either.
 */

require_once __DIR__ . '/oauth/client-id-metadata.php';

const WPPILOT_SAFE_FETCH_MAX_REDIRECTS = 5;

/**
 * Check one URL and choose the address its connection will be pinned to.
 *
 * @return array{url: string, host: string, port: int, ip: string}|WP_Error
 */
function wppilot_safe_fetch_target(string $url): array|WP_Error
{
    $parts = wp_parse_url($url);
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return new WP_Error('wppilot_invalid_media_url', __('A public HTTP(S) URL is required.', domain: 'wppilot'));
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return new WP_Error('wppilot_invalid_media_url', __('URLs with a user name or password in them are not fetched.', domain: 'wppilot'));
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    $bare_host = trim($host, '[]');

    $addresses = \WPPilot\OAuth\ClientIdMetadata\resolve_host($bare_host);
    if ($addresses === []) {
        return new WP_Error('wppilot_media_url_unresolvable', sprintf(
            /* translators: %s: host name */
            __('%s does not resolve to any address, so nothing was downloaded.', domain: 'wppilot'),
            $bare_host,
        ));
    }
    foreach ($addresses as $address) {
        if (\WPPilot\OAuth\ClientIdMetadata\is_blocked_ip($address)) {
            return new WP_Error('wppilot_media_url_private', sprintf(
                /* translators: %s: host name */
                __('%s points at a private, loopback, link-local or reserved address, so nothing was downloaded. Only files on the public internet can be imported.', domain: 'wppilot'),
                $bare_host,
            ), ['status' => 400]);
        }
    }

    // IPv4 first: an IPv6 pin on a server without IPv6 routing fails where the IPv4 one works.
    $ipv4 = array_values(array_filter($addresses, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
    $ip = $ipv4[0] ?? $addresses[0];

    return ['url' => $url, 'host' => $bare_host, 'port' => $port, 'ip' => $ip];
}

/**
 * The CURLOPT_RESOLVE entry that pins a checked target. IPv6 hosts and addresses are bracketed.
 *
 * @param array{host: string, port: int, ip: string} $target
 */
function wppilot_safe_fetch_resolve_entry(array $target): string
{
    $bracket = static fn (string $value): string => str_contains($value, ':') ? '[' . $value . ']' : $value;
    return $bracket($target['host']) . ':' . $target['port'] . ':' . $bracket($target['ip']);
}

/**
 * Download a URL to a temporary file, as download_url() does, with each hop resolved, checked
 * and pinned. The caller deletes the file.
 */
function wppilot_safe_download_url(string $url, int $timeout = 30): string|WP_Error
{
    if (!function_exists('curl_init')) {
        return new WP_Error('wppilot_media_url_unpinned', __(
            'This server has no PHP cURL extension, so the download could not be pinned to a checked address and was not made. Upload the file instead.',
            domain: 'wppilot',
        ));
    }

    for ($hop = 0; $hop <= WPPILOT_SAFE_FETCH_MAX_REDIRECTS; ++$hop) {
        $target = wppilot_safe_fetch_target($url);
        if ($target instanceof WP_Error) {
            return $target;
        }

        $temporary = wp_tempnam((string) wp_parse_url($url, PHP_URL_PATH));
        if ($temporary === '') {
            return new WP_Error('http_no_file', __('Could not create a temporary file for the download.', domain: 'wppilot'));
        }

        $pinned = false;
        $pin = static function ($handle, array $args, string $request_url) use ($target, &$pinned): void {
            if (strtolower(trim((string) wp_parse_url($request_url, PHP_URL_HOST), '[]')) !== $target['host']) {
                return;
            }
            curl_setopt($handle, CURLOPT_RESOLVE, [wppilot_safe_fetch_resolve_entry($target)]);
            $pinned = true;
        };
        add_action('http_api_curl', $pin, PHP_INT_MAX, 3);
        try {
            $response = wp_safe_remote_get($url, [
                'timeout' => $timeout,
                'stream' => true,
                'filename' => $temporary,
                'redirection' => 0,
            ]);
        } finally {
            remove_action('http_api_curl', $pin, PHP_INT_MAX);
        }

        if (is_wp_error($response) || !$pinned) {
            wp_delete_file($temporary);
            return is_wp_error($response) ? $response : new WP_Error('wppilot_media_url_unpinned', __(
                'The download did not go through cURL, so it could not be pinned to a checked address and was discarded. Upload the file instead.',
                domain: 'wppilot',
            ));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            wp_delete_file($temporary);
            $location = wp_remote_retrieve_header($response, 'location');
            if (is_array($location)) {
                $location = (string) end($location);
            }
            if (!is_string($location) || $location === '') {
                return new WP_Error('wppilot_media_download_refused', __('The download redirected without saying where to.', domain: 'wppilot'));
            }
            // Back to the top: the new URL is resolved, checked and pinned like the first.
            $url = WP_Http::make_absolute_url($location, $url);
            continue;
        }

        if ($status !== 200) {
            wp_delete_file($temporary);
            // Same shape as download_url()'s refusal, so the caller's message names the status.
            return new WP_Error('http_404', trim((string) wp_remote_retrieve_response_message($response)), ['code' => $status]);
        }

        return $temporary;
    }

    return new WP_Error('wppilot_media_download_refused', sprintf(
        /* translators: %d: redirect limit */
        __('The download redirected more than %d times, so it was stopped.', domain: 'wppilot'),
        WPPILOT_SAFE_FETCH_MAX_REDIRECTS,
    ));
}
