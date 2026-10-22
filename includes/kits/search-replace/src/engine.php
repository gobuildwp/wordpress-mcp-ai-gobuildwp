<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SearchReplace;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The matching and rewriting half of search-replace: one string, one serialized array, one JSON
 * document at a time. Nothing here reads or writes the database, so preview and apply run the
 * exact same replacement and a plan's diff is what apply will write.
 */

/** Post columns search-replace may touch. Never guid: it is an identifier, not a URL to fix. */
const FIELDS = ['post_title', 'post_content', 'post_excerpt'];

/**
 * pcre.backtrack_limit while a pattern runs. PHP's default is a million; a catastrophic pattern
 * over a 500 KB builder document burns seconds per post before it gives up, and a scan runs it
 * over thousands of posts. A value that hits the limit is skipped and reported, never half-written.
 */
const BACKTRACK_LIMIT = 100_000;

/** Before/after snippets kept per field or meta key; the counts are always complete. */
const SAMPLES_PER_TARGET = 3;

/** Bytes of context either side of a match in a snippet. */
const CONTEXT_BYTES = 60;

/** Larger values are skipped: one would dominate the snapshot budget and the diff. */
const MAX_VALUE_BYTES = 4_194_304;

/** Characters tried, in order, as the delimiter around a caller's regex. */
const DELIMITERS = ['~', '#', '%', '!', '@', ';', '`', '|'];

/**
 * Compile the caller's search into a pattern, once, and refuse one that cannot run safely.
 *
 * A literal search becomes a quoted pattern too, so both modes share one replace path and one
 * set of counts. Case-sensitive literal patterns run without the `u` modifier: they are byte
 * comparisons, and so still work on a value that is not valid UTF-8.
 *
 * @param array<string, mixed> $input `search`, `replace`, `regex`, `case_sensitive`.
 * @return array{search: string, replace: string, regex: bool, case_sensitive: bool, pattern: string, unicode: bool}|WP_Error
 */
function matcher(array $input): array|WP_Error
{
    $search = is_string($input['search'] ?? null) ? $input['search'] : '';
    $replace = is_string($input['replace'] ?? null) ? $input['replace'] : '';
    $regex = ($input['regex'] ?? false) === true;
    $case_sensitive = ($input['case_sensitive'] ?? true) !== false;
    if ($search === '') {
        return new WP_Error('kit_sr_empty_search', 'search must not be empty.', ['status' => 400]);
    }
    if (!$regex && $case_sensitive && $search === $replace) {
        return new WP_Error('kit_sr_no_change', 'search and replace are identical, so there is nothing to change.', ['status' => 400]);
    }

    if (!$regex) {
        $pattern = '~' . preg_quote($search, '~') . '~' . ($case_sensitive ? '' : 'iu');
        $unicode = !$case_sensitive;
    } else {
        $delimiter = '';
        foreach (DELIMITERS as $candidate) {
            if (!str_contains($search, $candidate)) {
                $delimiter = $candidate;
                break;
            }
        }
        if ($delimiter === '') {
            return new WP_Error('kit_sr_bad_regex', 'The pattern uses every delimiter this tool can wrap it in; rewrite it without one of ~ # % ! @ ; ` |.', ['status' => 400]);
        }
        $pattern = $delimiter . $search . $delimiter . 'u' . ($case_sensitive ? '' : 'i');
        $unicode = true;
        $problem = '';
        $compiled = quietly(static fn(): int|false => preg_match($pattern, ''), $problem);
        if ($compiled === false) {
            return new WP_Error('kit_sr_bad_regex', 'The pattern does not compile: ' . ($problem !== '' ? $problem : preg_last_error_msg()), ['status' => 400]);
        }
        if ($compiled === 1) {
            // A pattern that matches nothing matches between every character, and would insert the
            // replacement thousands of times per post.
            return new WP_Error('kit_sr_empty_match', 'The pattern matches an empty string; make it require at least one character.', ['status' => 400]);
        }
    }

    return [
        'search' => $search,
        'replace' => $replace,
        'regex' => $regex,
        'case_sensitive' => $case_sensitive,
        'pattern' => $pattern,
        'unicode' => $unicode,
    ];
}

/**
 * Replace every match in one string.
 *
 * @param array{pattern: string, replace: string, regex: bool, unicode: bool} $matcher
 * @return array{value: string, count: int, samples: list<array{before: string, after: string}>}|WP_Error
 */
function replace_string(string $subject, array $matcher): array|WP_Error
{
    if (strlen($subject) > MAX_VALUE_BYTES) {
        return new WP_Error('kit_sr_value_too_large', 'The value is larger than 4 MB, so it was left alone.');
    }
    if ($matcher['unicode'] && preg_match('//u', $subject) !== 1) {
        return new WP_Error('kit_sr_invalid_utf8', 'The value is not valid UTF-8, so a case-insensitive or regex search cannot run on it safely.');
    }

    $samples = [];
    $callback = static function (array $match) use (&$samples, $matcher, $subject): string {
        $replacement = $matcher['regex'] ? expand_references($matcher['replace'], $match) : $matcher['replace'];
        if (count($samples) < SAMPLES_PER_TARGET) {
            $samples[] = snippet($subject, (int) $match[0][1], strlen((string) $match[0][0]), $replacement);
        }
        return $replacement;
    };

    $count = 0;
    $result = bounded(static function () use ($matcher, $callback, $subject, &$count): ?string {
        return preg_replace_callback($matcher['pattern'], $callback, $subject, -1, $count, PREG_OFFSET_CAPTURE);
    });
    if (!is_string($result)) {
        return new WP_Error(
            'kit_sr_regex_failed',
            'The pattern gave up on this value (' . preg_last_error_msg() . '); simplify it, for example by removing nested quantifiers.',
        );
    }

    return ['value' => $result, 'count' => $count, 'samples' => $samples];
}

/**
 * Whether a value matches at all, for deciding if a value that will be skipped is worth reporting.
 *
 * @param array{pattern: string, unicode: bool} $matcher
 */
function matches(string $subject, array $matcher): bool
{
    if ($matcher['unicode'] && preg_match('//u', $subject) !== 1) {
        return false;
    }
    return bounded(static fn(): int|false => preg_match($matcher['pattern'], $subject)) === 1;
}

/**
 * Run a PCRE call under BACKTRACK_LIMIT, or the site's own limit when that is lower.
 *
 * @template T
 * @param callable(): T $run
 * @return T
 */
function bounded(callable $run): mixed
{
    $previous = ini_get('pcre.backtrack_limit');
    $limit = $previous !== false && (int) $previous > 0 ? min((int) $previous, BACKTRACK_LIMIT) : BACKTRACK_LIMIT;
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Lowers the PCRE backtrack limit for this one bounded call only (never raises it); restored in finally below.
    ini_set('pcre.backtrack_limit', (string) $limit);
    try {
        return $run();
    } finally {
        if ($previous !== false) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Restores the site's own PCRE backtrack limit saved above.
            ini_set('pcre.backtrack_limit', $previous);
        }
    }
}

/**
 * $1, ${1} and \1 in a regex replacement, from one match's groups.
 *
 * @param array<int|string, array{0: string, 1: int}> $match A PREG_OFFSET_CAPTURE match.
 */
function expand_references(string $template, array $match): string
{
    return (string) preg_replace_callback(
        '/\\\\(\d{1,2})|\$\{(\d{1,2})\}|\$(\d{1,2})/',
        static function (array $reference) use ($match): string {
            $group = (int) ($reference[1] !== '' ? $reference[1] : (($reference[2] ?? '') !== '' ? $reference[2] : ($reference[3] ?? '0')));
            return isset($match[$group]) && is_array($match[$group]) ? (string) $match[$group][0] : '';
        },
        $template,
    );
}

/**
 * @return array{before: string, after: string}
 */
function snippet(string $subject, int $offset, int $length, string $replacement): array
{
    $total = strlen($subject);
    $start = max(0, $offset - CONTEXT_BYTES);
    // Never start or end inside a multi-byte character: the snippet goes out as JSON, and one
    // broken UTF-8 sequence makes the whole response unencodable.
    while ($start > 0 && $start < $offset && (ord($subject[$start]) & 0xC0) === 0x80) {
        $start++;
    }
    $end = min($total, $offset + $length + CONTEXT_BYTES);
    while ($end < $total && (ord($subject[$end]) & 0xC0) === 0x80) {
        $end++;
    }
    $left = ($start > 0 ? '…' : '') . substr($subject, $start, $offset - $start);
    $right = substr($subject, $offset + $length, $end - $offset - $length) . ($end < $total ? '…' : '');

    return [
        'before' => printable($left . substr($subject, $offset, $length) . $right),
        'after' => printable($left . $replacement . $right),
    ];
}

function printable(string $text): string
{
    return preg_match('//u', $text) === 1 ? $text : '[not valid UTF-8]';
}

/**
 * Replace inside every string of an array or JSON object tree, keys untouched.
 *
 * @param array{count: int, samples: list<array<string, string>>, error: WP_Error|null} $acc
 * @param array{pattern: string, replace: string, regex: bool, unicode: bool} $matcher
 */
function walk(mixed $value, array $matcher, array &$acc, string $path = ''): mixed
{
    if ($acc['error'] !== null) {
        return $value;
    }
    if (is_string($value)) {
        $result = replace_string($value, $matcher);
        if ($result instanceof WP_Error) {
            $acc['error'] = $result;
            return $value;
        }
        if ($result['count'] === 0) {
            return $value;
        }
        $acc['count'] += $result['count'];
        foreach ($result['samples'] as $sample) {
            if (count($acc['samples']) < SAMPLES_PER_TARGET) {
                $acc['samples'][] = ['path' => $path, 'before' => $sample['before'], 'after' => $sample['after']];
            }
        }
        return $result['value'];
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = walk($item, $matcher, $acc, $path === '' ? (string) $key : $path . '.' . $key);
        }
        return $value;
    }
    if ($value instanceof \stdClass) {
        // Through an array cast and back: a JSON key can be "" or numeric, which property access
        // cannot address, and the object must stay an object or `{}` re-encodes as `[]`.
        $properties = (array) $value;
        foreach ($properties as $key => $item) {
            $properties[$key] = walk($item, $matcher, $acc, $path === '' ? (string) $key : $path . '.' . $key);
        }
        return (object) $properties;
    }
    return $value;
}

function contains_object(mixed $value): bool
{
    if (is_object($value)) {
        return true;
    }
    if (is_array($value)) {
        foreach ($value as $item) {
            if (contains_object($item)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * The replacement for one stored meta value, in the encoding it was stored in.
 *
 * Returns null when nothing matches, `skip` with a reason when something matches but the value
 * cannot be rewritten safely, or the new value: `value` is what to hand update_post_meta() (an
 * array for serialized data, which WordPress serializes again), `stored` is the exact string the
 * database should hold afterwards, which apply reads back to verify the write.
 *
 * @param array{pattern: string, replace: string, regex: bool, unicode: bool} $matcher
 * @return array{encoding: string, value: mixed, stored: string, count: int, samples: list<array<string, string>>, escaping_changes: bool}|array{skip: string, message: string}|null
 */
function replace_meta(string $raw, array $matcher): ?array
{
    if (is_serialized($raw)) {
        return replace_serialized($raw, $matcher);
    }
    $trimmed = ltrim($raw);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $json = replace_json($raw, $matcher);
        if ($json !== false) {
            return $json;
        }
    }
    $result = replace_string($raw, $matcher);
    if ($result instanceof WP_Error) {
        return $result->get_error_code() === 'kit_sr_regex_failed' || matches($raw, $matcher)
            ? ['skip' => $result->get_error_code(), 'message' => $result->get_error_message()]
            : null;
    }
    if ($result['count'] === 0) {
        return null;
    }
    return [
        'encoding' => 'text',
        'value' => $result['value'],
        'stored' => $result['value'],
        'count' => $result['count'],
        'samples' => array_map(static fn(array $s): array => ['path' => '', 'before' => $s['before'], 'after' => $s['after']], $result['samples']),
        'escaping_changes' => false,
    ];
}

/**
 * @param array{pattern: string, replace: string, regex: bool, unicode: bool} $matcher
 * @return array{encoding: string, value: mixed, stored: string, count: int, samples: list<array<string, string>>, escaping_changes: bool}|array{skip: string, message: string}|null
 */
function replace_serialized(string $raw, array $matcher): ?array
{
    // allowed_classes=false: unserializing site data must never instantiate a class. An object
    // comes back as __PHP_Incomplete_Class, which is detected below and never written back.
    $problem = '';
    $data = quietly(static fn(): mixed => unserialize($raw, ['allowed_classes' => false]), $problem);
    $reason = null;
    if ($data === false && $raw !== 'b:0;') {
        $reason = ['kit_sr_serialized_unreadable', 'The serialized value could not be read, so it was left alone.'];
    } elseif (contains_object($data)) {
        $reason = ['kit_sr_serialized_object', 'The serialized value contains a PHP object; objects are never unserialized or rewritten, so it was left alone.'];
    } elseif (!is_array($data)) {
        $reason = ['kit_sr_serialized_scalar', 'The value is a serialized string or number, not an array; it was left alone rather than guess how it was stored.'];
    }
    if ($reason !== null) {
        return matches($raw, $matcher) ? ['skip' => $reason[0], 'message' => $reason[1]] : null;
    }

    $acc = ['count' => 0, 'samples' => [], 'error' => null];
    $new = walk($data, $matcher, $acc);
    if ($acc['error'] instanceof WP_Error) {
        return ['skip' => $acc['error']->get_error_code(), 'message' => $acc['error']->get_error_message()];
    }
    if ($acc['count'] === 0) {
        return null;
    }
    return [
        'encoding' => 'serialized',
        'value' => $new,
        'stored' => serialize($new),
        'count' => $acc['count'],
        'samples' => $acc['samples'],
        'escaping_changes' => false,
    ];
}

/**
 * @param array{pattern: string, replace: string, regex: bool, unicode: bool} $matcher
 * @return array{encoding: string, value: mixed, stored: string, count: int, samples: list<array<string, string>>, escaping_changes: bool}|array{skip: string, message: string}|null|false
 *         false when the value is not a JSON object or array after all.
 */
function replace_json(string $raw, array $matcher): array|null|false
{
    $decoded = json_decode($raw, false, 512);
    if (json_last_error() !== JSON_ERROR_NONE || !(is_array($decoded) || is_object($decoded))) {
        return false;
    }
    if (preg_match('/[:\[,]\s*-?\d{16,}/', $raw) === 1) {
        // Integers past 2^53 decode to floats and would be written back rounded.
        return matches($raw, $matcher)
            ? ['skip' => 'kit_sr_json_large_number', 'message' => 'The JSON holds integers too large to re-encode exactly, so it was left alone.']
            : null;
    }

    $acc = ['count' => 0, 'samples' => [], 'error' => null];
    $new = walk($decoded, $matcher, $acc);
    if ($acc['error'] instanceof WP_Error) {
        return ['skip' => $acc['error']->get_error_code(), 'message' => $acc['error']->get_error_message()];
    }
    if ($acc['count'] === 0) {
        return null;
    }
    [$flags, $exact] = json_flags($raw, $decoded);
    $encoded = json_encode($new, $flags);
    if (!is_string($encoded)) {
        return ['skip' => 'kit_sr_json_encode_failed', 'message' => 'The changed JSON could not be encoded again, so it was left alone.'];
    }
    return [
        'encoding' => 'json',
        'value' => $encoded,
        'stored' => $encoded,
        'count' => $acc['count'],
        'samples' => $acc['samples'],
        'escaping_changes' => !$exact,
    ];
}

/**
 * The json_encode() flags that reproduce the stored document byte for byte, when some do.
 *
 * Elementor writes through wp_json_encode() with no flags (escaped slashes and unicode); other
 * plugins write unescaped. Re-encoding with the wrong flags changes every URL and accented
 * character in the document, which is harmless to the data but buries the real change in the diff
 * and in any comparison with an earlier copy. When no combination round-trips exactly, the closest
 * guess is used and the result says escaping changed.
 *
 * @return array{0: int, 1: bool}
 */
function json_flags(string $raw, mixed $decoded): array
{
    $options = [0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE];
    foreach ([0, JSON_PRESERVE_ZERO_FRACTION] as $fraction) {
        foreach ($options as $flags) {
            if (json_encode($decoded, $flags | $fraction) === $raw) {
                return [$flags | $fraction, true];
            }
        }
    }
    $flags = (str_contains($raw, '\\/') ? 0 : JSON_UNESCAPED_SLASHES)
        | (preg_match('/\\\\u[0-9a-fA-F]{4}/', $raw) === 1 ? 0 : JSON_UNESCAPED_UNICODE);
    return [$flags, false];
}

/**
 * Run something that reports failure through a PHP warning, and keep the warning's text rather
 * than letting it reach the response or the log.
 *
 * @template T
 * @param callable(): T $run
 * @return T
 */
function quietly(callable $run, string &$problem): mixed
{
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped to one PCRE/JSON call to capture its warning text for the caller; restore_error_handler() runs in finally.
    set_error_handler(static function (int $errno, string $message) use (&$problem): bool {
        $problem = preg_replace('/^preg_match\(\): /', '', $message) ?? $message;
        return true;
    });
    try {
        return $run();
    } finally {
        restore_error_handler();
    }
}
