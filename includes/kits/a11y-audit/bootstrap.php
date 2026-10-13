<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\A11yAudit;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/src/checks.php';
require_once __DIR__ . '/src/audit.php';
require_once __DIR__ . '/src/media.php';

/**
 * a11y-audit: a served-HTML accessibility audit, a media-library alt-text scan, a preview of an
 * image the model can see, and bulk alt-text writes with per-image undo.
 *
 * Alt-text undo is the runtime's post-partial strategy, which the host registers, so the kit
 * registers nothing at boot.
 */
return [
    'ability_files' => [
        __DIR__ . '/src/abilities/audit-accessibility.php',
        __DIR__ . '/src/abilities/audit-media-alt.php',
        __DIR__ . '/src/abilities/get-media-image.php',
        __DIR__ . '/src/abilities/update-image-alt.php',
    ],
];
