<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * What run-wp-cli may run: command rules, version pins and Read Mode.
 *
 * A command is the WP-CLI words an agent passes, flags left out: `["db", "drop", "--yes"]` is the
 * command `db drop`. A rule is one line of space-separated words, matched against the leading
 * words of the command, case-insensitively, with `*` standing for any run of characters inside one
 * word. `db drop` matches `db drop --yes`; `eval*` matches `eval` and `eval-file`;
 * `config set *_key` matches `config set AUTH_KEY abc`.
 *
 * - The deny list always wins. Its defaults are the commands that destroy the database, run
 *   arbitrary PHP, mint a credential or sign every user out.
 * - An allow list that is not empty is an allowlist: a command must match one of its rules.
 * - In Read Mode only read commands run (see wppilot_wp_cli_is_read_command()), whatever the
 *   lists say. The permission gate already refuses a write command in Read Mode (read-mode.php,
 *   wppilot_read_mode_input_is_read()); this second check holds where that gate does not run.
 * - Global flags that would run code or reach another site before any rule is read (`--exec`,
 *   `--require`, `--path`, `--ssh`, `--http` and an `@alias`) are always refused.
 *
 * Administrators edit both lists on the Abilities screen. Every save is confirmed there and
 * recorded in the change ledger with the lists before and after.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_WP_CLI_POLICY_OPTION = 'wppilot_wp_cli_policy';
const WPPILOT_WP_CLI_POLICY_MAX_RULES = 200;
const WPPILOT_WP_CLI_POLICY_LEDGER_NAME = 'wppilot/wp-cli-policy';

/**
 * @return array{deny: list<string>, allow: list<string>}
 */
function wppilot_wp_cli_default_policy(): array
{
    return [
        'deny' => [
            'db drop',
            'db reset',
            'db clean',
            'eval*',
            'shell',
            'user application-password create',
            'user app-password create',
            'config set *_key',
            'config set *_salt',
            'config shuffle-salts',
        ],
        'allow' => [],
    ];
}

/**
 * The stored rules. No option, or one that is not the expected shape, reads as the defaults:
 * a damaged option never opens the deny list.
 *
 * @return array{deny: list<string>, allow: list<string>}
 */
function wppilot_wp_cli_policy(): array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_WP_CLI_POLICY_OPTION, default_value: null);
    if (!is_array($stored) || !is_array($stored['deny'] ?? null) || !is_array($stored['allow'] ?? null)) {
        return wppilot_wp_cli_default_policy();
    }

    return [
        'deny' => wppilot_wp_cli_normalize_rules($stored['deny']),
        'allow' => wppilot_wp_cli_normalize_rules($stored['allow']),
    ];
}

/**
 * Rules from a list or from textarea text: one per line, lower-cased, inner spaces collapsed,
 * blank lines and `#` comments dropped, duplicates removed.
 *
 * @return list<string>
 */
function wppilot_wp_cli_normalize_rules(mixed $value): array
{
    $lines = is_string($value) ? preg_split('/\R/', $value) : (is_array($value) ? $value : []);
    $rules = [];
    foreach (is_array($lines) ? $lines : [] as $line) {
        if (!is_string($line)) {
            continue;
        }
        $line = strtolower(trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/', ' ', $line)));
        if ($line === '' || str_starts_with($line, '#') || strlen($line) > 200) {
            continue;
        }
        $rules[$line] = true;
        if (count($rules) >= WPPILOT_WP_CLI_POLICY_MAX_RULES) {
            break;
        }
    }

    return array_keys($rules);
}

/**
 * The command words: every argument that is not a flag, in order.
 *
 * @param list<string> $args
 * @return list<string>
 */
function wppilot_wp_cli_command_words(array $args): array
{
    $words = [];
    foreach ($args as $arg) {
        if ($arg !== '' && $arg[0] !== '-') {
            $words[] = $arg;
        }
    }

    return $words;
}

/**
 * @param list<string> $words
 */
function wppilot_wp_cli_rule_matches(string $rule, array $words): bool
{
    $parts = explode(' ', $rule);
    if (count($parts) > count($words)) {
        return false;
    }
    foreach ($parts as $index => $part) {
        $pattern = '/^' . str_replace('\\*', '.*', preg_quote($part, '/')) . '$/i';
        if (preg_match($pattern, $words[$index]) !== 1) {
            return false;
        }
    }

    return true;
}

/**
 * @param list<string> $rules
 * @param list<string> $words
 */
function wppilot_wp_cli_first_matching_rule(array $rules, array $words): ?string
{
    foreach ($rules as $rule) {
        if (wppilot_wp_cli_rule_matches($rule, $words)) {
            return $rule;
        }
    }

    return null;
}

/**
 * The first argument that would run code or reach another site before WP-CLI reads the command,
 * or null.
 *
 * @param list<string> $args
 */
function wppilot_wp_cli_refused_global_arg(array $args): ?string
{
    foreach ($args as $arg) {
        if (preg_match('/^--(?:exec|require|path|ssh|http)(?:=|$)/i', $arg) === 1) {
            return $arg;
        }
    }
    $words = wppilot_wp_cli_command_words($args);
    if ($words !== [] && str_starts_with($words[0], '@')) {
        return $words[0];
    }

    return null;
}

/**
 * Whether the command only reads.
 *
 * The verb is the first word after the command group that is not a sub-group (`post meta list`
 * reads, `post meta update` does not). Only the first four words are looked at, so a value that
 * happens to read "list" further along cannot turn a write into a read. `config` is never a read
 * here, because `config get` and `config list` print the database password and the salts; `db`
 * is a read only for its inspection verbs.
 *
 * @param list<string> $words
 */
function wppilot_wp_cli_is_read_command(array $words): bool
{
    $words = array_map('strtolower', array_slice($words, 0, 4));
    if ($words === []) {
        return false;
    }
    if ($words[0] === 'help') {
        return true;
    }
    if ($words[0] === 'config' || count($words) < 2) {
        return false;
    }
    if ($words[0] === 'db') {
        return in_array($words[1], ['size', 'tables', 'columns', 'prefix', 'check', 'search'], strict: true);
    }

    $read_verbs = [
        'list', 'get', 'status', 'exists', 'is-installed', 'is-active', 'path', 'version',
        'check-update', 'verify-checksums', 'search', 'info', 'count', 'pluck', 'has', 'is-enabled',
    ];
    $sub_groups = [
        'meta', 'term', 'application-password', 'app-password', 'role', 'cap', 'auto-updates',
        'type', 'taxonomy', 'event', 'schedule', 'item', 'location', 'plugin', 'theme', 'core',
        'mod', 'widget', 'sidebar', 'structure', 'site', 'network', 'super-admin', 'option',
    ];
    for ($index = 1, $last = count($words); $index < $last; ++$index) {
        if (in_array($words[$index], $read_verbs, strict: true)) {
            return true;
        }
        if (!in_array($words[$index], $sub_groups, strict: true)) {
            return false;
        }
    }

    return false;
}

/**
 * Where an administrator changes the rules, for the refusal messages.
 */
function wppilot_wp_cli_policy_location(): string
{
    $screen = function_exists('wppilot_nav_label') ? wppilot_nav_label('wppilot-abilities', 'Abilities') : 'Abilities';

    return sprintf('GoBuildWP > %s > WP-CLI command rules', $screen);
}

/**
 * Null when the command may run, or the refusal.
 *
 * @param list<string>                                  $args
 * @param array{deny: list<string>, allow: list<string>} $policy
 */
function wppilot_wp_cli_policy_check(array $args, array $policy, bool $read_mode): ?WP_Error
{
    $refused = wppilot_wp_cli_refused_global_arg($args);
    if ($refused !== null) {
        return new WP_Error(
            'wp_cli_argument_refused',
            sprintf(
                'The WP-CLI argument "%s" is refused: --exec, --require, --path, --ssh, --http and @aliases run code or reach another site before the command rules apply. Run the command on this site without it.',
                $refused,
            ),
            ['status' => 403, 'argument' => $refused],
        );
    }

    $words = wppilot_wp_cli_command_words($args);
    if ($words === []) {
        return new WP_Error('wp_cli_no_command', 'Pass a WP-CLI command, e.g. ["plugin", "list"].', ['status' => 400]);
    }
    $command = implode(' ', array_slice($words, 0, 4));

    $denied = wppilot_wp_cli_first_matching_rule($policy['deny'], $words);
    if ($denied !== null) {
        return new WP_Error(
            'wp_cli_command_denied',
            sprintf(
                'The WP-CLI command "%1$s" matches the deny rule "%2$s". A site administrator can remove that rule in %3$s; do not retry with a different spelling.',
                $command,
                $denied,
                wppilot_wp_cli_policy_location(),
            ),
            ['status' => 403, 'rule' => $denied],
        );
    }

    if ($policy['allow'] !== [] && wppilot_wp_cli_first_matching_rule($policy['allow'], $words) === null) {
        return new WP_Error(
            'wp_cli_command_not_allowed',
            sprintf(
                'The WP-CLI command "%1$s" is not on this site\'s allow list. A site administrator can add a rule for it in %2$s.',
                $command,
                wppilot_wp_cli_policy_location(),
            ),
            ['status' => 403, 'allow' => $policy['allow']],
        );
    }

    if ($read_mode && !wppilot_wp_cli_is_read_command($words)) {
        return new WP_Error(
            'wppilot_read_mode',
            sprintf(
                'The WP-CLI command "%s" can change the site, and this site is in Read Mode, so only read commands (list, get, status, version and similar) run. A site administrator turns Read Mode off in GoBuildWP Settings.',
                $command,
            ),
            ['status' => 403, 'read_mode' => true],
        );
    }

    return null;
}

/**
 * Whether an installed version satisfies a pin. A pin matches itself and any version that extends
 * it by more dotted parts: "10.2" accepts 10.2 and 10.2.1, not 10.20 or 10.3.
 */
function wppilot_wp_cli_version_matches(string $installed, string $expected): bool
{
    $installed = trim($installed);
    $expected = trim($expected);
    if ($installed === '' || $expected === '') {
        return false;
    }

    return $installed === $expected || str_starts_with($installed, $expected . '.');
}

/**
 * Each pin the installed versions do not meet, as a sentence.
 *
 * @param array<string, mixed> $expect    {core?: string, php?: string, plugin?: {slug: version}, theme?: {slug: version}}
 * @param array<string, mixed> $installed The same shape; a missing plugin or theme is absent or null.
 * @return list<string>
 */
function wppilot_wp_cli_expect_mismatches(array $expect, array $installed): array
{
    $mismatches = [];
    foreach (['core' => 'WordPress', 'php' => 'PHP'] as $key => $label) {
        if (!is_string($expect[$key] ?? null)) {
            continue;
        }
        $have = is_string($installed[$key] ?? null) ? $installed[$key] : '';
        if (!wppilot_wp_cli_version_matches($have, $expect[$key])) {
            $mismatches[] = sprintf('%s is %s, expected %s.', $label, $have === '' ? 'unknown' : $have, $expect[$key]);
        }
    }
    foreach (['plugin', 'theme'] as $kind) {
        $pins = is_array($expect[$kind] ?? null) ? $expect[$kind] : [];
        $have = is_array($installed[$kind] ?? null) ? $installed[$kind] : [];
        foreach ($pins as $slug => $version) {
            if (!is_string($version)) {
                continue;
            }
            $found = is_string($have[$slug] ?? null) ? $have[$slug] : null;
            if ($found === null) {
                $mismatches[] = sprintf('The %s "%s" is not installed, expected %s.', $kind, (string) $slug, $version);
            } elseif (!wppilot_wp_cli_version_matches($found, $version)) {
                $mismatches[] = sprintf('The %s "%s" is %s, expected %s.', $kind, (string) $slug, $found, $version);
            }
        }
    }

    return $mismatches;
}

/**
 * The installed versions of what $expect names.
 *
 * @param array<string, mixed> $expect
 * @return array<string, mixed>
 */
function wppilot_wp_cli_installed_versions(array $expect): array
{
    $installed = [
        'core' => (string) get_bloginfo('version'),
        'php' => PHP_VERSION,
        'plugin' => [],
        'theme' => [],
    ];

    if (is_array($expect['plugin'] ?? null) && $expect['plugin'] !== []) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (get_plugins() as $file => $data) {
            $slug = str_contains((string) $file, '/') ? dirname((string) $file) : basename((string) $file, '.php');
            $installed['plugin'][$slug] = (string) ($data['Version'] ?? '');
        }
    }
    if (is_array($expect['theme'] ?? null)) {
        foreach (array_keys($expect['theme']) as $slug) {
            $theme = wp_get_theme((string) $slug);
            if ($theme->exists()) {
                $installed['theme'][(string) $slug] = (string) $theme->get('Version');
            }
        }
    }

    return $installed;
}

/**
 * Null when every pin holds, or a 409 naming each one that does not.
 */
function wppilot_wp_cli_expect_check(mixed $expect): ?WP_Error
{
    if (!is_array($expect) || $expect === []) {
        return null;
    }
    $mismatches = wppilot_wp_cli_expect_mismatches($expect, wppilot_wp_cli_installed_versions($expect));
    if ($mismatches === []) {
        return null;
    }

    return new WP_Error(
        'wp_cli_version_mismatch',
        'Nothing was run: the site is not at the versions this command expects. ' . implode(' ', $mismatches),
        ['status' => 409, 'mismatches' => $mismatches],
    );
}

/**
 * The commands the in-process fallback answers when proc_open is disabled.
 *
 * @return list<string>
 */
function wppilot_wp_cli_in_process_commands(): array
{
    return ['core version', 'plugin list', 'theme list', 'option get <name>'];
}

/**
 * Answer a read command inside this PHP process, for hosts that disable proc_open. Null when the
 * command is not one wppilot_wp_cli_in_process_commands() lists. Output follows `--format=json`
 * or `--format=csv`; any other format prints tab-separated columns with a header row.
 *
 * @param list<string> $args
 * @return array<string, mixed>|null
 */
function wppilot_wp_cli_run_in_process(array $args): ?array
{
    $words = array_map('strtolower', wppilot_wp_cli_command_words($args));
    $format = 'table';
    foreach ($args as $arg) {
        if (preg_match('/^--format=(\w+)$/i', $arg, $match) === 1) {
            $format = strtolower($match[1]);
        }
    }
    $command = implode(' ', array_slice($words, 0, 2));

    if ($command === 'core version' && count($words) === 2) {
        $stdout = (string) get_bloginfo('version') . "\n";
    } elseif ($command === 'option get' && count($words) === 3) {
        $name = wppilot_wp_cli_command_words($args)[2];
        $value = get_option($name, default_value: null);
        if ($value === null) {
            return wppilot_wp_cli_in_process_result(1, '', sprintf("Error: Could not get '%s' option. Does it exist?\n", $name));
        }
        $stdout = (is_scalar($value) && $format !== 'json' ? (string) $value : (string) wp_json_encode($value)) . "\n";
    } elseif (($command === 'plugin list' || $command === 'theme list') && count($words) === 2) {
        $rows = $command === 'plugin list' ? wppilot_wp_cli_in_process_plugin_rows() : wppilot_wp_cli_in_process_theme_rows();
        $stdout = wppilot_wp_cli_format_rows($rows, $format);
    } else {
        return null;
    }

    return wppilot_wp_cli_in_process_result(0, $stdout, '');
}

/**
 * @return array<string, mixed>
 */
function wppilot_wp_cli_in_process_result(int $exit_code, string $stdout, string $stderr): array
{
    return [
        'success' => $exit_code === 0,
        'exit_code' => $exit_code,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'in_process' => true,
    ];
}

/**
 * @return list<array{name: string, status: string, update: string, version: string}>
 */
function wppilot_wp_cli_in_process_plugin_rows(): array
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $updates = get_site_transient('update_plugins');
    $pending = is_object($updates) && is_array($updates->response ?? null) ? $updates->response : [];
    $rows = [];
    foreach (get_plugins() as $file => $data) {
        $file = (string) $file;
        $status = is_plugin_active_for_network($file) ? 'active-network' : (is_plugin_active($file) ? 'active' : 'inactive');
        $rows[] = [
            'name' => str_contains($file, '/') ? dirname($file) : basename($file, '.php'),
            'status' => $status,
            'update' => isset($pending[$file]) ? 'available' : 'none',
            'version' => (string) ($data['Version'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @return list<array{name: string, status: string, update: string, version: string}>
 */
function wppilot_wp_cli_in_process_theme_rows(): array
{
    $updates = get_site_transient('update_themes');
    $pending = is_object($updates) && is_array($updates->response ?? null) ? $updates->response : [];
    $active = get_stylesheet();
    $parent = get_template();
    $rows = [];
    foreach (wp_get_themes() as $slug => $theme) {
        $slug = (string) $slug;
        $rows[] = [
            'name' => $slug,
            'status' => $slug === $active ? 'active' : ($slug === $parent ? 'parent' : 'inactive'),
            'update' => isset($pending[$slug]) ? 'available' : 'none',
            'version' => (string) $theme->get('Version'),
        ];
    }

    return $rows;
}

/**
 * @param list<array<string, string>> $rows
 */
function wppilot_wp_cli_format_rows(array $rows, string $format): string
{
    $columns = ['name', 'status', 'update', 'version'];
    if ($format === 'json') {
        return (string) wp_json_encode($rows) . "\n";
    }
    if ($format === 'count') {
        return count($rows) . "\n";
    }
    $separator = $format === 'csv' ? ',' : "\t";
    $lines = [implode($separator, $columns)];
    foreach ($rows as $row) {
        $cells = [];
        foreach ($columns as $column) {
            $cell = $row[$column] ?? '';
            $cells[] = $format === 'csv' && preg_match('/[",\r\n]/', $cell) === 1 ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
        }
        $lines[] = implode($separator, $cells);
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Store new rules and record the edit in the change ledger. Returns false when nothing changed.
 *
 * The ledger row holds both lists before and after. It is marked not undoable from the Changes
 * screen: the rules are a safety setting, and putting a deny rule back is a decision for the
 * person on the Abilities screen, not for a rollback button.
 *
 * @param list<string> $deny
 * @param list<string> $allow
 */
function wppilot_wp_cli_save_policy(array $deny, array $allow): bool
{
    $before = wppilot_wp_cli_policy();
    $after = [
        'deny' => wppilot_wp_cli_normalize_rules($deny),
        'allow' => wppilot_wp_cli_normalize_rules($allow),
    ];
    if ($before === $after) {
        return false;
    }

    update_option(WPPILOT_WP_CLI_POLICY_OPTION, $after, autoload: false);

    if (function_exists('wppilot_store_change')) {
        wppilot_store_change(wppilot_wp_cli_policy_ledger_row($before, $after));
    }

    return true;
}

/**
 * @param array{deny: list<string>, allow: list<string>} $before
 * @param array{deny: list<string>, allow: list<string>} $after
 * @return array<string, mixed>
 */
function wppilot_wp_cli_policy_ledger_row(array $before, array $after): array
{
    $user = wp_get_current_user();
    $input = ['before' => $before, 'after' => $after];

    return [
        'kind' => 'change',
        'id' => wp_generate_uuid4(),
        'ability' => WPPILOT_WP_CLI_POLICY_LEDGER_NAME,
        'risk' => 'critical',
        'recorded_at' => gmdate('c'),
        'duration_ms' => 0,
        'user' => ['id' => (int) $user->ID, 'login' => (string) $user->user_login],
        'agent' => [],
        'input' => $input,
        'input_sha256' => hash('sha256', (string) wp_json_encode($input)),
        'result' => [
            'removed_deny' => array_values(array_diff($before['deny'], $after['deny'])),
            'added_deny' => array_values(array_diff($after['deny'], $before['deny'])),
            'allow_count' => count($after['allow']),
        ],
        'rollback' => [
            'reversible' => false,
            'reason' => 'WP-CLI command rules are changed back on the Abilities screen; this row keeps both lists.',
        ],
        'rolled_back' => false,
        'design' => [],
        'confirmation' => ['method' => 'admin-form'],
    ];
}

/**
 * Handle the rules form on the Abilities screen. The caller has checked the capability and the
 * nonce. The save needs the confirm box ticked; "reset" restores the defaults.
 */
function wppilot_handle_wp_cli_policy_action(): void
{
    $base = admin_url('admin.php?page=wppilot-abilities');
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- Only called from wppilot_handle_ability_hub_actions() after check_admin_referer('wppilot_ability_hub_action').
    if (($_POST['wppilot_wp_cli_confirm'] ?? '') !== '1') {
        wp_safe_redirect(add_query_arg('wppilot_result', 'wp_cli_unconfirmed', $base) . '#wppilot-wp-cli-rules');
        exit();
    }
    if (isset($_POST['wppilot_wp_cli_reset'])) {
        $defaults = wppilot_wp_cli_default_policy();
        $deny = $defaults['deny'];
        $allow = $defaults['allow'];
    } else {
        $deny = wppilot_wp_cli_normalize_rules(is_string($_POST['wppilot_wp_cli_deny'] ?? null) ? sanitize_textarea_field(wp_unslash($_POST['wppilot_wp_cli_deny'])) : '');
        $allow = wppilot_wp_cli_normalize_rules(is_string($_POST['wppilot_wp_cli_allow'] ?? null) ? sanitize_textarea_field(wp_unslash($_POST['wppilot_wp_cli_allow'])) : '');
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    $changed = wppilot_wp_cli_save_policy($deny, $allow);
    wp_safe_redirect(add_query_arg('wppilot_result', $changed ? 'wp_cli_saved' : 'wp_cli_unchanged', $base) . '#wppilot-wp-cli-rules');
    exit();
}

/**
 * The rules panel on the Abilities screen.
 */
function wppilot_render_wp_cli_policy_panel(): void
{
    if (!wppilot_current_user_can_manage() || (function_exists('wppilot_is_wordpress_org_edition') && wppilot_is_wordpress_org_edition())) {
        return;
    }
    $policy = wppilot_wp_cli_policy();
    $defaults = wppilot_wp_cli_default_policy();
    ?>
    <div class="wppilot-card" id="wppilot-wp-cli-rules">
        <h2><?php esc_html_e('WP-CLI command rules', domain: 'wppilot'); ?></h2>
        <p><?php esc_html_e(
            'Which commands the Run WP-CLI Command ability may run. One rule per line, matched against the start of the command with flags left out; * stands for any characters inside one word. The deny list always wins. When the allow list has any rules, only commands matching one of them run. In Read Mode only read commands run. Every save is recorded on the Changes screen.',
            domain: 'wppilot',
        ); ?></p>
        <form method="post">
            <?php wp_nonce_field('wppilot_ability_hub_action'); ?>
            <input type="hidden" name="wppilot_ability_hub_action" value="wp_cli_policy" />
            <p>
                <label for="wppilot-wp-cli-deny"><strong><?php esc_html_e('Deny', domain: 'wppilot'); ?></strong></label><br />
                <textarea id="wppilot-wp-cli-deny" name="wppilot_wp_cli_deny" rows="8" cols="60" class="large-text code"><?php echo esc_textarea(implode("\n", $policy['deny'])); ?></textarea>
                <span class="description"><?php echo esc_html(sprintf(
                    /* translators: %s: the default deny rules, comma-separated */
                    __('Defaults: %s', domain: 'wppilot'),
                    implode(', ', $defaults['deny']),
                )); ?></span>
            </p>
            <p>
                <label for="wppilot-wp-cli-allow"><strong><?php esc_html_e('Allow only (leave empty to allow everything not denied)', domain: 'wppilot'); ?></strong></label><br />
                <textarea id="wppilot-wp-cli-allow" name="wppilot_wp_cli_allow" rows="4" cols="60" class="large-text code"><?php echo esc_textarea(implode("\n", $policy['allow'])); ?></textarea>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="wppilot_wp_cli_confirm" value="1" />
                    <?php esc_html_e('I understand that removing a deny rule lets agents run that command on this site.', domain: 'wppilot'); ?>
                </label>
            </p>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save WP-CLI rules', domain: 'wppilot'); ?></button>
                <button type="submit" class="button" name="wppilot_wp_cli_reset" value="1"><?php esc_html_e('Restore defaults', domain: 'wppilot'); ?></button>
            </p>
        </form>
    </div>
    <?php
}
