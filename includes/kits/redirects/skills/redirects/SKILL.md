---
name: redirects
description: Create, check and remove this site's own redirects (301, 302, 410), and turn the 404 log into redirect suggestions. Activate when the user moves or renames pages, asks to redirect an old URL, fix broken links or 404s, retire a page as gone, or asks why a URL lands somewhere unexpected. Not for redirects stored in Redirection, Rank Math, Yoast or other SEO plugins.
---

# Redirects

The site keeps its own redirect list (up to 500). Each redirect has a source path, a match
(`exact`, or `prefix` for the path and everything under it), a target and a type: 301 moved
permanently, 302 temporary, 410 gone.

- `wppilot/redirect-list`: what exists, with hit counts and `origin` (`manual` or `auto`).
- `wppilot/redirect-test`: where a path ends up, hop by hop. Simulated, not a real request.
- `wppilot/redirect-suggest`: most-hit 404s with a likely published page for each.
- `wppilot/redirect-create`: add one. Recorded in the change log; undo removes it.
- `wppilot/redirect-delete`: remove up to 50 by id. Destructive: confirm, and run `dry_run` first.

## Automatic redirects

When a published post or page changes its URL (slug, parent, or a parent's slug), a 301 from the
old URL is created with `origin: auto`, for the page and up to 50 published children. Moving a
page back removes the redirect that would now loop, and earlier automatic redirects to the old
URL are pointed straight at the new one. A redirect a person made is never changed.
`redirect-list` reports `auto_redirects`: `on`, `off`, or `elsewhere` when the plugin puts them in the
site's redirect plugin (Redirection, Rank Math and similar) instead.

## Working through 404s

1. `wppilot/redirect-suggest`. Each suggestion gives `source`, `target`, `post_id`, `title` and
   a `reason`: `old_slug` (the page used to have that slug, strong) or `same_slug` (a page has
   that slug now, check it is the same content).
2. Show the user the pairs you mean to create. Skip a suggestion whose page is not what the old
   URL was about; an unrelated redirect is worse for visitors and search than a 404.
3. `wppilot/redirect-create` for each, then `wppilot/redirect-test` on the source.
4. For a page removed for good, a 410 (no target) tells search engines to drop it.

## What is refused

- Sources under `/wp-admin`, `/wp-login.php`, the REST API, `xmlrpc.php` and `wp-cron.php`, and
  prefix sources that would cover them (including `/`).
- A source that already has a redirect: delete it first.
- Targets that are IP addresses, carry a user name or password, or are not http(s).
- Anything that would loop or pass a visitor through more than 3 redirects. Point the new
  redirect at the final page instead.

Matching ignores case, a trailing slash and the query string; the visitor's query string is
passed on to the target. Hit counts are a floor: at most one count is saved per second.
