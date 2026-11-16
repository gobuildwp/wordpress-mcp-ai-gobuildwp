<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Runtime\Hosts;

use WP_Ability;
use WP_Error;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\PagedLedger;
use WPPilot\Kits\Runtime\ProfileGate;
use WPPilot\Kits\Runtime\Runner;
use WPPilot\Kits\Runtime\SessionLedger;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The kit host inside GoBuildWP: its switch, capability, safety profile and change ledger.
 *
 * This is the one file in includes/kits allowed to name a wppilot_* function
 * (scripts/check-kit-boundaries.php enforces it). The exporter does not ship it: a kit carried
 * into another plugin runs on hosts/standalone.php instead.
 */
final class WPPilotHost implements Host, ProfileGate
{
    private ?WPPilotLedger $ledger = null;

    private ?Runner $jobs = null;

    public function id(): string
    {
        return 'wppilot';
    }

    public function can_manage(): bool
    {
        return \wppilot_permission_callback() === true;
    }

    public function is_enabled(): bool
    {
        return \wppilot_is_enabled();
    }

    public function safety_profile(): string
    {
        return \wppilot_get_safety_profile();
    }

    public function ledger(): Ledger
    {
        return $this->ledger ??= new WPPilotLedger();
    }

    public function jobs(): Jobs
    {
        return $this->jobs ??= new Runner();
    }

    /**
     * Pro, and anything else, offers extension points through this filter:
     * `add_filter('wppilot_kit_extension', fn($ext, $point) => $point === 'seo-providers' ? $registry : $ext, 10, 2)`.
     *
     * `ability-runner` is GoBuildWP's own and not filterable: it is the gate pipeline, and a filter
     * that could swap it would be a way to run abilities around it.
     */
    public function extension(string $point): mixed
    {
        if ($point === 'ability-runner') {
            return [$this, 'run_ability'];
        }
        if ($point === 'elementor-content-writer') {
            return [$this, 'write_elementor_content'];
        }
        if ($point === 'cloud-url') {
            // The pagespeed kit's proxy: the same base pairing uses, so WPPILOT_CLOUD_URL (and the
            // wppilot_cloud_url filter) point a development site's speed tests at a local Cloud too.
            // '' when the configured URL may not be used (plain HTTP outside a local environment).
            return function_exists('wppilot_cloud_url') ? \wppilot_cloud_url() : null;
        }
        if ($point === 'cloud-sign') {
            return [$this, 'sign_cloud_payload'];
        }
        if ($point === 'cloud-verified') {
            // Whether this request is a GoBuildWP Cloud call whose signature verified (Security Guardian R3).
            return function_exists('gobuildwp_cloud_request_verified') && \gobuildwp_cloud_request_verified();
        }
        if ($point === 'cloud-token-call') {
            // Whether this request carries the access token paired with GoBuildWP Cloud.
            if (!function_exists('wppilot_current_token_id') || !function_exists('wppilot_cloud_token_is_linked') || !function_exists('wppilot_cloud_link')) {
                return false;
            }
            $token = \wppilot_current_token_id();
            return $token > 0 && \wppilot_cloud_token_is_linked($token, \wppilot_cloud_link());
        }
        if ($point === 'cloud-rest-namespace') {
            // The REST namespace GoBuildWP Cloud calls this site's routes under.
            return 'gobuildwp/v1';
        }
        if ($point === 'cloud-rest-permission') {
            // The permission callback of GoBuildWP's own Cloud REST routes: the linked token, signed.
            return function_exists('wppilot_cloud_rest_permission') ? 'wppilot_cloud_rest_permission' : null;
        }
        if ($point === 'cloud-note-event') {
            // Records an event and schedules one push of it to GoBuildWP Cloud.
            return function_exists('wppilot_cloud_note_event')
                ? static function (string $event): void {
                    \wppilot_cloud_note_event($event);
                }
                : null;
        }
        if ($point === 'protected-option-prefixes') {
            // GoBuildWP's own options: they hold the connection's tokens, the Cloud pairing and its
            // safety settings. A security fix never cleans them, and the search-replace kit's
            // options target never reads or writes them.
            return ['gobuildwp_', 'wppilot_'];
        }
        if ($point === 'auto-redirects-elsewhere') {
            // GoBuildWP Pro creates slug-change redirects in the site's redirect plugin itself.
            return function_exists('WPPilot\\Pro\\Abilities\\Redirects\\auto_redirect_boot');
        }
        if ($point === 'protected-table-prefixes') {
            // GoBuildWP's own tables (ledger, tokens, connections), which db-write never changes.
            return ['gobuildwp_', 'wppilot_'];
        }
        if ($point === 'protected-tables') {
            /**
             * Tables db-write must never change, with or without the site prefix. Can only add names.
             *
             * @param list<string> $tables
             */
            return apply_filters('wppilot_db_protected_tables', []);
        }
        if ($point === 'unscanned-option-prefixes') {
            // GoBuildWP's own preview options, which the database scan skips as it skips the kits' own.
            return ['wppilot_preview_'];
        }
        if ($point === 'cloud-unpaired') {
            // Whether a site not connected to GoBuildWP Cloud may still use its PageSpeed proxy.
            // The WordPress.org edition contacts the Cloud only once an administrator connects it.
            return function_exists('wppilot_is_wordpress_org_edition') ? !\wppilot_is_wordpress_org_edition() : null;
        }
        if ($point === 'public-name') {
            // The names the kits went by before they could be exported; see public_name().
            return [self::class, 'public_name'];
        }
        if ($point === 'cloud-name') {
            return 'GoBuildWP Cloud';
        }
        return apply_filters('wppilot_kit_extension', null, $point);
    }

    /**
     * GoBuildWP's public name for one of a kit's own names.
     *
     * The security kits shipped inside GoBuildWP before they could be exported, under names sites
     * and clients already depend on: result codes Cloud matches (gobuildwp_security_fix_unchanged,
     * gobuildwp_cloud_pro_required), the GOBUILDWP_DISABLE_2FA and GOBUILDWP_DISABLE_PROTECTION
     * lockout escape hatches and GOBUILDWP_TRUSTED_PROXIES in wp-config.php, the stored
     * gobuildwp_live_login_failures option, the wp-content/gobuildwp-protection folder and the
     * markers that tell the pre-WordPress firewall's files and .user.ini/.htaccess blocks apart.
     * The kits now spell those in their own wppilot_kit_ form, which the exporter renames for
     * another plugin; this maps them back, so nothing a GoBuildWP site already has changes.
     *
     * Kit options already named gobuildwp_kit_* never pass through here.
     */
    public static function public_name(string $name): string
    {
        $exact = [
            'wppilot-kit pre-WordPress firewall' => 'GoBuildWP pre-WordPress firewall',
            '; BEGIN wppilot-kit protection' => '; BEGIN GoBuildWP protection',
            '; END wppilot-kit protection' => '; END GoBuildWP protection',
            '# BEGIN wppilot-kit protection' => '# BEGIN GoBuildWP protection',
            '# END wppilot-kit protection' => '# END GoBuildWP protection',
        ];
        if (isset($exact[$name])) {
            return $exact[$name];
        }
        foreach (['wppilot_kit_' => 'gobuildwp_', 'WPPILOT_KIT_' => 'GOBUILDWP_', 'wppilot-kit-' => 'gobuildwp-'] as $kit => $public) {
            if (str_starts_with($name, $kit)) {
                return $public . substr($name, strlen($kit));
            }
        }
        return $name;
    }

    /**
     * A call to the paired Cloud, signed as the heartbeat is (pairing protocol §5): the payload
     * after site_id, ts and nonce, and X-WPPilot-Signature over exactly those bytes. Null when the
     * site is not paired or cannot sign, so the caller sends its unsigned form instead. The site
     * holds no bearer credential for the Cloud, so nothing else authenticates it.
     *
     * @param array<string, mixed> $payload
     * @return array{base: string, body: string, headers: array<string, string>}|null
     */
    public function sign_cloud_payload(array $payload): ?array
    {
        if (!function_exists('wppilot_cloud_link') || !function_exists('wppilot_cloud_signed_body')) {
            return null;
        }
        $link = \wppilot_cloud_link();
        if ($link === null || !\wppilot_cloud_sodium_available()) {
            return null;
        }
        $base = \wppilot_cloud_normalize_url($link['cloud_url']);
        $keys = \wppilot_cloud_keys(create: false);
        if ($base === '' || $keys instanceof WP_Error) {
            return null;
        }
        $body = \wppilot_cloud_signed_body($link['site_id'], $payload);
        $signature = \wppilot_cloud_sign($body, $keys['secret']);
        if ($signature === '') {
            return null;
        }
        return ['base' => $base, 'body' => $body, 'headers' => ['X-WPPilot-Signature' => $signature]];
    }

    /**
     * Write an Elementor document through wppilot/elementor-set-content, so a kit's Elementor
     * write gets the same normalisation and schema validation as GoBuildWP's own, under the gate
     * pipeline (run_ability). Null when that ability is not registered (Elementor inactive); the
     * kit then saves through Elementor's document API itself.
     *
     * The write is not recorded in the change log of its own: it fills a document the calling
     * kit ability made in this same call, and that ability's own row undoes it (by deleting the
     * document). A row of its own would offer an undo that empties the document while the
     * caller's other changes stay, and a session undo would fail on it once the caller's row
     * had deleted the document. So only write documents your own ledger row removes on undo.
     *
     * @param list<array<string, mixed>> $elements
     */
    public function write_elementor_content(int $post_id, array $elements, string $template_type): mixed
    {
        $ability = wp_get_ability('wppilot/elementor-set-content');
        if (!$ability instanceof WP_Ability) {
            return null;
        }
        $was_suppressed = \wppilot_change_is_suppressed();
        \wppilot_change_is_suppressed(true);
        try {
            // The caller's own write was confirmed by the person; this is that write, per document.
            return $this->run_ability($ability, ['post_id' => $post_id, 'content' => $elements, 'template_type' => $template_type, 'confirm' => true]);
        } finally {
            \wppilot_change_is_suppressed($was_suppressed);
            // The gate noted how this inner call was confirmed, for a row that is not written.
            \wppilot_change_confirmation('wppilot/elementor-set-content', clear: true);
        }
    }

    /**
     * Run an ability a kit ability is running on the agent's behalf; see Runtime
un_ability().
     *
     * The whole gate pipeline applies — safety profile, the inner ability's own confirmation,
     * the confirm strip and every wppilot_pre_ability_execute control — under the `nested`
     * transport. Nothing vouches for a human here, so a destructive inner ability still needs
     * its own `confirm: true`. The rate limiter and Pro's approval holds skip `nested`: the call
     * that carried this one was charged and held (or not) when it arrived, and holding the inner
     * call would queue a replay that runs outside the context it was made in (another site).
     */
    public function run_ability(WP_Ability $ability, mixed $input): mixed
    {
        // An ability switched off in the Abilities Hub is unregistered on the site the request
        // arrived at, which is the only place that switch is otherwise enforced. A kit ability
        // running this one on another network site has already switched there, so read that
        // site's rules here, or its Hub setting would not apply to it.
        $name = $ability->get_name();
        $rules = \wppilot_get_ability_rules();
        if (($rules[$name]['disabled'] ?? false) === true && !\wppilot_ability_is_hub_protected($name)) {
            return new WP_Error(
                'wppilot_ability_disabled',
                sprintf('Ability "%s" is switched off in this site\'s Abilities Hub.', $name),
                ['status' => 403, 'ability' => $name],
            );
        }
        /** @var mixed $gated */
        $gated = \wppilot_gate_ability_call($ability, $input, transport: 'nested');
        if ($gated instanceof WP_Error) {
            return $gated;
        }
        return $ability->execute(\WPPilot\Kits\Runtime\empty_input_for($ability, $gated));
    }

    public function admin_parent_slug(): string
    {
        return 'wppilot-connect';
    }

    /**
     * GoBuildWP's own profile rule, so a kit ability answers exactly as the Abilities Hub and the
     * MCP transports do: min_profile first, then the risk class the profile allows.
     */
    public function profile_allows(string $ability_name): bool|WP_Error
    {
        $ability = wp_get_ability($ability_name);
        if (!$ability instanceof \WP_Ability) {
            return new WP_Error(
                'kit_ability_unknown',
                sprintf('Ability "%s" is not registered, so its safety policy cannot be checked.', $ability_name),
                ['status' => 403],
            );
        }
        return \wppilot_safety_check_ability($ability);
    }

    /**
     * wppilot_gate_ability_call() demanded and stripped `confirm` before execute() ran.
     */
    public function confirm_guard(string $ability_name, array $input): bool|WP_Error
    {
        return true;
    }
}

/**
 * Filters the kits fired under GoBuildWP names before they could be exported: each kit-form hook
 * also runs the callbacks on its old name, so a snippet or Pro's rescue installer that hooks
 * gobuildwp_live_known_file (or a gobuildwp_protection_* filter) keeps working. Kit hook => [old
 * hook, accepted args].
 */
const LEGACY_FILTERS = [
    'wppilot_kit_protection_trusted_proxies' => ['gobuildwp_protection_trusted_proxies', 1],
    'wppilot_kit_protection_cloudflare_ranges' => ['gobuildwp_protection_cloudflare_ranges', 1],
    'wppilot_kit_protection_prepend_root' => ['gobuildwp_protection_prepend_root', 1],
    'wppilot_kit_protection_prepend_site_root' => ['gobuildwp_protection_prepend_site_root', 1],
    'wppilot_kit_live_known_file' => ['gobuildwp_live_known_file', 3],
];

/** Hook each kit-form filter in LEGACY_FILTERS to its old name; called once, when the kits boot. */
function bridge_legacy_filters(): void
{
    foreach (LEGACY_FILTERS as $kit_hook => [$old_hook, $args]) {
        add_filter(
            $kit_hook,
            static fn(mixed ...$values): mixed => apply_filters($old_hook, ...$values),
            10,
            $args,
        );
    }
}

// @mago-expect lint:too-many-methods -- One method per Ledger, PagedLedger and SessionLedger member, plus the filter callbacks they install.
// @mago-expect lint:cyclomatic-complexity -- Same: the sum of small methods, none of them branchy.
final class WPPilotLedger implements Ledger, PagedLedger, SessionLedger
{
    /** @var array<string, callable> */
    private array $captures = [];

    /** @var array<string, array{read: callable, target: callable}> */
    private array $states = [];

    public function register_state(string $type, callable $read, callable $target): void
    {
        if ($this->states === []) {
            add_filter('wppilot_change_current_state', [$this, 'supply_current_state'], 10, 2);
            add_filter('wppilot_change_payload_target', [$this, 'supply_payload_target'], 10, 2);
        }
        $this->states[$type] ??= ['read' => $read, 'target' => $target];
    }

    /**
     * @param mixed $state
     * @param array<string, mixed> $rollback
     * @return mixed
     */
    public function supply_current_state(mixed $state, array $rollback): mixed
    {
        $reader = $this->states[(string) ($rollback['type'] ?? '')] ?? null;
        if ($state !== null || $reader === null) {
            return $state;
        }
        return ($reader['read'])(self::before_image($rollback));
    }

    /**
     * @param mixed $target
     * @param array<string, mixed> $rollback
     * @return mixed
     */
    public function supply_payload_target(mixed $target, array $rollback): mixed
    {
        $type = (string) ($rollback['type'] ?? '');
        $reader = $this->states[$type] ?? null;
        if ((is_string($target) && $target !== '') || $reader === null) {
            return $target;
        }
        $key = (string) ($reader['target'])(self::before_image($rollback));
        return $key === '' ? '' : $type . ':' . $key;
    }

    /**
     * @param array<string, mixed> $rollback
     * @return array<string, mixed>
     */
    private static function before_image(array $rollback): array
    {
        return is_array($rollback['snapshot'] ?? null) ? $rollback['snapshot'] : [];
    }

    public function capture_for(string $ability_name, callable $capture): void
    {
        if ($this->captures === []) {
            add_filter('wppilot_capture_before_image', [$this, 'supply_before_image'], 10, 3);
        }
        $this->captures[$ability_name] = $capture;
    }

    /**
     * @param mixed $before
     * @param mixed $input
     * @return mixed
     */
    public function supply_before_image(mixed $before, string $ability_name, mixed $input): mixed
    {
        if ($before !== null || !isset($this->captures[$ability_name])) {
            return $before;
        }
        return ($this->captures[$ability_name])(is_array($input) ? $input : []);
    }

    public function record_items(string $ability_name, array $items, ?string $group = null): array
    {
        return \wppilot_ledger_record_items($ability_name, $items, $group);
    }

    public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
    {
        return \wppilot_register_rollback_strategy($type, $restore, $build);
    }

    public function query(array $filters = []): array
    {
        return \wppilot_query_change_log($filters);
    }

    public function query_page(array $filters, int $limit, int $offset): array
    {
        return \wppilot_query_change_log($filters, max(1, $limit), max(0, $offset));
    }

    public function count(array $filters = []): int
    {
        return \wppilot_count_change_log($filters);
    }

    public function export_row(array $entry): array
    {
        return \wppilot_change_export_row($entry);
    }

    public function snapshot_budget(): int
    {
        return WPPILOT_CHANGE_BULK_SNAPSHOT_BUDGET_BYTES;
    }

    public function download_url(): string
    {
        return admin_url('admin.php?page=wppilot-changes');
    }
}
