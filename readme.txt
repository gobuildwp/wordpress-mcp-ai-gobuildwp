=== GoBuildWP ===
Contributors: wppilot
Tags: mcp, ai, claude, elementor, agent
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.18.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, Cursor, Codex and other AI clients to your WordPress site over MCP, with safety profiles you control.

== Description ==

GoBuildWP turns your WordPress site into an MCP (Model Context Protocol) server, so the AI client you already use can build in it directly instead of handing you code to paste. Pages, block-editor content, Elementor layouts, menus, taxonomies, media, SEO metadata: one prompt from you becomes hundreds of typed calls from the agent, each checked against your WordPress capabilities before it runs.

It is built on the WordPress Abilities API and the official MCP Adapter, and it ships a compact tool set for discovery, inspection and execution rather than a sprawl of one-off endpoints.

= Elementor editing, in the free plugin =

If Elementor is active, GoBuildWP registers Elementor abilities alongside the WordPress ones. An agent can read a document's element tree, discover the widgets and atomic style properties this install actually offers, and then add, edit, move, duplicate, reorder and delete elements, and read and write page settings.

It works on the element tree in postmeta, which is where an Elementor page actually lives; writing generated HTML into the post content changes nothing a visitor sees, and GoBuildWP refuses that write by name rather than reporting a success. Both element models are supported: v4 atomic elements with the atomic style schema, and classic v3 widgets and containers.

Nothing needs configuring. The abilities appear when Elementor 3.6 or newer is active and stay absent otherwise, so an agent is never offered a tool that cannot work here. Elementor Pro is not required.

Composing a whole page in one call, templates and theme parts, popups, forms, dynamic tags, global classes and variables are GoBuildWP Pro.

A prompt library ships in wp-admin: ten complete landing-page briefs, one per industry, each with its own palette, type pairing, design signature and content facts, so a bakery and a law firm do not come out as the same page. Choose Elementor or the block editor on the screen and the brief is written for it.

Full documentation is at [gobuildwp.com](https://gobuildwp.com/docs).

= You decide what agents may do =

Every remote call is checked against a safety profile before it runs, on the server, for MCP and REST alike:

* **Production Safe**, the default. Content and configuration work, no raw code execution.
* **Read Only**. Agents can look and report, and change nothing.
* **Developer Full Access**: adds PHP execution, WP-CLI, filesystem and database access. Intended for development and staging sites.

Destructive and critical operations require an explicit confirmation flag on top of the profile, and supported changes are recorded in a redacted change ledger you can roll back.

= Three ways to connect =

* **OAuth 2.1** with PKCE, sign in through the browser, no password pasted into a config file. Client ID Metadata Documents are the preferred registration mechanism, with dynamic client registration kept for clients that do not support them.
* **Application passwords**, for clients that do not run an OAuth flow.
* **Access tokens**, a long-lived bearer token for callers that have no browser at all: the Claude Messages API MCP connector, the OpenAI Responses API, cron jobs, automation platforms and scripts. Created with an optional expiry, shown once, stored only as a digest, and revocable one at a time.

Both MCP protocol revisions are served during the migration window: the stateless `2026-07-28` and the session-based `2025-11-25`. Existing clients keep working and do not need to reconnect.

Either way, GoBuildWP generates the exact configuration for your client and shows you where to put it.

= Clients it generates configuration for =

**Editors and CLIs:** Claude Code, Claude Desktop, Codex CLI, the Codex desktop app, Cursor, VS Code, GitHub Copilot, Factory Droid, Antigravity CLI, Antigravity IDE, Devin Desktop (formerly Windsurf), Zed, Cline, Roo Code, Kilo Code, Amazon Q, OpenCode, OpenClaw, Kimi Code CLI, Qwen Code, Gemini CLI and ZCode (GLM).

**Web apps**, each with its own walkthrough: Claude on the web, ChatGPT, Perplexity, Mistral Le Chat and Manus. Every one adds this site from its own settings screen, and GoBuildWP tells you which credential that app accepts — four of the five can take an access token instead of signing in.

**Anything else that speaks HTTP:** the Claude Messages API MCP connector, the OpenAI Responses API, and plain curl.

Every client's snippet is written in the shape that client actually parses. The field names disagree more than they should — VS Code nests servers under "servers", Antigravity and Devin Desktop want "serverUrl", Qwen Code and Gemini CLI want "httpUrl", Cline spells the transport "streamableHttp" where Roo Code spells it "streamable-http" and OpenClaw wants a "transport" field, OpenCode and Kilo Code nest servers under "mcp", Codex uses TOML — and a snippet copied from the wrong client's documentation often parses cleanly and then connects to nothing.

= Or let your AI set it up =

Every connection method also offers its setup as a ready-made prompt for an AI coding agent, carrying the server name, the URL, the exact snippet, the file it belongs in, and the rules that stop an agent inventing a transport that was never mentioned. Paste it into the agent already open next to WordPress.

= See what is connected =

The Overview screen names the AI clients actually talking to your site, identified by what each one reported when it connected, not by a label you typed, along with how it authenticated, when it first and last called, and how many requests it has made.

= Also included =

* Per-credential rate limiting on writes, with read-only abilities exempt.
* Typed design-library, Gutenberg staging, diagnostics, skills, change-ledger, file and developer operations, with every ability individually switchable.
* Skills and site-wide instructions, so agents start with your context rather than guessing.
* A sandbox for agent-authored PHP, isolated behind directory guards.
* A diagnostics screen that probes the connection the way a client does and names what to fix.

= External services =

The MCP endpoint runs on your own site, and your AI client connects to it directly without a GoBuildWP-hosted content relay.

GoBuildWP Chat is different: when you use Chat, WordPress sends the conversation history, selected attachments, site instructions, tool definitions and relevant tool results to the AI provider and model you selected through the WordPress AI Client. That provider is an external service and its own terms and privacy policy apply. GoBuildWP adds suggested text to WordPress's Privacy Policy Guide and integrates with the personal-data export and erase tools.

The stock-image search ability queries Openverse (api.openverse.org), WordPress.org's openly-licensed media search, and only when an agent calls it. The search terms and filters are sent to Openverse; nothing about your site, your users, or your content goes with them, and no account or API key is involved. Openverse is operated by the WordPress.org project and its own terms apply. Importing a chosen image then downloads that file from wherever its source hosts it, exactly as pasting the URL into the media importer would.

GoBuildWP Cloud (app.gobuildwp.com) is optional. Apart from the PageSpeed check described below, it is contacted only if an administrator connects the site under **GoBuildWP > Connect > GoBuildWP Cloud**. Connecting sends app.gobuildwp.com this site's address and name, the GoBuildWP, WordPress, PHP and GoBuildWP Pro versions, a one-time pairing code, the site's public signing key, and an access token that GoBuildWP creates for the Cloud with the access level you confirm. While connected, the site sends a signed heartbeat once an hour and after a plugin update (site ID, versions, safety profile and site address), and a signed notice when you disconnect. The Cloud then calls this site's MCP endpoint with that token on behalf of the AI clients in your workspace; those calls go through the same safety profile, confirmations and change log as any other client. Disconnecting revokes the token. A site connected by an older version keeps sending its heartbeat and disconnect notice to the address it was connected through, the service's former domain app.wppilot.co, which reaches the same GoBuildWP Cloud service, until it is disconnected and connected again. No usernames, email addresses, posts or other content are sent by the plugin; what AI clients read through the Cloud is whatever they ask for within the access you granted. Terms: https://gobuildwp.com/terms. Privacy policy: https://gobuildwp.com/privacy.

The PageSpeed check ability runs only when an agent calls it. It first uses Google Site Kit if that plugin is connected. Otherwise it sends the page address being tested, the mobile or desktop strategy and this site's home address to GoBuildWP Cloud (app.gobuildwp.com/api/pagespeed/v1/run), which asks Google PageSpeed Insights and returns the result; this happens whether or not the site is connected to the Cloud, and a connected site signs the request. If the Cloud cannot answer, the page address and strategy are sent to Google PageSpeed Insights (www.googleapis.com) directly, with your own API key if you saved one. Google's terms (https://developers.google.com/terms) and privacy policy (https://policies.google.com/privacy) apply to that request. No content, usernames or email addresses are sent.

The design preview in the admin loads the fonts you pick from Google Fonts (fonts.googleapis.com and fonts.gstatic.com), so your browser sends your IP address and browser details to Google when the preview shows a Google font. Google Fonts' privacy information: https://developers.google.com/fonts/faq/privacy.

When you use OAuth, the client you are connecting registers itself with your site. That traffic is between your site and your own AI client, and no GoBuildWP server is involved.

GoBuildWP is distributed from gobuildwp.com and GitHub releases, not the WordPress.org directory, so it checks gobuildwp.com for plugin updates rather than the directory API. The update check sends the plugin version and nothing else.

Builds downloaded from gobuildwp.com can send anonymous usage data to gobuildwp.com once a day, but only if you switch it on under **GoBuildWP > Settings > Anonymous usage reporting**. It is off until then: nothing is sent, and there is no notice asking. Switching it back off also asks us to delete what was already collected. When on, it sends this site's URL, the GoBuildWP, WordPress and PHP versions, your locale, whether Pro is active, your safety profile, and how many connections exist. It never sends usernames, email addresses, page or post content, or any record of what an agent did. Reports are kept in detail for 90 days and then reduced to daily totals; an install that stops reporting for 400 days is deleted.

== Installation ==

1. Install and activate GoBuildWP.
2. Open **GoBuildWP → Connect** and turn on AI abilities.
3. Choose a safety profile. Production Safe is the default and is the right choice for a live site.
4. Pick your authentication method and your AI client, then copy the generated configuration into it.
5. Use the client once. **GoBuildWP → Overview** will show it connected.

If something does not work, **GoBuildWP → Diagnostics** runs the same checks a client does and tells you what to fix.

== Frequently Asked Questions ==

= Is it safe to run this on a production site? =

On the default Production Safe profile, agents can work with content and configuration but cannot execute code, reach the filesystem, or query the database. Every call is capability-checked against the logged-in user, so an agent can never do more than the account it is connected as.

Developer Full Access is a different matter: it grants PHP execution, WP-CLI and filesystem access. Use it on development and staging sites.

= Do I need an OpenAI or Anthropic API key? =

No. GoBuildWP is the server. Your AI client, Claude, Cursor, Codex or another, connects to it, and that client handles its own model access.

= Which WordPress and PHP versions are required? =

The MCP server requires WordPress 6.9 or newer and PHP 8.0 or newer, and is tested against WordPress 7.1. GoBuildWP Chat additionally requires WordPress 7.0 or newer and a configured AI provider; on WordPress 6.9 the MCP server continues to work while Chat reports that it is unavailable.

= Can I control which abilities are exposed? =

Yes. **GoBuildWP → Abilities** lists every registered ability, grouped by provider, and each one can be switched off individually. Disabled abilities disappear from discovery and cannot be executed.

= Can an agent edit an Elementor page without Pro? =

Yes. Sixteen Elementor abilities ship in this plugin and register themselves as soon as Elementor 3.6 or newer is active: read the document, inspect the widget and style schemas, add, edit, move, duplicate, reorder and delete elements, and read and write page settings. The design system is here too, so the agent has a palette, type and spacing ladders and a set of compositions to build against.

What Pro adds is the authoring layer: building a whole page from one description or from a reproduction spec, templates and theme parts, display conditions, popups, Elementor Pro forms, dynamic tags, interactions, global classes and variables. Free can edit an Elementor page; Pro can compose one.

= What does GoBuildWP Pro add? =

Pro is for the plugins your site adds on top of WordPress: WooCommerce, page builders, forms, SEO suites, custom fields, themes and multilingual tools, plus persistent memory across conversations and an approval queue. It is a separate paid plugin from gobuildwp.com and is not required.

WordPress itself is Free. Content, taxonomies, media, comments, revisions, menus, user reads and the allowlisted settings surface are all in this plugin and need no licence, entitlement service or Pro install.

= Which WordPress operations work without Pro? =

* Posts, pages and public custom post types: list, search, read, create, update, trash, restore, and permanently delete with explicit confirmation.
* Categories, tags and other public taxonomies: discover, list, read, create, update, delete with confirmation, and assign to content.
* Media: list, read, import from a URL, update metadata and alt text, set and clear featured images, attach and detach, and delete with confirmation.
* Comments: list, read, reply, edit, approve, hold, spam, unspam, trash, restore, and delete with confirmation.
* Revisions: list with autosaves distinguished, read against the live post, and restore.
* Menus: create, rename, delete, add and remove items, reorder, and assign to the theme's navigation locations.
* Users, privacy-minimized reads.
* Site information and an explicit settings allowlist.
* Plugins and themes: search the WordPress.org directory, read one in detail, activate, deactivate, update, and switch themes. Installing and deleting are Developer Full Access only, because they write executable code to the server.

New content is created as a draft unless you ask for publication explicitly, and publishing is checked against the post type's own capability.

== Screenshots ==

1. Overview: the AI clients connected to your site, how they authenticated, and how much they call.
2. Connect, generated configuration for your AI client, for OAuth, an application password, or an access token.
3. Settings, every site-wide switch in one place.
4. Abilities: every ability exposed to agents, grouped by provider, each one switchable.
5. Diagnostics, checks that probe the connection the way a client does.

== Development ==

Most of GoBuildWP is plain PHP, readable as shipped. The one compiled asset is the Chat screen's JavaScript, `includes/assets/chat/index.js`, built from the TypeScript source included in this package at `src/chat/index.tsx`.

To rebuild it from source:

1. Install dependencies: `npm install` (or `bun install`)
2. Build: `npm run build`. This runs `@wordpress/scripts` against `src/chat/index.tsx` and writes the bundle to `includes/assets/chat/`

The PHP dependencies under `vendor/` are installed with `composer install --no-dev` from the included `composer.json`.

== Changelog ==

= 1.18.6 =
* Changed: WPPilot is now GoBuildWP. On the GitHub edition, update checks and telemetry (when switched on) use gobuildwp.com, and new GoBuildWP Cloud connections use app.gobuildwp.com. Settings, connections, tokens, the change log and Pro licences are kept, and sites already connected to Cloud keep their existing connection.
* Changed: new client setups suggest the server name gobuildwp-<your-site>; existing client configurations keep working.
* Changed: ability descriptions an AI client reads now state what the code does, including which changes can be undone and the limits of safe-update rollback.
* Fixed: a missing bundled vendor directory now stops OAuth from starting with a clear notice instead of a fatal error.
* Security: GoBuildWP's MCP endpoints on the bundled MCP Adapter (/wp-json/mcp/wppilot, the OAuth endpoint and the legacy alias) admitted any signed-in user for the legacy protocol, so a subscriber with an Application Password could open a session, list the tools, run discover-abilities and read every skill resource, built-in and site-authored. Running abilities was already refused. They now require what the modern protocol, OAuth and the REST API already required: AI abilities switched on and GoBuildWP's manage capability. MCP servers other plugins register on the same adapter, such as Elementor's, are not changed.
* Fixed: OAuth clients speaking MCP 2026-07-28 on /wp-json/mcp/wppilot-oauth got "Missing Mcp-Session-Id", although the endpoint's protected-resource metadata advertises the modern protocol. It is now served there, behind the same OAuth route and scope checks.
* Fixed: on the WordPress.org edition wppilot/agent-context did not register (a PHP notice on every request), because its category was code-execution, which that edition does not register. It is now in the mcp-adapter category beside discover-abilities. A token limited to the code-execution category no longer reaches it; one limited to mcp-adapter does.
* Security: the PHP sandbox treated only .php as PHP, so write-file, edit-file and upload links could put .phtml, .phar, .php7, .pht, .phps and similar files outside wp-content/wppilot-sandbox/, where stock Debian and Ubuntu PHP configurations execute them outside WordPress. Every extension a server may run as PHP now has to stay in the sandbox, compared case-insensitively.
* Fixed: abilities public only through WordPress 7.1's core public flag (core/get-site-info, core/get-user-info, core/get-environment-info) were listed and callable on the modern protocol but missing from discover-abilities and refused by execute-ability on the legacy one. Both now publish the same abilities on GoBuildWP's endpoints.


= 1.18.5 =
* New: wppilot/site-issues, a read-only log of what went wrong on the site. WordPress's fatal error handler now records the error behind "There has been a critical error on this website" - type, the message's first line, the file relative to the WordPress root and the line, the plugin or theme it belongs to, the request path without its query string, a count and first/last seen - in a capped, non-autoloaded log of 50 entries. Out-of-memory and timeout fatals are not blamed on the file PHP happened to stop in. The ability reads that log with the plugins and themes WordPress paused in recovery mode, failed backups and the updates GoBuildWP Pro's safe update rolled back. Query strings, cookies, stack traces and server paths are never stored.
* New: GoBuildWP Cloud hears about changes within seconds instead of at the next hourly check-in: a finished or failed backup (UpdraftPlus, BackWPup, Duplicator), a core, plugin or theme update (safe updates included), a plugin or theme installed, activated, deactivated, deleted or switched, and a Site Kit connection change each send the same signed check-in from WordPress cron, at most once every 30 seconds and never during a visitor's page load.
* Changed: cloud/status sends a compact issues summary - counts, when the newest fatal was, and the newest ten issues with relative file paths only - so GoBuildWP Cloud can show an Issues log per site.
* Fixed: wppilot/pagespeed-check offered Site Kit dashboard sharing to a module owner whose Google sign-in was gone, which Site Kit refuses. It now skips Site Kit with site_kit_owner_signed_out and returns a sign-in fix that links to Site Kit > Dashboard.

= 1.18.4 =
* New: wppilot/pagespeed-check runs Google PageSpeed Insights on any page of the site and returns the Lighthouse scores, lab metrics, Core Web Vitals field data when Google has it, the opportunities ordered by the time they save, and failing diagnostics. No Google key is needed from anyone: Site Kit by Google answers when its PageSpeed module is connected and readable, otherwise GoBuildWP Cloud runs the check with GoBuildWP's own key (signed by a paired site, plain otherwise), and Google's keyless API is the last resort. A key of your own on Settings > PageSpeed is optional and not needed. refresh: true measures again instead of reusing a recent result, on the site and, for a paired site, at GoBuildWP Cloud too.
* New: wppilot/site-kit-enable-sharing turns on Site Kit's read-only dashboard sharing for Administrators for Search Console, Analytics and PageSpeed Insights, through Site Kit's own sharing route so its owner rules apply, and can be undone. PageSpeed checks say when this sharing is all that keeps Site Kit from answering.
* Changed: whether an All-in-One WP Migration backup can be started is now decided by what the install has - the same check GoBuildWP Pro 1.12.2's export runner makes - and never by its version. backup-status's trigger.reason names what is missing when it can't.
* Changed: GoBuildWP Cloud is told why a backup is stuck. cloud/status now reports, per backup plugin, whether it is running, the site's own reason it can't be started, and the export GoBuildWP Pro started (running, stalled, failed or finished, with a plain reason), so Cloud shows it on the run page and stops waiting for an export that failed. File names, folders, job ids and keys are never sent.

= 1.18.3 =
* Changed: Prompts is listed in the WordPress admin menu under GoBuildWP, next to Dashboard. GoBuildWP collapses its other screens into the tab bar on its pages, which had left the industry briefs two clicks deep under Studio. Sites that want a different set can change it with the wppilot_sidebar_visible_pages filter.

= 1.18.2 =
* Fixed: with GoBuildWP Cloud or an OAuth connector (Claude, ChatGPT), abilities that work through WordPress's own REST API failed with "This OAuth credential is not accepted on the requested REST route." That broke the block themes kit (Global Styles, templates, patterns, navigation), the block theme path of wppilot/build-site-header, Cloud's "Back up now" with All-in-One WP Migration, and GoBuildWP Pro abilities that use WordPress REST (WooCommerce reports, Etch, GeneratePress, Meta Box relationships, Site Kit). The access-token route limit now lets through a REST call an ability makes for itself while serving an MCP request that already passed it; that call still runs the route's own permission check as the token's user. A token sent straight to any other REST route, including inside a REST batch request, is still refused with 403. Application Password connections were not affected.
* Fixed: Dashboard > Updates > Check again now fetches GoBuildWP's release data instead of answering from a 12-hour cache, so a new release shows at once.

= 1.18.1 =
* New: All-in-One WP Migration backups are read alongside UpdraftPlus, BackWPup and Duplicator. backup-status and backup-list report its newest finished backup (time and size), how many there are and whether an export or import is running (only a complete archive of at least 1 MB in its backups folder counts, so a file dropped there cannot pass for a backup); GoBuildWP Cloud's Backups page shows it, and GoBuildWP Pro 1.12.1 counts it as the recent backup a safe update needs. Backup file names, folders and links are never returned, because on hosts that ignore .htaccess a .wpress file name is enough to download the whole site.

= 1.18.0 =
* New: GoBuildWP Cloud reporting. A site connected to GoBuildWP Cloud now reports its pending core, plugin and theme updates and its newest backup (UpdraftPlus, BackWPup or Duplicator), so Cloud's Updates and Backups screens can show every site at once. Cloud never receives backup files, links or storage credentials.
* New: safety settings pushed from GoBuildWP Cloud. Under GoBuildWP > Connect > GoBuildWP Cloud the site owner can let Cloud tighten this site's safety profile, approval mode and switched-off abilities, or also loosen them. Both are off by default, loosening needs tightening, Developer Full Access can never be set from Cloud, and a rule the owner changes afterwards stays the owner's.
* New: block themes kit. AI clients can edit Global Styles, templates and template parts (header, footer), patterns and navigation menus on block themes through WordPress's own REST endpoints, and every change can be undone.
* New: wppilot/build-site-header. The AI designs the site header for the brand (an Elementor Pro Theme Builder header or a block theme header part) and GoBuildWP does the rest: one header per Polylang language, a styled language switcher, the menu and the cart, confirmation and undo. GoBuildWP checks the design before saving (mobile menu button, contrast, empty images and links) and the served page after (wrapping, overlap, broken links), and reports each problem against the element so the AI can fix its own design.
* New: safety and undo for other MCP servers. Writes made through Elementor's and WooCommerce's own MCP abilities, and TranslatePress's and Easy Digital Downloads' settings and product abilities, are recorded in GoBuildWP's change log and can be undone. An optional setting (Settings > Other MCP servers) also asks for approval before they run.
* New: builder quality audit. wppilot/elementor-audit-output scores how editable an Elementor page is in the panel (native widgets versus HTML widgets and raw markup) and lists what to fix. GoBuildWP Pro 1.12.0 adds the fixer.
* New: Chat usage meter. WordPress Chat shows how many tokens each person has used today and this month. Only token counts are kept, for 62 days, and they never leave the site. GoBuildWP Pro 1.12.0 adds daily and monthly limits that stop Chat before the AI provider is asked.
* Fixed (reported by a client): elementor-set-content rejected classic sections and columns, so a page built with them could not be written back; Elementor Pro form fields such as Reply-To did not accept the form's own field IDs; rich-text fields documented inline tags only although paragraphs and lists are allowed; and Polylang sites were reported as WPML.
* Fixed: verify-rendered-page and the accessibility audit can check drafts, through a one-minute signed-in preview that only reaches this site, and every finding and heading says whether it comes from the page or the theme. Theme, sidebar and footer output no longer fails a page.
* Fixed: elementor-set-content kept a Theme Builder template's type (header, footer) instead of resetting it to a page; the Gutenberg finalizer keeps blocks whose attributes arrive empty; undoing a WooCommerce product edit no longer refuses over its live sales counters; parallel Chat steps are all counted.
* Changed: discover-abilities lists each ability's parameters, for example post_types?: array, so clients stop guessing names.
* Security: Elementor writes that bypass Elementor's document save (atomic v4 trees and the fallback path) now run every string through wp_kses_post when the user lacks unfiltered_html, as Elementor's own editor does, instead of relying on Elementor's REST-only meta sanitizer.

= 1.17.4 =
* Fixed: on a site running Yoast SEO, every request signed in with a GoBuildWP access token (GoBuildWP Cloud, and AI connectors that use OAuth) failed with an HTTP 500 error. Checking the token asked Yoast for the user's permissions, Yoast asked WordPress who the current user was, and that started the token check again until PHP ran out of memory. The token check now ignores that inner lookup, so the request signs in as the token's user. Application Password connections were not affected. No ability permissions changed.

= 1.17.3 =
* Fixed: an AI client could list GoBuildWP's abilities but never run one, because it was offered only mcp-adapter-discover-abilities. When another plugin read the WordPress Abilities registry early in the request, the bundled MCP Adapter missed the moment to register mcp-adapter/get-ability-info and mcp-adapter/execute-ability, so every GoBuildWP MCP endpoint (OAuth and Application Password alike) served discovery alone. GoBuildWP now registers both itself when the adapter did not. Clients that connected before updating should disconnect and reconnect, or start a new chat, so they read the tool list again. No ability permissions changed.

= 1.17.2 =
* Changed: GoBuildWP's admin screens (Connect, Abilities, Skills and the masthead Upgrade link) use the gobuildwp.com brand purple, #675CF4, instead of #3D2BFF, so the plugin, the website and the GoBuildWP Cloud dashboard look like one product. The README names the Pro plans by site count (Single Site, 10 Sites, 100 Sites, Unlimited Sites), as gobuildwp.com does. No behaviour, ability or permission changed.

= 1.17.1 =
* Fixed: when GoBuildWP Cloud could not finish connecting because its test request to this site failed, the Connect screen showed only "verification_failed". It now names the reason (for example verification_failed-unauthorized_403 when a firewall or security plugin refuses the request, or protocol_200 when a challenge page or redirect answers instead) and says what to change. Connection Doctor does not test the token GoBuildWP Cloud uses, so it can pass while this check fails.

= 1.17.0 =
* New: GoBuildWP Cloud. A GoBuildWP Cloud card on the Connect screen pairs this site with an app.gobuildwp.com workspace: sign in there, choose Read Only or Production Safe access and which abilities, then confirm on this site. The site creates an access token for the Cloud (listed as "Managed by GoBuildWP Cloud") and hands it over server to server, so it never passes through a browser. The Cloud can read the site's status and unlink itself through two new routes that accept only that token; the site sends a signed hourly heartbeat. Disconnect revokes the token. See External services for what is sent.
* The wppilot_tool_call_rate_limit filter now receives the credential being budgeted as a second argument. The GoBuildWP Cloud credential is allowed at least 600 write calls a minute, unless the limit is switched off.

= 1.16.0 =
* New, formerly GoBuildWP Pro: WooCommerce basics. wppilot/woocommerce-check-setup, list and read products and variations, product categories and tags, store settings, and read orders and customers (orders and customers also need manage_woocommerce), with the input and output Pro gives them. wppilot/woocommerce-edit-product edits one product's name, description, prices and stock, saves through WooCommerce so its lookup tables and caches follow, keeps backslashes, and can be undone from the Changes screen: the undo writes the old values back through WooCommerce and says which fields did not come back. Bulk prices and stock, order and customer writes, refunds, coupons, reports, shipping, tax, gateways and full product editing (SKU, categories, images, attributes) stay in Pro, whose richer edit-product takes over when Pro is active.
* New, formerly GoBuildWP Pro: one post's SEO title, meta description and robots in Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO and SmartCrawl (a get and an edit per plugin, under the names Pro uses; only the active plugins' abilities register). A raw meta write could trip Yoast's 0/2/1 noindex codes or AIOSEO's all-or-nothing robots switch; these write the way each plugin's own editor does. Every write keeps a before-image of exactly what it changed and wppilot/rollback-change restores and checks it; AIOSEO, which keeps SEO in its own table, is restored through its own model. Slim SEO cannot force index and has no follow flag, and SEOPress has no per-post force-index; the descriptions say so. Focus keywords, canonicals, social previews, schema, redirects and bulk SEO stay in Pro, whose richer per-post abilities register first on a site running it.
* New, formerly GoBuildWP Pro: read-only forms. List the forms of WPForms, Contact Form 7, Gravity Forms and Forminator, and read their entries (Contact Form 7's through Flamingo; WPForms entries need WPForms Pro). Entries come with email and phone answers, passwords, card data, uploads, signatures and the IP withheld, and email addresses and phone numbers cut out of free text; full values, exports and form editing are Pro's.
* New, formerly GoBuildWP Pro: backup and security status. wppilot/backup-status and wppilot/backup-list read UpdraftPlus, Duplicator 5 and BackWPup; wppilot/security-plugin-status, wppilot/security-scan-findings and wppilot/security-lockouts read Wordfence and Solid Security, with IP addresses shown as /24 or /48 networks and logins masked. Starting a backup, the require-a-fresh-backup hold, safe updates, hardening, IP blocks and scans stay in Pro.
* New, formerly GoBuildWP Pro: scheduled audits. A routine runs the read-only accessibility, content and media alt-text audits on WP-Cron, daily or weekly in the site's timezone, stores each run, compares it with the one before (new, resolved, changed) and emails the administrators it names. wppilot/routines-list and wppilot/routines-report read; wppilot/routines-save and wppilot/routines-delete can be undone from Changes; wppilot/routines-run-now queues a run. A Routines screen under Activity has a Run now button. Routines made with Pro keep running unchanged: the storage, cron hooks and undo are the same.
* New: undo a whole agent session. Every write an agent makes is filed under its session - one MCP connection, one wp wppilot mcp serve process, or one credential and client until it has been idle for 30 minutes - and wppilot/undo-session takes back everything that session did, newest first, each change verified against its before-image. Before writing anything it checks that nothing else changed a target after the session did; if something did, nothing is changed and the conflict is named (which change, which fields, which later edit). It stops at the first change that fails verification and reports what was undone, what failed and what was not attempted, and a change that cannot be undone is listed rather than skipped (the run is refused unless allow_partial is true). wppilot/redo-session puts an undone session back under the same checks, verified against the state the change first produced. wppilot/list-sessions lists sessions and shows what undo and redo would do now. The Changes screen lists recent sessions and has Undo session and Redo session buttons on a session's view. Works for Pro's writes that go through the change log.
* New: a local connection over WP-CLI. wp wppilot mcp serve --user=<login> serves the same MCP server as /wp-json/mcp/wppilot over stdio, for Claude Code, Cursor or a CI job on the machine that runs the site, with the same abilities, safety profile, confirmations and permission checks and no credential to create. The Connect screen shows the claude mcp add command and a Cursor mcp.json with this site's path filled in.
* New: native OAuth entries for Zed, Cline, Kilo Code, OpenCode, Amazon Q / Kiro and OpenClaw, which now run the sign-in themselves (only Roo Code still uses the mcp-remote bridge on a public site), and OAuth tabs for Kimi Code CLI, Qwen Code, ZCode, Factory Droid and OpenClaw, which had none (Gemini CLI gets one on a local site; its public-site entry is still to come). Manus can take an access token, as its documentation lists Bearer tokens for custom servers.
* Works beside GoBuildWP Pro 1.10.0, which still carries its own copies of all of the above: Pro registers first, and each of these abilities stands aside for a name that is already registered instead of registering it twice, so nothing changes on a site running Pro 1.10.0 until Pro's next release. While Pro 1.10.0 runs routines, the free copy stays off, so no routine runs twice.
* Kits: runtime 1.2 adds Runtime\unclaimed(), kit.json requires.any (a kit serving whichever of several plugins is active) and a SessionLedger host interface through which a kit lets session undo check and redo its own kind of change (the WooCommerce product edit, All in One SEO and routines use it), and loads Pro-tier kits ahead of Free ones in an export, so a richer copy of an ability wins there as it does inside GoBuildWP. scripts/test-kit-coexistence.php loads Pro's kits in the order Pro's engine does and takes --installed-pro=<a Pro release> to prove Free stands aside for a release that still carries the same abilities.
* Fixed: wppilot/delete-comment deletes a comment permanently, but the change log filed it as a reversible status change, so the Changes screen and session undo offered an undo that could only fail. It is now recorded as irreversible.
* Fixed: wppilot/delete-theme failed on every MCP call with "undefined function delete_theme()", because WordPress does not load its theme admin functions on REST requests.
* Fixed: wppilot/adopt-design-from-site threw a TypeError on themes whose spacing sizes have numeric slugs, such as Twenty Twenty-Five.
* Fixed: wppilot/update-media and wppilot/upload-media dropped backslashes from alt text, because WordPress unslashes meta on write.
* Fixed: the approval, OAuth consent, Block Editor Queue, visual runtime and Connected Apps screens logged a PHP deprecation on every load because they had no title.
* Fixed: the Dashboard's list of recent changes logged a WordPress notice for every change made by an ability that is no longer registered, such as an SEO plugin's edit after that plugin was switched off. It now reads the label only for abilities still registered.
* Fixed: every call made through the legacy MCP transport recorded a second change row for the adapter's execute tool, reads included, each saying "No supported before-image". The target ability's own row is unchanged; the extra row is no longer written.
* Fixed: generated client configuration that no longer matched the clients' own documentation (each re-checked against the vendor's docs on 2026-09-30, with the source cited in the code). Kimi now targets Kimi Code CLI (~/.kimi-code/mcp.json, /mcp-config, and /mcp-config login for OAuth) instead of the archived Python kimi-cli. GitHub Copilot snippets are for Copilot CLI (~/.copilot/mcp-config.json or .github/mcp.json, "mcpServers" with "tools"), since .github/copilot/mcp.json is not a documented location and Copilot CLI rejects the "servers" key; the notes say Copilot Chat in VS Code uses the VS Code tab and the cloud agent cannot use OAuth. Devin Desktop lists ~/.config/devin/mcp_config.json alongside the old Windsurf path. Kilo Code entries go in kilo.jsonc under "mcp". OpenClaw's token entry uses mcp.servers with transport "streamable-http" (it fell back to SSE before). Zed connects natively instead of through mcp-remote, without the retired "source" field. Claude Desktop's access-token entry runs through the mcp-remote bridge, because claude_desktop_config.json is for local servers. The Claude Code note no longer says /mcp shows a working token as "needs authentication", the Antigravity IDE hint names the IDE's own menu, and Roo Code points to Cline or ZooCode.
* Fixed: the change history hid fewer credentials than it should. Option names such as LiteSpeed's object-pswd and cdn-cloudflare_key, NitroPack's siteId, Autoptimize's ccss_key and any *_pass, *_pwd or *_auth key were shown in plain text in get-change, list-changes and the Changes screen; they are now withheld like passwords and tokens.
* Fixed: wppilot/read-file and wppilot/list-directory ran under Production Safe and even Read Only, because a read-only ability passed every profile, so an agent on those profiles could read wp-config.php with its database password and authentication salts. Both now need Developer Full Access like the other server-file abilities, and each call is recorded in the change log.
* The change log table gains two columns (session_id, redo_data); existing rows are kept and carry no session.
* 48 new abilities (216 in all). wppilot/read-file and wppilot/list-directory now need Developer Full Access; no other existing ability changed its permissions, and existing connections keep working and do not need re-authorising.

= 1.15.1 =
* Fixed: with Wordfence active, every MCP call made with an Application Password returned 401 and nothing said why. Wordfence switches Application Passwords off by default (its "Disable WordPress application passwords" setting), and the Connection Doctor, Diagnostics and the Connect screen only said "a plugin or code". They now name Wordfence and the exact setting to uncheck, name any other plugin that switches Application Passwords off by the file its filter lives in, and point to OAuth or an access token, which do not need Application Passwords.
* Fixed: change records showed email addresses in full. wppilot/get-change, wppilot/export-changes, the Changes screen and its download redacted secrets by key name only, so an address typed into an excerpt or a comment came back as written, and get-change returned before-images with no redaction at all. Email addresses in the input, the result, the before-image and the undo verification are now masked when a record is read (j***@e***.com), and before-images get the key-name redaction too. What is stored is unchanged, so undo still restores the exact original.
* Fixed: the content audit and wppilot/read-content only knew Yoast, Rank Math, SEOPress and AIOSEO (read-content not even SEOPress), so on a site using The SEO Framework, Slim SEO or SmartCrawl the audit said no SEO plugin was found and read-content reported no SEO title or description. Their meta is now read, Slim SEO's single settings array included. With GoBuildWP Pro active the audit reads the active SEO plugins through Pro's own readers, and suggests each plugin's update ability only when it is registered.
* Changed: the Pro upsell names the integrations Pro adds for UpdraftPlus, Duplicator, BackWPup, Wordfence, Solid Security, The SEO Framework, Slim SEO, SmartCrawl, Forminator, WS Form, Kadence Blocks patterns and FunnelKit when one of them is active.
* Kits: kit.json can declare another plugin's storage (vendor_storage) and the kits a kit needs exported with it (requires.kits); the coexistence check honours the first and the exporter the second. No abilities were added or changed, and existing connections keep working.

= 1.15.0 =
* New: a redesigned admin. GoBuildWP opens on a Dashboard that answers "is everything fine, what did agents do, does anything need me": whether agents can act and under which safety profile, connected clients and when they were last active, changes in the last 24 hours and how many can be undone, writes waiting for approval, and the latest changes with an Undo button on each. The connection wizard, credentials and endpoints moved to their own Connect screen. Sixteen screens are grouped into six sections - Dashboard, Connect, Activity, Agent, Studio, Settings - with the screens of each section as tabs under the page title, and Activity shows a count when writes wait for approval. The look is calmer: hairline cards instead of heavy black borders, sentence-case labels, and green for "agents can act" instead of red. The header is one white bar with the site's name and the agents' state, and the toolbar shows "GoBuildWP" with a green, amber or grey dot instead of a red "GoBuildWP ON" block. Every screen now fits a phone without scrolling sideways.
* Prompts shows one industry at a time with the search still covering all of them, in a two-column grid; the screen was 94,000 pixels long with Pro's briefs. The free briefs' industries now match Pro's, so "Pets" and "Pets & animals" are no longer two categories.
* New: search and replace across posts. wppilot/search-replace-preview builds a reviewed plan - every match, per field, with before and after - over post titles, content, excerpts and chosen meta keys, serialized values and builder JSON included (Elementor's escaping is kept byte for byte). wppilot/search-replace-apply writes only posts that are in the plan and unchanged since the preview, 100 per call or as a background job, takes a before-image of exactly the fields it touches, and records one undoable change per post under one batch, so one post or the whole run can be undone. Serialized objects, guids and options are never touched; regular expressions are off unless asked for and bounded. wppilot/search-replace-status and wppilot/search-replace-cancel follow and stop a background run.
* New: image editing. wppilot/edit-image resizes, crops, rotates and flips an image as a new attachment or in place, the way WordPress's own image editor names files and keeps backup sizes, and either can be undone. The original file is never deleted.
* New: accessibility. wppilot/audit-accessibility reads a page as visitors receive it and checks it against WCAG 2.2 - language, title, headings, alt text, labels, accessible names, duplicate ids, ARIA references, landmarks, tab order, zoom lock, iframe titles and table headers - with the success criterion and level on every finding, a score, and what it could not check (contrast, focus, target size, script-rendered content). wppilot/audit-media-alt finds images with missing or filename alt text; wppilot/get-media-image shows an image to the model as MCP image content so it can write real alt text; wppilot/update-image-alt sets it in bulk with undo per image or per batch.
* New: content audit. wppilot/audit-content finds broken internal links, orphan pages, thin content, missing SEO descriptions and structured-data problems (and notes that FAQ and HowTo markup no longer earns Google rich results) across the site as a background job; wppilot/audit-content-status reports it.
* New: Block Notes. An agent can leave a note on a block for a person, reply, and resolve, and read the notes a person resolved - the review loop WordPress 7.1's Notes make possible. Every note it writes can be undone.
* New: site tools. WP-Cron list, run and delete (a deleted event can be put back at the same time and schedule), Site Health tests, transient flush, and - only on Developer Full Access, and recorded in the change log without their results - an options explorer and wppilot/database-query, one checked, read-only SELECT with sensitive columns redacted and the users, options and key tables refused.
* New: multisite. On a network, wppilot/network-list-sites and wppilot/network-run-ability run an ability on another site through that site's own safety profile, confirmation and gates, and record the change in that site's own log.
* New: Connection Doctor, on Diagnostics and as wppilot/connection-doctor. It finds what stops an MCP client connecting - a firewall, Cloudflare, ModSecurity, a stripped Authorization header, disabled Application Passwords, OAuth clock skew or URL mismatch - and gives the exact fix for the server it runs on.
* New: an MCP server card at /.well-known/mcp/server-card.json and an MCP Registry entry; skills and industry briefs served as skill:// resources; MCP Tasks on the modern transport, with background execution for job-backed abilities, polling, results, listing and cancellation (other abilities complete synchronously); and an MCP Apps preview card, which hosts that support it show beside a preview with an Apply button.
* New: a person, not the model, can confirm destructive calls. With Settings > Confirmation mode set to "A person approves", confirm=true from the model is no longer enough: the person approves the exact call in their MCP client, or on a one-time wp-admin link that shows exactly what will run. Each change records how it was confirmed. Off by default; existing connections behave as before.
* New: access tokens are agent identities. A token now has an expiry chosen at creation, can be limited to chosen abilities or categories, and can carry a safety-profile ceiling below the site's. Everything outside its scope is refused and hidden from it, and its changes are attributed to it by name.
* New: other plugins' abilities are governed like GoBuildWP's own. Any ability can be set to always require confirmation or a minimum profile on the Abilities screen, and a declarative ledger map lets writes by other plugins' abilities (Rank Math and SEOPress settings to start) be undone instead of recorded as irreversible.
* Changed: the change log is stored in its own database table instead of one option capped at 500 rows. Existing history is moved across on update, in order; if the table cannot be created the option keeps working and Diagnostics says why. The Changes screen and the export page through it in the database. History is kept for 10,000 changes or 90 days, and large before-images of changes older than 30 days are dropped.
* New: the features above are portable kits (includes/kits), each exportable into another plugin with scripts/export-kit.php.
* Elementor edits can be undone without Pro. The free Elementor writes (add, edit, move, duplicate, reorder and delete elements, set content and page settings) had no before-image, so on a site without Pro every one was recorded as "No supported before-image". Pro's own Elementor capture still takes over when Pro is active.
* Fixed: undoing a change to a post could report "did not match the before-image" when the post was restored exactly, because the check depended on the order WordPress returned the post's meta in. The check is now order-independent, and when an undo really does not match it names the fields that still differ.
* Fixed: an invalid or expired access token got WordPress's generic 401 on any site where another plugin reads the current user while plugins load (Beaver Builder does), so the client never saw WWW-Authenticate invalid_token and an expired token never said it had expired. The credential is now judged at REST authentication when nothing judged it earlier.
* Fixed: a revalidated server card was always sent in full, and the approval page had no browser tab title.
* 26 new abilities (168 in all). The database, options and PHP abilities stay on Developer Full Access only. No existing ability changed its permissions; existing connections keep working and do not need re-authorising.

= 1.14.0 =
* Chat now runs every ability through the same controls as MCP and the REST API. It called the ability directly, so a Chat write skipped the write rate limit, the design gate and anything a companion plugin adds, such as Pro's approval queue. Every execution path - the modern MCP transport, GoBuildWP's REST route, WordPress core's ability runner, Chat and Pro's approval replay - now runs one gate pipeline: safety profile, confirmation, then the shared wppilot_pre_ability_execute filter. Chat's approve button is the confirmation, so the model is not asked for confirm=true there. The require-preview rule still stands aside for Chat, as its setting has always said. The modern-only wppilot_modern_mcp_pre_ability_execute filter is no longer fired; hook wppilot_pre_ability_execute, which now runs on that transport too.
* New screen: GoBuildWP > Changes. Code and skills have long said to undo a mistake "from the Changes screen", but there was no such screen; the only way back was the rollback-change ability, run by the same agent that made the mistake. The screen lists every recorded change newest first, filters by ability, agent, date range, status and kind, shows one change's redacted input, result and undo verification, and undoes one change, one bulk batch, or every undoable change a filter shows - all of one agent's work today, say - up to 200 at once, newest first, with a report of any that did not come back. The same filters download as CSV or JSON, without before-images; CSV cells that a spreadsheet would run as a formula are defused.
* New ability: wppilot/export-changes. The change record as flat rows for a client report or an audit, filtered like the Changes screen and paged 500 rows or 256 KB at a time. Before-images never leave the site.
* Undoing a change to a draft failed. A draft that was never scheduled has a floating date, and WordPress moves a floating date to "now" whenever the post is updated unless told the date is deliberate, so the undo put the content back and then failed its own check on the date, reporting "Rollback ran but the observed state did not match the before-image". It keeps the draft's date now.
* Bulk writes record one undoable change per item, grouped, so a batch can be undone as one or item by item, and one call cannot fill the change record: before-images stop after 1 MB per call and later items say plainly that none was kept. The change record is written under a database lock, so two writes recorded at the same moment can no longer erase each other.
* An ability can now keep a sensitive read off Production Safe and have every call recorded: meta.safety.min_profile names the least permissive profile it runs under, and meta.safety.audit_reads records who ran it and with what input, never what it returned. A read-only ability used to pass every profile, because its read-only flag was checked before anything else.
* Kits. A feature can now be built as a self-contained folder - kit.json, abilities, skill - on a small runtime that supplies the safety profile, confirmation, change record, undo and background jobs, so the same folder can be exported into another plugin with scripts/export-kit.php, renamed under that plugin's own prefix. wppilot/export-changes is the first. A kit that cannot load on this site says why instead of registering nothing.
* Modules can register how their own changes are undone (wppilot_register_rollback_strategy), so a write that keeps its own before-image is no longer recorded as impossible to undo.
* One new ability, read-only. No permission changes; existing connections keep working and do not need re-authorising.

= 1.13.0 =
* GoBuildWP no longer fatals beside another plugin that bundles the same dependencies. Composer derives the Jetpack autoloader's namespace from the dependency set, so two plugins with similar composer.json files generate the identical namespace and the second one to activate dies with "Cannot declare class Automattic\Jetpack\Autoloader\jp<hash>\al<version>\Autoloader, because the name is already in use" - a fatal that names a hashed class nobody can search for and reads as a GoBuildWP bug. The namespace is now pinned to one GoBuildWP owns, so it cannot collide with a hash another project happened to generate. On WP_DEBUG sites the autoloader's own conflict check is switched on as well, so the next collision of any kind is logged by name instead of appearing as a white screen.
* GoBuildWP now shares a site with Elementor's MCP, and with any other plugin that ships the WordPress MCP Adapter. Only one copy of the adapter can ever load - its classes are global and unprefixed - and which copy wins is decided by version arbitration across every plugin's classmap, so GoBuildWP can find itself running Elementor's copy. class_exists() cannot see this: it answers true whoever provided the class. GoBuildWP now resolves the adapter's actual origin and behaves accordingly. When the copy is its own, nothing changes. When it is not, GoBuildWP stops renaming the adapter's default server and stops claiming the legacy alias slug - that server belongs to the plugin that bundled the adapter - and registers /wp-json/mcp/wppilot explicitly instead, so every existing client configuration keeps working rather than 404ing the moment another MCP plugin is installed. Creating a server whose id is already taken is skipped rather than thrown, because that throw happens inside mcp_adapter_init and would take down every server on the site rather than one registration.
* wppilot/system-status reports which copy of the adapter is loaded, its version, and the plugin directory that owns it. On a site where MCP behaves oddly and more than one MCP plugin is installed, that is the first line to read.
* New ability: wppilot/elementor-audit-atomic-readiness. Elementor 4 introduced atomic elements, and the tooling built on them - Elementor's own MCP included - works on atomic documents. Every site built before that is classic widgets, and the failure is not an error message: the tool connects, reports success, and cannot edit the page anyone asked about. Nothing on the site says why. This reads every Elementor document rather than one, classifies every element, and answers it in one call: how much is already atomic, how much GoBuildWP can convert, which widget types block the rest and in how many documents. A widget whose plugin has been deactivated is reported as unregistered rather than counted as classic, because those elements are lost rather than converted and that has to be known before anybody starts.
* The classic-to-atomic equivalents the audit counts on live in one table the converter reads too, so an audit that calls a widget mappable and a converter that then cannot map it is not a state this can reach. A widget with no honest structural equivalent is absent from it: icon-list can be rebuilt as a flexbox of paragraphs, but that is a reconstruction with its own losses and belongs in a conversion report rather than in a table claiming equivalence.
* wppilot/verify-rendered-page can now check a page that is not published. It used to refuse - a draft has no public URL, and fetching one anonymously returns the 404 rather than the page - which sent the check away at exactly the moment it was most wanted, while the page was still a draft. The preview is now fetched carrying the caller's own session, opened for the request and destroyed as soon as it returns. Nothing is escalated: the cookies authenticate the current user and nobody else, they are valid for a minute, they are only ever attached to a URL on this site, and a caller who cannot read the draft in wp-admin cannot read it here. The result says it was a preview, and says what a preview does not exercise.
* New ability: wppilot/get-page-view-link. Every other check in GoBuildWP reads the page; none of them can see it. Whether a headline is legible over the photograph behind it, whether the third card wrapped onto its own row at 1024px - those are visual facts, and reading HTML answers none of them. Most AI clients already have a browser, so this closes the loop with the one they have rather than by building a renderer: it returns the URL to open and the three widths worth looking at. For a draft it says a signed-in browser is needed and names wppilot/create-admin-access-link, rather than minting a session itself: a read-only ability that handed out a wp-admin session would have walked past the critical rating, the confirmation and the safety profile that guard that link.
* Visual verification. Every other check in GoBuildWP reads the page; none of them could see it, and the failures that matter most to whoever owns the site are visual - a headline illegible over the photograph behind it, a card that wrapped onto its own row at 1024px, an element that rendered and is invisible. The rendered check has listed visual-regression and responsive-breakpoints under not_checked since it was written. This is that gap: wppilot/register-capture records a screenshot against the page it shows, wppilot/list-captures finds the one to compare against, and wppilot/compare-captures reports how much changed and exactly where.
* The comparison samples a grid of average colours rather than comparing pixels. A pixel-exact diff reports every page as changed - a font rasterises differently, an image is re-encoded, a carousel is on another slide - and a check that cries wolf is a check that gets switched off within a day. Adjacent changed cells are merged into regions, with one cell of slack so the gap between two words in a changed headline does not split one finding into two, and the regions come back in the coordinates of the newer image so they can be pointed at. A page that got taller is reported separately, because sampling normalises both images to the same grid and added content would otherwise shift every cell rather than show up as a region.
* GoBuildWP still bundles no renderer and never will: a headless browser inside a WordPress plugin is not something a shared host survives. The screenshot is taken by a browser that already exists - the AI client's own, through wppilot/get-page-view-link - and these abilities store what comes back and compare it. Captures are attachments and everything refers to one by id, because a preview record is capped at 256KB and the whole change ledger at 4MB: a base64 screenshot in either would evict real history to store a picture.
* Ten captures are kept per page, oldest pruned on write. Comparing two captures taken at different widths is answered with the comparison and a warning, because "everything changed" is true there and useless.
* A queued block-editor change whose target page was deleted stranded its whole batch. The queue already refused a missing target at commit, but an item never reached commit: it was handed to a browser tab that cannot open a post which is not there, and the tab had nothing useful to report back, so the item sat running until its lease expired and the batch never finished. The target is checked when the item is claimed now, so it fails once, names the reason, and the rest of the batch carries on.
* The Prompts screen is usable at three hundred briefs rather than ten. Every brief printed open, which came to 2.7MB of markup before anybody saw the first one; bodies are collapsed now - still in the page, so Copy and find-in-page reach them - and a filter sits above the sector headings.
* A brief's `scope` survives the parser. It says whether a brief builds a landing page, a whole site, a store, a course or a booking site; free's own briefs are all landing pages and leave it empty, and a field the normaliser dropped was one nothing could show or filter on.
* Five new abilities, one of them a write that only records an attachment as a capture. One existing ability widened. No permission changes; existing connections keep working and do not need re-authorising.
* GoBuildWP runs on PHP 8.0 again, as its header promises. Four call sites used first-class callable syntax (`intval(...)`, `is_array(...)`), a parse error before PHP 8.1: the design capture and visual-runtime files, and the new Elementor atomic-readiness audit. They pass the function name instead.
* Security: the modern MCP transport answered tools/list and server/discover before WordPress had checked who was asking, so an anonymous request could read every ability's schema and the safety profile, and the transport ignored the AI Abilities master switch. It now answers only a caller who passes the same checks as the legacy route, and everyone else gets the normal 401 challenge. Also in 1.12.0.
* Security: GoBuildWP access tokens and OAuth bearer credentials were honoured on every request, not only the REST API, so a Read Only token could post a comment or drive admin-ajax as the administrator. They are accepted on REST requests only, as WordPress scopes Application Passwords.
* Security: wppilot/verify-rendered-page sent the caller's session cookies to wherever a draft's preview redirected. A signed-in check no longer follows redirects, sends cookies only to this site over the same scheme and port, and every other fetch it makes validates each redirect hop.
* Captures: pruning a page's capture history permanently deleted any media item that had been registered as a capture - a logo or product photo could go. Only files GoBuildWP created are deleted now; anything else is simply unmarked. Captures are looked up per page, so a quiet page's history no longer disappears behind busier pages, and the capture runtime requires upload rights and edit access to the page.
* Rollback: restoring a post's meta stripped a level of backslashes, so Elementor, Bricks, Breakdance and Oxygen data came back as JSON that no longer decoded. Terms, comments and menus were restored and then reported as failed, because they never reported verification; they compare a fresh snapshot now.
* wppilot/elementor-audit-atomic-readiness is listed over MCP and honours the master switch; it registered but was never served. Modern-transport writes count against the write rate limit.
* PHP 8.0: seven more places unpacked string-keyed arrays, which PHP 8.0 refuses at run time - among them every successful elementor-set-content call and every preview apply.
* Security: WordPress core's own ability runner (/wp-abilities/v1/abilities/{name}/run) reached abilities past GoBuildWP's confirmation gate, write rate limit, preview and design gates and Pro's approval queue. It now runs through the same controls as GoBuildWP's own REST route; a destructive call without confirm is refused there, and the refusal names the GoBuildWP route that accepts confirm.
* The admin tab bar no longer cuts off its first group when the screen is narrower than the tabs; the groups wrap onto a second centred row.

= 1.12.0 =
* WordPress 7.1 support. The release changed what `wp_get_abilities()` answers: every call now runs the `wp_get_abilities_item_include` and `wp_get_abilities_result` filters, the bare no-argument one included, so the function reports what a site publishes rather than what is registered. GoBuildWP read it in both senses. Discovery still calls it, because a site that filters its registry means the filtered list; the ability policy and the Abilities screen now read the registry itself, because a rule that enforces something across the registry cannot be applied to a list another plugin has already narrowed - an ability hidden from discovery would have stayed registered and stayed executable while the screen reported it as switched off.
* The safety profile now applies to every caller, not only to GoBuildWP's own transports. WordPress 7.1 added `wp_ability_permission_result`, which runs inside `WP_Ability::execute()` and in the standalone permission checks the REST API and WP-CLI perform, so core REST, WP-CLI, the AI Client, another MCP adapter and GoBuildWP Chat all get the same refusal that MCP has always given. Until now the profile was enforced by unregistering what it refuses, which had to stand down on the Abilities screen so that screen could list disabled abilities - a request to it that also executed an ability ran under no profile at all. The filter only ever denies: a call something upstream already refused keeps that refusal.
* An ability registered with WordPress 7.1's channel-independent `public` flag is now reachable over MCP. GoBuildWP's own `mcp.public` stays authoritative and an ability setting it to false stays hidden, but the fallback follows core's own precedence - the channel's flag, then `public`, then false - so an ability written by someone who never heard of this plugin is served rather than ignored.
* Tool schemas are prepared for clients before they are advertised. Every ability on the site is exposed, not only GoBuildWP's, and a schema authored for WordPress carries things a JSON Schema validator does not accept: a property-level `required: true` where the parent needs a `required` array, and the PHP-only `sanitize_callback`, `validate_callback` and `arg_options` keys. A client validating arguments against the advertised schema then dropped the field or refused the tool. WordPress 7.1's `wp_prepare_json_schema_for_client()` does the translation where it exists. What a call is allowed to send is unchanged - the ability's own schema still validates input.
* The change ledger records the risk of the ability that actually ran. Both execution hooks now hand the `WP_Ability` instance to their callbacks, so the ledger stops looking the name up again after the write - a lookup that came back empty, and recorded a plain "write", when the ability being recorded had switched the safety profile or disabled something and the policy had unregistered rows mid-request.
* The compatibility block published to clients now carries `tested_wordpress_version` and a `wordpress_capabilities` map. The capabilities are probed by asking for the function or class that implements them rather than by comparing version strings, because a feature plugin, a backport or a release candidate can all put a capability on a WordPress whose reported version says otherwise.
* Everything here is inert on WordPress 6.9 and 7.0. The minimum is still 6.9, no ability was added, removed or renamed, and existing connections keep working.

= 1.11.0 =
* The prompt library is now one complete landing-page brief per industry. The earlier packs were organised by editor and by task and named ability chains, which taught the agent which calls to make and nothing about what to build, so every business got the same centred hero with three cards under it. Ten briefs ship - a bakery, a yoga studio, a dental clinic, a landscaper, a bookshop, a wedding photographer, a plumber, a family law firm, a pet groomer and a brewery taproom - and each carries a flat palette, a type pairing, a design signature that makes its page unlike the others, the sections it must communicate, verbatim content facts, and one shared standards block covering WCAG 2.1 AA, real photography, one icon set, builder-native construction and completeness. The Prompts screen gains a builder picker that writes the chosen editor into the first line of every brief.
* Fixed: an OAuth client that registered through Dynamic Client Registration with a `localhost` callback, MCP Inspector among them, was refused with "redirect_uri not registered for this client" from 1.10.1 onward. That release began normalising the callback the client sends onto `127.0.0.1` before comparing it, but a registered row keeps the spelling it was posted with, and the loopback comparison ignores the port while still comparing the host. The stored list is now normalised the same way when it is read, which also repairs rows registered before this fix. Client ID Metadata Document clients were already normalised and are unaffected.

= 1.10.2 =
* Fixed: a native client that got through the authorization screen in 1.10.1 could still fail at the token exchange with "The request is missing a required parameter, includes an invalid parameter value, includes a parameter more than once, or is otherwise malformed." The authorization endpoint normalises a `localhost` callback onto the IPv4 literal, so the issued code carries the normalised spelling, while the client sends the token request with the spelling it started with. The two are compared as exact strings and disagreed on the host name. The token endpoint now normalises the incoming `redirect_uri` the same way, so both sides are in one form. A client that never used a loopback callback is unaffected.

= 1.10.1 =
* Fixed: OAuth sign-in failed with "Unknown client_id" for any client whose Client ID Metadata Document declares loopback callbacks without an `application_type`. OpenID Connect defaults that field to `web`, a web client may never redirect to loopback, and so the whole document was refused - which is every desktop MCP client registering this way, Claude Code among them. A document whose redirect URIs are all loopback or private-use is now read as `native`. One that declares `web`, or mixes a remote HTTPS callback into the list, is refused exactly as before.
* Fixed: authorization then stopped at "redirect_uri not registered for this client" when the callback was spelled `localhost`. RFC 8252 section 7.3 lets a native client bind an ephemeral port, but the port-agnostic comparison recognises only the `127.0.0.1` and `[::1]` literals, so `http://localhost:53821/callback` was matched as an exact string against the registered `http://localhost/callback` and failed on the port. A loopback callback is now normalised onto the IPv4 literal on both sides before anything compares it.
* Neither fix changes what a connected client may do on the site: the safety profile, the approval rules and the ability surface are untouched.

= 1.10.0 =
* Elementor editing is free. Sixteen abilities move out of Pro and into this plugin: read a document, discover the widgets and the seventy-three atomic style properties the install offers, inspect one widget's parameters, find elements, replace a document's tree, and add, edit, move, duplicate, reorder and delete elements, plus read and write page settings. They register when Elementor 3.6 or newer is active and stay absent otherwise.
* Why this and not more: the design system already shipped here - a direction, a type scale, the compositions a page may be built from, and the checks that grade the result - while the builder that could apply any of it did not. A free install could derive a design it had no way to use. That is not a teaser, it is a dead end.
* The line is drawn at editing. Composing a whole page from a description or a reproduction spec, templates and theme parts, display conditions, popups, forms, dynamic tags, interactions, global classes and variables remain Pro. Free can edit an Elementor page; Pro can compose one.
* Pro no longer carries its own copy of the Elementor runtime, the tree and style helpers, or the primitive abilities. It imports them from here, so the two cannot drift apart on a site running both. GoBuildWP Pro 1.5.0 or newer is required alongside this release.
* Fixed: a single unsupported property no longer takes down a whole `elementor-set-content` write. The offending key is dropped, the element keeps everything else, and the response names what was lost. Pass `strict` to refuse the write instead.
* Fixed: a list-shaped style value, `transform` among them, raised a TypeError instead of being wrapped.

= 1.9.0 =
* The spec can describe a composition now, not only a stack. `grammars.php` has always named the shapes a page can be built from - a panel crossing a section boundary, layers on a common ground, a column that stays put while its neighbour scrolls, an element escaping the page edge - and `wppilot/list-layout-grammars` has always returned them. Nothing could ask for one. Every page this pipeline built came out as a stack of full-width bands, because a stack of bands was the only thing the vocabulary could say, and the grammar most worth having was the one that module describes as "the one thing a stack of bands can never do". Four new layouts close that: `bleed`, `layer`, `index-detail` and `overlap`.
* Two-column splits can name their own ratio. The named splits cover the ratios worth having an opinion about and a page measured off a real reference does not land on them - one homepage alone uses 46:54, 36:64 and 54:46, none of which was in the list. Writing `split-46-54` now generates that grid on demand; `split-fifty-fifty` is still refused, so a typo does not quietly become a stack.
* An item can hold blocks. An item was a flat record of eight strings, and that is most of why every card this pipeline produced looked like every other one: a real pricing tier is a ribbon, a name, a muted eligibility line, a price row where the figure and the suffix share one baseline at different sizes, a feature list and a button, and none of that is expressible as icon-eyebrow-title-text. The model was not short of taste; the vocabulary was short of a sentence. The flat keys stay, because most sets really are a mark and two lines and making those declare a block list would be a tax on the common case. An item is one or the other, never both - a hybrid needs an ordering rule nobody can predict from the schema.
* A set can name variants. A set whose members are identical is a table with padding on it, and the difference between a pricing table and a pricing page is that one tier reads as the chosen one. Declare `variants` on the block, then set `variant` on the members that take it. No variant names ship with the plugin: they are yours, and an undeclared one is refused rather than silently ignored, because a featured tier that renders identically to its neighbours with no error is the failure this exists to prevent.
* Blocks and items can declare hover and focus states, which the builder stores as real Elementor style variants rather than as a second style fighting the first.
* New layouts `row` and `row-baseline`, for a figure and its suffix on one line at two sizes. Elementor's atomic style schema has no `baseline` in its `align-items` enum - a class asking for one is refused at compile time - so this resolves to `flex-end`, which aligns the line boxes rather than the baselines. Give the suffix a line-height near 1 and the two agree.
* New ability: `wppilot/check-composition`. Validates a block subtree on its own, through the same normalizer a spec's blocks go through, and reports its structural signature and how many set members were composed rather than written as shorthand. It exists so composition knowledge can be captured rather than shipped: a file per industry saying what a dentist's homepage contains works the day it is written, is stale a month later, covers only the trades somebody listed, and hands every dentist the same page. Moving the prescription from pixel level to section level does not stop it being a template.
* The site now tells an agent what it looks like before the agent does anything. The instructions injected on every request carried a palette, a type stack and a Don't list; they now also carry the type, spacing and corner ladders, the compositions this design permits, and the standards a finished page owes - contrast floors, one h1, fill a section before starting the next. All of that was already derived and none of it was being sent. A well-written page brief supplies the same things and then works for the one page somebody pasted it into; this arrives with every request, including "make the hero bigger".
* A site with no saved direction now says so, and names `wppilot/generate-design`, `wppilot/adopt-design-from-site` and `wppilot/compare-references` as the way out. Silence was the worst available answer: asked for a page with no direction, any model returns the same competent anonymous result, and by the time anybody looks the page already exists.
* Fixed a card grid of one, or of five or more, losing its grid entirely. `Spec\layouts()` declared `split-2` through `split-4` and the builder asked for `layout-split-{n}` from the item count, so a set of six requested a role nothing had generated, the class was silently skipped, and the cards stacked in a bare div - on the exact page the docblock uses as its motivating example.
* Fixed a block's layout being accepted at save time and quietly degraded to a stack at build time. Sections have always had their layout checked against the list and blocks never did. That was survivable while every layout was a grid; with a baseline row in the vocabulary a misspelling turns a price into two lines and nothing anywhere says why.
* Fixed the structural signature used for distinctiveness walking only the top level of a page. Two pages that differ entirely inside their groups reduced to the same string, so the check called them the same page and reported nothing - and nesting was about to make that much worse.
* Fixed a generated design declaring three type roles where a spec needs eight. `wppilot/generate-design` emitted heading, section and body, so every rule that asks whether a size is on the scale had almost nothing to measure against: a page could use nine sizes and be reported as restrained. It now derives the full ladder from the one ratio, and returns the same scale in the shape a spec takes, so a design and its spec agree about every number instead of one being retyped from the other.
* Fixed card padding, inner gap and button insets being written into the plugin rather than derived. They were 32px, 12px and 18 by 32 on every design this plugin had ever produced, which is authored composition living inside the tool - two specs that agreed about nothing else still produced cards with the same inset. They now come from the design's own gap and radius. A nested filled block also takes a concentric corner, because two curves read as one object only when they share a centre.
* Fixed the type role deciding the heading tag. Asking for `display` or `h1` produced an `<h1>`, so a page with a hero and a loud closing band shipped two, and a price set at heading size shipped one per tier. A block can now name its `tag` separately; without one the role still decides, so every spec written before this behaves exactly as it did.
* Fixed `wppilot/check-design` reporting rules as checked that never ran. With no active design there is no palette, no ladder and no Don't list, and seven of the rules read them - yet the full list came back either way. That converts "there was nothing to check against" into "we checked and it was fine", which is the exact failure the `not_checked` list beside it exists to prevent.


= 1.8.1 =
* Fixed wppilot/reorder-menu-items destroying the menu it was asked to reorder. wp_update_nav_menu_item() merges its own defaults over whatever it is not given and rewrites the item, so a call carrying only a position blanked the label, cleared the link, reset the type to "custom" and flattened the nesting of every item it touched - and reported success. Reordering now seeds each write with the item's existing values first, the same way upsert-menu-item already did.
* Fixed the Chat screen rendering nothing. Its script was enqueued from a build manifest looked for one directory too deep, so the file was never found and the fallback declared no dependencies at all: the bundle printed before wp-element and wp-components and threw on execution, leaving an empty page with no error anybody could see.
* Fixed a site-wide fatal reachable through wppilot/update-site-settings. Any string was accepted as timezone_string, and an identifier PHP cannot resolve makes wp_timezone() throw from inside current_time() and wp_date() - every request that formats a date, for everyone, until the option is repaired by hand. A typo'd timezone is now refused with the setting named.
* Fixed skill and design bodies being corrupted on the way in. Escape processing meant for AI clients that JSON-encode their tool arguments twice was applied to every submission, so a Windows path in a skill turned C:\temp into C:<TAB>emp and a regex \d+ into d+ - silently, on save, for content typed into wp-admin or read from an uploaded .md file. The rescue now runs only on a body carrying the signature it exists for: no real line breaks, but literal \n sequences where they should be.
* Fixed backslashes being stripped from comments, skill bodies and design titles. wp_insert_comment(), wp_update_comment() and wp_update_post() unslash what they are given, so anything handed to them unslashed arrived one round of stripping short.
* Fixed a Block Editor batch that failed at commit becoming permanently stuck. The retry re-serialized the same spec to byte-identical content, WordPress reported "no change" as false, and the item was failed for it - with a message blaming WordPress - on that attempt and on every attempt after it.
* Fixed the "Get Pro" link never appearing on the Plugins page. The filter it hooked was built from the wrong path, naming a file that does not exist, so nothing ever called it.
* Fixed an uncaught fatal in wppilot/edit-file when old_string was empty. PHP 8 throws on an empty needle rather than returning a count, so the ability died instead of refusing. It now refuses and points at write-file.
* Fixed wppilot/set-featured-image and wppilot/update-post reporting a failure when the requested image was already the featured image. Both are idempotent by declaration and were the one thing a retry could not do.
* Fixed a 500 on a Chat session update carrying an empty or non-JSON body, which reached a typed helper as null.
* Fixed the Preview screen describing a preview that expires in 23 hours as having expired 23 hours ago. The relative-time helper is used for both a creation date and an expiry and only ever said "ago".
* Fixed a new skill created as Disabled going live immediately. The form offered the choice and the create path ignored it, publishing every skill regardless.
* Trashing or permanently deleting a skill now verifies the post type first, as every other action on that screen already did.
* The web-apps panel no longer walks you through an OAuth sign-in on a site where the OAuth routes are deliberately not served. On a public plain-HTTP site those endpoints 404 by design; the panel now says so and points at the access token instead of contradicting the method card next to it.

= 1.8.0 =
* New ability: wppilot/adopt-design-from-site. On any site that is not brand new, the brand already exists - in the theme's global styles, in the customizer, in the pages somebody built - and a fresh direction invented alongside it produces work that clashes with every page already there. This reads what the site already has and returns a DESIGN.md draft, with the adopted palette's contrast reported alongside it, because an inherited palette was never chosen and finding out it cannot carry body text is worth knowing before anybody builds on it. Nothing is saved; review the draft, add the reasoning it cannot know, then save it.
* New ability: wppilot/list-design-examples. Seven worked example directions - light and dark, serif and sans, dense and airy, quiet and loud - shipped as real DESIGN.md files that pass the contract. They are reference, not a menu: nothing applies one, deliberately. Fifty selectable palettes produce fifty sites that look like the palette rather than like the business, which is the failure the design system exists to prevent. Read the nearest one to see how a finished direction argues its palette and phrases its rules, then write one for the site in front of you. One of them is a worked example of declaring a deliberate waiver for an anti-slop rule rather than tripping it.
* New ability: wppilot/verify-rendered-page. Everything else checks the string an agent produced. This fetches a published page and checks what the server actually sends - heading order, images without alt text, empty elements, PHP notices that made it into the markup, page weight, and the colours and faces actually present. A build can pass every check on its own output and still land on a page whose accent is the theme's, because the theme's stylesheet loads last. It does not run JavaScript or fetch external stylesheets, and its result says so.
* The Design screen now offers those seven directions as starter kits, shown as colour rather than as a list of hex values, each with a live specimen of its own headline, body text and button. Choosing one copies it into your designs and opens it for editing; it is never activated by the click and never applied to your site, because a palette adopted unchanged is the generic result the design system exists to prevent.
* A design can now declare its type scale, and it is enforced like everything else. Typography roles have always accepted fontSize, lineHeight, letterSpacing and measure - the parser read them - but nothing downstream looked, so a design could set its sizes and every page still invented its own. They now reach the preview, the CSS variables and, with Pro, the builder's own typography presets. The readiness check warns when a design has no scale, and warns separately when it sets sizes without leading, because large type at the browser default is the most recognisable untouched-web look there is.
* All seven shipped starter kits gained a scale suited to their direction, and the test that guards them now fails any example whose heading leading exceeds 1.25 or whose body is not set looser than its heading.
* Fixed the design's own Don't rules matching inside longer words. A rule naming Inter flagged "interrogate", "interest" and "internal" - words an accounting or engineering site uses on nearly every page - each reported as a possible violation of a rule about a typeface. Keywords are now bounded at both ends, and detection of the real thing is unchanged: a page actually set in Inter still fails.
* Uninstalling no longer destroys your designs and skills. Both are documents somebody wrote - a palette argued for, rules about what the site must never do, reasoning that took a conversation to produce - and neither can be regenerated from anything left behind. They were deleted on uninstall without asking, which is the same decision as deleting a site's posts. They are now removed only when the site has explicitly opted in by setting wppilot_delete_content_on_uninstall; absent means keep. Machine-generated data, such as the Block Editor queue, still goes with the plugin.
* The two-h1 finding now names its likely cause and the fix, rather than only stating the count.
* The rendered check follows the page's own same-origin stylesheets now, ranked so builder-generated CSS is read first. Elementor writes every widget's colour and typeface into a per-post stylesheet, so a reader limited to inline styles reported a correctly styled page as carrying none of its design. Quoted font families were invisible too, which is most of them.
* The Design screen was rebuilt around the active design: it opens with that design rendered in its own colours and faces, beside the facts that decide whether it is usable, and the rest is tabs rather than one long column.
* Three new abilities, all read-only. No permission changes; existing connections keep working and do not need re-authorising.
* New ability: wppilot/check-contrast. Computes WCAG contrast ratios for every pair in the active design's palette and reports which surfaces can carry body text at AA. Ask it what to put on a background and it answers with the design's own colours instead of defaulting to black or white, and it names a palette that cannot produce readable text at all rather than leaving that to be discovered page by page.
* The active design is now put in front of the agent automatically. Its palette, type stack and Don't rules are appended to the server instructions every session, so an agent starts a build already knowing the direction rather than having to ask for it. Prevention is cheaper than a refusal; the design block is fenced and marked untrusted, because a design file may have been imported from anywhere.
* No permission changes. One new read-only ability; existing connections keep working and do not need re-authorising.
* New: the active design can now be enforced on the write path. Under **GoBuildWP > Settings > Design direction on writes**, choose Off, Warn and record, or Refuse the write. A write using a colour outside the palette, a font outside the type stack, or something the design's own Don't rules forbid is either recorded in the change ledger or refused outright.
* A refusal names the offending value and the palette entry to use instead, chosen by the role the colour was playing rather than by raw numeric distance, so the agent can correct it in one step instead of guessing again.
* Builder-agnostic: colours and fonts are read out of the write itself, so the same check covers Elementor settings, Bricks trees, Flatsome shortcodes, Gutenberg block specs and raw HTML without per-builder parsing. rgb(), rgba(), hsl() and hsla() values are normalised, so the same colour is judged the same way whichever notation a builder uses.
* Off by default, and it does nothing at all until a design is active. Only the palette, the type stack and the design's Don't rules are gated; the copy and anti-slop rules stay in wppilot/check-design, where an agent asks for a judgement rather than being blocked by one.

= 1.7.0 =
* New ability: wppilot/search-images. Searches Openverse — WordPress.org's openly-licensed media search — for CC-licensed and public-domain images, with licence, licence-type, orientation, extension and source filters. No API key and no account. Every result carries a ready-made attribution string, and the ability's own description tells the agent to carry it into the caption when importing, because attribution is a licence obligation for every CC licence except CC0 and the public domain mark.
* Search and import stay separate abilities on purpose: search is read-only and costs nothing, and a failed import should not look like a failed search. Import a chosen result with the existing wppilot/import-media-url.
* See External services above for exactly what the search sends: the query and filters, and nothing else.

= 1.6.4 =
* Preview inputs are encrypted at rest and malformed, tampered, legacy or oversized payloads fail closed. A stale apply can recover safely, while discard now refuses a preview another request is applying instead of racing it.
* Menu item partial updates preserve fields the request did not change. Moving an item through the wrong menu, or assigning a parent from another menu, is refused.
* The Block Editor Queue can claim an eligible batch beyond the first fifty records instead of reporting that no work exists.
* Front-page site settings reject invalid page combinations before writing anything, and their preview shows the settings that will change.
* Approval hooks now cover abilities added through the modern MCP transport, so Pro extensions receive the same preview and live safety checks as built-in abilities.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.6.3 =
* Fixed a Block Editor Queue batch getting stuck with nobody working it. If the tab that picked up a batch was closed, navigated away from, or left in the background long enough for the browser to throttle it, the batch stayed marked as running and no other tab would take it — an administrator had to cancel and queue it again. It is picked up and retried automatically now. A batch another tab is actively working is still left alone.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.6.2 =
* Fixed blocks from libraries that fill in part of their own settings from the editor — Spectra among them — saving without those settings. An agent-built page could reach the database with the block's style id missing, render unstyled, and still be reported as finished. Blocks now go through the hidden block editor before they are saved.
* Fixed the Block Editor Queue getting stuck on a batch when a request arrived without a readable body. The endpoint ended in a fatal error part-way through an item, and nothing was left running to finish it or hand it back. It now refuses the request and says what to send.
* Fixed revoking a connection not freeing its connection slot. The slot stayed in use until that connection expired on its own, so a site could report itself full immediately after an administrator made room.
* Fixed a recursive directory listing coming back empty because of a single folder it could not read. Unreadable entries are skipped and the rest of the listing is returned.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.6.1 =
* Anonymous usage reporting is now off until you switch it on, and the notice that appeared on your dashboard in 1.6.0 is gone. A feature that only runs when asked for has nothing to disclose on activation, so there is nothing left to dismiss.
* Sites that already turned reporting off in 1.6.0 stay off. Sites that pressed **Keep it on** stay on — that was a recorded choice and this release does not overrule it. Every other site stops reporting on update, whether or not it ever saw the notice.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.6.0 =
* Added optional anonymous usage reporting, so compatibility decisions about WordPress and PHP versions stop being guesswork. A notice explains it the first time you open wp-admin, and one click turns it off under **GoBuildWP > Settings**. Full detail under External services above.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.5.1 =
* Fixed the tool list being rejected outright by AI clients that validate it. An ability declaring no input reached the client with `properties` encoded as a JSON array where a JSON object is required, and one such ability fails the whole tool payload — every tool on the connection, not just that one.
* Fixed abilities that take no input refusing every call with "input is not of type object". 1.5.0 fixed the client sending an empty object; this fixes the server sending nothing at all, which those abilities reject just as firmly.
* Fixed destructive tools demanding a confirmation they never advertised. GoBuildWP refuses them without `confirm`, but the field appeared in no schema and abilities forbid undeclared fields, so a validating client dropped it and the refusal repeated with no way to comply. It is now declared, and required, on the 44 tools that are gated.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.5.0 =
* Added preview before write. An agent can ask what a change would do and get the answer without doing it: `wppilot/preview-ability` returns a before/after diff plus a URL, and `wppilot/apply-preview` performs the write once a person has agreed to it. Nothing is written until then.
* The diff is computed rather than performed, so the site is never touched to produce one — not in a transaction, not on a copy.
* A preview never invents a diff. An ability with no projector is refused by name with a reason, and a call that would be rejected is reported as such instead of being shown as a change that would never have landed.
* New Preview screen under Agent, listing what is waiting for review, with the AI client and the WordPress user named on each entry.
* Applying re-checks that the target has not moved, scoped to the fields in the diff. A change elsewhere is reported as a warning; a change to what you reviewed refuses and writes nothing.
* Applying re-runs the safety profile check and the ability's own confirmation requirement, so it is not a route around either.
* New Settings option, off by default: require a reviewed preview before agent writes, over MCP and REST. Abilities that cannot be previewed are exempt rather than blocked.
* Previews are capped at fifty and expire after a day. Two administrators cannot apply the same one twice.
* Fixed importing media from a URL, which could never run: the ability required a WordPress core file under a name that does not exist, so every call failed before the download started.
* Fixed abilities that take no input rejecting a call that sends an empty object — which is what AI clients send rather than omitting the field. Six abilities were affected, including the site settings read and the diagnostics reports.

= 1.4.1 =
* Fixed the plugin version constant, which still read 1.3.0 in the 1.4.0 release while the plugin header read 1.4.0. That constant is what the update check advertises, what admin assets are cache-busted against, and what GoBuildWP Pro compares when it decides which features the free plugin owns, so the three disagreed on every 1.4.0 install.
* Writing post_content to a page built with Elementor, Bricks or Beaver Builder is now refused instead of silently doing nothing. Those builders keep their layout in postmeta and render that, so the write was stored, reported as successful, and changed nothing a visitor could see — and the builder overwrote it from its own tree at the next save. The refusal names the builder and the ability that does own the page. Passing allow_raw_content_on_builder_post: true still performs the write, for feeds and search, and returns an audit note saying the page itself did not change. Divi, Etch and WPBakery were already covered, because they store markup in post_content where it can be recognised; Breakdance keeps its own separate gate.
* The refusal for hand-written builder storage meta no longer names an ability that is not installed. On a site without Pro it now says to edit in the builder instead, matching what the post_content refusal has always done.
* Fixed the WordPress-core abilities being served by an older copy inside GoBuildWP Pro. Registration order follows plugin load order, which follows activation order rather than the alphabet, so on a site where Pro had been activated first its own copies of 23 core abilities registered ahead of the free plugin's and won. Every fix shipped to those abilities since 1.1.0 was inert on those sites. The free plugin owns them from 1.1.0 onward, and Pro 1.1.2 now stands aside; older Pro builds are unaffected because the free plugin's own duplicate guard still yields to them.

= 1.4.0 =
* Added access tokens, a third way to connect. A long-lived bearer token for callers that have no browser: the Claude Messages API MCP connector, the OpenAI Responses API, cron jobs, automation platforms and scripts. OAuth and application passwords are unchanged and existing connections keep working.
* A token is shown once when you create it and stored only as a digest, so it cannot be read back afterwards. Give it an expiry of 30 days, 90 days, a year, or none, and revoke it on its own at any time. Deleting the WordPress user deletes their tokens.
* A token has exactly the access of the account that created it, checked on every request. Removing that account's permissions closes the token immediately.
* The Connect screen now offers all three methods side by side, with ready-made configuration for seventeen AI clients plus the Claude API, the OpenAI API and curl.
* Creating a token now takes you straight to it, with the three steps that connect a client and a jump to the ready-made configuration. Previously the page reloaded at the top and the token — shown only once — was far below.
* The Overview shows an Access tokens block with a direct link to create or manage one.
* Every method now offers its setup as a copy-paste prompt for an AI coding agent, alongside the existing snippets and instructions rather than in place of them.
* Setup guides for the web chat interfaces are rewritten for their current menus, and Mistral Le Chat, Perplexity and Manus are added alongside ChatGPT and Claude on the web. Each says where the setting actually lives, what plan it needs, and to switch the connector on in the chat afterwards.
* Claude on the web, Le Chat and Perplexity can also be connected with an access token instead of signing in, with step-by-step instructions for each.
* Client setup instructions refreshed for August 2026, including the config format changes in Antigravity, Devin Desktop, Cline and Kilo Code, and a note that Roo Code was discontinued in May 2026.

= 1.3.0 =
* Fixed restoring a revision, which could not run at all on 1.1.0 through 1.2.1. Every call failed with a fatal error before the post was touched. Nothing else was affected, and no other ability shared the fault.
* The same ability reported success when the restore had not written anything. A revision with no restorable fields, or a write WordPress refused, both came back as though the post had been rolled back. Failure is now reported as failure.
* The change ledger now names the agent behind each write, not only the WordPress user. Claude Code, Cursor and Codex usually connect as the same administrator, so entries could not say which of them made a change. Each entry now carries the OAuth client or application password used, and the client name and version it introduced itself with.
* Changes made outside an AI client — in wp-admin, over WP-CLI, or by another plugin — are recorded as direct rather than attributed to the last agent that connected. OAuth client identifiers are stored hashed.
* Ability count is unchanged at 103. No permission changes, and existing connections keep working.

= 1.2.1 =
* Fixed extension search against the themes directory. It returns the author as a profile record where the plugins directory returns a link, so every theme search raised an "Array to string conversion" warning and reported the author as the literal word "Array".
* An extension that declares no minimum WordPress version reports it as false, not as text. That was rendered as "1" or as an empty string; it now reads as no requirement.
* Theme results report a download count where plugin results report active installs, so theme popularity came back as zero. Both are read now.

= 1.2.0 =
* Plugins and themes can now be managed, not just listed. Eleven new abilities: search the WordPress.org directory, read one plugin or theme in detail, activate, deactivate, update, switch themes, install and delete. Ability count is 103, up from 92.
* Installing and deleting write executable code to the server, so they are treated like PHP execution: blocked on Production Safe and Read Only, available in Developer Full Access with explicit confirmation.
* Activating, deactivating, updating and switching themes fetch nothing and write no files, so they work on a live site — but each one can take a site down, so all four require explicit confirmation. Ordinary content editing is unaffected.
* An install leaves the plugin inactive and the theme unswitched, so the review step is a separate, separately confirmed call.
* Sites with DISALLOW_FILE_MODS set, or without direct filesystem access, get a named refusal explaining what to fix instead of a stalled or partial install.
* Security: GoBuildWP cannot deactivate or delete itself, an active plugin cannot be deleted before it is deactivated, and the active theme and its parent cannot be deleted. Slugs, plugin files and package URLs are validated before use.
* Activation, deactivation and theme switches are recorded in the change ledger and can be rolled back. Installs, updates and deletions are recorded as non-reversible, each stating why and how to undo it manually.

= 1.1.0 =
* The WordPress core surface now ships in the free plugin: content, taxonomies, media, comments, revisions, menus, privacy-minimized user reads, and an allowlisted settings surface. No licence, entitlement service or Pro install is involved. Ability count is 92, up from 42.
* New content is created as a draft unless publication is requested explicitly. An absent, blank or malformed status resolves to draft before any capability check, so nothing is published by accident.
* Capabilities come from each post type's and taxonomy's own capability object, so a custom post type declaring its own set is enforced on its own terms. Publishing is checked separately from editing.
* MCP 2026-07-28 is served alongside 2025-11-25. The new revision is stateless, no handshake, no session. Existing clients are unaffected and do not need to reconnect.
* Client ID Metadata Documents are now the preferred OAuth registration mechanism, with Dynamic Client Registration kept as a fallback.
* Security: create, update and delete of content previously ran on a single administrator check and ignored the post type's capabilities and post ownership. Both are now enforced.
* Security: menu item URLs are validated before storage. A javascript:, data: or vbscript: URL was previously stored verbatim and rendered into a link.
* Privacy: user reads no longer return email addresses by default. Login name, roles and registration date require the capability to list users; the email address requires the capability to edit them.
* Comment listings withhold commenter email and IP from accounts that cannot moderate comments.
* The change ledger covers the new operations, with verified rollback for term edits, comment edits and moderation, menu placement and menu ordering. Permanent deletions are recorded as non-reversible with the reason stated.

= 1.0.0 =
* Initial public release.
* MCP server built on the WordPress Abilities API and the official MCP Adapter.
* OAuth 2.1 with PKCE and dynamic client registration, or application passwords.
* Generated connection configuration for sixteen AI clients.
* Overview screen showing which AI clients are connected and how much they call.
* Production Safe, Read Only and Developer Full Access profiles, enforced on the server.
* Explicit confirmation for destructive and critical operations, with a redacted change ledger and rollback.
* Per-credential rate limiting on writes.
* Skills, site instructions, and a guarded sandbox for agent-authored PHP.

== Upgrade Notice ==

= 1.18.6 =
WPPilot is now GoBuildWP: same plugin, settings and connections. Also closes subscriber access to the MCP tool list and tightens the PHP sandbox. Update together with GoBuildWP Pro 1.12.4.

= 1.18.5 =
Records the PHP error behind "There has been a critical error" so you and GoBuildWP Cloud can see what broke, and keeps Cloud current within seconds. Update together with GoBuildWP Pro 1.12.3, whose safe updates no longer roll back on LiteSpeed servers for an error they caused themselves.

= 1.18.4 =
Adds PageSpeed checks with no Google key needed and Site Kit dashboard sharing, and lets GoBuildWP Cloud show why a backup is stuck. Update together with GoBuildWP Pro 1.12.2 for All-in-One WP Migration backups on any host.

= 1.18.3 =
Prompts is back in the WordPress admin menu. No ability or permission changed.

= 1.18.2 =
Fixes GoBuildWP Cloud and OAuth connections failing on abilities that use WordPress's REST API (block themes, Cloud backups with All-in-One WP Migration, several Pro abilities). Update if you connect through GoBuildWP Cloud, Claude or ChatGPT.

= 1.18.1 =
Reads All-in-One WP Migration backups, so sites that back up with it can use GoBuildWP Cloud's Backups page and GoBuildWP Pro 1.12.1's safe updates.

= 1.18.0 =
Adds GoBuildWP Cloud update, backup and safety-policy reporting, AI-designed site headers, the block themes kit, undo for Elementor's and WooCommerce's own MCP servers, and the client-reported Elementor and Polylang fixes. Update before GoBuildWP Pro 1.12.0, which requires it.

= 1.17.4 =
Fixes HTTP 500 errors for GoBuildWP Cloud and OAuth connectors on sites running Yoast SEO. No ability permissions changed.

= 1.17.3 =
Fixes AI clients that could discover abilities but not run them. Reconnect the client after updating. No ability permissions changed.

= 1.17.2 =
Visual only: the admin screens use the gobuildwp.com brand purple. No ability permissions changed.

= 1.17.1 =
When connecting GoBuildWP Cloud fails its connection check, the Connect screen now says why and what to change. No ability permissions changed.

= 1.17.0 =
New: GoBuildWP Cloud (optional). Connect this site to an app.gobuildwp.com account and reach every connected site from ChatGPT, Claude or any MCP client through one link. Nothing changes until you connect; direct connections work as before. No ability permissions changed.

= 1.16.0 =
WooCommerce reads and a basic product editor, one post's SEO title, description and robots for seven SEO plugins, read-only forms with contact details redacted, backup and security status, and scheduled audits, all formerly GoBuildWP Pro. Each stands aside while GoBuildWP Pro 1.10.0 still provides it. Also new: undo or redo everything one agent session did, and a local connection over WP-CLI (wp wppilot mcp serve). 48 new abilities. Reading server files (wppilot/read-file, wppilot/list-directory) now needs Developer Full Access.

= 1.15.1 =
Fixes Application Password 401s under Wordfence going unexplained (the Connection Doctor and Connect screen now name Wordfence and the setting to change), email addresses showing in full in change records, and the content audit missing The SEO Framework, Slim SEO and SmartCrawl. No new abilities or permission changes.

= 1.15.0 =
A redesigned admin with a Dashboard, six sections and a phone-friendly layout. Search and replace with undo, image editing, WCAG and content audits, Block Notes, site tools, multisite, Connection Doctor, MCP Tasks and Apps, human confirmation, scoped expiring tokens, and a change log in its own table (existing history is moved across on update). 26 new abilities; no permission changes.

= 1.6.4 =
Fixes preview confidentiality and lifecycle races, menu partial updates and ownership checks, queue discovery beyond the first fifty records, front-page settings validation, and approval enforcement for Pro abilities on the modern MCP transport. No new abilities or permission changes.

= 1.6.3 =
Fixes a Block Editor Queue batch getting stuck when the tab working it goes away — closed, navigated off, or throttled in the background. The batch is retried automatically instead of needing an administrator to cancel and re-queue it. One more fix, no new abilities, and existing connections keep working.

= 1.6.2 =
Four fixes, no new abilities. Blocks from libraries such as Spectra keep the settings their editor assigns, so an agent-built page stops rendering unstyled while the queue says it worked. The Block Editor Queue no longer strands a batch when a request arrives without a readable body, revoking a connection frees its slot straight away, and a recursive directory listing survives a folder it cannot read. Existing connections keep working.

= 1.6.1 =
Makes anonymous usage reporting opt-in and removes the dashboard notice that 1.6.0 introduced. Sites that made a choice in 1.6.0 keep it; every other site stops reporting on update. No new abilities, no permission changes, and existing connections keep working.

= 1.6.0 =
Adds optional anonymous usage reporting, which tells us which WordPress and PHP versions are actually in use. A notice explains what is sent the first time you open wp-admin and offers a one-click switch off. No new abilities, no permission changes, and existing connections keep working.

= 1.5.1 =
Fixes three defects that together made GoBuildWP unusable from any AI client that checks its calls against the schemas the server hands it — Claude among them. The tool list itself was rejected, abilities that take no input refused every call, and destructive tools asked for a confirmation the client had no way to send, so the same refusal repeated forever. A more forgiving client saw none of this. No new abilities and no permission changes; existing connections keep working.

= 1.5.0 =
Adds preview before write: an agent can show you exactly what a change would do, as a field-by-field diff, before anything is written — and you apply it from a screen in wp-admin. GoBuildWP could already undo a change; this is the first release where it can show you one first. Nothing about how existing calls behave changes unless you switch on the new Settings option, which is off by default. No permission changes, and existing connections keep working.

= 1.4.1 =
Fixes four defects, two of which affect what an agent can silently get wrong. Writing page content to an Elementor, Bricks or Beaver Builder page used to succeed and change nothing visible; it is now refused, with the builder and the right ability named. On sites running GoBuildWP Pro, 23 core abilities were being served by Pro's older copies whenever Pro had been activated first, so free's fixes since 1.1.0 never applied there — update Pro to 1.1.2 alongside this release to complete that fix. The version constant, which reported 1.3.0 throughout 1.4.0, is corrected. No new abilities and no permission changes; existing connections keep working.

= 1.4.0 =
A large release, all of it about getting connected. Access tokens are a third way in, for callers that cannot sign in through a browser: the Claude Messages API MCP connector, the OpenAI Responses API, cron jobs, automation platforms and scripts. Web apps get their own route, with real walkthroughs for Claude on the web, ChatGPT, Perplexity, Mistral Le Chat and Manus. Seven clients are new — Kimi Code CLI, Qwen Code, Gemini CLI, ZCode (GLM) and the web apps above — and every existing client's instructions were rewritten against its current interface, several of which had moved. Each method now also offers its setup as a copy-paste prompt for an AI coding agent. The Connect screen is reordered so the setup comes before the status panels. OAuth and application passwords are unchanged, existing connections keep working, and there are no new abilities and no permission changes.

= 1.3.0 =
Fixes restoring a revision, which failed with a fatal error on every call from 1.1.0 onward and, behind that, reported a restore that never happened as successful. Recommended for everyone. Change ledger entries now name the agent that made each write, so a site connected by more than one AI client can tell them apart. No new abilities, no permission changes, and existing connections keep working.

= 1.2.1 =
Fixes theme search, which reported every author as "Array" and every download count as zero on 1.2.0. Recommended for anyone using the plugin and theme abilities. No new abilities, no permission changes, and existing connections keep working.

= 1.2.0 =
Adds plugin and theme management to the free plugin. Installing and deleting stay off Production Safe; activating, updating and switching themes work there with explicit confirmation. Existing connections keep working and do not need to be re-authorised.

= 1.1.0 =
Adds the WordPress core surface to the free plugin and fixes three security defects in content and menu handling, plus a privacy defect in user reads. Your AI client will see roughly twice as many tools after updating. Existing connections keep working and do not need to be re-authorised.

= 1.0.0 =
First public release.
