<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/** Runner job kinds. Kept apart from the engine so bootstrap.php stays a few lines on every request. */
const KIND_FILES = 'security-integrity-files';
const KIND_DB = 'security-integrity-db';
