<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The one place GoBuildWP's screens are named and ordered.
 *
 * Thirteen screens used to name themselves at their own registration site, which
 * produced two problems this file exists to fix.
 *
 * The names drifted apart. "Configuration" and "Settings" were separate screens
 * whose titles are synonyms, so no label told you which one held the thing you
 * wanted. "Abilities Hub" and "Block Editor Queue" carried internal vocabulary
 * outward. Every screen now takes its label from wppilot_nav_label(), so a
 * rename happens once and reaches the sidebar and the tab rail together.
 *
 * And sixteen sibling items read as a list to be searched rather than a
 * structure to be navigated. They sit in six sections, each named for the
 * question it answers: Dashboard (is everything fine), Connect (how agents
 * reach the site), Activity (what they did and what waits for you), Agent (what
 * they may do and know), Studio (what they build) and Settings.
 *
 * Slugs are deliberately untouched. They are in bookmarks, in support threads,
 * and in the `page=` links other plugins may hold; renaming a screen should not
 * break a URL someone saved.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Section headings for the tab rail, in the order they appear.
 *
 * @return array<string, string>
 */
function wppilot_nav_groups(): array
{
    // Six sections, each named for the question it answers. Sixteen screens in four
    // groups wrapped the rail onto two rows and made every screen look equally
    // important; a section row plus a short sub-row for the screen inside it fits
    // on one line and says where you are twice.
    $groups = [
        'dashboard' => __('Dashboard', domain: 'wppilot'),
        'connect' => __('Connect', domain: 'wppilot'),
        'prompts' => __('Prompts', domain: 'wppilot'),
        'activity' => __('Activity', domain: 'wppilot'),
        'agent' => __('Agent tools', domain: 'wppilot'),
        'studio' => __('Design & chat', domain: 'wppilot'),
        'settings' => __('Settings', domain: 'wppilot'),
    ];

    /**
     * Filter the tab rail's section headings.
     *
     * @param array<string, string> $groups Group key to heading, in display order.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_nav_groups', $groups);
    if (!is_array($filtered)) {
        return $groups;
    }

    $safe = [];
    /** @var mixed $heading */
    foreach ($filtered as $key => $heading) {
        $safe[(string) $key] = (string) (is_scalar($heading) ? $heading : $key);
    }

    return $safe;
}

/**
 * Where each known screen sits, in the order it appears inside its section.
 *
 * This wins over the group a screen names for itself through wppilot_nav_map,
 * because the screens that name themselves (Changes, Preview, Prompts, and Pro's
 * Approvals, Licence and Plans) were written against the old four groups and
 * would otherwise land wherever their stale key happened to fall.
 *
 * @return array<string, string>
 */
function wppilot_nav_placement(): array
{
    return [
        'wppilot-connect' => 'dashboard',

        'wppilot-setup' => 'connect',
        'wppilot-troubleshoot' => 'connect',

        'wppilot-changes' => 'activity',
        'wppilot-pro-approvals' => 'activity',
        'wppilot-preview' => 'activity',

        'wppilot-abilities' => 'agent',
        'wppilot-skills' => 'agent',
        'wppilot-context' => 'agent',
        'wppilot-pro-memory' => 'agent',

        'wppilot-design' => 'studio',
        'wppilot-prompts' => 'prompts',
        'wppilot-chat' => 'studio',

        'wppilot-settings' => 'settings',
        'wppilot-pro-license' => 'settings',
        'wppilot-pro-activate' => 'settings',
        'wppilot-sandbox' => 'settings',
        'wppilot-pro-pricing' => 'settings',
    ];
}

/**
 * Translate a group key from the four-group rail to the six-section one, so an
 * add-on that still says 'connection' or 'system' lands somewhere sensible.
 */
function wppilot_nav_legacy_group(string $group): string
{
    return match ($group) {
        'connection' => 'connect',
        'system' => 'settings',
        default => $group,
    };
}

/**
 * Every GoBuildWP screen: its label and the group it belongs to.
 *
 * An add-on registering a screen adds itself here through the filter; anything
 * absent still appears on the rail, in the group named by the fallback below.
 *
 * @return array<string, array<array-key, mixed>>
 */
function wppilot_nav_map(): array
{
    $map = [
        // The home screen. The slug stays `wppilot-connect` because it is the parent
        // every other GoBuildWP screen registers under, and renaming it would break
        // every one of those registrations along with any saved link.
        'wppilot-connect' => ['label' => __('Dashboard', domain: 'wppilot'), 'group' => 'dashboard'],

        // How agents reach this site, and whether they are getting through.
        'wppilot-setup' => ['label' => __('Connect a client', domain: 'wppilot'), 'group' => 'connect'],
        'wppilot-troubleshoot' => ['label' => __('Diagnostics', domain: 'wppilot'), 'group' => 'connect'],

        // What an agent is allowed to do, and what it knows before it starts.
        'wppilot-abilities' => ['label' => __('Abilities', domain: 'wppilot'), 'group' => 'agent'],
        'wppilot-context' => ['label' => __('Instructions', domain: 'wppilot'), 'group' => 'agent'],
        'wppilot-skills' => ['label' => __('Skills', domain: 'wppilot'), 'group' => 'agent'],
        'wppilot-pro-memory' => ['label' => __('Memory', domain: 'wppilot'), 'group' => 'agent'],

        // Where work on the site actually gets made.
        'wppilot-chat' => ['label' => __('Chat', domain: 'wppilot'), 'group' => 'studio'],
        'wppilot-design' => ['label' => __('Design', domain: 'wppilot'), 'group' => 'studio'],

        // Deliberately absent: wppilot-gutenberg-finalize. It is a hidden
        // machinery page rather than a destination — see
        // includes/admin/gutenberg-finalizer.php — and keeping it out of this
        // map keeps it off the tab rail as well as out of the sidebar. It names
        // itself through gutenberg_finalizer_title(), which still consults this
        // map first so a rename here would reach it.

        // The machinery underneath.
        'wppilot-sandbox' => ['label' => __('Sandbox', domain: 'wppilot'), 'group' => 'settings'],
        'wppilot-settings' => ['label' => __('Settings', domain: 'wppilot'), 'group' => 'settings'],
    ];

    /**
     * Filter the GoBuildWP screen registry.
     *
     * @param array<string, array<string, mixed>> $map Page slug to label and group.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_nav_map', $map);
    if (!is_array($filtered)) {
        return $map;
    }

    $safe = [];
    /** @var mixed $entry */
    foreach ($filtered as $slug => $entry) {
        if (is_array($entry)) {
            $safe[(string) $slug] = $entry;
        }
    }

    return $safe;
}

/**
 * The label for a screen, for both the WordPress sidebar and the tab rail.
 *
 * Registration sites pass their own previous title as the fallback, so a screen
 * this file does not know about keeps working and simply keeps its old name.
 */
function wppilot_nav_label(string $slug, string $fallback = ''): string
{
    $entry = wppilot_nav_map()[$slug] ?? null;
    if (is_array($entry) && is_string($entry['label'] ?? null) && $entry['label'] !== '') {
        return $entry['label'];
    }

    return $fallback !== '' ? $fallback : $slug;
}

/**
 * The group a screen belongs to.
 *
 * Unknown screens land in the last group rather than being dropped: an add-on
 * that has not declared itself should still be reachable.
 */
function wppilot_nav_group(string $slug): string
{
    $placed = wppilot_nav_placement()[$slug] ?? null;
    if ($placed !== null && isset(wppilot_nav_groups()[$placed])) {
        return $placed;
    }

    $entry = wppilot_nav_map()[$slug] ?? null;
    if (is_array($entry) && is_string($entry['group'] ?? null) && $entry['group'] !== '') {
        $group = wppilot_nav_legacy_group($entry['group']);
        if (isset(wppilot_nav_groups()[$group])) {
            return $group;
        }
    }

    $groups = wppilot_nav_groups();
    $keys = array_keys($groups);

    return $keys === [] ? 'settings' : (string) end($keys);
}

/**
 * Position of a screen inside its section: placed screens in placement order,
 * everything else after them in the order WordPress registered it.
 */
function wppilot_nav_rank(string $slug): int
{
    $position = array_search($slug, array_keys(wppilot_nav_placement()), strict: true);

    return $position === false ? 1000 : (int) $position;
}
