<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Plain-text extraction from a PDF, in pure PHP, for wppilot/read-pdf.
 *
 * No shell, no Composer dependency. It reads the objects (including those packed in PDF 1.5
 * object streams), walks the page tree in order, decodes each page's content streams and
 * collects the strings shown by the text operators, mapping them through the font's
 * ToUnicode table when it has one and through WinAnsi when it does not.
 *
 * What it cannot read, and says so in its notes:
 * - encrypted PDFs (any password, including "owner only" ones that open without asking);
 * - scanned pages and text drawn as images or vector outlines: no OCR is done;
 * - text inside form XObjects, annotations and form fields;
 * - streams compressed with anything but FlateDecode, ASCIIHexDecode or ASCII85Decode;
 * - fonts without a ToUnicode table whose codes are not WinAnsi (some symbol, CID and
 *   Type 3 fonts), which come out as the wrong characters.
 * Reading order is the order the text was drawn in, which is usually but not always the
 * order a person reads it; columns and tables can interleave.
 */

const WPPILOT_PDF_MAX_BYTES = 10485760;          // 10 MB file
const WPPILOT_PDF_MAX_PAGES = 200;               // hard page cap, whatever the caller asks
const WPPILOT_PDF_MAX_DECODED_BYTES = 67108864;  // 64 MB of decompressed streams in total
const WPPILOT_PDF_MAX_TEXT_CHARS = 200000;       // text returned

/**
 * Parser state for one document. Arrays rather than a class so the file stays a set of
 * functions like the rest of the plugin.
 *
 * @return array{objects: array<int, mixed>, streams: array<int, string>, trailer: array<string, mixed>, budget: int, notes: array<string, string>}
 */
function wppilot_pdf_open(string $bytes): array
{
    $doc = ['objects' => [], 'streams' => [], 'trailer' => [], 'budget' => WPPILOT_PDF_MAX_DECODED_BYTES, 'notes' => []];

    preg_match_all('/(?<![0-9])(\d{1,10})\s+(\d{1,5})\s+obj\b/', $bytes, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as $index => $match) {
        $number = (int) $matches[1][$index][0];
        $pos = $match[1] + strlen($match[0]);
        $value = wppilot_pdf_parse_value($bytes, $pos);
        $doc['objects'][$number] = $value;
        wppilot_pdf_skip_space($bytes, $pos);
        if (substr($bytes, $pos, 6) === 'stream') {
            $pos += 6;
            if (($bytes[$pos] ?? '') === "\r") {
                ++$pos;
            }
            if (($bytes[$pos] ?? '') === "\n") {
                ++$pos;
            }
            $length = is_array($value) ? ($value['d']['Length'] ?? null) : null;
            $end = -1;
            if (is_int($length) && $length >= 0 && strpos(substr($bytes, $pos + $length, 32), 'endstream') !== false) {
                $end = $pos + $length;
            } else {
                $found = strpos($bytes, 'endstream', $pos);
                $end = $found === false ? strlen($bytes) : $found;
            }
            $doc['streams'][$number] = rtrim(substr($bytes, $pos, $end - $pos), "\r\n");
        }
        if (is_array($value) && isset($value['d']) && (($value['d']['Type'] ?? null) === '/XRef')) {
            $doc['trailer'] = array_merge($doc['trailer'], $value['d']);
        }
    }

    // Classic trailers. The last one in the file is the newest, so later ones win.
    $offset = 0;
    while (($found = strpos($bytes, 'trailer', $offset)) !== false) {
        $pos = $found + 7;
        $trailer = wppilot_pdf_parse_value($bytes, $pos);
        if (is_array($trailer) && isset($trailer['d'])) {
            $doc['trailer'] = array_merge($doc['trailer'], $trailer['d']);
        }
        $offset = $found + 7;
    }

    // Objects packed into object streams (PDF 1.5+). An object defined directly wins over one
    // in a stream only if it came later, which the order here does not know; direct copies
    // are rare outside incremental updates, so they are kept.
    foreach ($doc['objects'] as $number => $value) {
        if (!is_array($value) || ($value['d']['Type'] ?? null) !== '/ObjStm') {
            continue;
        }
        $data = wppilot_pdf_stream($doc, $number);
        if ($data === null) {
            continue;
        }
        $count = (int) ($value['d']['N'] ?? 0);
        $first = (int) ($value['d']['First'] ?? 0);
        $header = preg_split('/\s+/', trim(substr($data, 0, $first))) ?: [];
        for ($i = 0; $i + 1 < count($header) && $i / 2 < $count; $i += 2) {
            $inner = (int) $header[$i];
            if (!array_key_exists($inner, $doc['objects'])) {
                $pos = $first + (int) $header[$i + 1];
                $doc['objects'][$inner] = wppilot_pdf_parse_value($data, $pos);
            }
        }
    }

    return $doc;
}

function wppilot_pdf_is_encrypted(array $doc): bool
{
    return isset($doc['trailer']['Encrypt']);
}

function wppilot_pdf_skip_space(string $s, int &$pos): void
{
    $length = strlen($s);
    while ($pos < $length) {
        $c = $s[$pos];
        if ($c === '%') {
            while ($pos < $length && $s[$pos] !== "\n" && $s[$pos] !== "\r") {
                ++$pos;
            }
            continue;
        }
        if (strpos(" \t\r\n\f\0", $c) === false) {
            return;
        }
        ++$pos;
    }
}

/**
 * Parse one PDF value at $pos. Dictionaries come back as ['d' => [...]], arrays as
 * ['a' => [...]], references as ['r' => n], strings as ['s' => bytes], names as '/Name',
 * numbers as int|float, operators and keywords as ['k' => word].
 */
function wppilot_pdf_parse_value(string $s, int &$pos, int $depth = 0): mixed
{
    wppilot_pdf_skip_space($s, $pos);
    $length = strlen($s);
    if ($pos >= $length || $depth > 64) {
        return null;
    }
    $c = $s[$pos];
    if ($c === '<' && ($s[$pos + 1] ?? '') === '<') {
        $pos += 2;
        $dict = [];
        while (true) {
            wppilot_pdf_skip_space($s, $pos);
            if ($pos >= $length) {
                break;
            }
            if ($s[$pos] === '>' && ($s[$pos + 1] ?? '') === '>') {
                $pos += 2;
                break;
            }
            $key = wppilot_pdf_parse_value($s, $pos, $depth + 1);
            if (!is_string($key)) {
                // Not a name where a key belongs: the dictionary is damaged. Stop here.
                break;
            }
            $dict[substr($key, 1)] = wppilot_pdf_parse_value($s, $pos, $depth + 1);
        }
        return ['d' => $dict];
    }
    if ($c === '<') {
        $end = strpos($s, '>', $pos);
        $end = $end === false ? $length : $end;
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $pos + 1, $end - $pos - 1)) ?? '';
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        $pos = $end + 1;
        return ['s' => (string) hex2bin($hex), 'hex' => true];
    }
    if ($c === '(') {
        return ['s' => wppilot_pdf_literal_string($s, $pos)];
    }
    if ($c === '[') {
        ++$pos;
        $items = [];
        while (true) {
            wppilot_pdf_skip_space($s, $pos);
            if ($pos >= $length) {
                break;
            }
            if ($s[$pos] === ']') {
                ++$pos;
                break;
            }
            $before = $pos;
            $items[] = wppilot_pdf_parse_value($s, $pos, $depth + 1);
            if ($pos === $before) {
                ++$pos;
            }
        }
        return ['a' => $items];
    }
    if ($c === '/') {
        $start = $pos++;
        while ($pos < $length && strpos(" \t\r\n\f\0()<>[]{}/%", $s[$pos]) === false) {
            ++$pos;
        }
        $name = substr($s, $start, $pos - $start);
        return preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $name) ?? $name;
    }
    if ($c === ')' || $c === '>' || $c === ']' || $c === '{' || $c === '}') {
        ++$pos;
        return ['k' => $c];
    }
    $start = $pos;
    while ($pos < $length && strpos(" \t\r\n\f\0()<>[]{}/%", $s[$pos]) === false) {
        ++$pos;
    }
    $word = substr($s, $start, $pos - $start);
    if (preg_match('/^[+-]?\d+$/', $word) === 1) {
        // "12 0 R" is a reference; look ahead without consuming anything else.
        $look = $pos;
        if (preg_match('/\G\s+(\d+)\s+R(?![A-Za-z0-9])/', $s, $m, 0, $look) === 1) {
            $pos = $look + strlen($m[0]);
            return ['r' => (int) $word];
        }
        return (int) $word;
    }
    if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)$/', $word) === 1) {
        return (float) $word;
    }
    if ($word === 'true' || $word === 'false') {
        return $word === 'true';
    }
    if ($word === 'null') {
        return null;
    }
    return ['k' => $word];
}

function wppilot_pdf_literal_string(string $s, int &$pos): string
{
    $length = strlen($s);
    ++$pos;
    $out = '';
    $depth = 1;
    while ($pos < $length) {
        $c = $s[$pos++];
        if ($c === '\\') {
            $n = $s[$pos++] ?? '';
            $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
            if (isset($map[$n])) {
                $out .= $map[$n];
            } elseif ($n >= '0' && $n <= '7') {
                $octal = $n;
                for ($i = 0; $i < 2 && ($s[$pos] ?? '') >= '0' && ($s[$pos] ?? '') <= '7'; ++$i) {
                    $octal .= $s[$pos++];
                }
                $out .= chr(octdec($octal) & 0xFF);
            } elseif ($n === "\r") {
                if (($s[$pos] ?? '') === "\n") {
                    ++$pos;
                }
            } elseif ($n !== "\n") {
                $out .= $n;
            }
            continue;
        }
        if ($c === '(') {
            ++$depth;
        } elseif ($c === ')') {
            if (--$depth === 0) {
                break;
            }
        }
        $out .= $c;
    }
    return $out;
}

/** Follow a reference to the object it names; anything else comes back unchanged. */
function wppilot_pdf_resolve(array $doc, mixed $value, int $hops = 0): mixed
{
    while (is_array($value) && isset($value['r']) && $hops++ < 32) {
        $value = $doc['objects'][$value['r']] ?? null;
    }
    return $value;
}

/** @return array<string, mixed> */
function wppilot_pdf_dict(array $doc, mixed $value): array
{
    $value = wppilot_pdf_resolve($doc, $value);
    return is_array($value) && isset($value['d']) && is_array($value['d']) ? $value['d'] : [];
}

/**
 * A stream's decoded bytes, or null when a filter is not supported or the decoded-size budget
 * is spent (a small file that inflates to gigabytes is refused, not decoded).
 */
function wppilot_pdf_stream(array &$doc, int $number): ?string
{
    if (!isset($doc['streams'][$number])) {
        return null;
    }
    $data = $doc['streams'][$number];
    $dict = wppilot_pdf_dict($doc, $doc['objects'][$number] ?? null);
    $filter = wppilot_pdf_resolve($doc, $dict['Filter'] ?? null);
    $filters = is_array($filter) && isset($filter['a']) ? $filter['a'] : ($filter === null ? [] : [$filter]);
    foreach ($filters as $name) {
        if ($doc['budget'] <= 0) {
            $doc['notes']['budget'] = 'Stopped decoding: the PDF expands to more than 64 MB of content.';
            return null;
        }
        if ($name === '/FlateDecode' || $name === '/Fl') {
            // zlib_decode, not the gz* pair: host malware scanners quarantine any file that calls
            // those by name, and a quarantined plugin file takes the whole site down. Same limits.
            $decoded = @zlib_decode($data, $doc['budget']);
            if ($decoded === false) {
                // A damaged checksum: skip the two-byte zlib header and read the raw deflate data.
                $decoded = @zlib_decode(substr($data, 2), $doc['budget']);
            }
            if ($decoded === false) {
                $doc['notes']['flate'] = 'Some compressed streams were damaged or too large and were skipped.';
                return null;
            }
            $data = $decoded;
        } elseif ($name === '/ASCIIHexDecode' || $name === '/AHx') {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', explode('>', $data)[0]) ?? '';
            $data = (string) hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex);
        } elseif ($name === '/ASCII85Decode' || $name === '/A85') {
            $data = wppilot_pdf_ascii85($data);
        } else {
            $doc['notes']['filter'] = sprintf('Some streams use %s compression, which this reader does not decode; their text is missing.', ltrim((string) (is_string($name) ? $name : 'an unknown'), '/'));
            return null;
        }
        $doc['budget'] -= strlen($data);
    }
    return $data;
}

function wppilot_pdf_ascii85(string $data): string
{
    $data = preg_replace('/\s+/', '', $data) ?? '';
    if (str_starts_with($data, '<~')) {
        $data = substr($data, 2);
    }
    $end = strpos($data, '~>');
    if ($end !== false) {
        $data = substr($data, 0, $end);
    }
    $out = '';
    $group = [];
    $length = strlen($data);
    for ($i = 0; $i < $length; ++$i) {
        $c = $data[$i];
        if ($c === 'z' && $group === []) {
            $out .= "\0\0\0\0";
            continue;
        }
        $group[] = ord($c) - 33;
        if (count($group) === 5) {
            $value = 0;
            foreach ($group as $digit) {
                $value = $value * 85 + $digit;
            }
            $out .= pack('N', $value & 0xFFFFFFFF);
            $group = [];
        }
    }
    if ($group !== []) {
        $pad = 5 - count($group);
        $group = array_merge($group, array_fill(0, $pad, 84));
        $value = 0;
        foreach ($group as $digit) {
            $value = $value * 85 + $digit;
        }
        $out .= substr(pack('N', $value & 0xFFFFFFFF), 0, 4 - $pad);
    }
    return $out;
}

/**
 * Page dictionaries in reading order, each with its inherited Resources resolved.
 *
 * @return list<array{page: array<string, mixed>, resources: array<string, mixed>}>
 */
function wppilot_pdf_pages(array $doc, int $limit): array
{
    $root = wppilot_pdf_dict($doc, $doc['trailer']['Root'] ?? null);
    $pages = [];
    $seen = [];
    $walk = static function (mixed $node, array $resources) use (&$walk, &$pages, &$seen, $doc, $limit): void {
        if (count($pages) >= $limit) {
            return;
        }
        if (is_array($node) && isset($node['r'])) {
            if (isset($seen[$node['r']])) {
                return;
            }
            $seen[$node['r']] = true;
        }
        $dict = wppilot_pdf_dict($doc, $node);
        if (isset($dict['Resources'])) {
            $resources = wppilot_pdf_dict($doc, $dict['Resources']);
        }
        $kids = wppilot_pdf_resolve($doc, $dict['Kids'] ?? null);
        if (($dict['Type'] ?? null) === '/Pages' || (is_array($kids) && isset($kids['a']))) {
            foreach (is_array($kids) ? ($kids['a'] ?? []) : [] as $kid) {
                $walk($kid, $resources);
            }
            return;
        }
        if (($dict['Type'] ?? null) === '/Page' || isset($dict['Contents'])) {
            $pages[] = ['page' => $dict, 'resources' => $resources];
        }
    };
    if (isset($root['Pages'])) {
        $walk($root['Pages'], []);
    }
    if ($pages !== []) {
        return $pages;
    }
    // No usable page tree (a damaged or truncated file): fall back to every page object in
    // object-number order.
    $numbers = array_keys($doc['objects']);
    sort($numbers);
    foreach ($numbers as $number) {
        $dict = wppilot_pdf_dict($doc, $doc['objects'][$number]);
        if (($dict['Type'] ?? null) === '/Page') {
            $pages[] = ['page' => $dict, 'resources' => wppilot_pdf_dict($doc, $dict['Resources'] ?? null)];
            if (count($pages) >= $limit) {
                break;
            }
        }
    }
    return $pages;
}

/** How many pages the page tree says the document has, or 0 when it does not say. */
function wppilot_pdf_page_count(array $doc): int
{
    $root = wppilot_pdf_dict($doc, $doc['trailer']['Root'] ?? null);
    $pages = wppilot_pdf_dict($doc, $root['Pages'] ?? null);
    return is_int($pages['Count'] ?? null) ? max(0, $pages['Count']) : 0;
}

/**
 * A font's ToUnicode table: code bytes => UTF-8, plus the code width in bytes.
 *
 * @return array{width: int, map: array<string, string>}|null
 */
function wppilot_pdf_font_cmap(array &$doc, mixed $font): ?array
{
    $dict = wppilot_pdf_dict($doc, $font);
    $ref = $dict['ToUnicode'] ?? null;
    if (!is_array($ref) || !isset($ref['r'])) {
        // Type0 fonts with Identity-H and no ToUnicode still use two-byte codes; without a
        // table the codes are glyph ids and cannot be turned into text.
        if (($dict['Subtype'] ?? null) !== '/Type0') {
            return null;
        }
        $doc['notes']['glyphs'] = 'Some text uses fonts with no character map, so it cannot be turned back into letters and is missing.';
        return ['width' => 2, 'map' => []];
    }
    $cmap = wppilot_pdf_stream($doc, $ref['r']);
    if ($cmap === null) {
        return null;
    }
    $width = 1;
    if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $m) === 1) {
        $width = max(1, intdiv(strlen($m[1]), 2));
    } elseif (($dict['Subtype'] ?? null) === '/Type0') {
        $width = 2;
    }
    $map = [];
    preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks);
    foreach ($blocks[1] as $block) {
        preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $map[(string) hex2bin(wppilot_pdf_even_hex($pair[1]))] = wppilot_pdf_utf16_hex($pair[2]);
        }
    }
    preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks);
    foreach ($blocks[1] as $block) {
        preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $block, $ranges, PREG_SET_ORDER);
        foreach ($ranges as $range) {
            $low = (int) hexdec($range[1]);
            $high = (int) hexdec($range[2]);
            $digits = strlen($range[1]);
            if ($high < $low || $high - $low > 65535) {
                continue;
            }
            if ($range[3][0] === '[') {
                preg_match_all('/<([0-9A-Fa-f]*)>/', $range[3], $targets);
                foreach ($targets[1] as $offset => $target) {
                    if ($low + $offset > $high) {
                        break;
                    }
                    $map[(string) hex2bin(str_pad(dechex($low + $offset), $digits, '0', STR_PAD_LEFT))] = wppilot_pdf_utf16_hex($target);
                }
                continue;
            }
            $start = trim($range[3], '<>');
            $base = (int) hexdec(substr($start, -4) ?: '0');
            $prefix = strlen($start) > 4 ? substr($start, 0, -4) : '';
            for ($code = $low; $code <= $high; ++$code) {
                $key = (string) hex2bin(str_pad(dechex($code), $digits, '0', STR_PAD_LEFT));
                $map[$key] = wppilot_pdf_utf16_hex($prefix . str_pad(dechex($base + $code - $low), 4, '0', STR_PAD_LEFT));
            }
        }
    }
    return ['width' => $width, 'map' => $map];
}

function wppilot_pdf_even_hex(string $hex): string
{
    return strlen($hex) % 2 === 1 ? '0' . $hex : $hex;
}

function wppilot_pdf_utf16_hex(string $hex): string
{
    $bytes = (string) hex2bin(wppilot_pdf_even_hex($hex));
    if ($bytes === '') {
        return '';
    }
    if (function_exists('mb_convert_encoding')) {
        return (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
    }
    $out = '';
    foreach (str_split($bytes, 2) as $unit) {
        $out .= wppilot_pdf_utf8_codepoint(strlen($unit) === 2 ? (ord($unit[0]) << 8) | ord($unit[1]) : ord($unit));
    }
    return $out;
}

function wppilot_pdf_utf8_codepoint(int $cp): string
{
    if ($cp < 0x80) {
        return chr($cp);
    }
    if ($cp < 0x800) {
        return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
    }
    return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
}

/** WinAnsi (close to Windows-1252) bytes to UTF-8, for simple fonts without a ToUnicode table. */
function wppilot_pdf_winansi(string $bytes): string
{
    static $high = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020,
        0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
        0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022,
        0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
        0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
    ];
    $out = '';
    $length = strlen($bytes);
    for ($i = 0; $i < $length; ++$i) {
        $byte = ord($bytes[$i]);
        if ($byte < 0x20 && $byte !== 0x09) {
            continue;
        }
        $out .= wppilot_pdf_utf8_codepoint($high[$byte] ?? $byte);
    }
    return $out;
}

/**
 * What the text operators need from a font: how to turn its codes into text, and how wide each
 * code is (in thousandths of the font size), so the gap before the next string can be measured.
 *
 * @return array{cmap: array{width: int, map: array<string, string>}|null, bytes: int, widths: array<int, float>, missing: float}
 */
function wppilot_pdf_font(array &$doc, mixed $font): array
{
    $dict = wppilot_pdf_dict($doc, $font);
    $cmap = wppilot_pdf_font_cmap($doc, $font);
    $widths = [];
    if (($dict['Subtype'] ?? null) === '/Type0') {
        $descendants = wppilot_pdf_resolve($doc, $dict['DescendantFonts'] ?? null);
        $cid = wppilot_pdf_dict($doc, is_array($descendants) ? ($descendants['a'][0] ?? null) : null);
        $w = wppilot_pdf_resolve($doc, $cid['W'] ?? null);
        $items = is_array($w) ? ($w['a'] ?? []) : [];
        $count = count($items);
        for ($i = 0; $i < $count && count($widths) < 65536;) {
            $first = $items[$i] ?? null;
            $next = wppilot_pdf_resolve($doc, $items[$i + 1] ?? null);
            if (is_int($first) && is_array($next) && isset($next['a'])) {
                foreach ($next['a'] as $offset => $value) {
                    if (is_numeric($value)) {
                        $widths[$first + $offset] = (float) $value;
                    }
                }
                $i += 2;
            } elseif (is_int($first) && is_int($next) && is_numeric($items[$i + 2] ?? null) && $next >= $first && $next - $first < 65536) {
                for ($code = $first; $code <= $next; ++$code) {
                    $widths[$code] = (float) $items[$i + 2];
                }
                $i += 3;
            } else {
                break;
            }
        }
        $missing = is_numeric($cid['DW'] ?? null) ? (float) $cid['DW'] : 1000.0;
        return ['cmap' => $cmap, 'bytes' => $cmap['width'] ?? 2, 'widths' => $widths, 'missing' => $missing];
    }
    $first = is_int($dict['FirstChar'] ?? null) ? $dict['FirstChar'] : 0;
    $list = wppilot_pdf_resolve($doc, $dict['Widths'] ?? null);
    foreach (is_array($list) ? ($list['a'] ?? []) : [] as $offset => $value) {
        $value = wppilot_pdf_resolve($doc, $value);
        if (is_numeric($value)) {
            $widths[$first + $offset] = (float) $value;
        }
    }
    $descriptor = wppilot_pdf_dict($doc, $dict['FontDescriptor'] ?? null);
    // The standard 14 fonts carry no widths; 500 is close to their average glyph.
    $missing = is_numeric($descriptor['MissingWidth'] ?? null) && (float) $descriptor['MissingWidth'] > 0 ? (float) $descriptor['MissingWidth'] : 500.0;
    return ['cmap' => $cmap, 'bytes' => 1, 'widths' => $widths, 'missing' => $missing];
}

/**
 * Decode shown bytes to UTF-8 and measure them.
 *
 * @param array{cmap: array{width: int, map: array<string, string>}|null, bytes: int, widths: array<int, float>, missing: float} $font
 * @return array{0: string, 1: float} text and advance in thousandths of the font size
 */
function wppilot_pdf_decode_shown(string $bytes, array $font): array
{
    $text = '';
    $advance = 0.0;
    foreach (str_split($bytes, max(1, $font['bytes'])) ?: [] as $code) {
        $number = $font['bytes'] === 1 ? ord($code) : (int) hexdec(bin2hex($code));
        $advance += $font['widths'][$number] ?? $font['missing'];
        if ($font['cmap'] !== null && isset($font['cmap']['map'][$code])) {
            $text .= $font['cmap']['map'][$code];
        } elseif ($font['bytes'] === 1) {
            $text .= wppilot_pdf_winansi($code);
        }
    }
    return [$text, $advance];
}

/**
 * The text a content stream shows.
 *
 * Positions are tracked well enough to tell a new line (the baseline moved by more than half
 * the font size) from a word gap (the next string starts more than 0.15 em away from where the
 * last one ended) from a glyph placed right after the previous one, which word processors do
 * one letter at a time. Graphics-state transforms (cm) are not followed.
 *
 * @param array<string, mixed> $resources
 */
function wppilot_pdf_content_text(array &$doc, string $content, array $resources): string
{
    $fonts = wppilot_pdf_dict($doc, $resources['Font'] ?? null);
    $cache = [];
    $font = ['cmap' => null, 'bytes' => 1, 'widths' => [], 'missing' => 500.0];
    $size = 0.0;
    $scale = 1.0;
    $leading = 0.0;
    $line_x = $line_y = $x = $y = 0.0;
    $end_x = $end_y = null;
    $text = '';
    $operands = [];
    $pos = 0;
    $length = strlen($content);

    $show = static function (string $bytes) use (&$font, &$size, &$scale, &$x, &$y, &$end_x, &$end_y, &$text): void {
        [$shown, $advance] = wppilot_pdf_decode_shown($bytes, $font);
        $em = abs($size * $scale) ?: 1.0;
        if ($end_x !== null && $text !== '' && !str_ends_with($text, "\n")) {
            if (abs($y - $end_y) > $em * 0.5) {
                $text .= "\n";
            } elseif (abs($x - $end_x) > $em * 0.15 && !str_ends_with($text, ' ') && !str_starts_with($shown, ' ')) {
                $text .= ' ';
            }
        }
        $text .= $shown;
        $x += $advance / 1000 * $em;
        $end_x = $x;
        $end_y = $y;
    };
    $next_line = static function () use (&$leading, &$scale, &$line_x, &$line_y, &$x, &$y): void {
        $line_y -= $leading * $scale;
        $x = $line_x;
        $y = $line_y;
    };

    while ($pos < $length) {
        $before = $pos;
        $value = wppilot_pdf_parse_value($content, $pos);
        if ($pos === $before) {
            ++$pos;
            continue;
        }
        if (!is_array($value) || !isset($value['k'])) {
            $operands[] = $value;
            continue;
        }
        $number = static fn (int $i): float => is_numeric($operands[$i] ?? null) ? (float) $operands[$i] : 0.0;
        switch ($value['k']) {
            case 'BI':
                // Inline image: binary data up to EI, which must not be read as operators.
                $pos = preg_match('/\sEI(?=\s|$)/', $content, $m, PREG_OFFSET_CAPTURE, $pos) === 1 ? $m[0][1] + 3 : $length;
                break;
            case 'BT':
                $scale = 1.0;
                $line_x = $line_y = $x = $y = 0.0;
                break;
            case 'Tf':
                $name = $operands[0] ?? null;
                if (is_string($name)) {
                    $key = substr($name, 1);
                    $cache[$key] ??= wppilot_pdf_font($doc, $fonts[$key] ?? null);
                    $font = $cache[$key];
                }
                $size = $number(1);
                break;
            case 'TL':
                $leading = $number(0);
                break;
            case 'Tm':
                $scale = hypot($number(0), $number(1)) ?: 1.0;
                $line_x = $x = $number(4);
                $line_y = $y = $number(5);
                break;
            case 'TD':
            case 'Td':
                if ($value['k'] === 'TD') {
                    $leading = -$number(1);
                }
                $line_x += $number(0) * $scale;
                $line_y += $number(1) * $scale;
                $x = $line_x;
                $y = $line_y;
                break;
            case 'T*':
                $next_line();
                break;
            case "'":
            case '"':
                $next_line();
                $shown = end($operands);
                if (is_array($shown) && isset($shown['s'])) {
                    $show($shown['s']);
                }
                break;
            case 'Tj':
                $shown = $operands[0] ?? null;
                if (is_array($shown) && isset($shown['s'])) {
                    $show($shown['s']);
                }
                break;
            case 'TJ':
                $items = $operands[0] ?? null;
                foreach (is_array($items) ? ($items['a'] ?? []) : [] as $item) {
                    if (is_array($item) && isset($item['s'])) {
                        $show($item['s']);
                    } elseif (is_numeric($item)) {
                        // A kern, in thousandths of the font size; negative moves right.
                        $x -= (float) $item / 1000 * (abs($size * $scale) ?: 1.0);
                    }
                }
                break;
        }
        $operands = [];
    }
    return $text;
}

/**
 * Extract the text of up to $max_pages pages, starting at page $start_page (1-based).
 *
 * @return array{page_count: int, pages: list<array{page: int, text: string}>, truncated: bool, notes: list<string>}|WP_Error
 */
function wppilot_pdf_extract_text(string $bytes, int $start_page = 1, int $max_pages = 50): array|WP_Error
{
    if (strpos(substr($bytes, 0, 1024), '%PDF-') === false) {
        return new WP_Error('wppilot_pdf_not_pdf', __('The file is not a PDF: it has no %PDF- header.', domain: 'wppilot'));
    }
    $doc = wppilot_pdf_open($bytes);
    if (wppilot_pdf_is_encrypted($doc)) {
        return new WP_Error('wppilot_pdf_encrypted', __(
            'The PDF is encrypted, so its text cannot be read here. That includes PDFs that open without a password but restrict copying or printing. Save an unencrypted copy and upload that.',
            domain: 'wppilot',
        ));
    }
    $start_page = max(1, $start_page);
    $max_pages = max(1, min($max_pages, WPPILOT_PDF_MAX_PAGES));
    $all = wppilot_pdf_pages($doc, $start_page - 1 + $max_pages);
    $count = max(wppilot_pdf_page_count($doc), count($all));
    $page_list = array_slice($all, $start_page - 1, $max_pages, preserve_keys: true);
    $pages = [];
    $total = 0;
    $truncated = $count > $start_page - 1 + count($page_list);
    $empty = 0;
    foreach ($page_list as $index => $entry) {
        $contents = wppilot_pdf_resolve($doc, $entry['page']['Contents'] ?? null);
        $refs = [];
        if (is_array($entry['page']['Contents'] ?? null) && isset($entry['page']['Contents']['r']) && !isset($contents['a'])) {
            $refs[] = $entry['page']['Contents']['r'];
        } elseif (is_array($contents) && isset($contents['a'])) {
            foreach ($contents['a'] as $item) {
                if (is_array($item) && isset($item['r'])) {
                    $refs[] = $item['r'];
                }
            }
        }
        $stream = '';
        foreach ($refs as $ref) {
            $stream .= (wppilot_pdf_stream($doc, $ref) ?? '') . "\n";
        }
        $text = trim((string) preg_replace("/[ \t]+\n/", "\n", wppilot_pdf_content_text($doc, $stream, $entry['resources'])));
        if ($text === '') {
            ++$empty;
        }
        if ($total + strlen($text) > WPPILOT_PDF_MAX_TEXT_CHARS) {
            $text = function_exists('mb_strcut') ? mb_strcut($text, 0, max(0, WPPILOT_PDF_MAX_TEXT_CHARS - $total), 'UTF-8') : substr($text, 0, max(0, WPPILOT_PDF_MAX_TEXT_CHARS - $total));
            $pages[] = ['page' => $index + 1, 'text' => $text];
            $truncated = true;
            $doc['notes']['chars'] = sprintf(
                'Stopped at %1$d characters of text, part way through page %2$d. Call again with start_page %2$d to read on.',
                WPPILOT_PDF_MAX_TEXT_CHARS,
                $index + 1,
            );
            break;
        }
        $total += strlen($text);
        $pages[] = ['page' => $index + 1, 'text' => $text];
    }
    if ($empty > 0) {
        $doc['notes']['empty'] = sprintf(
            '%d of %d pages read had no extractable text. They are probably scanned images or text drawn as shapes; no OCR is done.',
            $empty,
            count($pages),
        );
    }
    if ($pages === [] && $count > 0) {
        $doc['notes']['range'] = sprintf('The PDF has %1$d pages, so there is no page %2$d.', $count, $start_page);
    } elseif ($truncated && !isset($doc['notes']['chars'])) {
        $last = $start_page - 1 + count($pages);
        $doc['notes']['pages'] = sprintf('Read pages %1$d to %2$d of %3$d. Call again with start_page %4$d to read on.', $start_page, $last, $count, $last + 1);
    }
    return ['page_count' => $count, 'pages' => $pages, 'truncated' => $truncated, 'notes' => array_values($doc['notes'])];
}
