<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\Gutenberg\Markdown;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Markdown to core block specs ({name, attributes, innerBlocks}), for the Block Editor Queue
 * to serialize with each block's own save(). Covers what agents write: ATX headings,
 * paragraphs, bullet and numbered lists (nested by indent), block quotes, fenced code,
 * horizontal rules, standalone images and pipe tables, with **bold**, *italic*, ~~strike~~,
 * `code`, links and inline images. HTML in the Markdown is escaped and shown as text, never
 * passed through.
 */
const MAX_BYTES = 200000;
const MAX_DEPTH = 6;

/**
 * @return array{blocks: list<array<string, mixed>>, warnings: list<string>}
 */
function convert(string $markdown): array
{
    $warnings = [];
    $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $markdown);
    if (preg_match('/<\/?[a-z][a-z0-9-]*(\s[^>]*)?>/i', $text) === 1) {
        $warnings[] = 'HTML tags in the Markdown were escaped and will show as text.';
    }
    $blocks = parse_lines(explode("\n", $text), 0);

    return ['blocks' => $blocks, 'warnings' => $warnings];
}

function block(string $name, array $attributes = [], array $inner = []): array
{
    return ['name' => $name, 'attributes' => $attributes, 'innerBlocks' => $inner];
}

/**
 * @param list<string> $lines
 * @return list<array<string, mixed>>
 */
function parse_lines(array $lines, int $depth): array
{
    $blocks = [];
    $paragraph = [];
    $count = count($lines);
    $flush = static function () use (&$paragraph, &$blocks): void {
        if ($paragraph !== []) {
            $blocks[] = block('core/paragraph', ['content' => paragraph_html($paragraph)]);
            $paragraph = [];
        }
    };

    for ($i = 0; $i < $count; ++$i) {
        $line = $lines[$i];
        $trimmed = trim($line);

        if ($trimmed === '') {
            $flush();
            continue;
        }

        if (preg_match('/^\s{0,3}(`{3,}|~{3,})\s*([\w+#.-]*)\s*$/', $line, $fence) === 1) {
            $flush();
            $code = [];
            for (++$i; $i < $count; ++$i) {
                if (preg_match('/^\s{0,3}' . preg_quote($fence[1][0], '/') . '{' . strlen($fence[1]) . ',}\s*$/', $lines[$i]) === 1) {
                    break;
                }
                $code[] = $lines[$i];
            }
            $blocks[] = block('core/code', ['content' => escape(implode("\n", $code))]);
            continue;
        }

        if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)(\s+#+)?\s*$/', $line, $heading) === 1) {
            $flush();
            $blocks[] = block('core/heading', ['level' => strlen($heading[1]), 'content' => inline($heading[2])]);
            continue;
        }

        if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
            $flush();
            $blocks[] = block('core/separator');
            continue;
        }

        if (preg_match('/^\s{0,3}>/', $line) === 1) {
            $flush();
            $quoted = [];
            for (; $i < $count && preg_match('/^\s{0,3}>\s?(.*)$/', $lines[$i], $quote) === 1; ++$i) {
                $quoted[] = $quote[1];
            }
            --$i;
            $inner = $depth < MAX_DEPTH ? parse_lines($quoted, $depth + 1) : [block('core/paragraph', ['content' => paragraph_html($quoted)])];
            $blocks[] = block('core/quote', [], $inner);
            continue;
        }

        if (list_marker($line) !== null) {
            $flush();
            $consumed = 0;
            $blocks[] = parse_list(array_slice($lines, $i), $depth, $consumed);
            $i += $consumed - 1;
            continue;
        }

        if ($paragraph === [] && $i + 1 < $count && str_contains($line, '|') && is_table_rule($lines[$i + 1])) {
            $rows = [];
            for ($j = $i + 2; $j < $count && trim($lines[$j]) !== '' && str_contains($lines[$j], '|'); ++$j) {
                $rows[] = table_cells($lines[$j]);
            }
            $blocks[] = table_block(table_cells($line), $rows);
            $i = $j - 1;
            continue;
        }

        if ($paragraph === [] && preg_match('/^!\[([^\]]*)\]\(\s*(\S+?)(?:\s+"([^"]*)")?\s*\)$/', $trimmed, $image) === 1) {
            $next = $lines[$i + 1] ?? '';
            if (trim($next) === '' && safe_url($image[2])) {
                $attributes = ['url' => $image[2], 'alt' => $image[1]];
                if (($image[3] ?? '') !== '') {
                    $attributes['caption'] = escape($image[3]);
                }
                $blocks[] = block('core/image', $attributes);
                continue;
            }
        }

        $paragraph[] = $line;
    }
    $flush();

    return $blocks;
}

/**
 * @return array{indent: int, ordered: bool, number: int, text: string}|null
 */
function list_marker(string $line): ?array
{
    if (preg_match('/^(\s*)([-*+]|(\d{1,9})[.)])\s+(.*)$/', $line, $match) !== 1) {
        return null;
    }
    // "---" and "* * *" are rules, not lists.
    if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
        return null;
    }

    return [
        'indent' => strlen($match[1]),
        'ordered' => $match[3] !== '',
        'number' => $match[3] !== '' ? (int) $match[3] : 1,
        'text' => $match[4],
    ];
}

/**
 * One list, starting at $lines[0]. Lines indented past the marker belong to the item above
 * them and are parsed as its own Markdown, which is how a nested list comes out nested.
 *
 * @param list<string> $lines
 * @return array<string, mixed>
 */
function parse_list(array $lines, int $depth, int &$consumed): array
{
    $first = list_marker($lines[0]);
    $base = $first === null ? 0 : $first['indent'];
    $ordered = $first !== null && $first['ordered'];
    $items = [];
    $current = null;
    $children = [];
    $count = count($lines);
    $close = static function () use (&$items, &$current, &$children, $depth): void {
        if ($current === null) {
            return;
        }
        $inner = [];
        if ($children !== [] && $depth < MAX_DEPTH) {
            $inner = parse_lines(dedent($children), $depth + 1);
        }
        $nested = array_values(array_filter($inner, static fn(array $b): bool => $b['name'] === 'core/list'));
        $text = $current;
        foreach ($inner as $b) {
            if ($b['name'] === 'core/paragraph') {
                $text .= '<br>' . $b['attributes']['content'];
            }
        }
        $items[] = block('core/list-item', ['content' => $text], $nested);
        $current = null;
        $children = [];
    };

    for ($i = 0; $i < $count; ++$i) {
        $line = $lines[$i];
        $marker = list_marker($line);
        if ($marker !== null && $marker['indent'] <= $base + 1) {
            if ($marker['ordered'] !== $ordered) {
                break;
            }
            $close();
            $current = inline($marker['text']);
            continue;
        }
        if (trim($line) === '') {
            $next = $lines[$i + 1] ?? '';
            $next_marker = list_marker($next);
            $continues = $next_marker !== null
                ? $next_marker['ordered'] === $ordered || $next_marker['indent'] > $base + 1
                : strlen($next) - strlen(ltrim($next)) > $base + 1;
            if (!$continues) {
                break;
            }
            $children[] = '';
            continue;
        }
        $indent = strlen($line) - strlen(ltrim($line));
        if ($indent > $base + 1) {
            $children[] = $line;
            continue;
        }
        // A lazy continuation line of the item's own text.
        if ($marker === null && $children === [] && $current !== null) {
            $current .= ' ' . inline(trim($line));
            continue;
        }
        break;
    }
    $close();
    $consumed = max(1, $i);

    $attributes = $ordered ? ['ordered' => true] : [];
    if ($ordered && $first !== null && $first['number'] !== 1) {
        $attributes['start'] = $first['number'];
    }

    return block('core/list', $attributes, $items);
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function dedent(array $lines): array
{
    $min = null;
    foreach ($lines as $line) {
        if (trim($line) !== '') {
            $indent = strlen($line) - strlen(ltrim($line));
            $min = $min === null ? $indent : min($min, $indent);
        }
    }

    return array_map(static fn(string $line): string => (string) substr($line, (int) $min), $lines);
}

function is_table_rule(string $line): bool
{
    return str_contains($line, '-')
        && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $line) === 1;
}

/** @return list<string> */
function table_cells(string $line): array
{
    $line = trim($line);
    $line = preg_replace('/^\||\|$/', '', $line) ?? $line;
    $cells = preg_split('/(?<!\\\\)\|/', $line) ?: [];

    return array_map(static fn(string $cell): string => inline(str_replace('\\|', '|', trim($cell))), $cells);
}

/**
 * @param list<string>       $head
 * @param list<list<string>> $rows
 * @return array<string, mixed>
 */
function table_block(array $head, array $rows): array
{
    $width = count($head);
    $row = static function (array $cells, string $tag) use ($width): array {
        $cells = array_slice(array_pad($cells, $width, ''), 0, $width);
        return ['cells' => array_map(static fn(string $c): array => ['content' => $c, 'tag' => $tag], $cells)];
    };

    return block('core/table', [
        'head' => [$row($head, 'th')],
        'body' => array_map(static fn(array $cells): array => $row($cells, 'td'), $rows),
    ]);
}

/** @param list<string> $lines */
function paragraph_html(array $lines): string
{
    $html = '';
    $last = count($lines) - 1;
    foreach ($lines as $index => $line) {
        $hard = preg_match('/( {2,}|\\\\)$/', $line) === 1;
        $html .= inline(trim(rtrim($line, '\\')));
        if ($index < $last) {
            $html .= $hard ? '<br>' : ' ';
        }
    }

    return $html;
}

function escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Relative URLs, fragments and http(s)/mailto/tel only. Anything else (javascript:, data:,
 * vbscript:) stays as plain text.
 */
function safe_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x20<>"]/', $url) === 1) {
        return false;
    }
    if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $scheme) === 1) {
        return in_array(strtolower($scheme[1]), ['http', 'https', 'mailto', 'tel'], true);
    }

    return true;
}

/**
 * Inline Markdown to HTML. The text is escaped first and only the tags made here are added,
 * so nothing the Markdown carries can become markup.
 */
function inline(string $text): string
{
    $tokens = [];
    $stash = static function (string $html) use (&$tokens): string {
        $tokens[] = $html;
        return "\x1A" . (count($tokens) - 1) . "\x1A";
    };

    // Code spans first: nothing inside them is Markdown.
    $text = preg_replace_callback('/(`+)(.+?)\1/s', static fn(array $m): string => $stash('<code>' . escape(trim($m[2])) . '</code>'), $text) ?? $text;
    // Backslash escapes.
    $text = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!|~>])/', static fn(array $m): string => $stash(escape($m[1])), $text) ?? $text;
    $text = preg_replace_callback(
        '/!\[([^\]]*)\]\(\s*(\S+?)(?:\s+"([^"]*)")?\s*\)/',
        static fn(array $m): string => safe_url($m[2])
            ? $stash('<img src="' . escape($m[2]) . '" alt="' . escape($m[1]) . '"/>')
            : $m[0],
        $text,
    ) ?? $text;
    $text = preg_replace_callback(
        '/\[([^\]]+)\]\(\s*(\S+?)(?:\s+"([^"]*)")?\s*\)/',
        static fn(array $m): string => safe_url($m[2])
            ? $stash('<a href="' . escape($m[2]) . '">') . $m[1] . $stash('</a>')
            : $m[0],
        $text,
    ) ?? $text;
    $text = preg_replace_callback(
        '/<(https?:\/\/[^\s<>"]+)>/',
        static fn(array $m): string => $stash('<a href="' . escape($m[1]) . '">' . escape($m[1]) . '</a>'),
        $text,
    ) ?? $text;

    $text = escape($text);
    $text = preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/s', '<strong>$2</strong>', $text) ?? $text;
    $text = preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?!\*)/s', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/s', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<s>$1</s>', $text) ?? $text;

    // Restore stashed HTML. Tokens can nest (a link around a code span), so repeat.
    for ($pass = 0; $pass < 4 && str_contains($text, "\x1A"); ++$pass) {
        $text = preg_replace_callback('/\x1A(\d+)\x1A/', static fn(array $m): string => $tokens[(int) $m[1]] ?? '', $text) ?? $text;
    }

    return $text;
}
