<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Shared admin chrome.
 *
 * The header every GoBuildWP screen renders, and the date/time format
 * helper those screens format timestamps with.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * JSON encoded for embedding directly inside a <script> block.
 *
 * Plain wp_json_encode() is already safe against the obvious attack: it escapes
 * forward slashes by default, so an embedded "</script>" comes out as
 * "<\/script>" and cannot close the block. This goes further and escapes the
 * angle brackets and ampersand too.
 *
 * The reason is that the existing safety rests entirely on slash escaping, which
 * is a default someone could switch off later by adding JSON_UNESCAPED_SLASHES
 * for prettier URLs — a change that looks cosmetic and would quietly reopen the
 * hole. Escaping the brackets themselves does not depend on that.
 *
 * The output is still valid JSON: a browser reads the hex escapes back to the
 * original characters, so the JavaScript sees exactly the intended value.
 */
function wppilot_script_json(mixed $value): string
{
    $json = wp_json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return $json === false ? 'null' : $json;
}

/**
 * Build a combined date/time format string from WordPress settings.
 *
 * Falls back to 'Y-m-d H:i:s' if either format is empty.
 *
 * @param string $fallback Optional fallback format.
 * @return string
 */
function wppilot_get_datetime_format($fallback = 'Y-m-d H:i:s')
{
    $date_format = (string) get_option('date_format');
    $time_format = (string) get_option('time_format');

    if ($date_format === '' || $time_format === '') {
        return $fallback;
    }

    return $date_format . ' ' . $time_format;
}

/**
 * Render the GoBuildWP header: the brand bar and the section navigation under it.
 *
 * Styling lives in includes/assets/admin.css, which every GoBuildWP screen loads.
 *
 * The right-hand readout is whether agents can currently act on this site. It
 * is the single most consequential fact about GoBuildWP's state, so it is on every
 * screen, and it links to the switch that changes it.
 *
 * @param string $legend Kept for callers that still pass a section name; the
 *                       section row under the bar now names the screen.
 */
function wppilot_render_admin_header(string $legend = ''): void
{
    unset($legend);
    $armed = wppilot_is_enabled() && wppilot_get_mcp_dependency_error() === null;
    $blocked = wppilot_is_enabled() && wppilot_get_mcp_dependency_error() !== null;

    // Off is idle, not "good": a green lamp next to "abilities off" would read
    // as a healthy running system, which is the opposite of what it means.
    $state_class = match (true) {
        $armed => 'wppilot-masthead__status--armed',
        $blocked => 'wppilot-masthead__status--attention',
        default => 'wppilot-masthead__status--idle',
    };
    $state_text = match (true) {
        $armed => __('Agents can act', domain: 'wppilot'),
        $blocked => __('Agents blocked', domain: 'wppilot'),
        default => __('Agents off', domain: 'wppilot'),
    };
    $pro_active = function_exists('wppilot_pro_is_active') && wppilot_pro_is_active();
    // The optional Cloud pointer belongs where connections are chosen, not on every
    // screen: WordPress.org asks plugins to keep promotional notices limited in scope.
    $current_page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $show_cloud_banner = in_array($current_page, ['wppilot-connect', 'wppilot-setup'], true);
    ?>
    <div class="wppilot-masthead">
        <?php if ($show_cloud_banner) : ?>
        <div class="wppilot-cloud-banner">
            <span><strong><?php esc_html_e('GoBuildWP Cloud', domain: 'wppilot'); ?></strong> — <?php esc_html_e('Optional: connect your sites through one workspace.', domain: 'wppilot'); ?></span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wppilot-setup&wppilot_method=cloud')); ?>"><?php esc_html_e('View Cloud connection', domain: 'wppilot'); ?> →</a>
        </div>
        <?php endif; ?>
        <div class="wppilot-masthead__inner">
        <div class="wppilot-masthead__mark">
            <a class="wppilot-masthead__brand" href="<?php echo esc_url(admin_url('admin.php?page=wppilot-connect')); ?>">
                <img
                    src="<?php echo esc_url((string) WPPILOT_PLUGIN_URL . 'assets/wppilot_logo-ink.svg'); ?>"
                    alt="<?php esc_attr_e('GoBuildWP dashboard', domain: 'wppilot'); ?>"
                    width="93"
                    height="30"
                >
            </a>
            <span class="wppilot-masthead__legend" title="<?php echo esc_attr(home_url()); ?>"><?php
                echo esc_html(get_bloginfo('name') !== '' ? get_bloginfo('name') : (string) wp_parse_url(home_url(), PHP_URL_HOST));
            ?></span>
            <span class="wppilot-masthead__version"><?php echo esc_html('v' . (string) WPPILOT_VERSION); ?></span>
        </div>
        <div class="wppilot-masthead__actions">
            <a
                class="wppilot-masthead__status <?php echo esc_attr($state_class); ?>"
                href="<?php echo esc_url(admin_url('admin.php?page=wppilot-settings')); ?>"
                title="<?php esc_attr_e('Turn agent access on or off in Settings', domain: 'wppilot'); ?>"
            >
                <span class="wppilot-masthead__lamp" aria-hidden="true"></span>
                <span><?php echo esc_html($state_text); ?></span>
            </a>
            <a class="wppilot-masthead__link" href="https://gobuildwp.com/docs/" target="_blank" rel="noopener noreferrer"><?php
                esc_html_e('Docs', domain: 'wppilot');
            ?></a>
            <?php if (!$pro_active && defined('WPPILOT_PRO_URL')): ?>
                <a class="wppilot-masthead__link wppilot-masthead__link--upgrade" href="<?php
                    echo esc_url((string) constant('WPPILOT_PRO_URL') . '?utm_source=plugin&utm_medium=header');
                ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get Pro', domain: 'wppilot'); ?></a>
            <?php endif; ?>
        </div>
        </div>
    </div>
    <?php

    wppilot_render_admin_tabs();
}

/**
 * Product navigation for the GoBuildWP screens: one row of sections, and under the
 * page title a short row of the screens inside the current section.
 *
 * Built from the registered submenu rather than a hardcoded list, so it cannot
 * drift when a screen is added, removed, or capability-gated — Pro's Memory tab
 * appears here only because Pro registered it.
 */
function wppilot_render_admin_tabs(): void
{
    $sections = wppilot_admin_sections();
    if (count($sections) < 2) {
        return;
    }

    $current = wppilot_admin_current_section();
    ?>
    <nav class="wppilot-tabs" aria-label="<?php esc_attr_e('GoBuildWP sections', domain: 'wppilot'); ?>">
        <div class="wppilot-tabs__row">
        <?php foreach ($sections as $key => $section) {
            $is_current = $key === $current;
            $badge = wppilot_admin_section_badge($key);
            ?>
            <a
                class="wppilot-tabs__section<?php echo $is_current ? ' is-current' : ''; ?>"
                href="<?php echo esc_url(admin_url('admin.php?page=' . $section['tabs'][0]['slug'])); ?>"
                <?php echo $is_current ? 'aria-current="true"' : ''; ?>
            ><?php echo esc_html($section['heading']); ?><?php if ($badge > 0): ?><span class="wppilot-tabs__badge" title="<?php
                esc_attr_e('Waiting for a decision', domain: 'wppilot');
            ?>"><?php echo esc_html((string) $badge); ?></span><?php endif; ?></a>
        <?php
        } ?>
        </div>
    </nav>
    <?php

    wppilot_render_admin_subtabs($sections[$current]['tabs'] ?? []);
}

/**
 * The screens inside one section, as a segmented control under the page title.
 *
 * Every screen prints its own <h1>, and threading a call into sixteen templates
 * would scatter the navigation across the codebase, so the control is printed
 * here and a few lines of script lift it to just under the title. Without
 * script it stays above the title, which still works.
 *
 * @param list<array{slug: string, label: string}> $tabs
 */
function wppilot_render_admin_subtabs(array $tabs): void
{
    if (count($tabs) < 2) {
        return;
    }

    $current = is_string($_GET['page'] ?? null) ? sanitize_key((string) $_GET['page']) : '';
    ?>
    <div class="wrap wppilot-subtabs-wrap">
    <nav class="wppilot-subtabs" aria-label="<?php esc_attr_e('Screens in this section', domain: 'wppilot'); ?>">
        <?php foreach ($tabs as $tab) {
            $is_current = $tab['slug'] === $current;
            ?>
            <a
                class="wppilot-subtabs__tab<?php echo $is_current ? ' is-current' : ''; ?>"
                href="<?php echo esc_url(admin_url('admin.php?page=' . $tab['slug'])); ?>"
                <?php echo $is_current ? 'aria-current="page"' : ''; ?>
            ><?php echo esc_html($tab['label']); ?></a>
        <?php
        } ?>
    </nav>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var holder = document.querySelector('.wppilot-subtabs-wrap');
        var nav = holder && holder.querySelector('.wppilot-subtabs');
        var h1 = document.querySelector('#wpbody-content .wrap:not(.wppilot-subtabs-wrap) h1');
        if (!nav || !h1) { return; }
        var row = document.createElement('div');
        row.className = 'wppilot-subtabs-row';
        row.appendChild(nav);
        // A title that shares a row with buttons is inline; the control needs its own line,
        // after the title's row of actions rather than wedged between them.
        var anchor = h1;
        while (anchor.nextElementSibling && anchor.nextElementSibling.matches('.page-title-action, a.button, button, .wppilot-title-actions')) {
            anchor = anchor.nextElementSibling;
        }
        var next = anchor.nextElementSibling;
        if (next && next.matches('p.wppilot-lede, p.description, .wppilot-lede')) {
            anchor = next;
        }
        anchor.insertAdjacentElement('afterend', row);
        holder.parentNode.removeChild(holder);
    });
    </script>
    <?php
}

/**
 * The current screen's section key.
 */
function wppilot_admin_current_section(): string
{
    $page = is_string($_GET['page'] ?? null) ? sanitize_key((string) $_GET['page']) : '';

    return wppilot_nav_group($page);
}

/**
 * Count shown beside a section when something there is waiting on a person.
 *
 * Only approvals qualify: a held agent write does nothing until someone decides,
 * so it is the one thing worth pulling attention from every screen.
 */
function wppilot_admin_section_badge(string $section): int
{
    if ($section !== 'activity') {
        return 0;
    }

    return wppilot_admin_pending_approvals();
}

/**
 * Agent writes held for a decision, or 0 without Pro's approval queue.
 */
function wppilot_admin_pending_approvals(): int
{
    static $count = null;
    if ($count !== null) {
        return $count;
    }

    $count = 0;
    $counter = 'WPPilot\\Pro\\Approval\\count_pending';
    if (function_exists($counter)) {
        try {
            $count = (int) $counter();
        } catch (\Throwable) {
            $count = 0;
        }
    }

    return $count;
}

/**
 * The sections the current user can open, each with its screens in order.
 *
 * @return array<string, array{heading: string, tabs: non-empty-list<array{slug: string, label: string}>}>
 */
function wppilot_admin_sections(): array
{
    /** @var array<string, list<array{slug: string, label: string, rank: int}>> $bucketed */
    $bucketed = [];
    foreach (wppilot_admin_tabs() as $index => $tab) {
        $bucketed[wppilot_nav_group($tab['slug'])][] = $tab + ['rank' => wppilot_nav_rank($tab['slug']) * 100 + $index];
    }

    $sections = [];
    foreach (wppilot_nav_groups() as $key => $heading) {
        $group_tabs = $bucketed[$key] ?? [];
        if ($group_tabs === []) {
            continue;
        }
        usort($group_tabs, static fn(array $a, array $b): int => $a['rank'] <=> $b['rank']);
        $sections[$key] = [
            'heading' => $heading,
            'tabs' => array_map(
                static fn(array $t): array => ['slug' => $t['slug'], 'label' => $t['label']],
                $group_tabs,
            ),
        ];
    }

    return $sections;
}

/**
 * The GoBuildWP sections the current user may open, in menu order.
 *
 * @return list<array{slug: string, label: string}>
 */
function wppilot_admin_tabs(): array
{
    // @mago-expect lint:no-global -- $submenu is WordPress' menu registry.
    global $submenu;

    // The sidebar hides these entries with CSS rather than unregistering them
    // (see includes/admin/sidebar.php for why removing them breaks the pages),
    // so this array is still the full, authoritative list.
    /** @var array<string, list<array<int, string>>> $submenu */
    $items = $submenu['wppilot-connect'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $tabs = [];
    foreach ($items as $item) {
        $slug = (string) ($item[2] ?? '');
        if ($slug === '') {
            continue;
        }

        // WordPress allows a submenu entry whose "slug" is an external URL, and
        // the Get Pro link is one. Rendering it here produced a tab labelled with
        // the raw URL, pointing at admin.php?page=https://... — so anything that
        // is not a real page slug belongs in the sidebar only.
        if (str_contains($slug, '://')) {
            continue;
        }

        $capability = (string) ($item[1] ?? '');
        if ($capability !== '' && !current_user_can($capability)) {
            continue;
        }

        $label = wppilot_admin_tab_label((string) ($item[0] ?? $slug), $slug);
        if ($label === $slug) {
            // The title was entirely markup, so there is no text to put on a tab.
            continue;
        }

        $tabs[] = ['slug' => $slug, 'label' => $label];
    }

    return $tabs;
}

/**
 * Reduce a menu title to plain tab text.
 *
 * Menu titles may append markup — "Visual" carries an Experimental badge, and
 * WordPress adds count bubbles the same way. Only the text before the first tag
 * belongs on the tab, or it reads "Visual Experimental".
 */
function wppilot_admin_tab_label(string $title, string $fallback): string
{
    $head = explode('<', $title, limit: 2)[0];
    $label = trim(wp_strip_all_tags($head));

    return $label === '' ? $fallback : $label;
}

/**
 * Give GoBuildWP's link-only screens (approval, OAuth consent, Block Editor Queue, visual runtime,
 * connected apps) their title.
 *
 * They are registered with no parent so they stay out of the menu, and get_admin_page_title()
 * never looks there, so it leaves the global null. admin-header.php then passes that null to
 * strip_tags(), which PHP 8.1 and later report as deprecated on every visit.
 */
function wppilot_title_hidden_admin_page(): void
{
    global $title, $plugin_page, $submenu;

    if (!empty($title) || !is_string($plugin_page) || !str_starts_with($plugin_page, 'wppilot')) {
        return;
    }
    foreach (is_array($submenu[''] ?? null) ? $submenu[''] : [] as $item) {
        if (is_array($item) && ($item[2] ?? null) === $plugin_page && is_string($item[3] ?? null)) {
            $title = $item[3];
            return;
        }
    }
}
add_action('admin_init', 'wppilot_title_hidden_admin_page');
