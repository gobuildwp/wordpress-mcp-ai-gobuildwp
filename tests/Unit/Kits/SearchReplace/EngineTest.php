<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SearchReplace;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\SearchReplace as SR;

require_once __DIR__ . '/harness.php';

/**
 * The replacement itself: literal and regex matching, serialized arrays, JSON documents.
 */
final class EngineTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/search-replace/src/engine.php';
    }

    /** @return array<string, mixed> */
    private static function matcher(array $input): array
    {
        $matcher = SR\matcher($input);
        self::assertIsArray($matcher);
        return $matcher;
    }

    public function testRegexIsOffByDefaultSoMetacharactersAreLiteral(): void
    {
        $matcher = self::matcher(['search' => 'a.c', 'replace' => 'X']);

        self::assertFalse($matcher['regex']);
        self::assertSame(0, SR\replace_string('abc', $matcher)['count']);
        $result = SR\replace_string('a.c and a.c', $matcher);
        self::assertSame('X and X', $result['value']);
        self::assertSame(2, $result['count']);
    }

    public function testCaseInsensitiveLiteralMatchesUnicode(): void
    {
        $matcher = self::matcher(['search' => 'ÉCOLE', 'replace' => 'school', 'case_sensitive' => false]);

        self::assertSame('la school, la school', SR\replace_string('la école, la École', $matcher)['value']);
    }

    public function testRegexReplacementExpandsGroupReferences(): void
    {
        $matcher = self::matcher(['search' => '(\w+)@old\.example', 'replace' => '$1@new.example / ${1} / \1', 'regex' => true]);

        $result = SR\replace_string('Mail ann@old.example today', $matcher);

        self::assertSame('Mail ann@new.example / ann / ann today', $result['value']);
        self::assertSame('Mail ann@old.example today', $result['samples'][0]['before']);
        self::assertSame('Mail ann@new.example / ann / ann today', $result['samples'][0]['after']);
    }

    public function testRegexThatCannotCompileOrMatchesEmptyIsRefused(): void
    {
        $bad = SR\matcher(['search' => '(unclosed', 'regex' => true]);
        $empty = SR\matcher(['search' => 'x*', 'regex' => true]);

        self::assertInstanceOf(WP_Error::class, $bad);
        self::assertSame('kit_sr_bad_regex', $bad->get_error_code());
        self::assertInstanceOf(WP_Error::class, $empty);
        self::assertSame('kit_sr_empty_match', $empty->get_error_code());
    }

    public function testCatastrophicBacktrackingIsStoppedAndRestoresTheLimit(): void
    {
        $before = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        // JIT has its own limits and would finish this quickly; the backtrack limit is what
        // guards sites without it.
        ini_set('pcre.jit', '0');
        try {
            $matcher = self::matcher(['search' => '(a+)+$', 'replace' => 'x', 'regex' => true]);
            $result = SR\replace_string(str_repeat('a', 40) . 'b', $matcher);
        } finally {
            ini_set('pcre.jit', (string) $jit);
        }

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('kit_sr_regex_failed', $result->get_error_code());
        self::assertSame($before, ini_get('pcre.backtrack_limit'));
    }

    public function testSnippetsNeverSplitAMultiByteCharacter(): void
    {
        $matcher = self::matcher(['search' => 'needle', 'replace' => 'pin']);
        $subject = str_repeat('é', 40) . 'needle' . str_repeat('ü', 40);

        $sample = SR\replace_string($subject, $matcher)['samples'][0];

        self::assertSame(1, preg_match('//u', $sample['before']));
        self::assertSame(1, preg_match('//u', $sample['after']));
        self::assertStringContainsString('needle', $sample['before']);
        self::assertStringContainsString('pin', $sample['after']);
    }

    public function testSerializedArraysAreWalkedAndReserializedWithCorrectLengths(): void
    {
        $raw = serialize(['title' => 'old.example home', 'nested' => ['links' => ['https://old.example/a', 'keep']], 'n' => 3]);
        $matcher = self::matcher(['search' => 'old.example', 'replace' => 'new-domain.example']);

        $result = SR\replace_meta($raw, $matcher);

        self::assertSame('serialized', $result['encoding']);
        self::assertSame(2, $result['count']);
        self::assertSame(
            ['title' => 'new-domain.example home', 'nested' => ['links' => ['https://new-domain.example/a', 'keep']], 'n' => 3],
            unserialize($result['stored']),
        );
        self::assertSame('nested.links.0', $result['samples'][1]['path']);
    }

    public function testSerializedObjectsAreNeverInstantiatedOrRewritten(): void
    {
        $raw = 'a:1:{s:1:"o";O:8:"stdClass":1:{s:4:"text";s:11:"old.example";}}';
        $matcher = self::matcher(['search' => 'old.example', 'replace' => 'new.example']);

        $result = SR\replace_meta($raw, $matcher);

        self::assertSame('kit_sr_serialized_object', $result['skip']);
        // No match, no report: an object nobody searched for is not noise in the diff.
        self::assertNull(SR\replace_meta($raw, self::matcher(['search' => 'absent', 'replace' => 'x'])));
    }

    public function testJsonIsWalkedAndKeepsItsEscapingAndEmptyObjects(): void
    {
        $document = [['id' => 'a1', 'settings' => (object) [], 'elements' => [['settings' => ['title' => 'Visit old.example/shop — café', 'link' => ['url' => 'https://old.example/x']]]]]];
        $raw = json_encode($document);
        self::assertStringContainsString('\/', $raw);
        self::assertStringContainsString('\u00e9', $raw);
        $matcher = self::matcher(['search' => 'old.example', 'replace' => 'new.example']);

        $result = SR\replace_meta($raw, $matcher);

        self::assertSame('json', $result['encoding']);
        self::assertFalse($result['escaping_changes']);
        self::assertSame(2, $result['count']);
        self::assertSame(str_replace('old.example', 'new.example', $raw), $result['stored']);
        self::assertStringContainsString('"settings":{}', $result['stored']);
        self::assertSame('0.elements.0.settings.title', $result['samples'][0]['path']);
    }

    public function testJsonWrittenUnescapedStaysUnescaped(): void
    {
        $raw = json_encode(['url' => 'https://old.example/é'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $matcher = self::matcher(['search' => 'old.example', 'replace' => 'new.example']);

        $result = SR\replace_meta($raw, $matcher);

        self::assertSame('{"url":"https://new.example/é"}', $result['stored']);
        self::assertFalse($result['escaping_changes']);
    }

    public function testJsonThatCannotRoundTripSaysEscapingChanges(): void
    {
        $raw = "{\n    \"url\": \"https:\\/\\/old.example\"\n}";
        $matcher = self::matcher(['search' => 'old.example', 'replace' => 'new.example']);

        $result = SR\replace_meta($raw, $matcher);

        self::assertTrue($result['escaping_changes']);
        self::assertSame(['url' => 'https://new.example'], json_decode($result['stored'], true));
    }

    public function testKeysAreNotReplaced(): void
    {
        $matcher = self::matcher(['search' => 'old', 'replace' => 'new']);

        self::assertSame(['old' => 'new'], unserialize(SR\replace_meta(serialize(['old' => 'old']), $matcher)['stored']));
        self::assertSame('{"old":"new"}', SR\replace_meta('{"old":"old"}', $matcher)['stored']);
    }

    public function testBackslashesInJsonStringsSurvive(): void
    {
        $raw = json_encode(['html' => '<a href=\"x\">old</a> C:\\path']);
        $matcher = self::matcher(['search' => 'old', 'replace' => 'new']);

        $result = SR\replace_meta($raw, $matcher);

        self::assertSame(['html' => '<a href=\"x\">new</a> C:\\path'], json_decode($result['stored'], true));
    }
}
