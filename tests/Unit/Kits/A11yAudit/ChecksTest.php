<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\A11yAudit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPPilot\Kits\A11yAudit;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Hosts\StandaloneHost;

/**
 * The served-HTML checks, each against a fixture that fails it and one that passes it.
 *
 * Fixtures are whole pages built around one fragment, from a baseline that passes every check,
 * so a test that expects one rule and gets two has found a real interaction.
 */
final class ChecksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/media-doubles.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/hosts/standalone.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/a11y-audit/src/checks.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/a11y-audit/src/media.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/a11y-audit/src/abilities/update-image-alt.php';
    }

    public function testTheCleanBaselinePassesEveryCheck(): void
    {
        $audit = A11yAudit\audit_html(self::page(''));

        self::assertSame([], $audit['findings']);
        self::assertSame(100, $audit['score']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function cases(): array
    {
        return [
            // rule => [failing page, passing page, the rule]
            'lang missing' => [self::page('', lang: ''), self::page('', lang: 'en-GB'), 'html-lang-missing'],
            'lang invalid' => [self::page('', lang: 'english please'), self::page('', lang: 'pt-BR'), 'html-lang-invalid'],
            'title missing' => [self::page('', title: ' '), self::page('', title: 'Contact us'), 'document-title-missing'],
            'title only in an svg' => [self::page('<svg role="img" aria-label="x"><title>Logo</title></svg>', title: ''), self::page('<svg role="img" aria-label="x"><title>Logo</title></svg>'), 'document-title-missing'],
            'heading skipped' => [self::page('<h2>A</h2><h4>B</h4>'), self::page('<h2>A</h2><h3>B</h3><h2>C</h2>'), 'heading-skipped-level'],
            'heading empty' => [self::page('<h2> </h2>'), self::page('<h2><img src="/a.png" alt="Pricing"></h2>'), 'heading-empty'],
            'no h1' => [self::page('', h1: ''), self::page(''), 'heading-no-h1'],
            'img alt missing' => [self::page('<img src="/wp-content/uploads/cat.jpg">'), self::page('<img src="/wp-content/uploads/cat.jpg" alt="">'), 'image-alt-missing'],
            'img alt missing but presentational' => [self::page('<img src="/a.png">'), self::page('<img src="/a.png" role="presentation">'), 'image-alt-missing'],
            'img alt missing but aria-hidden' => [self::page('<img src="/a.png">'), self::page('<div aria-hidden="true"><img src="/a.png"></div>'), 'image-alt-missing'],
            'img alt missing inside noscript' => [self::page('<img src="/a.png">'), self::page('<noscript><img src="/a.png"></noscript>'), 'image-alt-missing'],
            'img alt is the file name' => [self::page('<img src="/u/sunset-beach-300x200.jpg" alt="sunset_beach">'), self::page('<img src="/u/sunset-beach-300x200.jpg" alt="Sunset over the bay">'), 'image-alt-filename'],
            'img alt is a camera name' => [self::page('<img src="/u/a.jpg" alt="IMG_0042">'), self::page('<img src="/u/a.jpg" alt="Our team at the 2026 retreat">'), 'image-alt-filename'],
            'img alt has an extension' => [self::page('<img src="/u/a.jpg" alt="hero.webp">'), self::page('<img src="/u/a.jpg" alt="Hero">'), 'image-alt-filename'],
            'input image alt' => [self::page('<form><input type="image" src="/go.png"></form>'), self::page('<form><input type="image" src="/go.png" alt="Search"></form>'), 'input-image-alt-missing'],
            'area alt' => [self::page('<map name="m"><area href="/a" shape="rect" coords="0,0,1,1"></map>'), self::page('<map name="m"><area href="/a" alt="Store" shape="rect" coords="0,0,1,1"></map>'), 'area-alt-missing'],
            'svg img name' => [self::page('<svg role="img"><path d="M0 0"/></svg>'), self::page('<svg role="img"><title>Rating: 4 stars</title><path d="M0 0"/></svg>'), 'svg-img-name-missing'],
            'label missing' => [self::page('<form><input type="email" name="e"></form>'), self::page('<form><label for="e">Email</label><input type="email" id="e" name="e"></form>'), 'form-label-missing'],
            'label by wrapping' => [self::page('<form><select name="s"><option>A</option></select></form>'), self::page('<form><label>Size <select name="s"><option>A</option></select></label></form>'), 'form-label-missing'],
            'label by aria' => [self::page('<form><textarea name="t"></textarea></form>'), self::page('<form><textarea name="t" aria-label="Message"></textarea></form>'), 'form-label-missing'],
            'hidden inputs need no label' => [self::page('<form><input type="text"></form>'), self::page('<form><input type="hidden" name="n"><input type="submit"></form>'), 'form-label-missing'],
            'link name' => [self::page('<a href="/cart"><i class="icon-cart"></i></a>'), self::page('<a href="/cart"><i class="icon-cart"></i><span class="sr-only">Cart</span></a>'), 'link-name-missing'],
            'link name from image alt' => [self::page('<a href="/"><img src="/logo.png" alt=""></a>'), self::page('<a href="/"><img src="/logo.png" alt="Acme home"></a>'), 'link-name-missing'],
            'link name ignores hidden text' => [self::page('<a href="/x"><span aria-hidden="true">→</span></a>'), self::page('<a href="/x" aria-label="Next"><span aria-hidden="true">→</span></a>'), 'link-name-missing'],
            'button name' => [self::page('<button><svg><path d="M0 0"/></svg></button>'), self::page('<button aria-label="Close"><svg><path d="M0 0"/></svg></button>'), 'button-name-missing'],
            'input button name' => [self::page('<form><input type="button"></form>'), self::page('<form><input type="button" value="Apply"></form>'), 'button-name-missing'],
            'role button name' => [self::page('<div role="button" tabindex="0"></div>'), self::page('<div role="button" tabindex="0">Menu</div>'), 'button-name-missing'],
            'duplicate id' => [self::page('<div id="x"></div><div id="x"></div>'), self::page('<div id="x"></div><div id="y"></div>'), 'duplicate-id'],
            'duplicate referenced id' => [self::page('<label for="q">Q</label><input id="q"><input id="q" aria-label="Also q">'), self::page('<label for="q">Q</label><input id="q"><input id="r" aria-label="R">'), 'duplicate-id-referenced'],
            'labelledby target' => [self::page('<div role="dialog" aria-labelledby="nope">Hi</div>'), self::page('<h2 id="t">Title</h2><div role="dialog" aria-labelledby="t">Hi</div>'), 'aria-labelledby-missing-target'],
            'describedby target' => [self::page('<button aria-describedby="help">Go</button>'), self::page('<button aria-describedby="help">Go</button><p id="help">Opens a form</p>'), 'aria-reference-missing-target'],
            'label for target' => [self::page('<label for="gone">Name</label><input aria-label="Name">'), self::page('<label for="n">Name</label><input id="n">'), 'label-for-missing-target'],
            'no main, no skip link' => [self::page('', main: false), self::page('<a href="#content">Skip to content</a><div id="content"></div>', main: false), 'bypass-missing'],
            'two mains' => [self::page('<main>Other</main>'), self::page('<main hidden>Other</main>'), 'landmark-main-multiple'],
            'positive tabindex' => [self::page('<a href="/a" tabindex="3">A</a>'), self::page('<a href="/a" tabindex="0">A</a><div tabindex="-1"></div>'), 'tabindex-positive'],
            'zoom locked' => [self::page('', viewport: 'width=device-width, user-scalable=no'), self::page('', viewport: 'width=device-width, initial-scale=1'), 'viewport-zoom-disabled'],
            'zoom capped' => [self::page('', viewport: 'width=device-width, maximum-scale=1.0'), self::page('', viewport: 'width=device-width, maximum-scale=5'), 'viewport-zoom-disabled'],
            'iframe title' => [self::page('<iframe src="https://www.youtube.com/embed/x"></iframe>'), self::page('<iframe src="https://www.youtube.com/embed/x" title="Product tour video"></iframe>'), 'iframe-title-missing'],
            'hidden iframe needs no title' => [self::page('<iframe src="/t"></iframe>'), self::page('<iframe src="/t" style="display:none"></iframe>'), 'iframe-title-missing'],
            'table headers' => [self::page(self::table('td')), self::page(self::table('th')), 'table-headers-missing'],
            'layout table' => [self::page(self::table('td')), self::page(str_replace('<table>', '<table role="presentation">', self::table('td'))), 'table-headers-missing'],
            'one-column table' => [self::page(self::table('td')), self::page('<table><tr><td>a</td></tr><tr><td>b</td></tr></table>'), 'table-headers-missing'],
        ];
    }

    #[DataProvider('cases')]
    public function testEachCheckFailsAndPasses(string $failing, string $passing, string $rule): void
    {
        $failed = self::rules($failing);
        self::assertArrayHasKey($rule, $failed, 'expected ' . $rule . ', got ' . implode(', ', array_keys($failed)));

        $passed = self::rules($passing);
        self::assertArrayNotHasKey($rule, $passed);
    }

    public function testEveryRuleMapsToAWcag22CriterionAndLevel(): void
    {
        foreach (A11yAudit\RULES as $rule => [$criterion, $severity]) {
            self::assertArrayHasKey($criterion, A11yAudit\WCAG, $rule);
            self::assertContains(A11yAudit\WCAG[$criterion][1], ['A', 'AA'], $rule);
            self::assertArrayHasKey($severity, A11yAudit\SEVERITY_WEIGHT, $rule);
        }
    }

    public function testEveryFindingCarriesItsCriterionLevelAndUnderstandingLink(): void
    {
        $html = self::page(
            '<img src="/a.jpg"><h4></h4><input type="text"><a href="/x"></a><button></button><div id="d"></div><div id="d"></div>'
            . '<div aria-labelledby="none"></div><span tabindex="2"></span><iframe src="/f"></iframe>' . self::table('td'),
            lang: '',
            title: '',
            h1: '',
            main: false,
            viewport: 'user-scalable=0',
        );
        $findings = A11yAudit\audit_html($html)['findings'];

        self::assertGreaterThanOrEqual(12, count($findings));
        foreach ($findings as $finding) {
            self::assertSame('2.2', $finding['wcag']['version']);
            self::assertMatchesRegularExpression('/^\d\.\d\.\d+$/', $finding['wcag']['criterion']);
            self::assertContains($finding['wcag']['level'], ['A', 'AA']);
            self::assertStringStartsWith('https://www.w3.org/WAI/WCAG22/Understanding/', $finding['wcag']['url']);
            self::assertNotSame('', $finding['message']);
            self::assertNotSame('', $finding['fix']);
            self::assertGreaterThanOrEqual(1, $finding['count']);
        }
    }

    public function testFindingsCountEveryInstanceButKeepTenExamples(): void
    {
        $findings = self::rules(self::page(str_repeat('<img src="/a.jpg">', 14)));

        self::assertSame(14, $findings['image-alt-missing']['count']);
        self::assertCount(A11yAudit\MAX_EXAMPLES, $findings['image-alt-missing']['examples']);
        self::assertSame('img', $findings['image-alt-missing']['examples'][0]['selector']);
        self::assertSame('<img src="/a.jpg">', $findings['image-alt-missing']['examples'][0]['html']);
    }

    public function testImageFindingsNameTheAttachmentsToFix(): void
    {
        $findings = self::rules(self::page('<img src="/a.jpg" class="aligncenter wp-image-42"><img src="/b.jpg" class="wp-image-7 size-full"><img src="/c.jpg" class="wp-image-42">'));

        self::assertSame([42, 7], $findings['image-alt-missing']['attachment_ids']);
        self::assertSame(42, $findings['image-alt-missing']['examples'][0]['attachment_id']);
    }

    public function testTheFixAbilityIsNamedOnlyWhenThisSiteHasIt(): void
    {
        // The kit's own ability, under whatever prefix this copy of the kit registers it.
        $prefix = strstr('wppilot/update-image-alt', '/', true);
        Runtime\host(new StandaloneHost((string) $prefix));
        $present = self::rules(self::page('<img src="/a.jpg">'));
        self::assertSame('wppilot/update-image-alt', $present['image-alt-missing']['fix_ability']);
        // A theme problem has no ability that fixes it, and none is named.
        self::assertNull(self::rules(self::page('', lang: ''))['html-lang-missing']['fix_ability']);

        Runtime\host(new StandaloneHost('elsewhere'));
        self::assertNull(self::rules(self::page('<img src="/a.jpg">'))['image-alt-missing']['fix_ability']);
    }

    public function testNotCheckedNamesContrastFocusTargetSizeAndScriptContent(): void
    {
        $not_checked = A11yAudit\audit_html(self::page(''))['not_checked'];
        $checks = array_column($not_checked, 'check');

        foreach (['color-contrast', 'focus-visible', 'target-size', 'javascript-rendered-content'] as $expected) {
            self::assertContains($expected, $checks);
        }
        $by_check = array_column($not_checked, null, 'check');
        self::assertSame('1.4.3', $by_check['color-contrast']['wcag']['criterion']);
        self::assertSame('2.5.8', $by_check['target-size']['wcag']['criterion']);
        self::assertSame('AA', $by_check['focus-visible']['wcag']['level']);
    }

    public function testScoreFallsWithSeverityAndCountsARepeatedFailureOnlyUpToAPoint(): void
    {
        $one = A11yAudit\audit_html(self::page('<img src="/a.jpg">'))['score'];
        $five = A11yAudit\audit_html(self::page(str_repeat('<img src="/a.jpg">', 5)))['score'];
        $fifty = A11yAudit\audit_html(self::page(str_repeat('<img src="/a.jpg">', 50)))['score'];
        $minor = A11yAudit\audit_html(self::page('<div id="x"></div><div id="x"></div>'))['score'];

        self::assertSame(90, $one);
        self::assertSame(80, $five);
        self::assertSame($five, $fifty);
        self::assertSame(99, $minor);
    }

    public function testSummaryCountsRulesBySeverityAndInstances(): void
    {
        $summary = A11yAudit\audit_html(self::page(str_repeat('<img src="/a.jpg">', 3) . '<div id="x"></div><div id="x"></div>'))['summary'];

        self::assertSame(1, $summary['critical']);
        self::assertSame(1, $summary['minor']);
        self::assertSame(2, $summary['rules_failed']);
        self::assertSame(4, $summary['instances']);
    }

    public function testMarkupThatCannotBeParsedStillReportsTheDocumentLevelRules(): void
    {
        $rules = self::rules('');

        self::assertArrayHasKey('html-lang-missing', $rules);
        self::assertArrayHasKey('document-title-missing', $rules);
    }

    public function testAltFilenameHeuristic(): void
    {
        self::assertTrue(A11yAudit\alt_is_filename('DSC_0199', ''));
        self::assertTrue(A11yAudit\alt_is_filename('team photo', '/wp-content/uploads/team-photo-scaled.jpg'));
        self::assertTrue(A11yAudit\alt_is_filename('Screenshot 2026-09-01', ''));
        self::assertFalse(A11yAudit\alt_is_filename('', '/a.jpg'));
        self::assertFalse(A11yAudit\alt_is_filename('Team photo at the office', '/wp-content/uploads/team-photo.jpg'));
    }

    /** @return array<string, array<string, mixed>> */
    private static function rules(string $html): array
    {
        return array_column(A11yAudit\audit_html($html)['findings'], null, 'rule');
    }

    private static function table(string $header_cell): string
    {
        return "<table><tr><{$header_cell}>Plan</{$header_cell}><{$header_cell}>Price</{$header_cell}></tr><tr><td>Basic</td><td>$5</td></tr></table>";
    }

    private static function page(
        string $body,
        string $lang = 'en',
        string $title = 'A page',
        string $h1 = 'Welcome',
        bool $main = true,
        string $viewport = 'width=device-width, initial-scale=1',
    ): string {
        $lang_attr = $lang === '' ? '' : ' lang="' . $lang . '"';
        $heading = $h1 === '' ? '' : "<h1>{$h1}</h1>";
        $content = $heading . $body;
        return "<!DOCTYPE html><html{$lang_attr}><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"{$viewport}\"><title>{$title}</title></head>"
            . '<body>' . ($main ? "<main>{$content}</main>" : $content) . '</body></html>';
    }
}
