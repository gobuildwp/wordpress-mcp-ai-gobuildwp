<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;

use function WPPilot\Abilities\WordPress\wordpress_install_source;
use function WPPilot\OAuth\Consent\consent_grant_details;

/**
 * What differs between the GitHub edition (this source tree) and the WordPress.org edition
 * the release packager builds from it.
 *
 * The constant that names the edition cannot be redefined inside one PHP process, so each
 * branch takes the edition as an optional argument and both are asserted here.
 */
final class EditionBranchesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/oauth/consent.php';
    }

    public function testTheSourceTreeIsTheGitHubEdition(): void
    {
        self::assertFalse(wppilot_is_wordpress_org_edition());
        self::assertSame('github-compat-free', WPPILOT_DISTRIBUTION_PROFILE);
    }

    public function testTheDirectoryEditionRefusesAPackageUrlBeforeFetchingAnything(): void
    {
        foreach ([false, true] as $is_theme) {
            $zip = wordpress_install_source(['zip_url' => 'https://example.test/x.zip'], $is_theme, directory_only: true);
            self::assertInstanceOf(WP_Error::class, $zip);
            self::assertSame('wppilot_install_source_directory_only', $zip->get_error_code());

            $both = wordpress_install_source(['slug' => 'akismet', 'zip_url' => 'https://example.test/x.zip'], $is_theme, directory_only: true);
            self::assertInstanceOf(WP_Error::class, $both);
            self::assertSame('wppilot_install_source_directory_only', $both->get_error_code());

            $neither = wordpress_install_source([], $is_theme, directory_only: true);
            self::assertInstanceOf(WP_Error::class, $neither);
            self::assertSame('wppilot_install_source_directory_only', $neither->get_error_code());
        }
    }

    public function testTheGitHubEditionKeepsItsExactlyOneSourceRule(): void
    {
        $neither = wordpress_install_source([], false, directory_only: false);
        self::assertInstanceOf(WP_Error::class, $neither);
        self::assertSame('wppilot_install_source_ambiguous', $neither->get_error_code());

        $both = wordpress_install_source(['slug' => 'akismet', 'zip_url' => 'https://example.test/x.zip'], false, directory_only: false);
        self::assertInstanceOf(WP_Error::class, $both);
        self::assertSame('wppilot_install_source_ambiguous', $both->get_error_code());

        // The default is the running edition, which here is the GitHub one.
        $default = wordpress_install_source([], false);
        self::assertSame('wppilot_install_source_ambiguous', $default->get_error_code());
    }

    public function testProfileDescriptionsNameOnlyWhatTheEditionHas(): void
    {
        $github = wppilot_safety_profiles(false);
        self::assertStringContainsString('PHP execution', $github['developer']['description']);
        self::assertStringContainsString('WP-CLI', $github['production']['description']);
        self::assertSame($github, wppilot_safety_profiles(), 'the source tree describes the GitHub edition');

        $directory = wppilot_safety_profiles(true);
        foreach ($directory as $profile) {
            self::assertDoesNotMatchRegularExpression('/PHP execution|WP-CLI|filesystem|administrator access|code execution/i', $profile['description']);
        }
        self::assertStringContainsString('plugins and themes from WordPress.org', $directory['developer']['description']);
        self::assertSame(array_keys($github), array_keys($directory));
        self::assertSame(array_column($github, 'label'), array_column($directory, 'label'));
    }

    public function testConsentRisksNameOnlyWhatTheEditionHas(): void
    {
        $github = consent_grant_details('full', false)['risks'];
        self::assertContains('Execute PHP and WP-CLI.', $github);
        self::assertSame($github, consent_grant_details('full')['risks']);

        $directory = consent_grant_details('full', true)['risks'];
        self::assertNotEmpty($directory);
        foreach ($directory as $risk) {
            self::assertDoesNotMatchRegularExpression('/PHP|WP-CLI|server files|temporary administrator/', $risk);
        }
    }
}
