<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockNotes;

use stdClass;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Blocks in stored post content, addressed so an agent can name one, and the note anchor on them.
 *
 * WordPress anchors a note to a block through the block's `metadata.noteId` attribute: a list of
 * note (comment) IDs since 7.1, a single ID before. The editor finds a note's block by scanning
 * block attributes for its ID, so an agent's note shows on a block only if that ID is in that
 * block's delimiter comment in post_content.
 *
 * Editor client IDs are per session and never stored, so they cannot address a block from here.
 * A block is addressed by its path — child indexes from the top, "0.2.1", counting only real
 * blocks, the way parse_blocks() nests them — or by its HTML anchor (id attribute).
 *
 * Only the one delimiter is rewritten. Re-serialising the whole post would re-encode every other
 * block's attributes and hand the person a diff full of changes nobody made.
 */
final class Blocks
{
    /** WP_Block_Parser::next_token()'s delimiter pattern, verbatim. */
    private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

    /**
     * Every block in document order (parents before their children).
     *
     * @return list<array{path: string, name: string, anchor: string, label: string, excerpt: string, note_ids: list<int>, start: int, length: int, void: bool, raw_name: string, attrs_raw: string}>
     */
    public static function outline(string $content): array
    {
        if (preg_match_all(self::DELIMITER, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $blocks = [];
        $stack = [];
        $counters = [0];
        foreach ($matches as $match) {
            $start = (int) $match[0][1];
            $length = strlen($match[0][0]);
            $is_closer = isset($match['closer']) && $match['closer'][1] !== -1 && $match['closer'][0] !== '';
            $is_void = isset($match['void']) && $match['void'][1] !== -1 && $match['void'][0] !== '';
            if ($is_closer) {
                $open = array_pop($stack);
                array_pop($counters);
                if ($open !== null) {
                    $blocks[$open]['inner_end'] = $start;
                }
                continue;
            }
            $namespace = isset($match['namespace']) && $match['namespace'][1] !== -1 ? $match['namespace'][0] : '';
            $parent_path = $stack === [] ? '' : $blocks[$stack[count($stack) - 1]]['path'] . '.';
            $depth = count($counters) - 1;
            $index = $counters[$depth];
            $counters[$depth] = $index + 1;
            $attrs_raw = isset($match['attrs']) && $match['attrs'][1] !== -1 ? trim($match['attrs'][0]) : '';
            /** @var mixed $attrs */
            $attrs = $attrs_raw === '' ? [] : json_decode($attrs_raw, true);
            $attrs = is_array($attrs) ? $attrs : [];
            $metadata = is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : [];
            $blocks[] = [
                'path' => $parent_path . $index,
                'name' => ($namespace !== '' ? $namespace : 'core/') . $match['name'][0],
                'anchor' => is_string($attrs['anchor'] ?? null) ? $attrs['anchor'] : '',
                'label' => is_string($metadata['name'] ?? null) ? $metadata['name'] : '',
                'excerpt' => '',
                'note_ids' => self::note_ids($metadata['noteId'] ?? null),
                'start' => $start,
                'length' => $length,
                'void' => $is_void,
                'raw_name' => $namespace . $match['name'][0],
                'attrs_raw' => $attrs_raw,
                'inner_end' => $start + $length,
            ];
            if (!$is_void) {
                $stack[] = count($blocks) - 1;
                $counters[] = 0;
            }
        }
        foreach ($blocks as $i => $block) {
            $inner = substr($content, $block['start'] + $block['length'], max(0, $block['inner_end'] - $block['start'] - $block['length']));
            if ($block['anchor'] === '') {
                // Core keeps a block's anchor in its markup (the id attribute), not in the
                // delimiter; only the block's own wrapper counts, not a nested block's.
                $own = (string) preg_split('/<!--\s+wp:/', $inner, 2)[0];
                if (preg_match('/^\s*<[a-z][a-z0-9-]*\b[^>]*?\sid\s*=\s*["\']([^"\']+)["\']/i', $own, $id) === 1) {
                    $blocks[$i]['anchor'] = html_entity_decode($id[1], ENT_QUOTES, 'UTF-8');
                }
            }
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/<!--.*?-->/s', ' ', $inner))));
            $blocks[$i]['excerpt'] = mb_substr(html_entity_decode($text, ENT_QUOTES, 'UTF-8'), 0, 120);
            unset($blocks[$i]['inner_end']);
        }
        return array_values($blocks);
    }

    /**
     * The block a caller named, by path or by anchor.
     *
     * @param list<array<string, mixed>> $outline
     * @return array<string, mixed>|WP_Error
     */
    public static function find(array $outline, string $path, string $anchor): array|WP_Error
    {
        if (($path === '') === ($anchor === '')) {
            return new WP_Error('kit_block_notes_block_required', 'Name the block by exactly one of block_path or block_anchor.', ['status' => 400]);
        }
        $found = array_values(array_filter($outline, static function (array $block) use ($path, $anchor): bool {
            return $path !== '' ? $block['path'] === $path : $block['anchor'] === $anchor;
        }));
        if ($found === []) {
            return new WP_Error(
                'kit_block_notes_block_not_found',
                'No block matches. Call wppilot/list-block-notes with include_blocks=true for the current paths and anchors.',
                ['status' => 404],
            );
        }
        if (count($found) > 1) {
            return new WP_Error('kit_block_notes_block_ambiguous', 'More than one block has that anchor; name it by block_path.', ['status' => 409]);
        }
        return $found[0];
    }

    /**
     * Content with a note ID added to one block's metadata.noteId.
     *
     * @param array<string, mixed> $block An entry from outline() of this same content.
     */
    public static function add_note_id(string $content, array $block, int $note_id): string
    {
        $attrs = self::decode((string) $block['attrs_raw']);
        $metadata = isset($attrs->metadata) && $attrs->metadata instanceof stdClass ? $attrs->metadata : new stdClass();
        $ids = self::note_ids(isset($metadata->noteId) ? $metadata->noteId : null);
        if (!in_array($note_id, $ids, true)) {
            $ids[] = $note_id;
        }
        $metadata->noteId = $ids;
        $attrs->metadata = $metadata;
        return self::replace($content, $block, $attrs);
    }

    /**
     * Content with a note ID removed from every block that references it.
     */
    public static function remove_note_id(string $content, int $note_id): string
    {
        // Right to left, so each replacement leaves the offsets of the ones still to come intact.
        foreach (array_reverse(self::outline($content)) as $block) {
            if (!in_array($note_id, $block['note_ids'], true)) {
                continue;
            }
            $attrs = self::decode($block['attrs_raw']);
            $metadata = $attrs->metadata;
            $ids = array_values(array_diff(self::note_ids($metadata->noteId), [$note_id]));
            if ($ids === []) {
                unset($metadata->noteId);
            } else {
                $metadata->noteId = $ids;
            }
            if (get_object_vars($metadata) === []) {
                // As the editor does (cleanEmptyObject): no empty metadata left behind.
                unset($attrs->metadata);
            }
            $content = self::replace($content, $block, $attrs);
        }
        return $content;
    }

    /** @return list<array<string, mixed>> Blocks that reference a note. */
    public static function referencing(string $content, int $note_id): array
    {
        return array_values(array_filter(
            self::outline($content),
            static fn(array $block): bool => in_array($note_id, $block['note_ids'], true),
        ));
    }

    /** Whether the content carries an inline marker for the note (a note on a text selection). */
    public static function has_inline_marker(string $content, int $note_id): bool
    {
        $pattern = '/<mark\b(?=[^>]*\bclass\s*=\s*["\'](?:[^"\']*\s)?wp-note(?:\s[^"\']*)?["\'])(?=[^>]*\bdata-id\s*=\s*["\']' . $note_id . '["\'])[^>]*>/i';
        return preg_match($pattern, $content) === 1;
    }

    /**
     * metadata.noteId as a list: an array since WordPress 7.1, a single number before.
     *
     * @return list<int>
     */
    public static function note_ids(mixed $value): array
    {
        $raw = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($raw as $id) {
            if (is_numeric($id) && (int) $id > 0 && !in_array((int) $id, $ids, true)) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /**
     * Block attributes as serialize_block_attributes() writes them.
     */
    public static function encode_attrs(stdClass $attrs): string
    {
        if (function_exists('serialize_block_attributes')) {
            return (string) serialize_block_attributes($attrs);
        }
        $encoded = (string) json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return strtr($encoded, [
            '\\\\' => '\\u005c',
            '--' => '\\u002d\\u002d',
            '<' => '\\u003c',
            '>' => '\\u003e',
            '&' => '\\u0026',
            '\\"' => '\\u0022',
        ]);
    }

    /**
     * Decoded as objects, so `{}` stays an object when it is written back: as an array it would
     * come back as `[]`, which changes what the block's other attributes mean.
     */
    private static function decode(string $raw): stdClass
    {
        /** @var mixed $attrs */
        $attrs = $raw === '' ? null : json_decode($raw);
        return $attrs instanceof stdClass ? $attrs : new stdClass();
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function replace(string $content, array $block, stdClass $attrs): string
    {
        $json = get_object_vars($attrs) === [] ? '' : self::encode_attrs($attrs) . ' ';
        $delimiter = '<!-- wp:' . $block['raw_name'] . ' ' . $json . ($block['void'] ? '/-->' : '-->');
        return substr_replace($content, $delimiter, (int) $block['start'], (int) $block['length']);
    }
}
