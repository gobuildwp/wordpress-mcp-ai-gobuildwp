<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Telemetry;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Anonymous usage reporting: the switch, the identity, and the settings row.
 *
 * Opt-in. Nothing leaves the site until an administrator turns the toggle on,
 * so there is no first-run notice to argue with and no window in which a site
 * reports before anyone has been told it would.
 *
 * This whole directory is also removed from the wordpress.org build by
 * scripts/package.sh — that build physically cannot phone home, rather than
 * depending on a runtime flag somebody could get wrong. See
 * includes/updater.php for the same pattern.
 */

const OPTION_ENABLED = 'wppilot_telemetry_enabled';
const OPTION_INSTALL_ID = 'wppilot_install_id';

const CRON_HOOK = 'wppilot_telemetry_ping';

const ENDPOINT = 'https://gobuildwp.com/api/wppilot/telemetry';

/**
 * Whether this site reports.
 *
 * Off until somebody turns it on. An absent option is not a quiet yes: a site
 * that has never been asked has never agreed, and the first report carries the
 * site's address.
 *
 * Three states are still stored, not two: '1' is on, '0' is off, and absent is
 * "never chose", which reads the same as off but is not a decision — see
 * decided(). Keeping them apart is what lets an explicit '1' survive
 * reactivation and every new site added to a network, the same reasoning as
 * wppilot_enable_ai_abilities_on_activate().
 *
 * true is accepted alongside '1' because that is the test wppilot_is_enabled()
 * makes, and because a boolean written by some other code path would read back
 * as the string '1' only after a database round trip. A strict `=== true` would
 * hold in the request that wrote it and fail in every request after.
 */
function enabled(): bool
{
    /** @var mixed $value */
    $value = get_option(OPTION_ENABLED, default_value: null);

    if ($value === null) {
        return false;
    }

    return $value === '1' || $value === true;
}

/** Whether anyone has ever made a choice, either way. */
function decided(): bool
{
    return get_option(OPTION_ENABLED, default_value: null) !== null;
}

/**
 * A stable identifier for this install.
 *
 * A random UUID rather than a hash of the site URL. A site that moves from
 * http to https, or from www to the apex domain, would otherwise become two
 * installs and inflate the count — the URL is a mutable attribute of an
 * install, not its identity.
 *
 * Not autoloaded: it is read once a day by cron and never on a front-end
 * request.
 */
function install_id(): string
{
    /** @var mixed $stored */
    $stored = get_option(OPTION_INSTALL_ID, default_value: null);

    if (is_string($stored) && $stored !== '') {
        return $stored;
    }

    $uuid = wp_generate_uuid4();
    update_option(OPTION_INSTALL_ID, $uuid, autoload: false);

    return $uuid;
}

/**
 * Record the operator's decision and act on it immediately.
 *
 * Turning it off sends one final `opt-out` event, which is the deletion
 * request: the receiving end drops the site URL, clears the stored pings and
 * stamps a purge time. Sending that is the whole point — an opt-out that only
 * stops future pings would leave everything already collected in place.
 */
function set_enabled(bool $on): void
{
    $was = enabled();

    // '1' and '0', not true and false.
    //
    // update_option() reads the current value with get_option(), which answers
    // false for an option that does not exist, and then returns early when the
    // new value equals the old one. Storing boolean false on an option nobody
    // has ever set is therefore a silent no-op: add_option() is never reached
    // and nothing is written, so decided() would keep answering false and a
    // recorded "off" would not survive a later change to the default.
    //
    // A string '0' is not false, so the comparison fails and the write happens.
    update_option(OPTION_ENABLED, $on ? '1' : '0', autoload: true);

    if ($on) {
        schedule();
        return;
    }

    unschedule();

    if ($was) {
        send('opt-out');
    }
}

/**
 * Add the toggle to the GoBuildWP settings screen.
 *
 * @param mixed $sections
 * @return mixed
 */
function register_setting(mixed $sections): mixed
{
    if (!is_array($sections)) {
        return $sections;
    }

    $sections[] = [
        'id' => 'wppilot-telemetry',
        'title' => __('Anonymous usage reporting', domain: 'wppilot'),
        'description' => __(
            'Off unless you switch it on. Tells us which WordPress and PHP versions to keep working, and which integrations are actually used.',
            domain: 'wppilot',
        ),
        'fields' => [
            [
                'type' => 'toggle',
                'name' => OPTION_ENABLED,
                'label' => __('Send anonymous usage data once a day', domain: 'wppilot'),
                'help' => __(
                    'Sends this site\'s URL, the GoBuildWP, WordPress and PHP versions, your locale, whether Pro is active, your safety profile, and how many connections exist. It never sends usernames, email addresses, post content, or any record of what an agent did. Turning this off sends one final message asking us to delete what was collected.',
                    domain: 'wppilot',
                ),
                'value' => enabled(),
                'state' => enabled() ? 'armed' : 'ready',
            ],
        ],
        'save' => static function (array $post): void {
            set_enabled(isset($post[OPTION_ENABLED]));
        },
    ];

    return $sections;
}
