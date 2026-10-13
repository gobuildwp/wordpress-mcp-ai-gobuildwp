<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The Dashboard: the first screen, and the answer to "is everything fine, what did
 * agents do, and does anything need me?".
 *
 * The home screen used to be the connection wizard with the site's state stacked
 * under it, 6,500 pixels of it. That is the right screen once, on the day a client
 * is connected, and the wrong one every day after: someone checking on a site had
 * to scroll past three setup steps to learn whether an agent had changed anything.
 * The wizard now has its own screen (Connect), and this one leads with state.
 *
 * Everything here is read from the same sources the detail screens use: the change
 * ledger, the connection registry, Pro's approval queue and the safety profile.
 * Nothing is computed only for this screen, so it cannot disagree with them.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Rows in the recent-changes list. The Changes screen has the rest. */
const WPPILOT_HOME_RECENT_CHANGES = 8;

/**
 * Render the Dashboard.
 */
function wppilot_render_home_page(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }

    $dependency_error = wppilot_get_mcp_dependency_error();
    $enabled = wppilot_is_enabled();
    $live = $enabled && $dependency_error === null;
    $clients = wppilot_dashboard_client_activity();
    $setup_url = admin_url('admin.php?page=' . WPPILOT_SETUP_PAGE);
    ?>
    <?php wppilot_render_admin_header(); ?>
    <div class="wrap wppilot-home">
        <h1><?php echo esc_html(wppilot_nav_label('wppilot-connect')); ?></h1>
        <p class="wppilot-lede"><?php echo esc_html(sprintf(
            /* translators: %s: site name */
            __('What AI agents can do on %s, and what they have done.', domain: 'wppilot'),
            get_bloginfo('name') !== '' ? get_bloginfo('name') : wp_parse_url(home_url(), PHP_URL_HOST),
        )); ?></p>

        <?php wppilot_render_mcp_dependency_inline_notice($dependency_error); ?>
        <?php wppilot_render_authorization_header_warning(); ?>

        <?php if (!$enabled) { ?>
            <?php wppilot_home_callout(
                __('Agents are switched off', domain: 'wppilot'),
                __('No AI client can reach this site until agent access is turned on. Nothing else here changes while it is off.', domain: 'wppilot'),
                __('Turn on in Settings', domain: 'wppilot'),
                admin_url('admin.php?page=wppilot-settings'),
                'attention',
            ); ?>
        <?php } elseif ($live && $clients === []) { ?>
            <?php wppilot_home_callout(
                __('Connect your first AI client', domain: 'wppilot'),
                __('Claude, ChatGPT, Cursor and about twenty other clients can connect. It takes about a minute: pick a sign-in method, paste one line into the client, approve.', domain: 'wppilot'),
                __('Connect a client', domain: 'wppilot'),
                $setup_url,
                'accent',
            ); ?>
        <?php } ?>

        <?php wppilot_home_stats($live, $clients); ?>

        <div class="wppilot-home__grid">
            <div class="wppilot-home__main">
                <?php wppilot_home_recent_changes(); ?>
            </div>
            <div class="wppilot-home__side">
                <?php wppilot_home_clients($clients, $setup_url); ?>
                <?php wppilot_home_shortcuts(); ?>
                <?php wppilot_home_pro(); ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * A stored time as a Unix timestamp, or false.
 *
 * The ledger stores ISO 8601 with an offset and the connection registry stores
 * MySQL DATETIME in UTC with none; both are read as UTC.
 */
function wppilot_home_time(string $value): int|false
{
    if ($value === '') {
        return false;
    }

    return strtotime(preg_match('/[TZ+]/', $value) === 1 ? $value : $value . ' UTC');
}

/**
 * One prominent call to action above the stats.
 *
 * @param 'accent'|'attention' $tone
 */
function wppilot_home_callout(string $title, string $body, string $action, string $url, string $tone): void
{
    ?>
    <div class="wppilot-home__callout wppilot-home__callout--<?php echo esc_attr($tone); ?>">
        <div>
            <h2><?php echo esc_html($title); ?></h2>
            <p><?php echo esc_html($body); ?></p>
        </div>
        <a class="button button-primary" href="<?php echo esc_url($url); ?>"><?php echo esc_html($action); ?></a>
    </div>
    <?php
}

/**
 * The four readings across the top.
 *
 * @param array<string, array<string, mixed>> $clients
 */
function wppilot_home_stats(bool $live, array $clients): void
{
    $profiles = wppilot_safety_profiles();
    $profile = wppilot_get_safety_profile();
    $profile_label = (string) ($profiles[$profile]['label'] ?? $profile);

    $last_seen = '';
    foreach ($clients as $client) {
        $last_seen = max($last_seen, (string) ($client['last_seen'] ?? ''));
    }
    $last_seen_ts = wppilot_home_time($last_seen);

    $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    $today = wppilot_count_change_log(['kind' => 'change', 'since' => $since]);
    $undoable = wppilot_count_change_log(['status' => 'undoable']);
    $pending = wppilot_admin_pending_approvals();
    $has_queue = function_exists('WPPilot\\Pro\\Approval\\count_pending');
    $changes_url = admin_url('admin.php?page=wppilot-changes');
    ?>
    <div class="wppilot-home__stats">
        <?php wppilot_home_stat(
            __('Agent access', domain: 'wppilot'),
            $live ? __('On', domain: 'wppilot') : __('Off', domain: 'wppilot'),
            sprintf(
                /* translators: %s: safety profile name */
                __('Profile: %s', domain: 'wppilot'),
                $profile_label,
            ),
            admin_url('admin.php?page=wppilot-settings'),
            $live ? 'live' : 'idle',
        ); ?>
        <?php wppilot_home_stat(
            __('AI clients', domain: 'wppilot'),
            (string) count($clients),
            $last_seen_ts !== false
                ? sprintf(
                    /* translators: %s: human time difference, such as "5 mins" */
                    __('Last active %s ago', domain: 'wppilot'),
                    human_time_diff($last_seen_ts),
                )
                : __('None connected yet', domain: 'wppilot'),
            admin_url('admin.php?page=' . WPPILOT_SETUP_PAGE),
        ); ?>
        <?php wppilot_home_stat(
            __('Changes, last 24 hours', domain: 'wppilot'),
            (string) $today,
            sprintf(
                /* translators: %d: number of changes that can still be undone */
                _n('%d can be undone', '%d can be undone', $undoable, 'wppilot'),
                $undoable,
            ),
            $changes_url,
        ); ?>
        <?php if ($has_queue) {
            wppilot_home_stat(
                __('Waiting for approval', domain: 'wppilot'),
                (string) $pending,
                $pending > 0 ? __('Agents are paused until you decide', domain: 'wppilot') : __('Nothing waiting', domain: 'wppilot'),
                admin_url('admin.php?page=wppilot-pro-approvals'),
                $pending > 0 ? 'attention' : '',
            );
        } else {
            $exposure = wppilot_dashboard_exposure();
            wppilot_home_stat(
                __('Abilities available', domain: 'wppilot'),
                (string) $exposure['total'],
                __('What agents can call on this site', domain: 'wppilot'),
                admin_url('admin.php?page=wppilot-abilities'),
            );
        } ?>
    </div>
    <?php
}

/**
 * One stat card. The whole card is the link, so the number is also the way in.
 *
 * @param ''|'live'|'idle'|'attention' $state
 */
function wppilot_home_stat(string $label, string $value, string $detail, string $url, string $state = ''): void
{
    ?>
    <a class="wppilot-home__stat<?php echo $state !== '' ? ' is-' . esc_attr($state) : ''; ?>" href="<?php echo esc_url($url); ?>">
        <span class="wppilot-home__stat-label"><?php echo esc_html($label); ?></span>
        <span class="wppilot-home__stat-value">
            <?php if ($state === 'live' || $state === 'idle' || $state === 'attention') { ?>
                <span class="wppilot-home__dot" aria-hidden="true"></span>
            <?php } ?>
            <?php echo esc_html($value); ?>
        </span>
        <span class="wppilot-home__stat-detail"><?php echo esc_html($detail); ?></span>
    </a>
    <?php
}

/**
 * The newest ledger rows, each with its undo button when one exists.
 */
function wppilot_home_recent_changes(): void
{
    $rows = wppilot_query_change_log(['kind' => 'change'], WPPILOT_HOME_RECENT_CHANGES);
    $changes_url = admin_url('admin.php?page=wppilot-changes');
    ?>
    <section class="wppilot-panel wppilot-home__card">
        <header class="wppilot-home__card-head">
            <h2><?php esc_html_e('Recent changes', domain: 'wppilot'); ?></h2>
            <a href="<?php echo esc_url($changes_url); ?>"><?php esc_html_e('View all', domain: 'wppilot'); ?></a>
        </header>
        <?php if ($rows === []) { ?>
            <div class="wppilot-home__empty">
                <p><strong><?php esc_html_e('No changes yet', domain: 'wppilot'); ?></strong></p>
                <p><?php esc_html_e('Every edit an agent makes through GoBuildWP shows up here, with a one-click undo when one is possible.', domain: 'wppilot'); ?></p>
            </div>
        <?php } else { ?>
            <ul class="wppilot-home__changes">
                <?php foreach ($rows as $entry) {
                    wppilot_home_change_row($entry);
                } ?>
            </ul>
        <?php } ?>
    </section>
    <?php
}

/**
 * One change: what happened in words, who did it, when, and the way back.
 *
 * @param array<string, mixed> $entry
 */
function wppilot_home_change_row(array $entry): void
{
    $id = (string) ($entry['id'] ?? '');
    $ability = (string) ($entry['ability'] ?? '');
    $status = wppilot_change_status($entry);
    $recorded = wppilot_home_time((string) ($entry['recorded_at'] ?? ''));
    $who = function_exists('WPPilot\\Admin\\Changes\\actor_label')
        ? \WPPilot\Admin\Changes\actor_label($entry)
        : '';
    $pill = match ($status) {
        'undoable' => ['ready', __('Can be undone', domain: 'wppilot')],
        'rolled-back' => ['', __('Undone', domain: 'wppilot')],
        default => ['', __('Not reversible', domain: 'wppilot')],
    };
    $detail_url = add_query_arg(['page' => 'wppilot-changes', 'change' => $id], admin_url('admin.php'));
    ?>
    <li class="wppilot-home__change">
        <div class="wppilot-home__change-main">
            <a class="wppilot-home__change-title" href="<?php echo esc_url($detail_url); ?>"><?php
                echo esc_html(wppilot_home_ability_label($ability));
            ?></a>
            <span class="wppilot-home__change-meta"><?php
                echo esc_html(implode(' · ', array_filter([
                    $who,
                    $recorded !== false
                        ? sprintf(
                            /* translators: %s: human time difference, such as "5 mins" */
                            __('%s ago', domain: 'wppilot'),
                            human_time_diff($recorded),
                        )
                        : '',
                ])));
            ?></span>
        </div>
        <span class="wppilot-pill<?php echo $pill[0] !== '' ? ' wppilot-pill--' . esc_attr($pill[0]) : ''; ?>"><?php echo esc_html($pill[1]); ?></span>
        <?php if ($status === 'undoable') { ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php esc_attr_e('Undo this change?', domain: 'wppilot'); ?>">
                <input type="hidden" name="action" value="wppilot_changes_undo">
                <input type="hidden" name="change_id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field('wppilot_changes_undo'); ?>
                <button type="submit" class="button button-small"><?php esc_html_e('Undo', domain: 'wppilot'); ?></button>
            </form>
        <?php } ?>
    </li>
    <?php
}

/**
 * The ability's own label when it is registered, else its name made readable.
 *
 * "wppilot/seo-bulk-update-meta" means something to a developer and little to
 * the person approving what an agent did; the registry carries a label for it.
 */
function wppilot_home_ability_label(string $name): string
{
    if ($name === '') {
        return __('Unknown change', domain: 'wppilot');
    }
    // wp_has_ability() first: a change made by an ability whose plugin has since been switched off
    // (an SEO plugin's edit) is ordinary history, and wp_get_ability() logs a notice for it.
    if (function_exists('wp_get_ability') && function_exists('wp_has_ability') && wp_has_ability($name)) {
        $ability = wp_get_ability($name);
        if (is_object($ability) && method_exists($ability, 'get_label')) {
            $label = trim((string) $ability->get_label());
            if ($label !== '') {
                return $label;
            }
        }
    }

    // Abilities from a plugin that is inactive right now (Yoast switched off since the
    // change) are not registered, so their label is gone; spell the name out instead.
    $slug = str_contains($name, '/') ? substr($name, (int) strpos($name, '/') + 1) : $name;
    $words = array_map(
        static fn(string $word): string => match ($word) {
            'seo', 'css', 'html', 'url', 'faq', 'acf', 'api', 'wp', 'cli', 'sql', 'db', 'ai' => strtoupper($word),
            'woocommerce' => 'WooCommerce',
            default => $word,
        },
        explode('-', $slug),
    );

    return ucfirst(implode(' ', $words));
}

/**
 * Connected clients, newest activity first.
 *
 * @param array<string, array<string, mixed>> $clients
 */
function wppilot_home_clients(array $clients, string $setup_url): void
{
    ?>
    <section class="wppilot-panel wppilot-home__card">
        <header class="wppilot-home__card-head">
            <h2><?php esc_html_e('AI clients', domain: 'wppilot'); ?></h2>
            <a href="<?php echo esc_url($setup_url); ?>"><?php esc_html_e('Connect another', domain: 'wppilot'); ?></a>
        </header>
        <?php if ($clients === []) { ?>
            <p class="wppilot-muted"><?php esc_html_e('No client has connected yet.', domain: 'wppilot'); ?></p>
        <?php } else { ?>
            <ul class="wppilot-home__clients">
                <?php foreach (array_slice($clients, 0, 6) as $client) {
                    $seen = wppilot_home_time((string) ($client['last_seen'] ?? ''));
                    ?>
                    <li class="<?php echo empty($client['reachable']) ? 'is-revoked' : ''; ?>">
                        <span class="wppilot-home__client-name"><?php echo esc_html((string) ($client['label'] ?? '')); ?></span>
                        <span class="wppilot-home__client-meta"><?php
                            echo esc_html(empty($client['reachable'])
                                ? __('Credential revoked', domain: 'wppilot')
                                : ($seen !== false
                                    ? sprintf(
                                        /* translators: %s: human time difference, such as "5 mins" */
                                        __('Active %s ago', domain: 'wppilot'),
                                        human_time_diff($seen),
                                    )
                                    : ''));
                        ?></span>
                        <span class="wppilot-home__client-count" title="<?php esc_attr_e('Requests', domain: 'wppilot'); ?>"><?php
                            echo esc_html(number_format_i18n((int) ($client['requests'] ?? 0)));
                        ?></span>
                    </li>
                <?php } ?>
            </ul>
            <?php if (count($clients) > 6) { ?>
                <p class="wppilot-muted"><a href="<?php echo esc_url($setup_url . '#wppilot-clients'); ?>"><?php
                    echo esc_html(sprintf(
                        /* translators: %d: number of further clients */
                        __('and %d more', domain: 'wppilot'),
                        count($clients) - 6,
                    ));
                ?></a></p>
            <?php } ?>
        <?php } ?>
    </section>
    <?php
}

/**
 * The places people go most, one click away.
 */
function wppilot_home_shortcuts(): void
{
    $links = [
        'wppilot-abilities' => [__('Choose what agents can do', domain: 'wppilot'), 'dashicons-admin-generic'],
        'wppilot-skills' => [__('Skills', domain: 'wppilot'), 'dashicons-welcome-learn-more'],
        'wppilot-design' => [__('Design system', domain: 'wppilot'), 'dashicons-art'],
        'wppilot-troubleshoot' => [__('Fix a connection', domain: 'wppilot'), 'dashicons-sos'],
    ];
    ?>
    <section class="wppilot-panel wppilot-home__card">
        <header class="wppilot-home__card-head">
            <h2><?php esc_html_e('Shortcuts', domain: 'wppilot'); ?></h2>
        </header>
        <ul class="wppilot-home__shortcuts">
            <?php foreach ($links as $slug => [$label, $icon]) { ?>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>">
                    <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                    <?php echo esc_html($label); ?>
                </a></li>
            <?php } ?>
        </ul>
    </section>
    <?php
}

/**
 * Pro, in one card: what it covers here when active, what it would add when not.
 */
function wppilot_home_pro(): void
{
    $status = wppilot_dashboard_pro_status();
    if ($status !== null) {
        ?>
        <section class="wppilot-panel wppilot-home__card">
            <header class="wppilot-home__card-head">
                <h2><?php esc_html_e('GoBuildWP Pro', domain: 'wppilot'); ?></h2>
                <?php if ($status['version'] !== '') { ?>
                    <span class="wppilot-pill wppilot-pill--ready"><?php echo esc_html('v' . $status['version']); ?></span>
                <?php } ?>
            </header>
            <p class="wppilot-muted"><?php echo esc_html(sprintf(
                /* translators: 1: integrations matched on this site, 2: integrations in the licence, 3: abilities Pro adds */
                __('%1$d of %2$d integrations match plugins on this site, adding %3$d abilities.', domain: 'wppilot'),
                count($status['active_integrations']),
                $status['total_integrations'],
                $status['abilities'],
            )); ?></p>
            <?php if ($status['active_integrations'] !== []) { ?>
                <p class="wppilot-home__tags"><?php foreach (array_slice($status['active_integrations'], 0, 8) as $name) { ?><span><?php echo esc_html($name); ?></span><?php } ?></p>
            <?php } ?>
        </section>
        <?php
        return;
    }

    if (function_exists('wppilot_render_pro_upsell_card')) {
        wppilot_render_pro_upsell_card();
    }
}
