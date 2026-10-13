<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Markdown;

use WPPilot\Design\Preflight;
use WPPilot\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Render a small, safe subset of Markdown to HTML for the detail view:
 * `#`–`######` headings, `-`/`*`/`N.` lists, `**bold**`, `` `code` `` and
 * paragraphs. All text is escaped first and only the structural tags we emit
 * are HTML, so an imported DESIGN.md cannot inject markup. Front matter and
 * horizontal rules are dropped.
 */
// @mago-expect lint:halstead
function to_html(string $md): string
{
    $m = [];
    $normalized = Tokens\normalize_md($md);
    if (str_starts_with($normalized, "---\n")) {
        $end = strpos($normalized, needle: "\n---\n", offset: 4);
        if ($end !== false) {
            $normalized = substr($normalized, offset: $end + 5);
        }
    }

    $html = '';
    $para = '';
    $list = '';
    $list_tag = '';

    foreach (explode(separator: "\n", string: $normalized) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || $trimmed === '---' || $trimmed === '***') {
            $html .= flush_para($para) . flush_list($list, $list_tag);
            $para = '';
            $list = '';
            $list_tag = '';
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m) === 1) {
            $html .= flush_para($para) . flush_list($list, $list_tag);
            $para = '';
            $list = '';
            $list_tag = '';
            $level = (int) min(strlen($m[1]) + 1, 6);
            $html .= '<h' . $level . '>' . inline($m[2]) . '</h' . $level . '>';
            continue;
        }

        $is_bullet = preg_match('/^[-*]\s+(.*)$/', $trimmed, $m) === 1;
        $is_numbered = !$is_bullet && preg_match('/^\d+[.)]\s+(.*)$/', $trimmed, $m) === 1;
        if ($is_bullet || $is_numbered) {
            $html .= flush_para($para);
            $para = '';
            $tag = $is_bullet ? 'ul' : 'ol';
            if ($list_tag !== '' && $list_tag !== $tag) {
                $html .= flush_list($list, $list_tag);
                $list = '';
            }
            $list_tag = $tag;
            $list .= '<li' . li_class($m[1]) . '>' . inline($m[1]) . '</li>';
            continue;
        }

        $html .= flush_list($list, $list_tag);
        $list = '';
        $list_tag = '';
        $para = $para === '' ? $trimmed : $para . ' ' . $trimmed;
    }

    return $html . flush_para($para) . flush_list($list, $list_tag);
}

function flush_para(string $para): string
{
    return $para === '' ? '' : '<p>' . inline($para) . '</p>';
}

function flush_list(string $list, string $list_tag): string
{
    return $list === '' || $list_tag === '' ? '' : '<' . $list_tag . '>' . $list . '</' . $list_tag . '>';
}

/**
 * Class attribute for a guidance list item: positive ("Do …", "Always …") or
 * negative ("Don't …", "Never …", "Avoid …"). Returns '' for ordinary items so
 * only Do's & Don'ts get the colour-coded treatment.
 */
function li_class(string $text): string
{
    if (Preflight\is_dont($text)) {
        return ' class="wppilot-doc-dont"';
    }
    if (Preflight\is_do($text)) {
        return ' class="wppilot-doc-do"';
    }
    return '';
}

/**
 * Render just the body of the first top-level or second-level section (one or
 * two leading hashes) whose title matches one of $titles, case-insensitively,
 * excluding the heading line itself. Deeper sub-headings stay part of the body.
 * Returns '' when no such section exists. Lets the detail view surface specific
 * narrative blocks (philosophy, Do's and Don'ts) instead of the whole document.
 *
 * @param list<string> $titles
 */
function section(string $md, array $titles): string
{
    $body = raw_section($md, $titles);
    return $body === '' ? '' : to_html($body);
}

/**
 * The raw Markdown body of the first matching section, or '' when absent.
 *
 * @param list<string> $titles
 */
function raw_section(string $md, array $titles): string
{
    $normalized = Tokens\normalize_md($md);
    $wanted = array_map(static fn(string $title): string => strtolower($title), $titles);
    $body = '';
    $capturing = false;
    $m = [];
    foreach (explode(separator: "\n", string: $normalized) as $line) {
        if (preg_match('/^#{1,2}\s+(.*)$/', $line, $m) === 1) {
            if ($capturing) {
                break;
            }
            $capturing = in_array(strtolower(trim($m[1])), $wanted, strict: true);
            continue;
        }
        if ($capturing) {
            $body .= $line . "\n";
        }
    }
    return $body;
}

/**
 * Split a guidance section into grouped Do items, Don't items and the
 * remaining prose, regardless of the order they appear in the source. Items
 * are safe inline HTML ready for an <li>; `rest` is rendered HTML.
 *
 * @param list<string> $titles
 * @return array{dos: list<string>, donts: list<string>, rest: string}
 */
function guidance(string $md, array $titles): array
{
    $body = raw_section($md, $titles);
    $dos = [];
    $donts = [];
    $rest = '';
    $m = [];
    foreach (explode(separator: "\n", string: $body) as $line) {
        if (preg_match('/^(?:[-*]|\d+[.)])\s+(.*)$/', trim($line), $m) === 1) {
            $text = trim($m[1]);
            if (Preflight\is_dont($text)) {
                $donts[] = inline($text);
                continue;
            }
            if (Preflight\is_do($text)) {
                $dos[] = inline($text);
                continue;
            }
        }
        $rest .= $line . "\n";
    }
    return [
        'dos' => $dos,
        'donts' => $donts,
        'rest' => trim($rest) === '' ? '' : to_html($rest),
    ];
}

/** Escape text, then re-apply inline bold + code. Escape-first keeps it safe. */
function inline(string $text): string
{
    $escaped = esc_html($text);
    $bold = preg_replace('/\*\*([^*]+)\*\*/', replacement: '<strong>$1</strong>', subject: $escaped);
    if (!is_string($bold)) {
        return $escaped;
    }
    $coded = preg_replace('/`([^`]+)`/', replacement: '<code>$1</code>', subject: $bold);
    return is_string($coded) ? $coded : $bold;
}
