<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * wppilot/read-pdf's pure-PHP text extraction. The PDFs are built here so the test needs no
 * fixture files: a WinAnsi page, a Type0 page mapped through ToUnicode, objects packed in an
 * object stream, an encrypted trailer, and a page with no text.
 */
final class PdfTextTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/pdf-text.php';
    }

    /** @param array<int, string> $objects object number => body (with stream, if any) */
    private static function pdf(array $objects, string $trailer = '/Root 1 0 R'): string
    {
        $out = "%PDF-1.7\n";
        foreach ($objects as $number => $body) {
            $out .= "{$number} 0 obj\n{$body}\nendobj\n";
        }
        return $out . "trailer\n<< {$trailer} >>\n%%EOF\n";
    }

    private static function stream(string $data, bool $flate = true): string
    {
        $data = $flate ? (string) gzcompress($data) : $data;
        return '<< /Length ' . strlen($data) . ($flate ? ' /Filter /FlateDecode' : '') . " >>\nstream\n{$data}\nendstream";
    }

    public function test_simple_font_text_comes_out_in_lines_with_word_gaps(): void
    {
        $content = "BT /F1 12 Tf 72 720 Td (Hello) Tj 40 0 Td [(Wor) -20 (ld)] TJ 0 -14 Td (Second line) Tj ET\n"
            . "BT /F1 12 Tf 1 0 0 1 72 600 Tm (Caf\\351) Tj 1 0 0 1 120 600 Tm (au lait) Tj ET";
        $bytes = self::pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => self::stream($content),
        ]);

        $result = \wppilot_pdf_extract_text($bytes);

        self::assertIsArray($result);
        self::assertSame(1, $result['page_count']);
        self::assertSame("Hello World\nSecond line\nCafé au lait", $result['pages'][0]['text']);
        self::assertSame([], $result['notes']);
    }

    public function test_type0_text_is_mapped_through_to_unicode_and_object_streams_are_read(): void
    {
        $cmap = "begincmap\n1 begincodespacerange <0000> <FFFF> endcodespacerange\n"
            . "2 beginbfchar <0001> <0048> <0002> <0069> endbfchar\n"
            . "1 beginbfrange <0010> <0012> <0041> endbfrange\nendcmap";
        // Objects 2 and 3 live only inside the object stream (object 9).
        $packed = '<< /Type /Pages /Kids [3 0 R] /Count 1 >> << /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>';
        $first = strpos($packed, '<< /Type /Page ');
        $header = "2 0 3 {$first} ";
        $objstm = (string) gzcompress($header . $packed);
        $bytes = self::pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            4 => '<< /Type /Font /Subtype /Type0 /BaseFont /X /Encoding /Identity-H /ToUnicode 6 0 R /DescendantFonts [7 0 R] >>',
            5 => self::stream('BT /F1 10 Tf 50 50 Td <00010002> Tj 20 0 Td [<0010> -300 <00110012>] TJ ET'),
            6 => self::stream($cmap),
            7 => '<< /Type /Font /Subtype /CIDFontType2 /DW 500 >>',
            9 => '<< /Type /ObjStm /N 2 /First ' . strlen($header) . ' /Length ' . strlen($objstm) . " /Filter /FlateDecode >>\nstream\n{$objstm}\nendstream",
        ]);

        $result = \wppilot_pdf_extract_text($bytes);

        self::assertIsArray($result);
        self::assertSame('Hi A BC', $result['pages'][0]['text']);
    }

    public function test_encrypted_and_non_pdf_files_are_refused_with_the_reason(): void
    {
        $encrypted = self::pdf([1 => '<< /Type /Catalog >>'], '/Root 1 0 R /Encrypt 2 0 R');
        $error = \wppilot_pdf_extract_text($encrypted);
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('wppilot_pdf_encrypted', $error->get_error_code());

        $not_pdf = \wppilot_pdf_extract_text('<html>not a pdf</html>');
        self::assertInstanceOf(WP_Error::class, $not_pdf);
        self::assertSame('wppilot_pdf_not_pdf', $not_pdf->get_error_code());
    }

    public function test_page_range_and_textless_pages_are_reported(): void
    {
        $kids = '';
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        for ($i = 0; $i < 3; ++$i) {
            $page = 10 + $i * 2;
            $kids .= "{$page} 0 R ";
            $objects[$page] = "<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /Contents " . ($page + 1) . ' 0 R >>';
            $objects[$page + 1] = self::stream($i === 1 ? '0 0 100 100 re f' : "BT /F1 12 Tf 10 10 Td (Page {$i}) Tj ET");
        }
        $objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count 3 >>";
        ksort($objects);

        $result = \wppilot_pdf_extract_text(self::pdf($objects), 2, 1);

        self::assertIsArray($result);
        self::assertSame(3, $result['page_count']);
        self::assertSame([['page' => 2, 'text' => '']], $result['pages']);
        self::assertTrue($result['truncated']);
        self::assertStringContainsString('no OCR', implode(' ', $result['notes']));
        self::assertStringContainsString('start_page 3', implode(' ', $result['notes']));
    }

    public function test_unsupported_compression_is_named(): void
    {
        $bytes = self::pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 5 0 R >>',
            5 => "<< /Length 3 /Filter /LZWDecode >>\nstream\nabc\nendstream",
        ]);

        $result = \wppilot_pdf_extract_text($bytes);

        self::assertIsArray($result);
        self::assertStringContainsString('LZWDecode', implode(' ', $result['notes']));
    }
}
