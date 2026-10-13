# pagespeed kit

Google PageSpeed Insights for a page of this site, with no Google key required from the site
owner. Read-only.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/pagespeed-check` | read | Lighthouse scores (performance, seo, accessibility, best_practices), lab metrics, CrUX field data, opportunities (largest saving first) and failing diagnostics for `url` (default home), `strategy` mobile, desktop or both. `source` and `attempts` say which source answered. |

## Sources, in order

1. **site-kit**: Site Kit by Google's PageSpeed Insights module through its own REST route
   (`GET google-site-kit/v1/modules/pagespeed-insights/data/pagespeed`, `url`, `strategy`), when
   the module is active and connected and this user can read it (own Google sign-in, or the
   module shared with their role). Site Kit requests the performance category only.
2. **cloud**: `POST {cloud}/api/pagespeed/v1/run` with `{url, strategy, site_url}`; the proxy holds
   the PageSpeed key and caches each (url, strategy) for an hour. When the host's `cloud-sign`
   extension can sign (a paired site), the body also carries `site_id`, `ts` and `nonce`, with the
   signature header the extension supplies, and goes to the paired cloud; a 404 `unknown_site` repeats the
   call unsigned. No `Authorization` header is ever sent (the proxy refuses one). The unsigned base
   URL is the host's `cloud-url` extension (none standalone); both pass the
   `wppilot_kit_pagespeed_proxy_url` filter, where '' skips the source. Client timeout 110 s.
3. **google-api-key**: Google's v5 API with the key in the `wppilot_kit_pagespeed_api_key` option
   (or the filter of the same name). The key is never returned or logged.
4. **google-keyless**: Google's v5 API with no key; shared global quota, so last.

A failure that belongs to the page (Lighthouse `NO_FCP`, `PAGE_HUNG`, `PROTOCOL_TIMEOUT`,
`FAILED_DOCUMENT_REQUEST`, …) stops the chain; a quota, key or transport failure moves to the next
source. Sources are skipped once 150 seconds have gone. Results are cached for 15 minutes in a
transient (`refresh: true` bypasses it, and on a signed call to the Cloud also asks it to skip its
own hour-long cache; the Cloud honours that only for a paired site's signed call).

## Vendor data, verified 2026-10-09

- **Site Kit by Google 1.189.0**: `PageSpeed_Insights::create_data_request()` (`GET:pagespeed`,
  `strategy` required, `url` defaulting to the reference site URL, locale from the site);
  `REST_Modules_Controller::get_modules_data_route()` needs `googlesitekit_setup` or
  `googlesitekit_view_posts_insights`; `core/modules/data/list` reports `active`/`connected`;
  `core/user/data/authentication` reports `authenticated`;
  `googlesitekit_read_shared_module_data` (with the module slug) is true when the module is
  shared with one of the user's roles.
- **PageSpeed Insights API v5** (`runPagespeed`): `lighthouseResult.categories.*.score`,
  `audits.*.numericValue`, opportunity audits (`details.type: "opportunity"`,
  `overallSavingsMs/Bytes`) up to Lighthouse 12 and performance insights (`*-insight`,
  `metricSavings`) from 12.x, `loadingExperience` for CrUX.

## Host needs

`Runtime\can_run()` plus `manage_options`, `Runtime\unclaimed()` (runtime 1.2), and optionally the
`cloud-url` extension point.

<!-- kit-export:omit -->
Inside GoBuildWP the API key is set on GoBuildWP > Settings > PageSpeed, and `WPPILOT_CLOUD_URL` in
wp-config.php points the proxy at a local Cloud along with pairing.
<!-- /kit-export:omit -->

## Tests

`tests/Unit/Kits/Pagespeed`: normalisation of both Lighthouse generations and of the Cloud
contract, source order and fall-through, page-level errors stopping the chain, key scrubbing,
URL restriction.
