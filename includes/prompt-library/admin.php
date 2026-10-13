<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\PromptLibrary\Admin;

use WPPilot\PromptLibrary;

/**
 * The Prompts screen: pick a builder, pick an industry, copy the brief.
 *
 * Deliberately static HTML with a copy button and no server round-trip. A
 * brief is text; the moment this screen needs saving, filtering or state it
 * has stopped being a library and started being an app nobody asked for. The
 * one piece of client-side behaviour is the builder picker, which rewrites the
 * first line of every brief in place so what is copied names the editor the
 * agent will actually be driving.
 *
 * Locked briefs are shown, not hidden: someone on the free plugin should be
 * able to see that a Pro brief exists and what it covers.
 */

if (!defined('ABSPATH')) {
    exit();
}

function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'wppilot-connect',
        page_title: \wppilot_nav_label(PromptLibrary\PAGE, __('Prompts', domain: 'wppilot')),
        menu_title: \wppilot_nav_label(PromptLibrary\PAGE, __('Prompts', domain: 'wppilot')),
        capability: (string) \wppilot_manage_capability(),
        menu_slug: PromptLibrary\PAGE,
        callback: __NAMESPACE__ . '\\render',
    );
}

/**
 * @param mixed $map
 * @return mixed
 */
function register_nav(mixed $map): mixed
{
    if (!is_array($map)) {
        return $map;
    }

    $map[PromptLibrary\PAGE] = ['label' => __('Prompts', domain: 'wppilot'), 'group' => 'prompts'];

    return $map;
}

/**
 * Whether Pro is licensed and answering.
 *
 * Uses the same filter the Dashboard reads, so this screen never calls into Pro
 * and never checks whether it is installed. An unlicensed Pro does not answer,
 * which is the correct reading: its briefs are not available.
 */
function pro_active(): bool
{
    /** @var mixed $status */
    $status = apply_filters('wppilot_pro_status', value: null);

    return is_array($status);
}

function render(): void
{
    if (!\wppilot_current_user_can_manage()) {
        return;
    }

    $briefs = PromptLibrary\briefs();
    $sectors = PromptLibrary\by_sector($briefs);
    $builders = PromptLibrary\builders();
    $default_builder = PromptLibrary\default_builder();
    $licensed = pro_active();
    $free_count = count(array_filter($briefs, static fn(array $b): bool => ($b['pro'] ?? false) !== true));

    \wppilot_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(\wppilot_nav_label(PromptLibrary\PAGE, __('Prompts', domain: 'wppilot'))); ?></h1>
        <?php // These are a shortcut, not the interface. Nobody should read this
              // screen and conclude their own wording will not work. ?>
        <p class="wppilot-lede"><?php esc_html_e(
            'Landing-page briefs for each kind of business: palette, type, sections and a design signature. Pick your builder, copy a brief and paste it to your agent. Asking in your own words works just as well.',
            domain: 'wppilot',
        ); ?></p>

        <?php if ($briefs === []) {
            ?>
            <section class="wppilot-panel">
                <p class="description"><?php esc_html_e('No briefs are available.', domain: 'wppilot'); ?></p>
            </section>
            <?php

            return;
        } ?>

        <div class="wppilot-prompt-toolbar">
            <?php
            /*
             * A filter rather than a long scroll. Grouping by sector was enough
             * at ten briefs; past a hundred, somebody looking for "dentist" is
             * not going to find it by reading eighteen sector headings.
             */
            ?>
            <label for="wppilot-prompt-filter"><?php esc_html_e('Find', domain: 'wppilot'); ?></label>
            <input
                type="search"
                id="wppilot-prompt-filter"
                class="regular-text"
                placeholder="<?php esc_attr_e('dentist, bakery, booking…', domain: 'wppilot'); ?>"
                autocomplete="off"
            >
            <span class="description" id="wppilot-prompt-count" aria-live="polite"></span>
            <label for="wppilot-prompt-builder"><?php esc_html_e('Build with', domain: 'wppilot'); ?></label>
            <select id="wppilot-prompt-builder">
                <?php foreach ($builders as $slug => $label) { ?>
                    <option value="<?php echo esc_attr($slug); ?>"<?php selected($slug, $default_builder); ?>><?php
                        echo esc_html($label); ?></option>
                <?php } ?>
            </select>
        </div>

        <?php
        /*
         * One industry at a time. Every sector printed in a column came to 94,000
         * pixels and 35,000 words once Pro's briefs were in; the chips were anchors
         * into that scroll. They are now a filter, the first industry is open, and
         * the search box still looks across all of them.
         */
        $first_sector = (string) array_key_first($sectors);
        ?>
        <nav class="wppilot-client-tabs wppilot-prompt-sectors" aria-label="<?php esc_attr_e('Industries', domain: 'wppilot'); ?>">
            <?php foreach ($sectors as $sector => $sector_briefs) { ?>
                <button
                    type="button"
                    class="wppilot-client-tab<?php echo $sector === $first_sector ? ' active' : ''; ?>"
                    data-sector="<?php echo esc_attr(sanitize_title((string) $sector)); ?>"
                    aria-pressed="<?php echo $sector === $first_sector ? 'true' : 'false'; ?>"
                >
                    <?php echo esc_html((string) $sector); ?>
                    <span class="wppilot-prompt-count"><?php echo esc_html((string) count($sector_briefs)); ?></span>
                </button>
            <?php } ?>
        </nav>
        <p class="description wppilot-prompt-summary"><?php
        $total = count($briefs);
        if ($licensed || $free_count === $total) {
            printf(
                /* translators: 1: number of briefs, 2: number of industries */
                esc_html__('%1$d briefs across %2$d industries.', domain: 'wppilot'),
                (int) $total,
                count($sectors),
            );
        } else {
            printf(
                /* translators: 1: briefs included free, 2: further briefs that come with Pro */
                esc_html__('%1$d briefs are free; %2$d more come with GoBuildWP Pro and are marked with a lock.', domain: 'wppilot'),
                (int) $free_count,
                (int) ($total - $free_count),
            );
        }
        ?></p>

        <?php foreach ($sectors as $sector => $sector_briefs) { ?>
            <section
                class="wppilot-prompt-sector"
                data-sector="<?php echo esc_attr(sanitize_title((string) $sector)); ?>"
                <?php echo $sector === $first_sector ? '' : 'hidden'; ?>
            >
                <h2 class="wppilot-prompt-sector__title"><?php echo esc_html((string) $sector); ?></h2>
                <div class="wppilot-prompt-grid">
                <?php foreach ($sector_briefs as $brief) {
                    render_brief($brief, $default_builder, $licensed);
                } ?>
                </div>
            </section>
        <?php } ?>
    </div>

    <?php // Styles for this screen live in includes/assets/admin.css. ?>
    <script>
    (function () {
        function fallbackClipboardCopy(text) {
            return new Promise(function (resolve, reject) {
                var textarea = document.createElement('textarea');
                var active = document.activeElement;
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.setAttribute('aria-hidden', 'true');
                textarea.style.position = 'fixed';
                textarea.style.top = '-9999px';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();

                var copied = false;
                try {
                    copied = document.execCommand('copy');
                } catch (error) {
                    copied = false;
                }

                document.body.removeChild(textarea);
                if (active && typeof active.focus === 'function') {
                    active.focus();
                }
                copied ? resolve() : reject(new Error('copy command was rejected'));
            });
        }

        // The Prompts screen is independent from Connect and Troubleshoot, so
        // it must own the helper it calls. Also fall back when the modern API
        // exists but rejects because of browser permissions or an HTTP origin.
        if (!window.wppilotClipboardCopy) {
            window.wppilotClipboardCopy = function (text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    return navigator.clipboard.writeText(text).catch(function () {
                        return fallbackClipboardCopy(text);
                    });
                }
                return fallbackClipboardCopy(text);
            };
        }

        function showCopyState(button, label) {
            var original = button.getAttribute('data-label') || button.textContent;
            button.setAttribute('data-label', original);
            button.textContent = label;
            setTimeout(function () { button.textContent = original; }, 1800);
        }

        // The builder picker rewrites the first line of every brief. The
        // choice is remembered per browser: a person building Elementor sites
        // should not have to say so on every visit.
        var picker = document.getElementById('wppilot-prompt-builder');
        var prefix = <?php echo wp_json_encode(PromptLibrary\BUILDER_LINE_PREFIX); ?>;
        var storageKey = 'wppilotPromptBuilder';

        function applyBuilder() {
            if (!picker) {
                return;
            }
            var label = picker.options[picker.selectedIndex] ? picker.options[picker.selectedIndex].text : '';
            document.querySelectorAll('.wppilot-prompt__builder').forEach(function (line) {
                line.textContent = prefix + label;
            });
            try {
                window.localStorage.setItem(storageKey, picker.value);
            } catch (error) {
                // Storage can be unavailable; the picker still works for this page view.
            }
        }

        if (picker) {
            try {
                var remembered = window.localStorage.getItem(storageKey);
                if (remembered && picker.querySelector('option[value="' + remembered + '"]')) {
                    picker.value = remembered;
                }
            } catch (error) {
                // Ignore: no storage, no memory, nothing lost.
            }
            picker.addEventListener('change', applyBuilder);
            applyBuilder();
        }

        // Filtering is over the card's own visible text - industry, title,
        // description, signature - rather than the whole brief, so typing
        // "booking" finds the booking sites instead of every brief that happens
        // to mention a booking form somewhere in its body.
        var filterInput = document.getElementById('wppilot-prompt-filter');
        var filterCount = document.getElementById('wppilot-prompt-count');
        var cards = Array.prototype.slice.call(document.querySelectorAll('.wppilot-prompt'));
        var haystacks = cards.map(function (card) {
            var head = card.querySelector('.wppilot-prompt__head');
            return (head ? head.innerText : card.innerText).toLowerCase();
        });

        var chips = Array.prototype.slice.call(document.querySelectorAll('.wppilot-prompt-sectors [data-sector]'));
        var activeSector = chips.length ? chips[0].getAttribute('data-sector') : '';

        // A search looks across every industry; with the box empty, only the
        // chosen industry is shown.
        function applyFilter() {
            var term = (filterInput.value || '').trim().toLowerCase();
            var shown = 0;

            cards.forEach(function (card, index) {
                var match = term === '' || haystacks[index].indexOf(term) !== -1;
                card.hidden = !match;
                if (match) { shown++; }
            });

            document.querySelectorAll('.wppilot-prompt-sector').forEach(function (section) {
                var visible = section.querySelectorAll('.wppilot-prompt:not([hidden])').length;
                section.hidden = term === ''
                    ? section.getAttribute('data-sector') !== activeSector
                    : visible === 0;
            });

            chips.forEach(function (chip) {
                var on = term === '' && chip.getAttribute('data-sector') === activeSector;
                chip.classList.toggle('active', on);
                chip.setAttribute('aria-pressed', on ? 'true' : 'false');
            });

            filterCount.textContent = term === ''
                ? ''
                : shown + ' of ' + cards.length;
        }

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                activeSector = chip.getAttribute('data-sector');
                if (filterInput) { filterInput.value = ''; }
                applyFilter();
            });
        });

        if (filterInput) {
            filterInput.addEventListener('input', applyFilter);
        }

        document.querySelectorAll('.wppilot-prompt-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var body = document.getElementById(btn.getAttribute('data-target'));
                if (!body) {
                    showCopyState(btn, btn.getAttribute('data-failed'));
                    return;
                }
                window.wppilotClipboardCopy(body.textContent).then(function () {
                    showCopyState(btn, btn.getAttribute('data-copied'));
                }).catch(function () {
                    showCopyState(btn, btn.getAttribute('data-failed'));
                });
            });
        });
    })();
    </script>
    <?php
}

/**
 * One brief: its heading, what makes it distinct, and the text to copy.
 *
 * The builder line is rendered as its own span so the picker can rewrite it.
 * Everything after it is the brief body plus the shared standards, exactly as
 * compose() would return it for the default builder.
 *
 * @param array<string, mixed> $brief
 */
function render_brief(array $brief, string $builder, bool $licensed): void
{
    $slug = (string) ($brief['slug'] ?? '');
    $industry = (string) ($brief['industry'] ?? '');
    $title = (string) ($brief['title'] ?? $industry);
    $description = (string) ($brief['description'] ?? '');
    $signature = (string) ($brief['signature'] ?? '');
    $pro = ($brief['pro'] ?? false) === true;
    $is_locked = $pro && !$licensed;
    $id = 'wppilot-brief-' . $slug;
    $copied = __('Copied', domain: 'wppilot');
    $copy_failed = __('Copy failed', domain: 'wppilot');
    $composed = PromptLibrary\compose($brief, $builder);
    $builder_line = PromptLibrary\builder_line($builder);
    $rest = str_starts_with($composed, $builder_line) ? substr($composed, strlen($builder_line)) : "\n\n" . $composed;
    ?>
    <div class="wppilot-prompt" id="<?php echo esc_attr($id . '-card'); ?>">
        <div class="wppilot-prompt__head">
            <div>
                <p class="wppilot-prompt__meta">
                    <span class="wppilot-prompt__industry"><?php echo esc_html($industry); ?></span>
                    <?php if ($pro) { ?>
                        <span class="wppilot-prompt-lock"><?php if ($is_locked) { ?><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php } ?> <?php esc_html_e('Pro', domain: 'wppilot'); ?></span>
                    <?php } ?>
                </p>
                <h3 class="wppilot-prompt__title"><?php echo esc_html($title); ?></h3>
                <?php if ($description !== '') { ?>
                    <p class="description wppilot-prompt__description"><?php echo esc_html($description); ?></p>
                <?php } ?>
            </div>
            <?php if (!$is_locked) { ?>
                <button
                    type="button"
                    class="button wppilot-prompt-copy"
                    data-target="<?php echo esc_attr($id); ?>"
                    data-copied="<?php echo esc_attr($copied); ?>"
                    data-failed="<?php echo esc_attr($copy_failed); ?>"
                    aria-live="polite"
                ><?php esc_html_e('Copy brief', domain: 'wppilot'); ?></button>
            <?php } ?>
        </div>
        <?php if ($is_locked) { ?>
            <p class="description" style="margin:0;"><?php esc_html_e(
                'Included with GoBuildWP Pro.',
                domain: 'wppilot',
            ); ?></p>
        <?php } else { ?>
            <?php
            /*
             * Collapsed, not hidden. Ten briefs could all be printed open; three
             * hundred cannot - every body is several kilobytes, so the screen
             * came to 2.7MB of markup and a browser had to lay out all of it
             * before anybody saw the first one. <details> keeps the text in the
             * page, so Copy and the browser's own find-in-page still reach it,
             * and lets the reader open the one they want.
             */
            ?>
            <details class="wppilot-prompt__details">
                <summary class="wppilot-prompt__summary"><?php esc_html_e('Read the brief', domain: 'wppilot'); ?></summary>
                <?php if ($signature !== '') { ?>
                    <p class="wppilot-prompt__signature"><strong><?php esc_html_e('Signature:', domain: 'wppilot'); ?></strong> <?php
                        echo esc_html($signature); ?></p>
                <?php } ?>
                <pre class="wppilot-prompt__body" id="<?php echo esc_attr($id); ?>"><span class="wppilot-prompt__builder"><?php
                    echo esc_html($builder_line); ?></span><?php echo esc_html($rest); ?></pre>
            </details>
        <?php } ?>
    </div>
    <?php
}

add_action('admin_menu', __NAMESPACE__ . '\register_menu', priority: 42);
add_filter('wppilot_nav_map', __NAMESPACE__ . '\register_nav');
