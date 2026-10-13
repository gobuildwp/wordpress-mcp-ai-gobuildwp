<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Mcp\Apps;
use WPPilot_Test_State;

use function WPPilot\Mcp\decorate_tool;
use function WPPilot\Mcp\dispatch_extensions;

require_once dirname(__DIR__, 2) . '/includes/mcp/tasks.php';
require_once dirname(__DIR__, 2) . '/includes/mcp/apps.php';
require_once dirname(__DIR__, 2) . '/includes/mcp/extensions.php';

/**
 * The MCP Apps preview card: its link from wppilot/preview-ability, and the ui:// resource.
 */
final class McpAppsTest extends TestCase
{
    public function testPreviewAbilityLinksTheCard(): void
    {
        // The surface tests assert on every registration in the boot snapshot, so this one is
        // read and then taken back out.
        $saved = [WPPilot_Test_State::$registrations, WPPilot_Test_State::$registered_abilities];
        require_once dirname(__DIR__, 2) . '/includes/abilities/preview.php';
        $registration = null;
        foreach (WPPilot_Test_State::$registrations as $entry) {
            if ($entry['name'] === 'wppilot/preview-ability') {
                $registration = $entry;
            }
        }
        [WPPilot_Test_State::$registrations, WPPilot_Test_State::$registered_abilities] = $saved;
        self::assertNotNull($registration, 'preview.php registered wppilot/preview-ability');

        $meta = $registration['args']['meta'];
        self::assertTrue($meta['mcp']['public']);
        self::assertSame(Apps\preview_tool_meta(), $meta['mcp']['_meta']);
        self::assertSame('ui://wppilot/preview-card', $meta['mcp']['_meta']['ui']['resourceUri']);

        $tool = decorate_tool(['name' => 'wppilot_preview_ability'], $meta);
        self::assertSame(['resourceUri' => Apps\PREVIEW_CARD_URI], $tool['_meta']['ui']);
    }

    public function testToolsWithoutMcpMetaGetNoMeta(): void
    {
        self::assertSame(['name' => 'x'], decorate_tool(['name' => 'x'], ['mcp' => ['public' => true, '_meta' => []]]));
    }

    public function testResourcesListIncludesTheCard(): void
    {
        $outcome = dispatch_extensions('resources/list', [], 3);
        self::assertIsArray($outcome);
        $resources = $outcome['body']['result']['resources'];
        $card = array_values(array_filter($resources, static fn(array $r): bool => $r['uri'] === Apps\PREVIEW_CARD_URI));

        self::assertCount(1, $card);
        self::assertSame('text/html;profile=mcp-app', $card[0]['mimeType']);
        self::assertSame(['connectDomains' => [], 'resourceDomains' => []], $card[0]['_meta']['ui']['csp']);
        self::assertSame('private', $outcome['body']['result']['cacheScope']);
    }

    public function testReadServesTheCardAndLeavesOtherSchemesAlone(): void
    {
        $outcome = dispatch_extensions('resources/read', ['uri' => Apps\PREVIEW_CARD_URI], 4);
        $contents = $outcome['body']['result']['contents'];

        self::assertCount(1, $contents);
        self::assertSame(Apps\PREVIEW_CARD_URI, $contents[0]['uri']);
        self::assertSame(Apps\MIME_TYPE, $contents[0]['mimeType']);
        self::assertStringStartsWith('<!DOCTYPE html>', $contents[0]['text']);

        self::assertNull(dispatch_extensions('resources/read', ['uri' => 'skill://search-replace/SKILL.md'], 4));
        $unknown = dispatch_extensions('resources/read', ['uri' => 'ui://wppilot/nope'], 4);
        self::assertSame(-32602, $unknown['body']['error']['code']);
    }

    /**
     * Nothing the card loads comes from anywhere else, and no script or style is external.
     */
    public function testTheCardIsSelfContained(): void
    {
        $html = Apps\preview_card_html('Example');

        self::assertDoesNotMatchRegularExpression('/<script[^>]+src=/i', $html);
        self::assertDoesNotMatchRegularExpression('/<link\b/i', $html);
        self::assertSame(1, preg_match('/<style>(.*?)<\/style>/s', $html, $style));
        self::assertDoesNotMatchRegularExpression('/@import|url\(/i', $style[1]);
        self::assertStringNotContainsString('https://', $html);
        self::assertSame(2, substr_count($html, '</script>'));
    }

    /**
     * Preview data reaches the page only as text: the script never parses a string as HTML.
     */
    public function testTheCardNeverTurnsDataIntoMarkup(): void
    {
        $html = Apps\preview_card_html('Example');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute(\'on'] as $sink) {
            self::assertStringNotContainsString($sink, $html, $sink);
        }
        self::assertStringContainsString('textContent', $html);
        // Replies are accepted only from the host frame.
        self::assertStringContainsString('event.source !== window.parent', $html);
        // Links are opened through the host, and only http(s) ones.
        self::assertStringContainsString("/^https?:\\/\\//i.test(url)", $html);
    }

    public function testAHostileSiteNameIsEscaped(): void
    {
        $hostile = '</script><script>alert(1)</script><b onmouseover="x">&amp;\'';

        $html = Apps\preview_card_html($hostile);

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<b onmouseover', $html);
        self::assertStringContainsString(esc_html($hostile), $html);
        self::assertSame(2, substr_count($html, '</script>'));
    }

    public function testTheConfigBlockCannotCloseItsScript(): void
    {
        $json = Apps\json_for_script(['x' => '</script><!-- "\'&']);

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertStringNotContainsString('&', $json);
        self::assertStringNotContainsString("'", $json);
        self::assertSame(['x' => '</script><!-- "\'&'], json_decode($json, true));
    }

    public function testTheCardCallsTheApplyToolByItsMcpName(): void
    {
        $html = Apps\preview_card_html('Example');

        self::assertStringContainsString('"applyTool":"wppilot_apply_preview"', $html);
        self::assertStringContainsString("'ui/initialize'", $html);
        self::assertStringContainsString("'ui/notifications/tool-result'", $html);
        self::assertStringContainsString("request('tools/call'", $html);
    }
}
