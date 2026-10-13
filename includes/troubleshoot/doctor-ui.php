<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Troubleshoot\DoctorUi;

use WPPilot\Troubleshoot\Admin;
use WPPilot\Troubleshoot\Doctor;

/**
 * The Connection Doctor section of the Diagnostics screen.
 *
 * Rendered on the server from a nonce-protected link rather than through the panel's REST script:
 * the doctor is run on demand, its report is read top to bottom, and a plain page load keeps it
 * working where admin JavaScript or REST cookie authentication is itself what is broken.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NONCE_ACTION = 'wppilot_connection_doctor';

const QUERY_FLAG = 'wppilot_doctor';

const ANCHOR = 'wppilot-connection-doctor';

function run_url(): string
{
    return wp_nonce_url(
        add_query_arg([
            'page' => Admin\PAGE_SLUG,
            QUERY_FLAG => '1',
        ], admin_url('admin.php')),
        NONCE_ACTION,
    ) . '#' . ANCHOR;
}

function requested(): bool
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified on the next line.
    if (!isset($_GET[QUERY_FLAG])) {
        return false;
    }
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';

    return wp_verify_nonce($nonce, NONCE_ACTION) !== false;
}

/**
 * @return array{text: string, color: string}
 */
function badge(string $status): array
{
    return match ($status) {
        'pass' => ['text' => __('Pass', domain: 'wppilot'), 'color' => '#00a32a'],
        'warn' => ['text' => __('Warning', domain: 'wppilot'), 'color' => '#dba617'],
        'fail' => ['text' => __('Fail', domain: 'wppilot'), 'color' => '#d63638'],
        'skip' => ['text' => __('Skipped', domain: 'wppilot'), 'color' => '#787c82'],
        default => ['text' => __('Info', domain: 'wppilot'), 'color' => '#2271b1'],
    };
}

function render_section(): void
{
    if (!\wppilot_current_user_can_manage()) {
        return;
    }
    ?>
    <section class="wppilot-panel" id="<?php echo esc_attr(ANCHOR); ?>" style="margin-top:24px;">
        <h2><?php esc_html_e('Connection Doctor', domain: 'wppilot'); ?></h2>
        <p class="description" style="max-width:70ch;"><?php esc_html_e(
            'Finds what sits between an AI client and GoBuildWP: a CDN or firewall answering instead of WordPress, a web server that drops the Authorization header, an OAuth challenge that never arrives, a server clock that spoils sign-in. Each finding comes with the exact change to make. It sends a few requests from this site to itself and changes nothing.',
            domain: 'wppilot',
        ); ?></p>
        <p><a class="button" href="<?php echo esc_url(run_url()); ?>"><?php esc_html_e('Run Connection Doctor', domain: 'wppilot'); ?></a></p>
        <?php
        if (requested()) {
            render_report(Doctor\run());
        }
        ?>
    </section>
    <?php
}

/**
 * @param array{generated_at: string, summary: array{status: string, counts: array<string, int>}, checks: list<array{id: string, status: string, label: string, finding: string, evidence: list<string>, fix: string}>, note: string} $report
 */
function render_report(array $report): void
{
    ?>
    <table class="widefat striped" style="max-width:1100px;">
        <thead>
            <tr>
                <th style="width:9em;"><?php esc_html_e('Result', domain: 'wppilot'); ?></th>
                <th><?php esc_html_e('Check', domain: 'wppilot'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($report['checks'] as $check) {
            $badge = badge($check['status']);
            ?>
            <tr>
                <td><strong style="color:<?php echo esc_attr($badge['color']); ?>;"><?php echo esc_html($badge['text']); ?></strong></td>
                <td>
                    <strong><?php echo esc_html($check['label']); ?></strong>
                    <p style="margin:4px 0;"><?php echo esc_html($check['finding']); ?></p>
                    <?php if ($check['evidence'] !== []) { ?>
                        <details>
                            <summary><?php esc_html_e('Evidence', domain: 'wppilot'); ?></summary>
                            <pre style="white-space:pre-wrap;margin:4px 0;"><?php echo esc_html(implode("\n", $check['evidence'])); ?></pre>
                        </details>
                    <?php } ?>
                    <?php if ($check['fix'] !== '') { ?>
                        <p style="margin:6px 0 2px;"><strong><?php esc_html_e('Fix', domain: 'wppilot'); ?></strong></p>
                        <pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;margin:0;"><code><?php echo esc_html($check['fix']); ?></code></pre>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <p class="description" style="max-width:70ch;"><?php echo esc_html($report['note']); ?></p>
    <?php
}
