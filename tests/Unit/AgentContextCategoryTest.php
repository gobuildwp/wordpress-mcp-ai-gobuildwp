<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * wppilot/agent-context must register on every edition.
 *
 * It was filed under `code-execution`, a category the WordPress.org edition never registers, so
 * on that edition it failed to register - a PHP notice on every request and no agent-context tool.
 * The edition is a constant fixed for the whole run, so this reads the two source files rather
 * than registering under both editions.
 */
final class AgentContextCategoryTest extends TestCase
{
    public function testAgentContextUsesACategoryEveryEditionRegisters(): void
    {
        $root = dirname(__DIR__, 2) . '/includes/abilities/';
        $ability = (string) file_get_contents($root . 'agent-context.php');
        self::assertSame(1, preg_match("/'category'\\s*=>\\s*'([a-z0-9-]+)'/", $ability, $match));
        $category = $match[1];

        $always = self::categoriesRegisteredOnEveryEdition((string) file_get_contents($root . 'bootstrap.php'));

        self::assertContains($category, $always, 'agent-context category ' . $category . ' is not registered on the WordPress.org edition');
        self::assertNotSame('code-execution', $category, 'a read-only guidance ability is not code execution');
    }

    /**
     * The categories wppilot_register_ability_categories() registers outside its
     * `if (!wppilot_is_wordpress_org_edition())` block.
     *
     * @return list<string>
     */
    private static function categoriesRegisteredOnEveryEdition(string $source): array
    {
        $start = strpos($source, 'function wppilot_register_ability_categories()');
        self::assertNotFalse($start);
        $body = self::block($source, (int) strpos($source, '{', $start));

        $guard = strpos($body, 'if (!wppilot_is_wordpress_org_edition())');
        self::assertNotFalse($guard, 'the directory-edition guard moved; update this test');
        $guarded = self::block($body, (int) strpos($body, '{', $guard));
        $unguarded = str_replace($guarded, '', $body);

        preg_match_all("/wp_register_ability_category\\('([a-z0-9-]+)'/", $unguarded, $matches);
        $categories = $matches[1];
        // The WordPress category is registered through its own helper.
        if (str_contains($unguarded, 'wppilot_register_wordpress_ability_category()')) {
            $categories[] = 'wordpress';
        }

        return $categories;
    }

    /** The brace-delimited block opening at $open, braces included. */
    private static function block(string $source, int $open): string
    {
        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        self::fail('unbalanced braces');
    }
}
