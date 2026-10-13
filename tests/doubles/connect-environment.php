<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The one environment.php answer the Connect-screen builders read.
 *
 * environment.php itself cannot load in the suite (the WordPress doubles already
 * declare wppilot_is_enabled()), and the builders only ask whether to add the
 * self-signed-certificate bypass. The tests describe a site with a trusted
 * certificate, so the answer is no.
 */
if (!function_exists('wppilot_likely_self_signed_https')) {
    function wppilot_likely_self_signed_https(): bool
    {
        return false;
    }
}
