<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Unit-test bootstrap.
 *
 * These are unit tests, not WordPress integration tests: they exercise GoBuildWP's
 * own decision logic (status resolution, post-type exposure, capability gating)
 * against WordPress function doubles rather than a live install. That keeps the
 * safety invariants under test on any machine with PHP, with no database.
 *
 * Anything that needs real WordPress behaviour — wp_insert_post(), the ledger's
 * before/after hooks, the REST surface — is out of scope here and belongs in a
 * WordPress integration suite.
 */

define('ABSPATH', __DIR__ . '/');

// The plugin-file constants the real plugin defines at load. The extension
// abilities read them to refuse deactivating or deleting GoBuildWP itself, so the
// suite needs them to exercise that refusal.
define('WPPILOT_PLUGIN_FILE', ABSPATH . 'wp-content/plugins/wppilot/wppilot.php');
define('WPPILOT_PRO_FILE', ABSPATH . 'wp-content/plugins/wppilot-pro/wppilot-pro.php');

// The fully qualified MCP Adapter class name, defined by the plugin file at load.
// adapter-origin.php reflects on it to work out whose copy of the adapter is
// serving MCP on this site.
define('WPPILOT_MCP_ADAPTER_CLASS', 'WP\\MCP\\Core\\McpAdapter');

require_once __DIR__ . '/doubles/wordpress.php';


// Which copy of the adapter won. Pure path and classmap reading; it registers
// nothing and touches WordPress only through wp_normalize_path().
require_once dirname(__DIR__) . '/includes/mcp/adapter-origin.php';
require_once dirname(__DIR__) . '/includes/mcp/adapter-abilities.php';

// The compatibility contract: the version constants, the probe of what the host
// WordPress offers, and the block published to clients. It registers nothing and
// reaches WordPress only from inside its functions.
require_once dirname(__DIR__) . '/includes/compatibility.php';

// Load the whole WordPress-core module, not just its helpers: registration
// happens at file scope, so requiring every file is what proves the surface
// comes up with Free alone — no Pro class, licence check, or entitlement call.
require_once dirname(__DIR__) . '/includes/abilities/wordpress/bootstrap.php';
wppilot_load_wordpress_abilities();

// Anonymous usage reporting, plus the lifecycle file that schedules it. Loaded
// for TelemetryConsentTest: reporting is opt-in, so the assertion that carries
// the promise is that an untouched install sends nothing, and the option
// semantics it depends on are a WordPress quirk rather than anything visible in
// this plugin's own code.
require_once dirname(__DIR__) . '/includes/telemetry/settings.php';
require_once dirname(__DIR__) . '/includes/telemetry/send.php';
require_once dirname(__DIR__) . '/includes/lifecycle.php';

// Registry reads and the MCP exposure rules. Pure decisions over an ability's
// metadata plus one registry read, so they load here and are exercised
// directly; policy.php, which is the caller that matters, reaches the options
// table and belongs to an integration suite.
require_once dirname(__DIR__) . '/includes/abilities/registry.php';

// The safety layer registers nothing at file scope and reaches WordPress only
// from inside its functions, so it loads here for its risk classification: the
// MCP transport asks it which tools must be confirmed before it will run them,
// and that answer decides what the tool list advertises.
require_once dirname(__DIR__) . '/includes/safety.php';
// The gate pipeline every execution path shares, and the one policy.php answer
// it needs (which abilities the Hub cannot switch off). policy.php registers
// nothing at file scope.
require_once dirname(__DIR__) . '/includes/abilities/policy.php';
require_once dirname(__DIR__) . '/includes/gate-pipeline.php';
require_once dirname(__DIR__) . '/includes/confirmation.php';

// The MCP protocol layer is deliberately free of WordPress dependencies beyond
// the ABSPATH guard, so it loads and is exercised here directly.
foreach (['protocol', 'errors', 'headers', 'results', 'discover', 'transport', 'confirmation'] as $module) {
    require_once dirname(__DIR__) . '/includes/mcp/' . $module . '.php';
}

// The prompt library reads Markdown from disk and reaches WordPress only for
// translation and the two filters, so it loads here for PromptLibraryTest: the
// promise under test is that ten complete, distinct briefs ship.
require_once dirname(__DIR__) . '/includes/prompt-library/packs.php';

// The preview differ is pure — no WordPress state, no options, no registry — so
// it loads on its own. That is the point of keeping it separate from the
// projectors, which do read WordPress.
require_once dirname(__DIR__) . '/includes/preview/diff.php';

require_once dirname(__DIR__) . '/includes/oauth/client-id-metadata.php';

// The access-token module and the Bearer middleware. Both touch WordPress only
// from inside their functions, so the parts under test here — credential shape,
// digesting, and the route boundary a token identity is allowed to cross — run
// without a database. Storage itself is integration territory.
require_once dirname(__DIR__) . '/includes/tokens.php';
require_once dirname(__DIR__) . '/includes/oauth/middleware.php';

// The change ledger registers nothing at file scope, so it loads here purely for
// its before-image capture: that code calls WordPress functions taking arguments
// by reference, and only a real call proves the call sites still satisfy them.
require_once dirname(__DIR__) . '/includes/change-log.php';
require_once dirname(__DIR__) . '/includes/change-log-table.php';
// Session undo and redo, and where a session id comes from. Both register nothing at file scope.
require_once dirname(__DIR__) . '/includes/change-sessions.php';
require_once dirname(__DIR__) . '/includes/agent-sessions.php';

// Agent identities (a token's scope and profile ceiling) and the declarative
// ledger map for third-party abilities. Both reach WordPress only from inside
// their functions; the map registers its two rollback strategies and one filter
// at load, which is what the tests assert against.
require_once dirname(__DIR__) . '/includes/agent-identities.php';
require_once dirname(__DIR__) . '/includes/ledger-map.php';

// The design system's pure layers: the document parser, the token extractor,
// the pre-flight rules, the distinctiveness comparison, the spec, and the
// derivation that turns a brief into a design. Only the pure ones, deliberately
// — the rest of the module registers abilities and reads a custom post type,
// and the parts under test here decide things from their arguments alone.
// typefaces.php and grammars.php are data plus a rule or two; gate.php is
// included for its colour conversions rather than for the gate. distinct.php
// names Library for check(), but PHP resolves that lazily and the suite
// exercises compare(), which takes the other designs as an argument precisely
// so the judgement can be tested without a database. generate.php reaches
// get_home_url(), which the doubles pin so a derived design does not change
// with the environment.
foreach (['parser', 'tokens', 'preflight', 'contrast', 'gate', 'seed', 'distinct', 'typefaces', 'spec', 'grammars', 'generate', 'context'] as $module) {
    require_once dirname(__DIR__) . '/includes/design/' . $module . '.php';
}

// Registrations captured during load are the baseline the suite asserts against.
// Snapshot them before any test calls WPPilot_Test_State::reset(). The full
// registration arguments are kept alongside the names so a test can assert on
// the annotations a profile decision is made from.
define('WPPILOT_TEST_BOOT_ABILITIES', WPPilot_Test_State::$registered_abilities);
define('WPPILOT_TEST_BOOT_REGISTRATIONS', WPPilot_Test_State::$registrations);
