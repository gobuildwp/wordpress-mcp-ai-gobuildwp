<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Stand-in for wp-admin/includes/plugin.php.
 *
 * ABSPATH points at tests/ in the unit suite, so the `require_once ABSPATH .
 * 'wp-admin/includes/plugin.php'` calls inside the extension abilities resolve
 * here. The functions those calls are reaching for (get_plugins,
 * is_plugin_active, plugin_basename) are declared in tests/doubles/wordpress.php,
 * which loads first, so this file only has to exist.
 */
