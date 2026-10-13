<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_State;

use function WPPilot\Abilities\WordPress\builder_remedy;
use function WPPilot\Abilities\WordPress\register_core_ability;
use function WPPilot\Abilities\WordPress\wordpress_builder_meta_error;
use function WPPilot\Abilities\WordPress\wordpress_create_capability_error;
use function WPPilot\Abilities\WordPress\wordpress_post_type_is_agent_facing;
use function WPPilot\Abilities\WordPress\wordpress_postmeta_builder_content_gate;
use function WPPilot\Abilities\WordPress\wordpress_postmeta_builder_content_warnings;
use function WPPilot\Abilities\WordPress\wordpress_postmeta_builder_owner;
use function WPPilot\Abilities\WordPress\wordpress_resolve_create_status;

/**
 * Safety invariants for the WordPress-core content wrappers now shipping in Free.
 */
final class ContentSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        WPPilot_Test_State::reset();
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::reset();
    }

    // ---------------------------------------------------------------- draft-first

    /**
     * Anything that is not an explicitly enumerated status must resolve to draft.
     * These are the values an agent realistically sends when it omits or fumbles
     * the field, and none of them may result in published content.
     */
    #[DataProvider('nonPublishingStatuses')]
    public function testAmbiguousStatusResolvesToDraft(mixed $status): void
    {
        self::assertSame('draft', wordpress_resolve_create_status($status));
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonPublishingStatuses(): array
    {
        return [
            'absent' => [null],
            'blank' => [''],
            'whitespace' => ['   '],
            'unknown word' => ['live'],
            'near miss' => ['published'],
            'integer' => [1],
            'boolean true' => [true],
            'array' => [['publish']],
            'uppercase unknown' => ['PUBLISH_NOW'],
        ];
    }

    public function testExplicitPublishIsHonoured(): void
    {
        self::assertSame('publish', wordpress_resolve_create_status('publish'));
    }

    public function testStatusIsCaseAndWhitespaceNormalised(): void
    {
        self::assertSame('publish', wordpress_resolve_create_status('  Publish '));
    }

    #[DataProvider('validStatuses')]
    public function testEveryEnumeratedStatusSurvives(string $status): void
    {
        self::assertSame($status, wordpress_resolve_create_status($status));
    }

    /** @return array<string, array{0: string}> */
    public static function validStatuses(): array
    {
        return [
            'publish' => ['publish'],
            'draft' => ['draft'],
            'pending' => ['pending'],
            'private' => ['private'],
            'future' => ['future'],
        ];
    }

    // ------------------------------------------------------- post-type exposure

    public function testPublicPostTypeIsAgentFacing(): void
    {
        WPPilot_Test_State::add_post_type('post');

        self::assertNull(wordpress_post_type_is_agent_facing('post'));
    }

    /**
     * Types owned by another subsystem stay closed even when WordPress registers
     * them as public and REST-visible.
     */
    #[DataProvider('internalPostTypes')]
    public function testInternalPostTypesAreRejected(string $post_type): void
    {
        WPPilot_Test_State::add_post_type($post_type, public: true, show_in_rest: true);

        $error = wordpress_post_type_is_agent_facing($post_type);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('post_type_not_agent_facing', $error->get_error_code());
        self::assertSame(403, $error->get_error_data()['status']);
    }

    /** @return array<string, array{0: string}> */
    public static function internalPostTypes(): array
    {
        return [
            'attachment' => ['attachment'],
            'revision' => ['revision'],
            'nav_menu_item' => ['nav_menu_item'],
            'wp_block' => ['wp_block'],
            'wp_template' => ['wp_template'],
            'wp_template_part' => ['wp_template_part'],
            'wp_global_styles' => ['wp_global_styles'],
            'wp_navigation' => ['wp_navigation'],
            'customize_changeset' => ['customize_changeset'],
            'user_request' => ['user_request'],
            'custom_css' => ['custom_css'],
            'oembed_cache' => ['oembed_cache'],
        ];
    }

    public function testPrivatePluginPostTypeIsRejected(): void
    {
        WPPilot_Test_State::add_post_type('acme_internal', public: false, show_in_rest: false);

        $error = wordpress_post_type_is_agent_facing('acme_internal');

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('post_type_not_public', $error->get_error_code());
    }

    public function testRestVisibleButNonPublicTypeIsAllowed(): void
    {
        WPPilot_Test_State::add_post_type('acme_docs', public: false, show_in_rest: true);

        self::assertNull(wordpress_post_type_is_agent_facing('acme_docs'));
    }

    public function testUnregisteredPostTypeIsRejected(): void
    {
        $error = wordpress_post_type_is_agent_facing('nope');

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('invalid_post_type', $error->get_error_code());
    }

    // ------------------------------------------------------- capability gating

    public function testCreateIsRefusedWithoutTheTypeCreateCapability(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['read'];

        $error = wordpress_create_capability_error('post', ['status' => 'draft']);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('cannot_create_post', $error->get_error_code());
        self::assertSame(403, $error->get_error_data()['status']);
    }

    /**
     * A custom type declaring its own capability set must not be satisfied by
     * `edit_posts`. This is the case the generic wrapper is most likely to get
     * wrong, so it is asserted from both directions.
     */
    public function testCustomPostTypeCapabilitiesAreNotSatisfiedByEditPosts(): void
    {
        WPPilot_Test_State::add_post_type('portfolio', capabilities: [
            'create_posts' => 'edit_portfolios',
            'publish_posts' => 'publish_portfolios',
        ]);
        WPPilot_Test_State::$capabilities = ['edit_posts', 'publish_posts'];

        $error = wordpress_create_capability_error('portfolio', ['status' => 'draft']);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('cannot_create_post', $error->get_error_code());
    }

    public function testCustomPostTypeCapabilityIsAcceptedWhenHeld(): void
    {
        WPPilot_Test_State::add_post_type('portfolio', capabilities: [
            'create_posts' => 'edit_portfolios',
        ]);
        WPPilot_Test_State::$capabilities = ['edit_portfolios'];

        self::assertNull(wordpress_create_capability_error('portfolio', ['status' => 'draft']));
    }

    public function testDraftIsAllowedWithoutPublishCapability(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts'];

        self::assertNull(wordpress_create_capability_error('post', ['status' => 'draft']));
    }

    /**
     * The publish grant is separate from the create grant, and every status that
     * makes content reachable is held to it.
     */
    #[DataProvider('publishingStatuses')]
    public function testPublishingIsRefusedWithoutPublishCapability(string $status): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts'];

        $error = wordpress_create_capability_error('post', ['status' => $status]);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('cannot_publish_post', $error->get_error_code());
    }

    /** @return array<string, array{0: string}> */
    public static function publishingStatuses(): array
    {
        return [
            'publish' => ['publish'],
            'future (self-publishes on schedule)' => ['future'],
            'private (published, restricted audience)' => ['private'],
        ];
    }

    public function testPublishingIsAllowedWithPublishCapability(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts', 'publish_posts'];

        self::assertNull(wordpress_create_capability_error('post', ['status' => 'publish']));
    }

    /**
     * A malformed status must not become a backdoor to publishing: the gate
     * resolves it draft-first before deciding, so no publish check is triggered
     * and no published content results.
     */
    public function testMalformedStatusDoesNotBypassThePublishGate(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts'];

        self::assertNull(wordpress_create_capability_error('post', ['status' => 'PUBLISH!']));
        self::assertSame('draft', wordpress_resolve_create_status('PUBLISH!'));
    }

    public function testAssigningAnotherAuthorNeedsEditOthersPosts(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts'];
        WPPilot_Test_State::$current_user_id = 7;

        $error = wordpress_create_capability_error('post', ['status' => 'draft', 'author' => 9]);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('cannot_assign_author', $error->get_error_code());
    }

    public function testSelfAuthorshipDoesNotNeedEditOthersPosts(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts'];
        WPPilot_Test_State::$current_user_id = 7;

        self::assertNull(wordpress_create_capability_error('post', ['status' => 'draft', 'author' => 7]));
    }

    // ------------------------------------------------- duplicate registration

    public function testCoreAbilityRegisters(): void
    {
        register_core_ability('wppilot/create-post', ['label' => 'Create']);

        self::assertSame(['wppilot/create-post'], WPPilot_Test_State::$registered_abilities);
    }

    /**
     * An older Pro registering the same name must not produce a second
     * registration; the first definition wins.
     */
    public function testDuplicateRegistrationIsSkipped(): void
    {
        register_core_ability('wppilot/create-post', ['label' => 'Free']);
        register_core_ability('wppilot/create-post', ['label' => 'Pro']);

        self::assertCount(1, WPPilot_Test_State::$registrations);
        self::assertSame('Free', WPPilot_Test_State::$registrations[0]['args']['label']);
    }

    // ------------------------------------------------------------ builder guard

    public function testBuilderRemedyNamesTheAbilityWhenRegistered(): void
    {
        WPPilot_Test_State::$registered_abilities = ['wppilot/divi-set-content'];

        self::assertStringContainsString(
            'wppilot/divi-set-content',
            builder_remedy('wppilot/divi-set-content', 'Divi 5'),
        );
    }

    /**
     * On a Free-only site the Pro ability does not exist, so the guard must not
     * send the agent after a tool it cannot call.
     */
    public function testBuilderRemedyFallsBackWhenAbilityIsAbsent(): void
    {
        $remedy = builder_remedy('wppilot/divi-set-content', 'Divi 5');

        self::assertStringNotContainsString('wppilot/divi-set-content', $remedy);
        self::assertStringContainsString('Divi 5', $remedy);
    }

    /**
     * The meta rejection has to route through builder_remedy() too, or it sends a
     * Free-only site after an ability that is not installed there.
     */
    public function testBuilderMetaRejectionDoesNotNameAnAbsentAbility(): void
    {
        $error = wordpress_builder_meta_error(['_elementor_data' => '[]']);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('builder_meta_rejected', $error->get_error_code());
        self::assertStringNotContainsString('wppilot/elementor-set-content', $error->get_error_message());
        self::assertStringContainsString('Elementor', $error->get_error_message());
    }

    public function testBuilderMetaRejectionNamesTheAbilityWhenRegistered(): void
    {
        WPPilot_Test_State::$registered_abilities = ['wppilot/elementor-set-content'];

        $error = wordpress_builder_meta_error(['_elementor_data' => '[]']);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertStringContainsString('wppilot/elementor-set-content', $error->get_error_message());
    }

    // ------------------------------------------- postmeta-builder content gate

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function postmetaBuilderOwners(): array
    {
        return [
            ['Elementor', '_elementor_edit_mode', 'builder'],
            ['Bricks', '_bricks_editor_mode', 'bricks'],
            ['Beaver Builder', '_fl_builder_enabled', '1'],
        ];
    }

    #[DataProvider('postmetaBuilderOwners')]
    public function testPostmetaBuilderOwnerIsDetected(string $builder, string $key, string $value): void
    {
        WPPilot_Test_State::set_post_meta(12, $key, $value);

        self::assertSame($builder, wordpress_postmeta_builder_owner(12)['builder'] ?? null);
    }

    /**
     * The whole point of the gate: this write used to be accepted, stored, and
     * invisible on the page, with a success response handed back to the agent.
     */
    #[DataProvider('postmetaBuilderOwners')]
    public function testContentWriteOnABuilderOwnedPostIsRefused(string $builder, string $key, string $value): void
    {
        WPPilot_Test_State::set_post_meta(12, $key, $value);

        $error = wordpress_postmeta_builder_content_gate(['content' => '<h2>New</h2>'], 12);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('builder_owned_post_content_needs_confirmation', $error->get_error_code());
        self::assertStringContainsString($builder, $error->get_error_message());
        self::assertStringContainsString('allow_raw_content_on_builder_post', $error->get_error_message());
    }

    public function testOrdinaryPostIsUnaffected(): void
    {
        self::assertNull(wordpress_postmeta_builder_owner(12));
        self::assertNull(wordpress_postmeta_builder_content_gate(['content' => '<h2>New</h2>'], 12));
    }

    /**
     * A disabled flag is commonly stored rather than deleted, so '0' must not
     * read as ownership.
     */
    public function testDisabledBuilderFlagIsNotOwnership(): void
    {
        WPPilot_Test_State::set_post_meta(12, '_fl_builder_enabled', '0');

        self::assertNull(wordpress_postmeta_builder_owner(12));
    }

    /**
     * Elementor's flag is a named mode, not a truthy marker: a post left in
     * 'editor' mode is not Elementor-owned.
     */
    public function testElementorEditorModeIsNotOwnership(): void
    {
        WPPilot_Test_State::set_post_meta(12, '_elementor_edit_mode', 'editor');

        self::assertNull(wordpress_postmeta_builder_owner(12));
    }

    public function testEmptyContentIsNotGated(): void
    {
        WPPilot_Test_State::set_post_meta(12, '_elementor_edit_mode', 'builder');

        self::assertNull(wordpress_postmeta_builder_content_gate(['content' => ''], 12));
        self::assertNull(wordpress_postmeta_builder_content_gate(['title' => 'Renamed'], 12));
    }

    public function testExplicitHandshakeAllowsTheWriteAndLeavesAnAuditNote(): void
    {
        WPPilot_Test_State::set_post_meta(12, '_elementor_edit_mode', 'builder');
        $input = ['content' => '<h2>New</h2>', 'allow_raw_content_on_builder_post' => true];

        self::assertNull(wordpress_postmeta_builder_content_gate($input, 12));

        $warnings = wordpress_postmeta_builder_content_warnings($input, 12);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('AUDIT', $warnings[0]);
        self::assertStringContainsString('Elementor', $warnings[0]);
    }

    public function testNoAuditNoteWithoutTheHandshake(): void
    {
        WPPilot_Test_State::set_post_meta(12, '_elementor_edit_mode', 'builder');

        self::assertSame([], wordpress_postmeta_builder_content_warnings(['content' => '<h2>New</h2>'], 12));
    }
}
