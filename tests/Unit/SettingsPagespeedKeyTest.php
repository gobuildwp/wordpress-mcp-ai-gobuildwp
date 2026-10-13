<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/kits/pagespeed/src/normalize.php';
require_once dirname(__DIR__, 2) . '/includes/kits/pagespeed/src/check.php';
require_once dirname(__DIR__, 2) . '/includes/admin/settings.php';

/**
 * The optional PageSpeed key on the Settings screen: saved into the pagespeed kit's own option,
 * never echoed back (a blank field keeps it), removable, and stripped of anything a Google key
 * cannot contain.
 */
final class SettingsPagespeedKeyTest extends TestCase
{
    protected function setUp(): void
    {
        \WPPilot_Test_State::$options = [];
    }

    public function testTheKeyIsSavedIntoTheKitsOptionAndABlankFieldKeepsIt(): void
    {
        self::assertSame('wppilot_kit_pagespeed_api_key', wppilot_settings_pagespeed_option());

        wppilot_settings_save_pagespeed([WPPILOT_SETTINGS_PAGESPEED_KEY_FIELD => " AIzaSyExample_Key-123\n"]);
        self::assertSame('AIzaSyExample_Key-123', \WPPilot_Test_State::$options['wppilot_kit_pagespeed_api_key']);

        wppilot_settings_save_pagespeed([WPPILOT_SETTINGS_PAGESPEED_KEY_FIELD => '']);
        self::assertSame('AIzaSyExample_Key-123', wppilot_settings_pagespeed_key());

        wppilot_settings_save_pagespeed([WPPILOT_SETTINGS_PAGESPEED_KEY_FIELD => 'ignored', WPPILOT_SETTINGS_PAGESPEED_KEY_FIELD . '_clear' => '1']);
        self::assertSame('', wppilot_settings_pagespeed_key());
    }
}
