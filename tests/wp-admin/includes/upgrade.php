<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Stand-in for wp-admin/includes/upgrade.php.
 *
 * The schema installers `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`
 * for dbDelta(), which tests/doubles/sqlite-wpdb.php declares. Like plugin.php
 * beside it, this file only has to exist.
 */
