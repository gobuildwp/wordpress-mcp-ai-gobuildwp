<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

use WP_Error;

use const ELEMENTOR_VERSION;

/**
 * Elementor: reading and writing a page's element tree.
 *
 * Elementor stores its tree as JSON in post meta and caches generated CSS
 * alongside it, so any write has to invalidate that cache or the page renders
 * with stale styles.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Generate a 7-character hex ID, matching the format Elementor uses for elements.
 */
function el_generate_id(): string
{
    return substr(bin2hex(random_bytes(4)), offset: 0, length: 7);
}

/**
 * Read and decode `_elementor_data` for a post.
 *
 * @return array{0: list<array<string, mixed>>, 1: null}|array{0: null, 1: string}
 */
function el_read_page(int $post_id): array
{
    /** @var mixed $raw */
    $raw = get_post_meta($post_id, key: '_elementor_data', single: true);
    if (!is_string($raw) || $raw === '') {
        return [[], null];
    }

    /** @var mixed $decoded */
    $decoded = json_decode($raw, associative: true);
    if (!is_array($decoded)) {
        return [null, 'Failed to parse Elementor data.'];
    }

    /** @var list<array<string, mixed>> $decoded */
    return [array_values($decoded), null];
}

/**
 * Read the Elementor tree for a post, treating an empty document as an
 * error. Use this from abilities that mutate existing content (dynamic
 * tags, global classes, interactions) where "no data yet" is not a
 * valid starting state.
 *
 * @return array{0: list<array<string, mixed>>, 1: null}|array{0: null, 1: string}
 */
function el_read_page_required(int $post_id): array
{
    [$elements, $error] = el_read_page($post_id);
    if ($elements === null) {
        return [null, $error ?? 'Unknown error.'];
    }
    if ($elements === []) {
        return [null, "No Elementor data found for page {$post_id}."];
    }
    return [$elements, null];
}

/**
 * Write the document tree to `_elementor_data` and set the supporting meta keys
 * Elementor expects on every editable post.
 *
 * @param list<array<string, mixed>> $elements
 */
function el_write_page(int $post_id, array $elements, ?string $template_type = null): bool|WP_Error
{
    if (!class_exists('Elementor\\Plugin')) {
        return new WP_Error('no_elementor', 'Elementor is not active.');
    }

    // Preserve the post's existing template type when the caller didn't
    // specify one. Overwriting with "wp-page" on every save would reset
    // elementor_library posts (header, footer, container, single-post,
    // etc.) to a plain page type, breaking Theme Builder display rules.
    $template_type = el_resolve_template_type($post_id, $template_type);

    // Ensure the Atomic Widgets Dynamic Tags module is initialized so that
    // the props-schema filter includes "dynamic" as a valid alternative.
    // Without this, {$$type: "dynamic"} values fail Props_Parser validation.
    el_ensure_dynamic_tags_module();

    // Elementor's Document::save() pipeline (get_data_for_save) silently
    // strips atomic widget elements (e-heading, e-paragraph, etc.) from
    // the tree — it only preserves legacy widgets and containers. When
    // the tree contains any atomic element, bypass the Document API and
    // write directly to post meta.
    if (el_tree_has_atomic_elements($elements)) {
        return el_write_page_raw($post_id, $elements, $template_type);
    }

    // Use the Elementor Document API when available. This runs the full save
    // pipeline: element data normalization (get_data_for_save, which for
    // legacy widgets validates settings), plain text generation for SEO,
    // and all save hooks.
    /** @var object $plugin */
    $plugin = \Elementor\Plugin::$instance;
    /** @var object|null $documents_manager */
    $documents_manager = $plugin->documents ?? null;
    if (!is_object($documents_manager) || !method_exists($documents_manager, 'get')) {
        return el_write_page_raw($post_id, $elements, $template_type);
    }

    /** @var \Elementor\Core\Base\Document|null $document */
    $document = $documents_manager->get($post_id);
    if ($document !== null) {
        update_post_meta($post_id, meta_key: '_elementor_edit_mode', meta_value: 'builder');
        update_post_meta($post_id, meta_key: '_elementor_template_type', meta_value: $template_type);

        try {
            $save_result = $document->save(['elements' => $elements]);
        } catch (\Throwable $e) {
            // Fallback: save() may throw or produce a PHP Error (e.g. calling
            // get_default_args() on an unexpected array in the elements manager).
            // Write raw so the page is always persisted.
            return el_write_page_raw($post_id, $elements, $template_type);
        }
        if ($save_result === false) {
            // Fallback: save() may fail due to permission checks in non-editor
            // context. Write directly but still clear caches properly.
            return el_write_page_raw($post_id, $elements, $template_type);
        }

        el_clear_css_cache($post_id);
        return true;
    }

    // Fallback for posts without an existing Elementor document.
    return el_write_page_raw($post_id, $elements, $template_type);
}

/**
 * Raw write fallback when the Document API is not available or fails
 * (e.g. permission checks in headless/MCP context).
 *
 * @param list<array<string, mixed>> $elements
 */
function el_write_page_raw(int $post_id, array $elements, string $template_type): bool|WP_Error
{
    $elements = el_kses_elements_for_current_user($elements);

    // Elementor's `elementor/document/save/data` filter runs the interactions
    // Parser to convert `temp-...` ids (the marker for client-side unsaved
    // interactions) into stable, post/element-scoped ids. The raw write path
    // bypasses that filter, so run the Parser here too — otherwise temp ids
    // leak into `_elementor_data` and Elementor treats every load as an
    // unsaved interaction.
    $elements = el_assign_interaction_ids($post_id, $elements);

    $encoded = wp_json_encode($elements);
    if ($encoded === false) {
        return new WP_Error('encode_failed', 'Failed to encode Elementor data.');
    }

    update_post_meta($post_id, meta_key: '_elementor_data', meta_value: wp_slash($encoded));
    update_post_meta($post_id, meta_key: '_elementor_edit_mode', meta_value: 'builder');
    update_post_meta($post_id, meta_key: '_elementor_template_type', meta_value: $template_type);

    if (defined('ELEMENTOR_VERSION')) {
        update_post_meta($post_id, meta_key: '_elementor_version', meta_value: ELEMENTOR_VERSION);
    }

    el_clear_css_cache($post_id);

    return true;
}

/**
 * Filter every string in an element tree through kses when the current user
 * may not post unfiltered HTML.
 *
 * Elementor holds such a user to this in its own editor: Document::save()
 * runs Utils::kses_post_deep() over the data, and its REST meta sanitizer does
 * the same to `_elementor_data`. The raw write bypasses Document::save(), and
 * Elementor registers that sanitizer only on rest_api_init, so without this an
 * HTML widget, a <script> in a title, or markup in custom CSS written from a
 * cron job, WP-CLI or an Elementor version without the sanitizer would be
 * stored as given. Keys are left alone and non-strings pass through, as in
 * Elementor's version.
 *
 * @param list<array<string, mixed>> $elements
 * @param (callable(string): string)|null $kses Defaults to wp_kses_post.
 * @return list<array<string, mixed>>
 */
function el_kses_elements_for_current_user(array $elements, ?callable $kses = null): array
{
    if (current_user_can('unfiltered_html')) {
        return $elements;
    }

    $kses ??= 'wp_kses_post';
    array_walk_recursive($elements, static function (mixed &$value) use ($kses): void {
        if (is_string($value)) {
            $value = (string) $kses($value);
        }
    });

    return $elements;
}

/**
 * Resolve the template type to persist. Uses the caller's explicit
 * value when provided, otherwise reads the existing post meta to
 * preserve it, falling back to "wp-page" only when the post has no
 * existing template type at all.
 */
function el_resolve_template_type(int $post_id, ?string $explicit): string
{
    if ($explicit !== null && $explicit !== '') {
        return $explicit;
    }
    if (!metadata_exists(meta_type: 'post', object_id: $post_id, meta_key: '_elementor_template_type')) {
        return 'wp-page';
    }
    $existing = (string) get_post_meta($post_id, key: '_elementor_template_type', single: true);
    if ($existing !== '') {
        return $existing;
    }
    return 'wp-page';
}

/**
 * Replace any `temp-...` interaction id in the tree with a stable id, using
 * Elementor's own Parser so the rules (id format, lookup deduplication,
 * JSON-encoded interactions field) match what the editor would produce on
 * save. Returns the input unchanged when the Parser class is absent.
 *
 * @param list<array<string, mixed>> $elements
 * @return list<array<string, mixed>>
 */
function el_assign_interaction_ids(int $post_id, array $elements): array
{
    if (!class_exists(\Elementor\Modules\Interactions\Parser::class)) {
        return $elements;
    }

    $parser = new \Elementor\Modules\Interactions\Parser($post_id);
    /** @var array{elements?: list<array<string, mixed>>} $assigned */
    $assigned = $parser->assign_interaction_ids(['elements' => $elements]);

    return $assigned['elements'] ?? $elements;
}

/**
 * Ensure the Atomic Widgets Dynamic Tags module is initialized. In MCP/REST
 * context this module may not be auto-loaded, which means the
 * `elementor/atomic-widgets/props-schema` filter that adds "dynamic" as a
 * valid prop alternative is missing — causing Props_Parser to reject
 * {$$type: "dynamic"} values during Document::save().
 */
function el_ensure_dynamic_tags_module(): void
{
    $class = 'Elementor\\Modules\\AtomicWidgets\\DynamicTags\\Dynamic_Tags_Module';
    if (!class_exists($class)) {
        return;
    }

    // The module is a singleton — instance() returns the existing one or
    // creates it. register_hooks() is idempotent (WordPress deduplicates
    // identical add_filter/add_action calls).
    $module = $class::instance();
    $module->register_hooks();
}

/**
 * Trigger Elementor's CSS regeneration so the front end reflects the change
 * without a manual editor save.
 *
 * Invalidation is scoped to the edited document. The previous implementation
 * called the GLOBAL `files_manager->clear_cache()`, which globs and unlinks
 * EVERY document's compiled CSS in `uploads/elementor/css/` (and wipes all
 * posts' Post_CSS + atomic cache-validity meta). Editing one post therefore
 * discarded every other page's compiled CSS — including the v4 atomic
 * editor-preview files (`local-<id>-preview-*.css`) an open editor canvas
 * depends on — forcing a needless site-wide recompile on every write and
 * opening a window where another page can render file-missing-but-flag-valid.
 * Scope it to `$post_id`: Elementor regenerates just this post's CSS on the
 * next render. Genuinely site-wide changes (global variables, kit styles)
 * keep their own `clear_cache()` in their own abilities, where it is correct.
 */
function el_clear_css_cache(int $post_id): void
{
    if (!class_exists('Elementor\\Plugin')) {
        return;
    }

    // Drop only THIS document's compiled v3 CSS file (+ its meta). Regenerated
    // lazily on the next render, exactly like the old global wipe did, but
    // without touching any other document.
    $post_css_class = 'Elementor\\Core\\Files\\CSS\\Post';
    if (class_exists($post_css_class)) {
        (new $post_css_class($post_id))->delete();
    }

    delete_post_meta($post_id, meta_key: '_elementor_css');

    // Invalidate the v4 atomic styles cache (per-element styles + global
    // classes CSS). Without this, changes to `styles` fields in atomic
    // elements are not reflected on the frontend.
    do_action('elementor/atomic-widgets/styles/clear', ['local', $post_id]);
    do_action('elementor/atomic-widgets/styles/clear', ['local', $post_id, 'frontend']);
    do_action('elementor/atomic-widgets/styles/clear', ['local', $post_id, 'preview']);

    // Elementor Pro caches rendered element HTML in its own post meta, and it
    // checks that cache BEFORE applying render filters. A write that leaves it
    // in place is a write the front end may never show — the CSS is rebuilt,
    // the markup is not. It had its own ability, which made an agent's success
    // depend on remembering a second call after every edit; nobody remembers,
    // and the failure looks like "the change did not save". Dropping it here
    // costs one delete_post_meta on a write that has already touched the
    // database, and Elementor rebuilds it on the next render.
    delete_post_meta($post_id, meta_key: '_elementor_element_cache');

    // Fire the WP standard post-cache invalidation so third-party
    // optimization plugins (Perfmatters, WP Rocket, LiteSpeed, …) that
    // listen to `clean_post_cache` can purge their per-post caches.
    // Without this, meta-only updates to `_elementor_data` leave those
    // caches stale and the frontend serves old styles.
    clean_post_cache($post_id);
}

/**
 * Where to go and look at what was just written.
 *
 * An agent builds a page and never sees it. Every other craft looks at the
 * thing it made; page building is the one where the tool hands back
 * `success: true` and the model moves on, having never once observed whether
 * the hero is balanced, whether two sections came out identical, or whether
 * the page renders at all. Returning the URL costs nothing and is the
 * difference between building and building blind.
 *
 * `view_url` is the address a visitor uses, and is empty for a document that
 * has no public URL of its own — a header, a popup, a loop item. `preview_url`
 * carries the nonce a draft needs, so an unpublished page is still viewable.
 *
 * @return array{view_url: string, preview_url: string, edit_url: string}
 */
function el_look_at_it(int $post_id): array
{
    $post = get_post($post_id);
    if ($post === null) {
        return ['view_url' => '', 'preview_url' => '', 'edit_url' => ''];
    }

    // A Theme Builder document, a popup or a loop item is rendered inside
    // something else and has no meaningful permalink of its own; offering one
    // would send the reader to a bare page that looks broken.
    $has_public_url = !in_array($post->post_type, ['elementor_library', 'elementor_snippet'], strict: true);

    $view = $has_public_url && $post->post_status === 'publish'
        ? (string) get_permalink($post_id)
        : '';

    $preview = $has_public_url ? (string) get_preview_post_link($post_id) : '';

    return [
        'view_url' => $view,
        'preview_url' => $preview,
        'edit_url' => (string) get_edit_post_link($post_id, context: 'raw'),
    ];
}
