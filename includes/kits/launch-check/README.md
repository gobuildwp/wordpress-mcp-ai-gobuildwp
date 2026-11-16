# launch-check kit

The things a site is usually launched without fixing. Read-only.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/launch-checklist` | read | Pass, warn, fail or info for search visibility (Settings > Reading, home page noindex, home page status), HTTPS, errors shown to visitors, favicon, analytics, privacy page, broken internal links in recent content and the forms to test. `link_sample` (10, max 25). |

## How each check decides

- **search_visibility** fails on `blog_public` 0, a `noindex`/`none` robots meta tag on the home
  page, or a home page answering 4xx/5xx.
- **ssl** fails unless both Site Address and WordPress Address use `https://`.
- **debug_display** judges `WP_DEBUG`, `WP_DEBUG_DISPLAY`, `WP_DEBUG_LOG` and php.ini's
  `display_errors` (read with `get_cfg_var`, because WordPress turns display off for the REST
  request that runs the check). A log written to the public `wp-content/debug.log` is a warning.
- **favicon** passes on a Site Icon, an icon `<link>` on the home page, or a `favicon.ico` file.
  `/favicon.ico` is not fetched: WordPress redirects it to its own logo when no file exists.
- **analytics** looks for known snippets in the home page HTML and for active analytics plugins.
- **broken_links** runs the content-audit kit's link resolver over the `link_sample` most
  recently modified posts and pages, with at most 10 HTTP checks.
- **test_forms** lists up to 20 forms from Contact Form 7, WPForms, Forminator, Gravity Forms and
  Fluent Forms, flagging titles such as "Test" or "Copy of".

One loopback fetch of the home page; nothing is changed or submitted.
