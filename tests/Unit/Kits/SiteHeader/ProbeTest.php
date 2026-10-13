<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SiteHeader;

use PHPUnit\Framework\TestCase;
use WPPilot\Kits\SiteHeader;

/**
 * site-header: the probe URL's token turns the in-browser probe on for the user it was issued to,
 * for 15 minutes, and for nothing else.
 */
final class ProbeTest extends TestCase
{
    private const KEY = 'probe-test-key';

    private const NOW = 1_800_000_000;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/site-header/src/probe.php';
    }

    public function test_a_token_names_the_user_it_was_issued_to_until_it_expires(): void
    {
        $token = SiteHeader\probe_token(7, self::NOW + SiteHeader\PROBE_TTL, self::KEY);

        $this->assertSame(7, SiteHeader\probe_token_user($token, self::NOW, self::KEY));
        $this->assertSame(7, SiteHeader\probe_token_user($token, self::NOW + SiteHeader\PROBE_TTL, self::KEY));
        $this->assertSame(0, SiteHeader\probe_token_user($token, self::NOW + SiteHeader\PROBE_TTL + 1, self::KEY), 'expired');
    }

    public function test_an_altered_forged_or_long_lived_token_is_refused(): void
    {
        $token = SiteHeader\probe_token(7, self::NOW + 600, self::KEY);
        [, $expires, $mac] = explode('.', $token);

        $this->assertSame(0, SiteHeader\probe_token_user('1.' . $expires . '.' . $mac, self::NOW, self::KEY), 'another user');
        $this->assertSame(0, SiteHeader\probe_token_user('7.' . ((int) $expires + 600) . '.' . $mac, self::NOW, self::KEY), 'a later expiry');
        $this->assertSame(0, SiteHeader\probe_token_user($token, self::NOW, 'another-key'), 'another site');
        // Signed correctly but valid for a day: only a short-lived token is accepted.
        $this->assertSame(0, SiteHeader\probe_token_user(SiteHeader\probe_token(7, self::NOW + 86400, self::KEY), self::NOW, self::KEY));
        foreach (['1', '', '0.' . $expires . '.' . $mac, $token . "\n", $token . 'a', strtoupper($token), '7.' . $expires . '.' . substr($mac, 0, 10)] as $bad) {
            $this->assertSame(0, SiteHeader\probe_token_user($bad, self::NOW, self::KEY), var_export($bad, true));
        }
    }

    public function test_a_wordpress_style_nonce_under_the_same_salt_is_not_a_probe_token(): void
    {
        // wp_create_nonce(): ten characters of an md5 HMAC. Nothing of that shape passes, and the
        // probe's own HMAC is over a message no nonce is made from.
        $nonce = substr(hash_hmac('md5', '123|wppilot-kit-header-probe|7|session', self::KEY), -12, 10);

        $this->assertSame(0, SiteHeader\probe_token_user($nonce, self::NOW, self::KEY));
        $this->assertSame(0, SiteHeader\probe_token_user('7.' . (self::NOW + 600) . '.' . $nonce, self::NOW, self::KEY));
    }
}
