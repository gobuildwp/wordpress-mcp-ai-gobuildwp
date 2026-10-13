# content-audit kit

Audits published content and reports findings with a suggested fix for each. Read-only: the
fixes are other abilities, named in each finding with whether they are available on the site.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/audit-content` | read | Starts a whole-site audit as a background job (default), or audits one post (`post_id`) or one batch (`mode: "page"`, `cursor`/`limit`) inline. |
| `wppilot/audit-content-status` | read | A job's status, progress and findings so far, worst first, paged and filterable; without `job_id`, the caller's recent audits. |

## Checks

- **broken_links**: links in `post_content` and Elementor's `_elementor_data`. Internal URLs are
  resolved without HTTP where WordPress can answer (`url_to_postid()` and the target's status;
  uploads URLs against the file on disk). Only what neither covers — archives, feeds, custom
  routes — gets a HEAD request to the site itself, at most `internal_http_limit` per run, with
  redirects not followed so they can be reported.
- **external_links** (opt-in): HEAD through `wp_safe_remote_head()`, falling back to a 2 KB GET
  where HEAD is refused, 5 s timeout, at most `external_limit` distinct URLs per run. 401, 403
  and 429 are reported as low severity: often a bot wall, not a dead page.
- **orphans**: published content that no other scanned published content links to, excluding the
  front page, the posts page and anything a navigation menu contains. Needs every post, so only a
  background run (or a first page that covers the site) decides it.
- **thin_content**: words in post content plus Elementor text fields, under `thin_words`
  (default 300). Block comments and shortcodes are not counted.
- **seo_meta**: missing description or title in Yoast, Rank Math, SEOPress, AIOSEO, The SEO Framework, Slim SEO or SmartCrawl post meta. Where the host offers an `seo-provider-registry` extension, the active SEO plugins are read through their own readers instead, which also covers storage the meta keys miss.
  The plugin is detected by which of their meta keys exist on the site; no plugin API is called.
  AIOSEO keeps its data in its own table and mirrors only some of it to post meta, so it can be
  under-reported. The source used is in each finding and in `stats.seo_sources`.
- **schema**: the served HTML of a sample of pages (`schema_sample`, spread over the run),
  fetched with `Runtime\Page::fetch()`. Reports invalid JSON-LD, a missing or non-schema.org
  `@context`, entities without `@type`, pages that return an HTTP error, and FAQPage/HowTo markup
  with Google's rule stated: FAQ rich results limited to authoritative government and health
  sites (August 2023), HowTo rich results no longer shown (September 2023). That markup is valid
  schema.org and is not reported as invalid.

Not seen: links a theme, a menu or a shortcode prints; content other builders keep outside
`post_content`; text in languages written without spaces is under-counted by the word count.

## Background runs

`audit-content` enqueues a job on the runtime's jobs runner (`host()->jobs()`), kind
`content-audit`, registered in `bootstrap.php` on every request so a cron tick finds it. Each step
audits 20 posts after a keyset cursor and saves its state; a step that dies loses only itself. The
state keeps at most 1000 findings (the most severe, when there are more) with exact counts. Jobs
are kept 7 days after they finish.

## Host needs

- `jobs()`: the runner, which is the runtime's own `Runner` on every host.
- `id()`: the namespace fix suggestions are looked up in; a suggested ability is reported only
  when it is registered on the site.

## Safety

Both abilities are read-only (`readonly: true`), so they run under every safety profile and are
never confirmation-gated. Outbound requests go only through `wp_safe_remote_*()` (private
addresses refused, redirects re-checked), and a page fetch only to this site. A job's findings are
readable by the user who started it and by administrators.

<!-- kit-export:omit -->
Fix suggestions name GoBuildWP abilities: `wppilot/search-replace-preview`/`-apply` and
`wppilot/update-post` (Free), and the redirect and SEO-meta abilities Pro ships per plugin.

## Tests

`tests/Unit/Kits/ContentAudit/` in the GoBuildWP repository (not shipped).
<!-- /kit-export:omit -->
