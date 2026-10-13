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
use WPPilot\Kits\Runtime\MiniLedger;
use WPPilot\Kits\Runtime\ProfileGate;
use WPPilot\Kits\Runtime\Runner;

use function WPPilot\Kits\Runtime\profile_check;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The kit host for a plugin that is not GoBuildWP.
 *
 * Supplies from nothing what GoBuildWP supplies from its own machinery: a capability check, a
 * safety profile, the confirm flag on destructive calls (there is no gate pipeline to demand it),
 * the `min_profile` rule, a change ledger, and two abilities to read and undo that ledger.
 *
 * Those two abilities are named from the host id at runtime rather than written out, because a
 * literal `wp_register_ability('wppilot/…')` anywhere under includes/ is counted as a GoBuildWP
 * ability by every verifier and by the website, and these never register inside GoBuildWP.
 */
final class StandaloneHost implements Host, ProfileGate
{
    private MiniLedger $ledger;

    private Runner $jobs;

    /**
     * @param array{
     *     enabled?: bool,
     *     capability?: string,
     *     safety_profile?: string,
     *     admin_parent_slug?: string,
     *     text_domain?: string,
     * } $config What the exporting plugin's config.php returns.
     */
    public function __construct(private string $id, private array $config = [])
    {
        $this->ledger = new MiniLedger($id);
        $this->jobs = new Runner();
        add_filter('wp_ability_permission_result', [$this, 'enforce_min_profile'], 10, 4);
        add_action('wp_abilities_api_categories_init', [$this, 'register_category'], 20);
        add_action('wp_abilities_api_init', [$this, 'register_ledger_abilities'], 20);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function can_manage(): bool
    {
        return current_user_can((string) ($this->config['capability'] ?? 'manage_options'));
    }

    public function is_enabled(): bool
    {
        return ($this->config['enabled'] ?? true) === true;
    }

    public function safety_profile(): string
    {
        $profile = (string) ($this->config['safety_profile'] ?? 'production');
        return in_array($profile, ['readonly', 'production', 'developer'], strict: true) ? $profile : 'production';
    }

    public function ledger(): Ledger
    {
        return $this->ledger;
    }

    public function jobs(): Jobs
    {
        return $this->jobs;
    }

    public function extension(string $point): mixed
    {
        return null;
    }

    public function admin_parent_slug(): string
    {
        return (string) ($this->config['admin_parent_slug'] ?? 'tools.php');
    }

    public function confirm_guard(string $ability_name, array $input): bool|WP_Error
    {
        $ability = wp_get_ability($ability_name);
        $annotations = $ability instanceof WP_Ability && is_array($ability->get_meta()['annotations'] ?? null)
            ? $ability->get_meta()['annotations']
            : [];
        if (($annotations['destructive'] ?? false) !== true || ($input['confirm'] ?? null) === true) {
            return true;
        }
        return new WP_Error(
            'kit_confirmation_required',
            sprintf('Ability "%s" is destructive. Obtain explicit user approval, then retry with confirm=true.', $ability_name),
            ['status' => 409],
        );
    }

    /**
     * The min_profile answer every kit ability asks for through Runtime\require_profile().
     */
    public function profile_allows(string $ability_name): bool|WP_Error
    {
        return profile_check($ability_name, $this->safety_profile());
    }

    /**
     * The same rule for every caller on WordPress 7.1+, where core runs this filter inside
     * WP_Ability itself; it also covers a kit ability that declares min_profile but was written
     * before require_profile() existed.
     *
     * @param bool|WP_Error $permission
     * @return bool|WP_Error
     */
    public function enforce_min_profile(mixed $permission, mixed $ability_name, mixed $input, mixed $ability = null): mixed
    {
        if ($permission !== true || !is_string($ability_name) || !str_starts_with($ability_name, $this->id . '/')) {
            return $permission;
        }
        return $this->profile_allows($ability_name);
    }

    public function register_category(): void
    {
        $slug = $this->id . '-changes';
        if (!wp_has_ability_category($slug)) {
            wp_register_ability_category($slug, [
                'label' => 'Kit changes',
                'description' => 'The change record kept for kit abilities, and the way back.',
            ]);
        }
    }

    public function register_ledger_abilities(): void
    {
        $permission = fn(): bool => $this->is_enabled() && $this->can_manage();

        wp_register_ability($this->id . '/kit-list-changes', [
            'label' => 'List kit changes',
            'description' => 'Lists the changes kit abilities made on this site, newest first, with whether each can be undone.',
            'category' => $this->id . '-changes',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                    'group' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => function (array $input = []): array {
                $rows = $this->ledger->query(['group' => (string) ($input['group'] ?? '')]);
                $rows = array_slice($rows, 0, (int) ($input['limit'] ?? 50));
                return ['items' => array_map([$this->ledger, 'export_row'], $rows), 'count' => count($rows)];
            },
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);

        wp_register_ability($this->id . '/kit-rollback-change', [
            'label' => 'Undo a kit change',
            'description' => 'Undoes one change a kit ability made, then re-reads the target and reports success only if it matches the before-image. Requires confirm=true.',
            'category' => $this->id . '-changes',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['change_id' => ['type' => 'string'], 'confirm' => ['type' => 'boolean']],
                'required' => ['change_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => function (array $input): array|WP_Error {
                $confirmed = $this->confirm_guard($this->id . '/kit-rollback-change', $input);
                if ($confirmed instanceof WP_Error) {
                    return $confirmed;
                }
                return $this->ledger->rollback((string) $input['change_id']);
            },
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            ],
        ]);
    }
}
