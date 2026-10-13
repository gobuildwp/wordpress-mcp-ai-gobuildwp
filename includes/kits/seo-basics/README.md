# seo-basics

This kit reads and sets one post's SEO title, meta description and robots (index and follow).
It works in whichever of seven SEO plugins the site runs, and every write has an undo.

| Plugin | Abilities | Stored in | Undo |
| --- | --- | --- | --- |
| Yoast SEO | `wppilot/yoast-get-post-seo`, `wppilot/yoast-edit-post-seo` | `_yoast_wpseo_*` post meta, through `WPSEO_Meta` | `kits/post-partial`, keys written |
| Rank Math | `wppilot/rank-math-get-post-seo`, `wppilot/rank-math-edit-post-seo` | `rank_math_title`, `rank_math_description`, `rank_math_robots` (token array) | `kits/post-partial`, keys written |
| All in One SEO | `wppilot/aioseo-get-post-seo`, `wppilot/aioseo-edit-post-seo` | its `aioseo_posts` table, through its `Post` model | `kits/seo-basics-aioseo`, columns changed |
| SEOPress | `wppilot/seopress-get-post-seo`, `wppilot/seopress-edit-post-seo` | `_seopress_titles_*`, `_seopress_robots_*` (absent row = default) | `kits/post-partial`, keys written |
| The SEO Framework | `wppilot/tsf-get-post-seo`, `wppilot/tsf-update-post-seo` | `_genesis_*` post meta, through TSF's `save_meta()` | `kits/post-partial`, all TSF keys (TSF rewrites them all) |
| Slim SEO | `wppilot/slim-seo-get-post-seo`, `wppilot/slim-seo-update-post-seo` | the `slim_seo` array row | `kits/post-partial`, that row |
| SmartCrawl | `wppilot/smartcrawl-get-post-seo`, `wppilot/smartcrawl-update-post-seo` | `_wds_title`, `_wds_metadesc`, `_wds_meta-robots-*` flags | `kits/post-partial`, keys written |

The kit is skipped when none of the seven plugins is active. When some are, only their
abilities are registered. Every ability needs the `edit_posts` capability plus permission to
edit that particular post.

## What a plugin cannot store

- Slim SEO cannot force index and keeps no follow flag. Setting `index` only clears its noindex
  flag, and a post type set to noindex stays noindexed.
- SEOPress has no per-post "force index". Setting `noindex: false` clears the override.
- In All in One SEO, going back to `index: default` returns the post to inherited robots. That
  also clears its advanced flags and preview limits, which a later undo puts back.

## Undo

- **Meta-backed plugins.** A write keeps a before-image of exactly the meta keys it writes. The
  runtime's post-partial strategy restores those keys and nothing else, then re-reads them. Yoast
  rebuilds its indexable for the post whenever one of its meta keys changes, and that includes
  the restore.
- **All in One SEO.** Its SEO is not post meta. The kit keeps its own before-image of the
  columns it changes (title, description and the robots columns). Its `kits/seo-basics-aioseo`
  strategy writes them back through AIOSEO's model and checks them against a fingerprint. The
  undo needs AIOSEO to be active.

## Scope

Focus keywords, canonical URLs, social previews, schema, redirects, scores and rendered values
are not included here.

<!-- kit-export:omit -->
GoBuildWP Pro 1.10.0 and later register richer abilities under these same names at
`wp_abilities_api_init` priority 10. They cover focus keywords, canonical URLs, social previews,
schema and redirects. On a licensed Pro site those register first, and every ability here stands
aside through `Runtime\unclaimed()`, along with its undo capture. Each input schema here is a
subset of Pro's, so a call written for this copy also works against Pro's.
<!-- /kit-export:omit -->
