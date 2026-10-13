<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth 2.1 protocol endpoint called by external MCP clients; WordPress nonces cannot exist in this flow. Input is validated against the RFC grammar and rejected with protocol errors, and redirects go to client-registered callback URLs as the spec requires.

namespace WPPilot\OAuth\Consent;

use League\OAuth2\Server\Exception\OAuthServerException;
use WPPilot\OAuth\Bridge;
use WPPilot\OAuth\Endpoints\Authorize;
use WPPilot\OAuth\Keys\KeyBootstrapError;
use WPPilot\OAuth\Repositories\ClientRepository;
use WPPilot\OAuth\Repositories\UserEntity;
use WPPilot\OAuth\ServerFactory;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    $hook = add_submenu_page(
        parent_slug: '',
        page_title: 'Authorize Application',
        menu_title: '',
        capability: \wppilot_manage_capability(),
        menu_slug: 'wppilot-oauth-consent',
        callback: __NAMESPACE__ . '\\render',
    );

    // Approve/Deny must redirect back to the client before any admin HTML is sent. The page
    // callback runs after the admin header (headers already flushed, so wp_redirect is a no-op
    // and the browser is left on a blank consent page), so the POST is handled on the load hook,
    // which fires before any output.
    if (is_string($hook) && $hook !== '') {
        add_action('load-' . $hook, __NAMESPACE__ . '\\handle_load');
    }
}

/**
 * Fires before the admin header. Handles the Approve/Deny POST (validate, then redirect to the
 * client); GET requests fall through untouched so the page callback can draw the form.
 */
function handle_load(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $ctx = resolve_pending();
    if ($ctx === null) {
        return;
    }
    render_post($ctx['token'], $ctx['pending'], $ctx['redirect_uri'], $ctx['state']);
}

function render(): void
{
    $ctx = resolve_pending();
    if ($ctx === null) {
        return;
    }
    render_form($ctx['token'], $ctx['client_name'], $ctx['redirect_uri'], $ctx['scope']);
}

/**
 * Validate the request and load the pending authorization, shared by the load hook and the page
 * callback. On any failure it calls wp_die (which exits the request); the null return exists only
 * to satisfy the return type and is never reached at runtime.
 *
 * @return array{
 *     token: string,
 *     pending: array<array-key, mixed>,
 *     redirect_uri: string,
 *     state: string,
 *     client_name: string,
 *     scope: string,
 * }|null
 */
function resolve_pending(): ?array
{
    if (!is_user_logged_in()) {
        wp_die('You must be logged in.', title: '', args: ['response' => 403]);
        return null;
    }
    if (!\wppilot_current_user_can_manage()) {
        wp_die('You are not allowed to authorize GoBuildWP applications.', title: '', args: ['response' => 403]);
        return null;
    }

    $raw_token = $_GET['token'] ?? '';
    $token = is_string($raw_token) ? sanitize_text_field($raw_token) : '';
    if ($token === '') {
        wp_die('Missing consent token.', title: '', args: ['response' => 400]);
        return null;
    }

    // @mago-expect analysis:mixed-assignment
    $pending = get_transient(Authorize\PENDING_PREFIX . $token);
    if ($pending === false || !is_array($pending)) {
        wp_die('Invalid or expired consent token.', title: '', args: ['response' => 400]);
        return null;
    }

    $stored_user_id = (int) ($pending['user_id'] ?? 0);
    if ($stored_user_id !== get_current_user_id()) {
        wp_die('Session mismatch.', title: '', args: ['response' => 403]);
        return null;
    }

    $client_id = (string) ($pending['client_id'] ?? '');
    $client = (new ClientRepository())->getClientEntity($client_id);
    if ($client === null) {
        delete_transient(Authorize\PENDING_PREFIX . $token);
        wp_die('The application is no longer registered.', title: '', args: ['response' => 400]);
        return null;
    }

    return [
        'token' => $token,
        'pending' => $pending,
        'redirect_uri' => (string) ($pending['redirect_uri'] ?? ''),
        'state' => (string) ($pending['state'] ?? ''),
        'client_name' => $client->getName(),
        'scope' => (string) ($pending['scope'] ?? 'mcp'),
    ];
}

/** @param array<array-key, mixed> $pending */
/**
 * Add the RFC 9207 `iss` parameter to an authorization response redirect.
 *
 * A client that talks to more than one authorization server cannot otherwise
 * tell which one produced a code, so an attacker who controls a second server
 * can have a code from theirs redeemed at this one. The value is `home_url()`,
 * the same string published as `issuer` in the authorization-server metadata —
 * they must stay identical or a conforming client rejects every response.
 *
 * Applied to error redirects as well as success, since a mix-up attack works
 * just as well against an error path.
 */
function with_issuer(string $location): string
{
    if ($location === '') {
        return $location;
    }

    // Not pre-encoded: add_query_arg() runs values through build_query(), and
    // encoding here as well would deliver a double-escaped issuer that no
    // conforming client would match against its recorded value.
    return add_query_arg('iss', home_url(), $location);
}

function render_post(string $token, array $pending, string $redirect_uri, string $state): void
{
    check_admin_referer('wppilot_oauth_consent_' . $token);

    if (array_key_exists('deny', $_POST)) {
        delete_transient(Authorize\PENDING_PREFIX . $token);
        wp_redirect(with_issuer(add_query_arg(['error' => 'access_denied', 'state' => $state], $redirect_uri)));
        exit();
    }

    try {
        $code_challenge = (string) ($pending['code_challenge'] ?? '');
        $code_challenge_method = (string) ($pending['code_challenge_method'] ?? '');
        $scope = (string) ($pending['scope'] ?? 'mcp');
        $client_id = (string) ($pending['client_id'] ?? '');
        $user_id = (int) ($pending['user_id'] ?? 0);

        $server = ServerFactory\build_authorization_server();
        $fakeRequest = Bridge\psr7_from_globals()->withQueryParams([
            'response_type' => 'code',
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
            'scope' => $scope,
            'state' => $state,
        ]);
        $authRequest = $server->validateAuthorizationRequest($fakeRequest);

        $userEntity = new UserEntity();
        $userEntity->setIdentifier((string) $user_id);
        $authRequest->setUser($userEntity);
        $authRequest->setAuthorizationApproved(true);

        delete_transient(Authorize\PENDING_PREFIX . $token);
        $psr7Response = $server->completeAuthorizationRequest($authRequest, Bridge\new_psr7_response());

        wp_redirect(with_issuer($psr7Response->getHeaderLine('Location')));
        exit();
    } catch (OAuthServerException $e) {
        delete_transient(Authorize\PENDING_PREFIX . $token);
        wp_redirect(with_issuer(add_query_arg([
            'error' => $e->getErrorType(),
            'error_description' => $e->getMessage(),
            'state' => $state,
        ], $redirect_uri)));
        exit();
    } catch (KeyBootstrapError $e) {
        // The generic message below would hide the one failure an operator can act on: this site
        // has no OAuth signing key and its PHP cannot make one. The reason (OpenSSL error strings
        // and configuration paths, never key material) goes to the PHP error log — but only when
        // the site has turned debugging on, so the message below does not promise a log entry that
        // may not exist.
        delete_transient(Authorize\PENDING_PREFIX . $token);
        if (\wppilot_debug_logging_enabled()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('GoBuildWP OAuth: ' . $e->getMessage());
        }
        wp_die(
            esc_html__(
                'GoBuildWP could not create the OAuth signing keys for this site. Run "wp wppilot oauth-keys generate" from WP-CLI to create the keys, then authorize again. With WP_DEBUG enabled, the PHP error log records the OpenSSL reason.',
                domain: 'wppilot',
            ),
            title: '',
            args: ['response' => 500],
        );
    } catch (\Throwable $e) {
        delete_transient(Authorize\PENDING_PREFIX . $token);
        wp_die('An error occurred during authorization. Please try again.', title: '', args: ['response' => 500]);
    }
}

function render_form(string $token, string $client_name, string $redirect_uri, string $scope): void
{
    $redirect_destination = redirect_destination_label($redirect_uri);
    $grant = consent_grant_details($scope);

    \wppilot_render_admin_header();
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Authorize Application', domain: 'wppilot') . '</h1>';
    echo
        sprintf(
            /* translators: 1: name of the connecting client, 2: label of the access being requested */
            '<p>' . esc_html__('%1$s is requesting %2$s.', domain: 'wppilot') . '</p>',
            '<strong>' . esc_html($client_name) . '</strong>',
            '<strong>' . esc_html($grant['label']) . '</strong>',
        )
    ;
    echo '<p>' . esc_html($grant['description']) . '</p>';
    echo
        '<p><strong>'
            . esc_html__('Requested OAuth scope:', domain: 'wppilot')
            . '</strong> <code>'
            . esc_html($scope)
            . '</code></p>'
    ;
    if ($grant['risks'] !== []) {
        echo '<p><strong>' . esc_html__('This grant can:', domain: 'wppilot') . '</strong></p><ul>';
        foreach ($grant['risks'] as $risk) {
            echo '<li>' . esc_html($risk) . '</li>';
        }
        echo '</ul>';
    }
    echo
        '<p><strong>'
            . esc_html__('Redirect destination:', domain: 'wppilot')
            . '</strong> '
            . esc_html($redirect_destination)
            . '</p>'
    ;
    echo
        '<p class="description">'
            . esc_html__(
                'Only authorize applications you trust. The application name is provided by the connecting client.',
                domain: 'wppilot',
            )
            . '</p>'
    ;
    echo '<form method="post">';
    wp_nonce_field('wppilot_oauth_consent_' . $token);
    echo
        '<button type="submit" name="approve" value="1" class="button button-primary">'
            . esc_html__('Authorize', domain: 'wppilot')
            . '</button> '
    ;
    echo
        '<button type="submit" name="deny" value="1" class="button">'
            . esc_html__('Deny', domain: 'wppilot')
            . '</button>'
    ;
    echo '</form>';
    echo '</div>';
}

/**
 * @return array{label: string, description: string, risks: list<string>}
 */
function consent_grant_details(string $scope): array
{
    return [
        'label' => __('full access to your WordPress site', domain: 'wppilot'),
        'description' => __(
            'Full access permits execution of GoBuildWP capabilities through MCP and REST, including REST-visible abilities registered by compatible third-party plugins.',
            domain: 'wppilot',
        ),
        'risks' => [
            __('Execute PHP and WP-CLI.', domain: 'wppilot'),
            __('Read, write, and delete server files.', domain: 'wppilot'),
            __('Change WordPress content and settings.', domain: 'wppilot'),
            __('Create temporary administrator access.', domain: 'wppilot'),
            __('Execute REST-visible abilities registered by compatible plugins.', domain: 'wppilot'),
        ],
    ];
}

function redirect_destination_label(string $redirect_uri): string
{
    $parsed = wp_parse_url($redirect_uri);
    if (!is_array($parsed)) {
        return $redirect_uri;
    }

    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
    $host = strtolower((string) ($parsed['host'] ?? ''));
    if ($host === '') {
        return $scheme !== '' ? $scheme . ':' : $redirect_uri;
    }

    return $scheme . '://' . $host;
}
