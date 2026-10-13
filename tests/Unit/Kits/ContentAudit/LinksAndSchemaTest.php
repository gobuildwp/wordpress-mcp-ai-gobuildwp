<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\ContentAudit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPPilot\Kits\ContentAudit\Links;
use WPPilot\Kits\ContentAudit\Schema;

/**
 * Link extraction and classification, word counts, and the JSON-LD inspection, on fixtures.
 */
final class LinksAndSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/content-audit/bootstrap.php';
    }

    public function testHrefsAreFoundInEveryQuotingStyleAndDecoded(): void
    {
        $html = '<p><a href="/about/">About</a> <a class="x" href=\'https://example.test/?a=1&amp;b=2\'>Q</a> '
            . '<a href=/bare>Bare</a> <link href="/style.css"> <a name="top">no href</a></p>';

        self::assertSame(['/about/', 'https://example.test/?a=1&b=2', '/bare'], Links::from_html($html));
    }

    public function testBuilderLinksComeFromLinkControlsAndTextFields(): void
    {
        $json = (string) json_encode([[
            'elements' => [
                ['settings' => ['link' => ['url' => 'https://example.test/pricing/', 'is_external' => ''], 'title' => 'Plans']],
                ['settings' => ['editor' => '<p>See <a href="/contact/">contact</a></p>']],
                ['settings' => ['link' => ['url' => '']]],
            ],
        ]]);

        self::assertSame(['https://example.test/pricing/', '/contact/'], Links::from_builder($json));
        self::assertSame([], Links::from_builder('not json'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function classifications(): array
    {
        return [
            'root-relative' => ['/about/', 'internal'],
            'same host' => ['https://example.test/about/', 'internal'],
            'www variant of the home host' => ['http://www.example.test/about/', 'internal'],
            'protocol-relative same host' => ['//example.test/x', 'internal'],
            'protocol-relative elsewhere' => ['//cdn.other.test/x', 'external'],
            'other host' => ['https://other.test/', 'external'],
            'subdomain is another site' => ['https://shop.example.test/', 'external'],
            'fragment' => ['#section', 'skip'],
            'mailto' => ['mailto:a@example.test', 'skip'],
            'tel' => ['tel:+1555', 'skip'],
            'javascript' => ['javascript:void(0)', 'skip'],
            'document-relative' => ['page-2.html', 'skip'],
        ];
    }

    #[DataProvider('classifications')]
    public function testClassify(string $url, string $expected): void
    {
        self::assertSame($expected, Links::classify($url, 'https://example.test/'));
    }

    public function testAbsoluteDropsTheFragmentAndKeepsTheHomeScheme(): void
    {
        self::assertSame('https://example.test/about/?x=1', Links::absolute('/about/?x=1#team', 'https://example.test/'));
        self::assertSame('https://example.test:8080/a', Links::absolute('/a', 'https://example.test:8080/'));
        self::assertSame('https://example.test/b', Links::absolute('//example.test/b', 'https://example.test/'));
    }

    public function testWordCountIgnoresMarkupCommentsAndShortcodes(): void
    {
        $content = '<!-- wp:paragraph {"a":"many words hidden here"} --><p>One two <strong>three</strong></p><!-- /wp:paragraph -->[gallery ids="1,2,3"] café l’été';
        $builder = (string) json_encode([['settings' => ['title' => 'Four five', 'image' => ['url' => 'https://x.test/a.jpg']]]]);

        self::assertSame(5, Links::word_count(Links::text($content, '')));
        self::assertSame(7, Links::word_count(Links::text($content, $builder)));
    }

    public function testValidMarkupHasNoProblems(): void
    {
        $result = Schema::inspect(self::page('{"@context":"https://schema.org","@type":"Article","headline":"Hi"}'));

        self::assertSame(1, $result['blocks']);
        self::assertSame(['Article'], $result['types']);
        self::assertSame([], $result['problems']);
    }

    public function testInvalidJsonIsHighWithTheParserError(): void
    {
        $result = Schema::inspect(self::page('{"@context":"https://schema.org","@type":"Article",}'));

        self::assertCount(1, $result['problems']);
        self::assertSame('schema_invalid_json', $result['problems'][0]['type']);
        self::assertSame('high', $result['problems'][0]['severity']);
        self::assertNotSame('', $result['problems'][0]['evidence']['error']);
    }

    public function testMissingContextAndTypeAreReportedSeparately(): void
    {
        $result = Schema::inspect(self::page('{"@type":"Organization","name":"A"}') . self::page('{"@context":"https://schema.org","name":"No type"}'));

        self::assertSame(['schema_missing_context', 'schema_missing_type'], array_column($result['problems'], 'type'));
        self::assertSame(2, $result['blocks']);
    }

    public function testAGraphTakesItsContextFromTheWrapper(): void
    {
        $result = Schema::inspect(self::page((string) json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [['@type' => 'WebSite', 'name' => 'S'], ['name' => 'untyped']],
        ])));

        self::assertSame(['schema_missing_type'], array_column($result['problems'], 'type'));
        self::assertSame(1, $result['problems'][0]['evidence']['entity']);
    }

    /**
     * FAQPage nested as a WebPage's mainEntity, and HowTo named by full URL inside a type list,
     * are both found; the finding states the rule instead of calling the markup invalid.
     */
    public function testFaqAndHowToAreIneligibleForRichResultsNotInvalid(): void
    {
        $result = Schema::inspect(
            self::page((string) json_encode(['@context' => 'https://schema.org', '@type' => 'WebPage', 'mainEntity' => ['@type' => 'FAQPage', 'mainEntity' => []]]))
            . self::page((string) json_encode(['@context' => ['@vocab' => 'https://schema.org/'], '@type' => ['Thing', 'https://schema.org/HowTo']])),
        );

        $ineligible = array_values(array_filter($result['problems'], static fn(array $p): bool => $p['type'] === 'schema_rich_result_ineligible'));
        self::assertCount(2, $ineligible);
        self::assertSame(['FAQPage'], $ineligible[0]['evidence']['types']);
        self::assertSame(['HowTo'], $ineligible[1]['evidence']['types']);
        self::assertSame('info', $ineligible[0]['severity']);
        self::assertStringContainsString('valid schema.org', $ineligible[0]['evidence']['rule']);
        self::assertCount(2, $result['problems'], 'nothing else is wrong with that markup');
    }

    public function testOnlyJsonLdScriptsAreReadWhateverTheirCasingOrCharset(): void
    {
        $html = '<script type="application/json">{"bad json"</script>'
            . '<script type="text/javascript">var x = {</script>'
            . '<script type="Application/LD+JSON; charset=utf-8">{"@context":"https://schema.org","@type":"Thing"}</script>';

        $result = Schema::inspect('<html><head>' . $html . '</head><body></body></html>');

        self::assertSame(1, $result['blocks']);
        self::assertSame([], $result['problems']);
    }

    private static function page(string $json): string
    {
        return '<html><head><script type="application/ld+json">' . $json . '</script></head><body><p>x</p></body></html>';
    }
}
