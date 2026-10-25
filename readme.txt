=== GoBuildWP ===
Contributors: wppilot
Tags: mcp, ai, claude, elementor, agent
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.18.8
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

The MCP endpoint runs on your own site. An AI client you connect directly talks to it without any GoBuildWP server in between. Requests that WordPress makes without setting its own user agent identify the site the way WordPress always does (`WordPress/<version>; <site address>`).

**AI provider (GoBuildWP Chat).** When you use Chat, WordPress sends the conversation history, selected attachments, the instructions Chat starts with, tool definitions and relevant tool results to the AI provider and model you selected through the WordPress AI Client, which may also ask that provider which models are available. That provider is an external service and its own terms and privacy policy apply. GoBuildWP adds suggested text to WordPress's Privacy Policy Guide and integrates with the personal-data export and erase tools.

**WordPress.org.** Searching for plugins and themes, reading one in detail, installing one and updating one go through WordPress's own plugins_api, themes_api and update functions, which contact api.wordpress.org and download packages from downloads.wordpress.org exactly as the Plugins and Themes screens do. The Connection Doctor (GoBuildWP > Diagnostics, or its ability) sends one HEAD request to api.wordpress.org and reads only the Date header, to check this server's clock. WordPress.org's privacy policy: https://wordpress.org/about/privacy/.

**Openverse.** The stock-image search ability queries Openverse (api.openverse.org), WordPress.org's openly-licensed media search, only when an agent calls it. The search terms and filters are sent, with a user agent naming GoBuildWP but not your site; no account or API key is involved. Openverse is operated by the WordPress.org project and its own terms apply: https://docs.openverse.org/terms_of_service.html.

**Addresses an agent supplies.** Importing media from a URL downloads that file from wherever it is hosted. The rendered-page check can fetch a page an agent names, including one on another site, and up to 12 stylesheets from that page's own host. The content audit's external-link check, off unless an agent asks for it (or a saved scheduled audit includes it), sends a HEAD request (or a GET limited to 2 KB if HEAD is refused) to at most 50 distinct links found in your posts, with a user agent that names your site. Only the status code and redirect target are kept.

**OAuth client metadata.** When an AI client connects with OAuth and identifies itself with an HTTPS client ID, your site fetches that client's metadata document from the client's own address (for example Claude's or ChatGPT's), at most 64 KB, and caches it for an hour. Nothing about your site other than the WordPress user agent is sent.

**GoBuildWP Cloud (app.gobuildwp.com)** is optional and is contacted only after an administrator connects the site under **GoBuildWP > Connect > GoBuildWP Cloud**. Connecting sends your browser to app.gobuildwp.com with this site's address and name; the site then sends a one-time pairing code and verifier, its address, the GoBuildWP, WordPress, PHP and GoBuildWP Pro versions, its public signing key, and an access token that GoBuildWP creates for the Cloud with the access level you confirm. While connected, the site sends a signed heartbeat once an hour and after GoBuildWP is updated (versions, safety profile and site address), a signed check-in within about 30 seconds of a plugin, theme or core update, a plugin or theme being installed, activated, deactivated, deleted or switched, a backup finishing or failing (UpdraftPlus, BackWPup, Duplicator) or a Site Kit connection change (the check-in names only the kind of event), and a signed notice when you disconnect. The Cloud can also read a status summary from this site through that token: the versions and safety profile, plugins, themes and core with pending updates (name, file or folder, current and new version), backup plugin status (which plugin, whether a backup can be started or is running, the newest backup time, with file names and paths removed), the safety settings the Cloud may manage, and a summary of recent site issues: counts and up to ten entries, each with the first line of a PHP fatal error message (likely credentials masked, at most 300 characters), the file path relative to WordPress, the line, the plugin or theme it belongs to, and the request path without its query string. If you allow it on the same screen (off by default), the Cloud can push a safety policy that tightens, or with a second opt-in also loosens, the safety profile, approval mode and switched-off abilities; it can never set Developer Full Access. The Cloud calls this site's MCP endpoint with the token on behalf of the AI clients in your workspace, through the same safety profile, confirmations and change log as any other client. These messages from the plugin to the Cloud contain no usernames, email addresses, user IDs, or post or page content or titles; posts, users and other content reach the Cloud only when an AI client in your workspace asks for them, within the access you granted. Disconnecting revokes the token. A site connected by an older version keeps sending to the address it was connected through, the service's former domain app.wppilot.co, which reaches the same service, until it is disconnected and connected again. Terms: https://gobuildwp.com/terms. Privacy policy: https://gobuildwp.com/privacy.

The PageSpeed check ability runs only when an agent calls it, and tests only pages of this site. It first uses Google Site Kit if that plugin's PageSpeed module is connected and readable by the user. Otherwise it sends the page address, the mobile or desktop strategy and this site's home address to GoBuildWP Cloud (app.gobuildwp.com/api/pagespeed/v1/run), which asks Google PageSpeed Insights and returns the result; this happens whether or not the site is connected to the Cloud, and a connected site signs the request (adding its site ID). If the Cloud cannot answer, the page address and strategy are sent to Google PageSpeed Insights (www.googleapis.com) directly, first with your own API key if you saved one, then without a key. Results are kept on the site for 15 minutes. Google's terms (https://developers.google.com/terms) and privacy policy (https://policies.google.com/privacy) apply to that request. No content, usernames or email addresses are sent.

**Google Fonts.** When a design's heading or body font is not installed on your computer, the design preview in the admin offers a "Preview with Google Fonts" button. Only if you press it does your browser load that font from Google Fonts (fonts.googleapis.com and fonts.gstatic.com), sending your IP address and browser details to Google. Google Fonts' privacy information: https://developers.google.com/fonts/faq/privacy.

GoBuildWP is distributed from gobuildwp.com and GitHub releases, not the WordPress.org directory, so it checks gobuildwp.com for plugin updates rather than the directory API. The update check sends the plugin version, and WordPress's default user agent with the WordPress version and site address.

Builds downloaded from gobuildwp.com can send anonymous usage data to gobuildwp.com once a day, but only if you switch it on under **GoBuildWP > Settings > Anonymous usage reporting**. It is off until then: nothing is sent, and there is no notice asking. Switching it back off also asks us to delete what was already collected. When on, it sends a random install ID, this site's URL, the GoBuildWP, GoBuildWP Pro, WordPress and PHP versions, your locale, whether the site is a multisite, your safety profile, whether AI abilities are switched on, and how many connections exist. It never sends usernames, email addresses, page or post content, or any record of what an agent did. Reports are kept in detail for 90 days and then reduced to daily totals; an install that stops reporting for 400 days is deleted.

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

Yes. Eighteen Elementor abilities ship in this plugin and register themselves as soon as Elementor 3.6 or newer is active: read the document, inspect the widget and style schemas, add, edit, move, duplicate, reorder and delete elements, and read and write page settings. The design system is here too, so the agent has a palette, type and spacing ladders and a set of compositions to build against.

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

= 1.18.8 =
* Fixed: tools whose input or output schema root is not an object, such as ACF Pro's native no-input abilities (root "type": "null"), broke tools/list for strict MCP clients, so Cloudflare MCP Portals and others refused the whole server. Both the modern transport and the bundled MCP Adapter's tool list now give a null or nullable root an empty object schema. A tool whose input root cannot be an object (such as a string) is left out, and a non-object output schema is dropped, instead of serving an invalid list.
* Fixed: calling one of those null-input abilities with {} or no arguments failed with "not of type null". Empty input now reaches them as null, over MCP and the REST run route.
* Fixed: modern MCP tool discovery now carries the abilities' safety hints (readOnlyHint, destructiveHint, idempotentHint, openWorldHint), as the legacy adapter already did, so Cloud and ChatGPT can verify read-only tools. A missing or non-boolean classification is left out, which clients treat as unsafe.

= 1.18.7 =
* New: wppilot/elementor-build-page builds a whole Elementor page from one nested description ({type, settings, styles, children}) in a single call, on v3 containers or v4 atomic elements. Composing a page one add-element at a time took a dozen calls, each carrying its own parent id and position, and a widget rejected halfway left a half-built page. The whole page is now validated before anything is written, a rejected widget returns its compact schema inline, and dry_run=true checks a description without touching the page. The default replaces the page's content; replace=false appends. A node's styles take the same flat {color, font-size, ...} map as edit-element, checked against the style schema before anything is written.
* Changed: GoBuildWP Pro can resolve design-spec roles and section grammars inside build-page through two filters, wppilot_elementor_build_page_node and wppilot_elementor_build_page_grammar. Without Pro a node that asks for either is refused by name rather than built without the styling it asked for.
* Fixed: wppilot/elementor-edit-element said styles merge on top of existing ones, but it merged whole style entries, and the flat {color, gap, ...} shorthand always writes the element's own entry. One small edit, such as right-aligning a row, deleted the row's other desktop properties and every tablet, mobile and hover variant, so a page came apart one edit at a time. Styles now merge property by property within the same breakpoint and state, and a new breakpoint or state is added beside the others. Removing a property still needs replace=true or wppilot/elementor-delete-element-style.
* Fixed: the block-note abilities answered "Permission denied" for a note or post ID that does not exist, which sent agents looking for a missing permission. They now say "No note has that ID" or "No post has that ID".
* Fixed: wppilot/import-media-url reported a refused download as just "Bad Request". It now names the host and the real HTTP status, and says that some hosts block downloads from WordPress servers.

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

Earlier versions are listed in CHANGELOG.txt, included with the plugin.

== Upgrade Notice ==

= 1.18.8 =
Fixes tool discovery for strict MCP clients such as Cloudflare MCP Portals when ACF Pro or another plugin registers abilities without an object input schema.

= 1.18.7 =
Adds wppilot/elementor-build-page: a whole Elementor page in one validated call. Update together with GoBuildWP Pro 1.13.0, which builds whole websites on top of it.

= 1.18.6 =
WPPilot is now GoBuildWP: same plugin, settings and connections. Also closes subscriber access to the MCP tool list and tightens the PHP sandbox. Update together with GoBuildWP Pro 1.12.4.

= 1.18.5 =
Records the PHP error behind "There has been a critical error" so you and GoBuildWP Cloud can see what broke, and keeps Cloud current within seconds. Update together with GoBuildWP Pro 1.12.3, whose safe updates no longer roll back on LiteSpeed servers for an error they caused themselves.

= 1.18.4 =
Adds PageSpeed checks with no Google key needed and Site Kit dashboard sharing, and lets GoBuildWP Cloud show why a backup is stuck. Update together with GoBuildWP Pro 1.12.2 for All-in-One WP Migration backups on any host.
