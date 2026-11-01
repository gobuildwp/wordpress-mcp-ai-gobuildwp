<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityTwoFactor;

use WP_Error;
use WP_User;

if (!defined('ABSPATH')) {
    exit();
}

/** The wp-login.php action that shows the second step. */
const LOGIN_ACTION = 'gobuildwp_kit_2fa';
/** The cookie that ties the second step to the browser that entered the password. */
const COOKIE = 'gobuildwp_kit_2fa';

/**
 * Hook the second step into the login and the setup into the profile page.
 *
 * GOBUILDWP_DISABLE_2FA (true in wp-config.php) switches all of it off, for an owner locked out
 * with neither phone nor backup codes and no other administrator to reset them.
 */
function install_hooks(): void
{
    if (defined('GOBUILDWP_DISABLE_2FA') && constant('GOBUILDWP_DISABLE_2FA') === true) {
        return;
    }
    // After the password checks (20) and login protection (99), so only a correct password gets here.
    add_filter('authenticate', __NAMESPACE__ . '\\filter_authenticate', 100, 3);
    add_action('application_password_did_authenticate', __NAMESPACE__ . '\\remember_application_password_user', 10, 1);
    add_action('login_form_' . LOGIN_ACTION, __NAMESPACE__ . '\\handle_second_step');
    add_filter('wp_login_errors', __NAMESPACE__ . '\\filter_login_errors', 10, 1);
    install_profile_hooks();
}

/**
 * Stop a correct password from finishing the login when the account needs a second factor.
 *
 * Browser logins are sent on to the second step (this exits). API logins cannot answer a prompt:
 * XML-RPC is let through only with an application password, which is a separate credential the user
 * makes on purpose and can revoke; REST requests with an application password never come through
 * this filter at all, so they keep working.
 *
 * @param WP_User|WP_Error|null $user
 * @return WP_User|WP_Error|null
 */
function filter_authenticate($user, $username = '', $password = '')
{
    if (!$user instanceof WP_User) {
        return $user;
    }
    $requirement = login_requirement($user->ID, array_values($user->roles), policy());
    if ($requirement === 'none') {
        return $user;
    }

    if (is_api_request()) {
        // Only this user's own application password counts: one XML-RPC multicall can log in several
        // times, and another account's application password must not excuse this one's password.
        if ($requirement === 'grace' || in_array($user->ID, application_password_users(), true)) {
            return $user;
        }
        return new WP_Error(
            'gobuildwp_kit_2fa_application_password',
            __('This account uses two-factor login. Sign in to the API with an application password (Users > Profile > Application Passwords) instead of the account password.', domain: 'wppilot'),
        );
    }

    if ($requirement === 'grace') {
        use_grace_login($user->ID);
        return $user;
    }

    if (user_throttled($user->ID)) {
        return new WP_Error('gobuildwp_kit_2fa_throttled', __('Too many wrong two-factor codes for this account. Try again in 15 minutes.', domain: 'wppilot'));
    }

    // A login form that posts in the background (a theme's AJAX login) cannot follow a redirect to
    // the second step, so it gets a message pointing to the normal login page.
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return new WP_Error('gobuildwp_kit_2fa_required', __('This account uses two-factor login. Sign in on the normal login page.', domain: 'wppilot'));
    }

    $extra = [
        'remember' => posted_string('rememberme') !== '',
        'redirect_to' => substr(posted_string('redirect_to'), 0, 2000),
        'interim' => posted_string('interim-login') !== '',
    ];
    if ($requirement === 'enroll') {
        $extra['secret'] = encrypt(new_secret());
    }
    $token = open_login($user->ID, $requirement, $extra);
    set_cookie($token, $requirement === 'enroll' ? ENROLL_TTL : LOGIN_TTL);
    wp_safe_redirect(second_step_url());
    exit();
}

/** A form or query value as wp-login.php reads it (POST first, then the query string), unslashed. */
function posted_string(string $key): string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? '';
    return is_string($value) ? (string) wp_unslash($value) : '';
}

/** @param WP_User|mixed $user */
function remember_application_password_user($user): void
{
    if ($user instanceof WP_User) {
        $GLOBALS['gobuildwp_2fa_app_password_users'][] = (int) $user->ID;
    }
}

/** @return list<int> Users that signed in with an application password during this request. */
function application_password_users(): array
{
    $ids = $GLOBALS['gobuildwp_2fa_app_password_users'] ?? [];
    return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
}

function is_api_request(): bool
{
    return (defined('XMLRPC_REQUEST') && (bool) constant('XMLRPC_REQUEST'))
        || (defined('REST_REQUEST') && (bool) constant('REST_REQUEST'));
}

function second_step_url(): string
{
    return add_query_arg('action', LOGIN_ACTION, wp_login_url());
}

function set_cookie(string $value, int $ttl): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(COOKIE, $value, [
        'expires' => $value === '' ? time() - 3600 : time() + $ttl,
        'path' => defined('SITECOOKIEPATH') ? (string) constant('SITECOOKIEPATH') : '/',
        'domain' => defined('COOKIE_DOMAIN') ? (string) constant('COOKIE_DOMAIN') : '',
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function cookie_token(): string
{
    $raw = $_COOKIE[COOKIE] ?? '';
    return is_string($raw) ? (string) wp_unslash($raw) : '';
}

/** The value the form must send back: binds the POST to the cookie without echoing the token. */
function form_check(string $token): string
{
    return substr(hash_hmac('sha256', 'form', $token), 0, 32);
}

/** Messages on the normal login form after the second step sent the user back there. */
function filter_login_errors(WP_Error $errors): WP_Error
{
    $reason = isset($_GET[LOGIN_ACTION]) && is_string($_GET[LOGIN_ACTION]) ? $_GET[LOGIN_ACTION] : '';
    if ($reason === 'expired') {
        $errors->add('gobuildwp_kit_2fa_expired', __('The two-factor step timed out. Enter your password again.', domain: 'wppilot'));
    } elseif ($reason === 'locked') {
        $errors->add('gobuildwp_kit_2fa_locked', __('Too many wrong two-factor codes. Enter your password again.', domain: 'wppilot'));
    }
    return $errors;
}

function back_to_login(string $reason): void
{
    set_cookie('', 0);
    wp_safe_redirect(add_query_arg(LOGIN_ACTION, $reason, wp_login_url()));
    exit();
}

/**
 * wp-login.php?action=gobuildwp_kit_2fa: ask for the code (or, when the role policy requires two-factor
 * login and the grace sign-ins are used up, set up the app first), then finish the login.
 */
// @mago-expect lint:cyclomatic-complexity -- One request handler: expired, form check, throttle, enrol or challenge, success or failure.
function handle_second_step(): void
{
    nocache_headers();
    $token = cookie_token();
    $state = read_login($token);
    if ($state === null) {
        back_to_login('expired');
        return;
    }
    $user = get_userdata((int) $state['user_id']);
    if (!$user instanceof WP_User) {
        close_login($token);
        back_to_login('expired');
        return;
    }
    $mode = $state['mode'] === 'enroll' ? 'enroll' : 'challenge';
    $errors = new WP_Error();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $check = isset($_POST['gobuildwp_kit_2fa_check']) && is_string($_POST['gobuildwp_kit_2fa_check']) ? (string) wp_unslash($_POST['gobuildwp_kit_2fa_check']) : '';
        $code = isset($_POST['gobuildwp_kit_2fa_code']) && is_string($_POST['gobuildwp_kit_2fa_code']) ? substr(trim((string) wp_unslash($_POST['gobuildwp_kit_2fa_code'])), 0, 64) : '';
        if (!hash_equals(form_check($token), $check)) {
            $errors->add('gobuildwp_kit_2fa_form', __('The form expired. Enter the code again.', domain: 'wppilot'));
        } elseif (user_throttled($user->ID)) {
            close_login($token);
            back_to_login('locked');
            return;
        } else {
            $now = time();
            $codes = [];
            $ok = false;
            if ($mode === 'enroll') {
                $secret = decrypt((string) ($state['secret'] ?? ''));
                $step = $secret === null ? null : verify_totp($secret, $code, $now);
                if ($secret !== null && $step !== null) {
                    $codes = enable($user->ID, $secret, $step, $now);
                    $ok = true;
                }
            } else {
                $ok = verify_any($user->ID, $code, $now) !== null;
            }
            if ($ok) {
                finish_login($token, $state, $user, $codes);
                return;
            }
            // Counted by login protection like a wrong password, so guessing codes locks out the address.
            do_action('wp_login_failed', $user->user_login, new WP_Error('gobuildwp_kit_2fa_invalid', __('Wrong two-factor code.', domain: 'wppilot')));
            if (!fail_login($token, $state)) {
                back_to_login('locked');
                return;
            }
            $errors->add('gobuildwp_kit_2fa_invalid', __('That code is not right. Try again.', domain: 'wppilot'));
        }
    }

    render_second_step($user, $mode, $state, $token, $errors);
    exit();
}

/**
 * The password and the code are both right: set the auth cookie the way wp_signon() would have.
 *
 * @param array<string, mixed> $state
 * @param list<string> $codes Backup codes made by setting up the app here, shown once before going on.
 */
function finish_login(string $token, array $state, WP_User $user, array $codes): void
{
    close_login($token);
    set_cookie('', 0);
    clear_failures($user->ID);
    wp_set_auth_cookie($user->ID, (bool) ($state['remember'] ?? false));
    do_action('wp_login', $user->user_login, $user);

    $requested = (string) ($state['redirect_to'] ?? '');
    $redirect_to = $requested !== '' ? $requested : admin_url();
    $redirect_to = (string) apply_filters('login_redirect', $redirect_to, $requested, $user);
    if (($redirect_to === '' || $redirect_to === 'wp-admin/' || $redirect_to === admin_url()) && !$user->has_cap('edit_posts')) {
        $redirect_to = $user->has_cap('read') ? admin_url('profile.php') : home_url();
    }
    $redirect_to = wp_validate_redirect($redirect_to, admin_url());

    if (($state['interim'] ?? false) === true) {
        add_filter('login_body_class', static fn(array $classes): array => array_merge($classes, ['interim-login', 'interim-login-success']));
        login_header('', '<p class="message">' . esc_html__('You have logged in successfully.', domain: 'wppilot') . '</p>');
        if ($codes !== []) {
            echo backup_codes_html($codes); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in backup_codes_html().
        }
        login_footer();
        exit();
    }
    if ($codes !== []) {
        login_header(__('Save your backup codes', domain: 'wppilot'));
        echo backup_codes_html($codes); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in backup_codes_html().
        echo '<p><a class="button button-primary button-large" href="' . esc_url($redirect_to) . '">' . esc_html__('I saved them, continue', domain: 'wppilot') . '</a></p>';
        login_footer();
        exit();
    }
    wp_safe_redirect($redirect_to);
    exit();
}

/** @param list<string> $codes */
function backup_codes_html(array $codes): string
{
    $items = '';
    foreach ($codes as $code) {
        $items .= '<li><code>' . esc_html($code) . '</code></li>';
    }
    return '<div class="gobuildwp-2fa-codes"><p>'
        . esc_html__('Backup codes let you sign in if you lose your phone. Each works once. Store them somewhere safe: they are not shown again.', domain: 'wppilot')
        . '</p><ul style="columns:2;font-size:15px;list-style:none;margin:0 0 1em;padding:0">' . $items . '</ul></div>';
}

/** The QR code, the secret to type in by hand, and what to do with them. */
function setup_html(string $secret, WP_User $user): string
{
    $uri = otpauth_uri($secret, $user->user_login, issuer_name());
    $grouped = trim(chunk_split(base32_encode($secret), 4, ' '));
    return '<p>' . esc_html__('Scan this code with an authenticator app (for example Google Authenticator, Microsoft Authenticator, 1Password or Authy), then enter the six-digit code it shows.', domain: 'wppilot') . '</p>'
        . '<p class="gobuildwp-2fa-qr" style="background:#fff;display:inline-block;margin:8px 0">' . qr_svg($uri) . '</p>'
        . '<p>' . esc_html__('Or enter this key in the app:', domain: 'wppilot') . '<br><code style="font-size:14px;user-select:all">' . esc_html($grouped) . '</code></p>';
}

/** What the authenticator app shows next to the codes: the site's name, else its host. */
function issuer_name(): string
{
    $name = trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));
    if ($name === '') {
        $name = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }
    return $name === '' ? 'WordPress' : substr($name, 0, 60);
}

/** @param array<string, mixed> $state */
function render_second_step(WP_User $user, string $mode, array $state, string $token, WP_Error $errors): void
{
    login_header(__('Two-factor login', domain: 'wppilot'), '', $errors);
    echo '<form name="gobuildwp_kit_2fa" id="loginform" action="' . esc_url(site_url('wp-login.php?action=' . LOGIN_ACTION, 'login_post')) . '" method="post" autocomplete="off">';
    if ($mode === 'enroll') {
        $secret = decrypt((string) ($state['secret'] ?? ''));
        echo '<p><strong>' . esc_html__('Your role must use two-factor login. Set it up to finish signing in.', domain: 'wppilot') . '</strong></p>';
        if ($secret !== null) {
            echo setup_html($secret, $user); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in setup_html(); the QR SVG is numbers only.
        }
        $label = __('Code from the app', domain: 'wppilot');
    } else {
        echo '<p>' . esc_html__('Enter the six-digit code from your authenticator app, or one of your backup codes.', domain: 'wppilot') . '</p>';
        $label = __('Authentication code', domain: 'wppilot');
    }
    echo '<p><label for="gobuildwp_kit_2fa_code">' . esc_html($label) . '</label>'
        . '<input type="text" name="gobuildwp_kit_2fa_code" id="gobuildwp_kit_2fa_code" class="input" value="" size="20" autocomplete="one-time-code" spellcheck="false" autocapitalize="off" maxlength="64" required autofocus></p>';
    echo '<input type="hidden" name="gobuildwp_kit_2fa_check" value="' . esc_attr(form_check($token)) . '">';
    echo '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__('Verify', domain: 'wppilot') . '"></p>';
    echo '</form>';
    echo '<p id="nav"><a href="' . esc_url(wp_login_url()) . '">' . esc_html__('Start over', domain: 'wppilot') . '</a></p>';
    login_footer('gobuildwp_kit_2fa_code');
}
