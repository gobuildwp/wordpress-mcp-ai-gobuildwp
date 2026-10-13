<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * A Polylang site as WordPress sees it: Polylang's own API, plus the WPML
 * compatibility layer Polylang ships, which defines icl_get_languages().
 */

if (!function_exists('pll_languages_list')) {
    /** @return list<string> */
    function pll_languages_list(): array
    {
        return ['lv', 'ru'];
    }
}

if (!function_exists('icl_get_languages')) {
    /** @return array<string, array{language_code: string}> */
    function icl_get_languages(string $args = ''): array
    {
        return ['lv' => ['language_code' => 'lv'], 'ru' => ['language_code' => 'ru']];
    }
}
