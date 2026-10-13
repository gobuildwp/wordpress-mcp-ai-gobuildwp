<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ContentAudit;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What to do about each kind of finding, and which abilities do it.
 *
 * The audit only reports. A fix names the ability that makes it, with whether that ability is
 * registered on this site: a redirect ability exists only with Pro and the matching redirect or
 * SEO plugin, and an agent told to call one that is not there wastes a turn finding out.
 *
 * Names are kept bare here and qualified with the host's ability namespace when reported. The
 * host's own abilities (search and replace, post updates, Pro's redirects) are not the kit's, so
 * a copy of this kit in another plugin reports them as unavailable rather than naming abilities
 * its plugin never registers.
 */
final class Fixes
{
    private const REDIRECTS = [
        'redirection-create-redirect',
        'rank-math-create-redirect',
        'yoast-create-redirect',
        'seopress-create-redirect',
        'aioseo-create-redirect',
    ];

    /** Abilities Pro ships; the rest are Free's. */
    private const PRO = [
        'redirection-create-redirect', 'rank-math-create-redirect', 'yoast-create-redirect',
        'seopress-create-redirect', 'aioseo-create-redirect', 'seo-bulk-update-meta',
        'yoast-edit-post-seo', 'rank-math-edit-post-seo', 'seopress-edit-post-seo',
        'aioseo-edit-post-seo', 'aioseo-edit-post-schema', 'seopress-edit-post-schema',
        'tsf-update-post-seo', 'slim-seo-update-post-seo', 'smartcrawl-update-post-seo',
    ];

    /**
     * SEO plugins recognised by the meta keys they store per post, keyed by the slug the host's
     * SEO provider registry (GoBuildWP Pro) uses for the same plugin.
     *
     * Where the host offers that registry, the audit reads through it instead (see
     * Source::seo_providers()), and these keys only label the evidence. Without it, detection is
     * by these keys only: no plugin API is called, so a plugin keeping its data in its own table
     * (AIOSEO does, mirroring some of it to these keys) can be under-reported. A key written
     * `base[field]` is one field of the array Slim SEO stores under `base`.
     */
    public const SEO_SOURCES = [
        'yoast' => ['label' => 'Yoast SEO', 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'ability' => 'yoast-edit-post-seo'],
        'rank-math' => ['label' => 'Rank Math', 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'ability' => 'rank-math-edit-post-seo'],
        'seopress' => ['label' => 'SEOPress', 'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc', 'ability' => 'seopress-edit-post-seo'],
        'aioseo' => ['label' => 'All in One SEO', 'title' => '_aioseo_title', 'description' => '_aioseo_description', 'ability' => 'aioseo-edit-post-seo'],
        'tsf' => ['label' => 'The SEO Framework', 'title' => '_genesis_title', 'description' => '_genesis_description', 'ability' => 'tsf-update-post-seo'],
        'slim-seo' => ['label' => 'Slim SEO', 'title' => 'slim_seo[title]', 'description' => 'slim_seo[description]', 'ability' => 'slim-seo-update-post-seo'],
        'smartcrawl' => ['label' => 'SmartCrawl', 'title' => '_wds_title', 'description' => '_wds_metadesc', 'ability' => 'smartcrawl-update-post-seo'],
    ];

    /** @var array<string, bool> */
    private array $available = [];

    public function __construct(private Source $source)
    {
    }

    /**
     * @return array{summary: string, abilities: list<string>}
     */
    public static function catalog(string $type): array
    {
        $replace = ['search-replace-preview', 'search-replace-apply'];
        $map = [
            'broken_internal_link' => ['Point the link at a live page across content with search and replace, or redirect the dead URL to its replacement.', array_merge($replace, self::REDIRECTS)],
            'internal_link_to_unpublished' => ['Publish the target, or change or remove the link: visitors following it get a 404.', array_merge(['update-post'], $replace)],
            'broken_file_link' => ['Upload the missing file again, or point the link or image at a file that exists.', $replace],
            'redirected_internal_link' => ['Replace the link with the URL it redirects to, so visitors and crawlers skip the hop.', $replace],
            'internal_link_error' => ['The target answered with an error; check that page, then fix or remove the link.', ['update-post']],
            'broken_external_link' => ['Update the link to where the page moved, or remove it.', array_merge($replace, ['update-post'])],
            'external_link_unreachable' => ['The site did not answer; check it by hand before changing anything, as it may be temporary.', ['update-post']],
            'orphan_content' => ['Link to it from related published content or a menu, or retire it with a redirect to a better page.', array_merge(['update-post'], self::REDIRECTS)],
            'thin_content' => ['Expand it, or merge it into a stronger page and redirect the old URL.', array_merge(['update-post'], self::REDIRECTS)],
            'missing_meta_description' => ['Write a meta description for this post in the SEO plugin that stores them.', ['seo-bulk-update-meta']],
            'missing_meta_title' => ['Optional: a post-specific SEO title. Until one is set, the SEO plugin\'s title template applies.', ['seo-bulk-update-meta']],
            'seo_meta_source_unknown' => ['No SEO plugin meta was found, so meta descriptions were not checked. Set one up if search snippets matter for this site.', []],
            'schema_invalid_json' => ['Fix the JSON-LD where it is printed: the SEO plugin\'s schema settings, the theme, or the block or snippet that outputs it.', ['aioseo-edit-post-schema', 'seopress-edit-post-schema']],
            'schema_missing_context' => ['Add "@context": "https://schema.org" where the JSON-LD is printed.', ['aioseo-edit-post-schema', 'seopress-edit-post-schema']],
            'schema_missing_type' => ['Give every JSON-LD entity an @type where it is printed.', ['aioseo-edit-post-schema', 'seopress-edit-post-schema']],
            'schema_rich_result_ineligible' => ['Nothing is broken. Keep or remove the markup as you prefer, but do not expect FAQ or HowTo rich results from it.', []],
            'page_error' => ['This published page does not load for visitors; fix that before anything else on it.', []],
            'page_not_fetched' => ['The server could not fetch its own page (loopback requests may be blocked), so its structured data was not checked.', []],
        ];
        [$summary, $abilities] = $map[$type] ?? ['', []];
        return ['summary' => $summary, 'abilities' => $abilities];
    }

    /**
     * The fix for one finding: its summary and the abilities for it that are registered here.
     *
     * @param list<string> $extra Bare ability names that fit this finding in particular, listed first.
     * @return array{summary: string, abilities: list<string>}
     */
    public function suggest(string $type, array $extra = []): array
    {
        $fix = self::catalog($type);
        $names = array_map([$this, 'qualify'], array_values(array_unique(array_merge($extra, $fix['abilities']))));
        return [
            'summary' => $fix['summary'],
            'abilities' => array_values(array_filter($names, [$this, 'is_available'])),
        ];
    }

    /**
     * The catalog entry for each type seen, with every ability and whether it is available, so
     * the agent can tell the person what Pro or a plugin would add.
     *
     * @param list<string> $types
     * @return array<string, array{summary: string, abilities: list<array{name: string, tier: string, available: bool}>}>
     */
    public function describe(array $types): array
    {
        $described = [];
        foreach ($types as $type) {
            $fix = self::catalog($type);
            $described[$type] = [
                'summary' => $fix['summary'],
                'abilities' => array_map(
                    fn(string $name): array => [
                        'name' => $this->qualify($name),
                        'tier' => in_array($name, self::PRO, true) ? 'pro' : 'free',
                        'available' => $this->is_available($this->qualify($name)),
                    ],
                    $fix['abilities'],
                ),
            ];
        }
        return $described;
    }

    public function qualify(string $name): string
    {
        return $this->source->ability_namespace() . '/' . $name;
    }

    public function is_available(string $name): bool
    {
        return $this->available[$name] ??= $this->source->has_ability($name);
    }
}
