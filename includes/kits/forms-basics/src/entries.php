<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\FormsBasics;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Paging and date filters every list-entries ability shares.
 *
 * Entries are returned with their (redacted) answers, so a page is kept small: 20 by default and
 * at most 100, where GoBuildWP Pro's compact listings, which carry no answers, go further.
 */

const ENTRIES_DEFAULT_LIMIT = 20;

const ENTRIES_MAX_LIMIT = 100;

function entries_limit(mixed $limit): int
{
    return is_numeric($limit) ? max(1, min(ENTRIES_MAX_LIMIT, (int) $limit)) : ENTRIES_DEFAULT_LIMIT;
}

function entries_offset(mixed $offset): int
{
    return is_numeric($offset) ? max(0, (int) $offset) : 0;
}

/**
 * A date bound as `Y-m-d H:i:s`, a bare date widened to the whole day, or '' when absent.
 *
 * A malformed date is an error rather than being ignored: dropping the filter silently would list
 * every entry the form ever received when the user asked for last week's.
 */
function date_bound(mixed $raw, bool $end): string|WP_Error
{
    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && \DateTime::createFromFormat('!Y-m-d', $raw) !== false) {
        return $raw . ($end ? ' 23:59:59' : ' 00:00:00');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $raw) === 1) {
        $raw = str_replace('T', ' ', $raw);
        return strlen($raw) === 16 ? $raw . ':00' : $raw;
    }
    return new WP_Error('forms_bad_date', sprintf(
        /* translators: %s: the rejected date value */
        __('Unrecognised date "%s". Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.', domain: 'wppilot'),
        $raw,
    ), ['status' => 400]);
}
