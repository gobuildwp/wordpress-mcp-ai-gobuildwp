<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ContentAudit;

use DOMXPath;
use WPPilot\Kits\Runtime\Page;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Problems in the JSON-LD a page actually serves.
 *
 * Read from served HTML because that is what a search engine parses: the SEO plugin, the theme
 * and a schema plugin can each print a block, and a broken one is invisible in post content.
 */
final class Schema
{
    /**
     * Google's rule, stated rather than paraphrased as "invalid": the markup is fine schema.org.
     */
    public const RICH_RESULT_RULE = 'Google limited FAQ rich results to well-known, authoritative government and health websites in August 2023, and stopped showing HowTo rich results in September 2023. This markup is valid schema.org and unused structured data does not cause problems in Search, but it will not earn FAQ or HowTo rich results on this site unless it is such a government or health site.';

    /** Types whose rich result Google no longer shows for most sites. */
    private const RESTRICTED_TYPES = ['FAQPage', 'HowTo'];

    /**
     * @return array{blocks: int, types: list<string>, problems: list<array{type: string, severity: string, evidence: array<string, mixed>}>}
     */
    public static function inspect(string $html): array
    {
        $result = ['blocks' => 0, 'types' => [], 'problems' => []];
        $document = Page::parse($html);
        if ($document === null) {
            return $result;
        }
        $scripts = (new DOMXPath($document))->query('//script[@type]');
        foreach ($scripts === false ? [] : $scripts as $script) {
            if (!$script instanceof \DOMElement) {
                continue;
            }
            // `application/ld+json; charset=utf-8` and odd casing are both seen in the wild.
            $type = strtolower(trim(explode(';', $script->getAttribute('type'))[0]));
            if ($type !== 'application/ld+json') {
                continue;
            }
            $result['blocks']++;
            $raw = trim((string) $script->textContent);
            /** @var mixed $data */
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                $result['problems'][] = [
                    'type' => 'schema_invalid_json',
                    'severity' => 'high',
                    'evidence' => [
                        'block' => $result['blocks'],
                        'error' => json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'Not a JSON object or array.',
                        'snippet' => mb_substr($raw, 0, 200),
                    ],
                ];
                continue;
            }
            self::inspect_block($data, $result['blocks'], $result);
        }
        $result['types'] = array_values(array_unique($result['types']));
        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array{blocks: int, types: list<string>, problems: list<array{type: string, severity: string, evidence: array<string, mixed>}>} $result
     */
    private static function inspect_block(array $data, int $block, array &$result): void
    {
        // A block is one object, a list of objects, or an object carrying an @graph; each
        // top-level object needs its own @context unless the @graph wrapper supplies it.
        $top = array_is_list_compat($data) ? $data : [$data];
        foreach ($top as $position => $item) {
            if (!is_array($item)) {
                continue;
            }
            $has_context = self::has_schema_context($item['@context'] ?? null);
            $entities = isset($item['@graph']) && is_array($item['@graph']) ? $item['@graph'] : [$item];
            if (!$has_context) {
                $result['problems'][] = [
                    'type' => 'schema_missing_context',
                    'severity' => 'medium',
                    'evidence' => [
                        'block' => $block,
                        'item' => $position,
                        'context' => isset($item['@context']) ? $item['@context'] : null,
                        'detail' => isset($item['@context']) ? '@context does not name schema.org.' : 'No @context.',
                    ],
                ];
            }
            foreach ($entities as $entity_index => $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                $types = self::types($entity['@type'] ?? null);
                if ($types === []) {
                    $result['problems'][] = [
                        'type' => 'schema_missing_type',
                        'severity' => 'medium',
                        'evidence' => [
                            'block' => $block,
                            'item' => $position,
                            'entity' => $entity_index,
                            'keys' => array_slice(array_map('strval', array_keys($entity)), 0, 10),
                        ],
                    ];
                }
                $found = [];
                self::collect_types($entity, $found, 0);
                foreach ($found as $type) {
                    $result['types'][] = $type;
                }
                $restricted = array_values(array_intersect(self::RESTRICTED_TYPES, $found));
                if ($restricted !== []) {
                    $result['problems'][] = [
                        'type' => 'schema_rich_result_ineligible',
                        'severity' => 'info',
                        'evidence' => [
                            'block' => $block,
                            'item' => $position,
                            'entity' => $entity_index,
                            'types' => $restricted,
                            'rule' => self::RICH_RESULT_RULE,
                        ],
                    ];
                }
            }
        }
    }

    private static function has_schema_context(mixed $context): bool
    {
        if (is_string($context)) {
            return stripos($context, 'schema.org') !== false;
        }
        if (is_array($context)) {
            foreach ($context as $value) {
                if (self::has_schema_context($value)) {
                    return true;
                }
            }
            return stripos((string) ($context['@vocab'] ?? ''), 'schema.org') !== false;
        }
        return false;
    }

    /** @return list<string> */
    private static function types(mixed $type): array
    {
        if (is_string($type) && trim($type) !== '') {
            return [self::short_type($type)];
        }
        if (is_array($type)) {
            return array_values(array_map(
                static fn(string $value): string => self::short_type($value),
                array_filter($type, static fn(mixed $value): bool => is_string($value) && trim($value) !== ''),
            ));
        }
        return [];
    }

    /** `https://schema.org/FAQPage` and `schema:FAQPage` are the same type as `FAQPage`. */
    private static function short_type(string $type): string
    {
        $type = trim($type);
        $cut = max((int) strrpos($type, '/'), (int) strrpos($type, ':'));
        return $cut > 0 ? substr($type, $cut + 1) : $type;
    }

    /**
     * Every @type in an entity and what it nests (a WebPage whose mainEntity is an FAQPage).
     *
     * @param array<array-key, mixed> $node
     * @param list<string> $found
     */
    private static function collect_types(array $node, array &$found, int $depth): void
    {
        if ($depth > 12) {
            return;
        }
        foreach (self::types($node['@type'] ?? null) as $type) {
            if (!in_array($type, $found, true)) {
                $found[] = $type;
            }
        }
        foreach ($node as $key => $child) {
            if ($key !== '@type' && is_array($child)) {
                self::collect_types($child, $found, $depth + 1);
            }
        }
    }
}

/**
 * array_is_list() is PHP 8.1.
 *
 * @param array<array-key, mixed> $value
 */
function array_is_list_compat(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}
