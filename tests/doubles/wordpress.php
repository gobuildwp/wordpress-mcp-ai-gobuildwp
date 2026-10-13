<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * WordPress function and class doubles for the unit suite.
 *
 * Each double reproduces only the behaviour the code under test depends on, and
 * every piece of mutable state lives on WPPilot_Test_State so a test can set up
 * a scenario and reset it in tearDown().
 */

/**
 * Mutable WordPress state for the doubles.
 */
final class WPPilot_Test_State
{
    /** @var array<string, WP_Post_Type> */
    public static array $post_types = [];

    /** @var list<string> */
    public static array $capabilities = [];

    public static int $current_user_id = 0;

    /** @var list<string> */
    public static array $registered_abilities = [];

    /** @var list<array{name: string, args: array<string, mixed>}> */
    public static array $registrations = [];

    /** @var list<string> */
    public static array $registered_categories = [];

    /** @var array<string, object> */
    public static array $taxonomies = [];

    public static bool $logged_in = true;

    public static bool $wppilot_enabled = true;

    public static bool $multisite = false;

    /** @var array<string, array<string, string>> */
    public static array $plugins = [];

    /**
     * The options table, as WordPress's in-request cache holds it: PHP values
     * exactly as they were written.
     *
     * @var array<string, mixed>
     */
    public static array $options = [];

    /** @var array<string, int> hook => next timestamp */
    public static array $cron = [];

    /** @var list<array{url: string, body: mixed}> */
    public static array $http_posts = [];

    /**
     * Simulate a request boundary.
     *
     * WordPress caches the PHP value you wrote for the rest of the request, but
     * the options table stores a string. So `true` comes back as `true` in the
     * request that wrote it and as `'1'` in every request after, and `false`
     * comes back as `''`. Code that reads an option it just wrote and code that
     * reads it a minute later are therefore reading different types — which is
     * the single most common source of "works once, then stops" option bugs.
     */
    public static function next_request(): void
    {
        foreach (self::$options as $name => $value) {
            if (is_bool($value)) {
                self::$options[$name] = $value ? '1' : '';
            } elseif (is_int($value) || is_float($value)) {
                self::$options[$name] = (string) $value;
            }
        }
    }

    /** @var array<int, WP_Post> */
    public static array $posts = [];

    /** @var array<int, WP_Post> */
    public static array $revisions = [];

    /**
     * Post meta, keyed by post id then meta key. Values are stored as the list
     * WordPress keeps internally, so the double can serve both the single and
     * the multi-value reads from one store.
     *
     * @var array<int, array<string, list<string>>>
     */
    public static array $post_meta = [];

    /** @var list<int> */
    public static array $nav_menus = [];

    /** @var array<int, list<int>> Menu item id to menu ids. */
    public static array $nav_menu_memberships = [];

    /** @var list<array{menu_id: int, item_id: int, args: array<string, mixed>}> */
    public static array $nav_menu_updates = [];

    /**
     * What wp_restore_post_revision() answers. WordPress returns the post id on
     * success, false when the revision carried no restorable fields, and null on
     * error, so the doubles have to be able to produce all three.
     */
    public static int|false|null $restore_result = null;

    public static function reset(): void
    {
        self::$post_types = [];
        self::$capabilities = [];
        self::$current_user_id = 0;
        self::$registered_abilities = [];
        self::$registrations = [];
        self::$registered_categories = [];
        self::$taxonomies = [];
        self::$logged_in = true;
        self::$wppilot_enabled = true;
        self::$multisite = false;
        self::$plugins = [];
        self::$posts = [];
        self::$revisions = [];
        self::$post_meta = [];
        self::$nav_menus = [];
        self::$nav_menu_memberships = [];
        self::$nav_menu_updates = [];
        self::$restore_result = null;
    }

    /**
     * Store one post meta value the way WordPress would.
     */
    public static function set_post_meta(int $post_id, string $key, string $value): void
    {
        self::$post_meta[$post_id][$key] = [$value];
    }

    /**
     * Register a revision and the parent post it belongs to.
     */
    public static function add_revision(int $revision_id, int $parent_id): void
    {
        $parent = new WP_Post();
        $parent->ID = $parent_id;
        $parent->post_title = 'Parent';
        $parent->post_status = 'publish';
        self::$posts[$parent_id] = $parent;

        $revision = new WP_Post();
        $revision->ID = $revision_id;
        $revision->post_parent = $parent_id;
        $revision->post_name = $parent_id . '-revision-v1';
        self::$revisions[$revision_id] = $revision;
    }

    /**
     * Register a taxonomy double.
     *
     * @param array<string, string> $capabilities Capability-object overrides.
     */
    public static function add_taxonomy(
        string $name,
        bool $public = true,
        bool $show_in_rest = true,
        bool $hierarchical = false,
        array $capabilities = [],
    ): void {
        self::$taxonomies[$name] = (object) [
            'name' => $name,
            'label' => ucfirst($name),
            'public' => $public,
            'show_in_rest' => $show_in_rest,
            'hierarchical' => $hierarchical,
            'object_type' => ['post'],
            'cap' => (object) array_merge([
                'manage_terms' => 'manage_categories',
                'edit_terms' => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'edit_posts',
            ], $capabilities),
        ];
    }

    /**
     * Register a post type double.
     *
     * @param array<string, mixed> $capabilities Capability-object overrides.
     */
    public static function add_post_type(
        string $name,
        bool $public = true,
        bool $show_in_rest = true,
        array $capabilities = [],
    ): void {
        $type = new WP_Post_Type();
        $type->name = $name;
        $type->public = $public;
        $type->show_in_rest = $show_in_rest;
        $type->cap = (object) array_merge([
            'create_posts' => 'edit_posts',
            'publish_posts' => 'publish_posts',
            'edit_others_posts' => 'edit_others_posts',
            'edit_post' => 'edit_post',
            'delete_post' => 'delete_post',
        ], $capabilities);

        self::$post_types[$name] = $type;
    }
}

if (!class_exists('WP_Post_Type')) {
    /**
     * Minimal stand-in for WordPress's registered post-type object.
     */
    class WP_Post_Type
    {
        public string $name = '';

        public bool $public = false;

        public bool $show_in_rest = false;

        public object $cap;

        public function __construct()
        {
            $this->cap = (object) [];
        }
    }
}

if (!class_exists('WP_Post')) {
    /**
     * Minimal stand-in for a WordPress post row.
     */
    class WP_Post
    {
        public int $ID = 0;

        public int $post_parent = 0;

        public string $post_type = 'post';

        public string $post_status = 'draft';

        public string $post_name = '';

        public string $post_title = '';

        public string $post_content = '';

        public string $post_excerpt = '';

        public string $post_mime_type = '';

        public string $post_modified_gmt = '';

        public int $post_author = 0;

        public int $menu_order = 0;

        public string $title = '';

        public string $url = '';

        public string $type = '';

        public string $object = '';

        public int $object_id = 0;

        public int $menu_item_parent = 0;

        public string $target = '';

        /** @var list<string> */
        public array $classes = [];
    }
}

if (!class_exists('WP_Error')) {
    /**
     * Minimal stand-in for WordPress's error object.
     */
    class WP_Error
    {
        /** @param array<string, mixed> $data */
        public function __construct(
            private string $code = '',
            private string $message = '',
            private array $data = [],
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /** @return array<string, mixed> */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * Minimal stand-in for a REST request.
     *
     * Only the JSON body matters here, and only because WordPress hands back null for it far more
     * often than the name suggests: no body, a body that did not parse, and a body sent without the
     * JSON content type all arrive the same way.
     */
    class WP_REST_Request
    {
        /** @param array<array-key, mixed> $params */
        public function __construct(
            private mixed $json_params = null,
            private array $params = [],
        ) {
        }

        public function get_json_params(): mixed
        {
            return $this->json_params;
        }

        /** @return array<array-key, mixed> */
        public function get_params(): array
        {
            return $this->params;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    /**
     * Minimal stand-in for a REST response.
     */
    class WP_REST_Response
    {
        public function __construct(
            public mixed $data = null,
            public int $status = 200,
        ) {
        }
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type): ?WP_Post_Type
    {
        return WPPilot_Test_State::$post_types[$post_type] ?? null;
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists(string $post_type): bool
    {
        return isset(WPPilot_Test_State::$post_types[$post_type]);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return in_array($capability, WPPilot_Test_State::$capabilities, true);
    }
}

if (!function_exists('get_post')) {
    /**
     * Honours ARRAY_A, which the ledger's before-image capture asks for.
     */
    function get_post(int $post_id, string $output = 'OBJECT'): WP_Post|array|null
    {
        $post = WPPilot_Test_State::$posts[$post_id] ?? null;
        if ($post === null) {
            return null;
        }

        return $output === 'ARRAY_A' ? get_object_vars($post) : $post;
    }
}

if (!function_exists('get_post_meta')) {
    /**
     * Mirrors WordPress: no key returns every value keyed by meta key; a key
     * with $single returns the first value or '' when absent; a key without
     * $single returns the whole list.
     *
     * @return mixed
     */
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        $meta = WPPilot_Test_State::$post_meta[$post_id] ?? [];

        if ($key === '') {
            return $meta;
        }

        // Stored as the postmeta table holds it; read back unserialized, as WordPress does.
        $values = array_map('maybe_unserialize', $meta[$key] ?? []);

        return $single ? ($values[0] ?? '') : $values;
    }
}

if (!function_exists('is_serialized')) {
    function is_serialized(mixed $data, bool $strict = true): bool
    {
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ($data === 'N;' || $data === 'b:0;') {
            return true;
        }
        if (preg_match('/^[aOsibdC]:/', $data) !== 1) {
            return false;
        }
        return @unserialize($data, ['allowed_classes' => false]) !== false;
    }
}

if (!function_exists('maybe_serialize')) {
    /**
     * As core: arrays and objects are serialized, and so is a string that is already serialized,
     * so it comes back from maybe_unserialize() as the same string.
     */
    function maybe_serialize(mixed $data): mixed
    {
        if (is_array($data) || is_object($data)) {
            return serialize($data);
        }
        return is_serialized($data, false) ? serialize($data) : $data;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize(mixed $data): mixed
    {
        return is_serialized($data) ? @unserialize(trim((string) $data)) : $data;
    }
}

if (!function_exists('get_posts')) {
    /**
     * The posts in WPPilot_Test_State::$posts that match the arguments GoBuildWP and its kits pass:
     * post_type, post_status, post_mime_type (a prefix such as `image`), paging, ID order and
     * `fields => ids`. No posts stored means no posts returned.
     *
     * @param array<string, mixed> $args
     * @return list<WP_Post|int>
     */
    function get_posts(array $args = []): array
    {
        $types = (array) ($args['post_type'] ?? 'post');
        $statuses = (array) ($args['post_status'] ?? 'publish');
        $mime = (string) ($args['post_mime_type'] ?? '');
        $matches = [];
        foreach (WPPilot_Test_State::$posts as $post) {
            if (!in_array('any', $types, true) && !in_array($post->post_type, $types, true)) {
                continue;
            }
            if (!in_array('any', $statuses, true) && !in_array($post->post_status, $statuses, true)) {
                continue;
            }
            if ($mime !== '' && !str_starts_with($post->post_mime_type, $mime)) {
                continue;
            }
            $matches[] = $post;
        }
        usort($matches, static fn(WP_Post $a, WP_Post $b): int => $a->ID <=> $b->ID);
        if (strtoupper((string) ($args['order'] ?? 'DESC')) === 'DESC') {
            $matches = array_reverse($matches);
        }
        $per_page = (int) ($args['posts_per_page'] ?? 5);
        if ($per_page > 0) {
            $matches = array_slice($matches, (max(1, (int) ($args['paged'] ?? 1)) - 1) * $per_page, $per_page);
        }
        return ($args['fields'] ?? '') === 'ids' ? array_map(static fn(WP_Post $post): int => $post->ID, $matches) : $matches;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(int|string $key, string $group = ''): bool
    {
        return true;
    }
}

if (!function_exists('get_object_taxonomies')) {
    /** @return list<string> */
    function get_object_taxonomies(string $post_type, string $output = 'names'): array
    {
        return [];
    }
}

if (!function_exists('wp_get_object_terms')) {
    /**
     * @param array<string, string> $args
     * @return list<int>
     */
    function wp_get_object_terms(int $object_id, string $taxonomy, array $args = []): array
    {
        return [];
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags, $depth);
    }
}

if (!function_exists('wp_get_post_revision')) {
    /**
     * Takes its argument by reference, as WordPress does, so a call site that
     * hands it a non-variable is caught here rather than in production.
     */
    function wp_get_post_revision(int &$post): ?WP_Post
    {
        return WPPilot_Test_State::$revisions[$post] ?? null;
    }
}

if (!function_exists('wp_restore_post_revision')) {
    function wp_restore_post_revision(int $revision_id): int|false|null
    {
        return WPPilot_Test_State::$restore_result;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return WPPilot_Test_State::$current_user_id;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    /**
     * Core's result for plain single-line text: tags stripped, whitespace collapsed.
     */
    function sanitize_text_field(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('_doing_it_wrong')) {
    /**
     * Recorded rather than raised, so a test can assert a misuse was reported.
     */
    function _doing_it_wrong(string $function_name, string $message, string $version): void
    {
        $GLOBALS['wppilot_test_doing_it_wrong'][] = [$function_name, $message, $version];
    }
}

if (!function_exists('wp_get_current_user')) {
    /**
     * The two fields the change ledger reads off the acting user.
     */
    function wp_get_current_user(): object
    {
        $id = WPPilot_Test_State::$current_user_id;

        return (object) ['ID' => $id, 'user_login' => $id > 0 ? 'user' . $id : ''];
    }
}

if (!function_exists('wp_has_ability')) {
    function wp_has_ability(string $name): bool
    {
        return in_array($name, WPPilot_Test_State::$registered_abilities, true);
    }
}

if (!function_exists('wp_register_ability')) {
    /** @param array<string, mixed> $args */
    function wp_register_ability(string $name, array $args): void
    {
        WPPilot_Test_State::$registered_abilities[] = $name;
        WPPilot_Test_State::$registrations[] = ['name' => $name, 'args' => $args];
    }
}

if (!function_exists('wp_has_ability_category')) {
    function wp_has_ability_category(string $slug): bool
    {
        return in_array($slug, WPPilot_Test_State::$registered_categories, true);
    }
}

if (!function_exists('wp_register_ability_category')) {
    /** @param array<string, mixed> $args */
    function wp_register_ability_category(string $slug, array $args): void
    {
        WPPilot_Test_State::$registered_categories[] = $slug;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return WPPilot_Test_State::$logged_in;
    }
}

if (!function_exists('wppilot_is_enabled')) {
    function wppilot_is_enabled(): bool
    {
        return WPPilot_Test_State::$wppilot_enabled;
    }
}

if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): object|false
    {
        return WPPilot_Test_State::$taxonomies[$taxonomy] ?? false;
    }
}

if (!function_exists('wp_allowed_protocols')) {
    /** @return list<string> */
    function wp_allowed_protocols(): array
    {
        return ['http', 'https', 'mailto', 'tel'];
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('validate_file')) {
    /**
     * Reproduces WordPress's traversal check: 0 is "acceptable path", and each
     * non-zero code is one of the rejections the real function reports.
     *
     * @param list<string> $allowed_files
     */
    function validate_file(string $file, array $allowed_files = []): int
    {
        if ($allowed_files !== [] && in_array($file, $allowed_files, true)) {
            return 0;
        }
        if (str_contains($file, '..')) {
            return 1;
        }
        if (preg_match('#^[A-Za-z]:#', $file) === 1) {
            return 2;
        }
        if (str_starts_with($file, './')) {
            return 3;
        }

        return 0;
    }
}

if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path(string $path): string
    {
        return preg_replace('#/+#', '/', str_replace('\\', '/', $path)) ?? $path;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        $file = wp_normalize_path($file);

        return ltrim((string) preg_replace('#^.*/plugins/#', '', $file), '/');
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return WPPilot_Test_State::$multisite;
    }
}

if (!function_exists('get_plugins')) {
    /** @return array<string, array<string, string>> */
    function get_plugins(): array
    {
        return WPPilot_Test_State::$plugins;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    /**
     * Mirrors WordPress: script and style elements lose their contents as well
     * as their tags, everything else keeps its text.
     */
    function wp_strip_all_tags(string $text): string
    {
        $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);

        return trim(strip_tags($text));
    }
}

if (!function_exists('esc_url_raw')) {
    /**
     * Scheme-stripping stand-in for WordPress's URL sanitizer.
     *
     * The real function returns an empty string for a URL whose scheme is not in
     * wp_allowed_protocols(); this reproduces that specific behaviour, which is
     * the property wordpress_unsafe_url_error() is written against.
     */
    function esc_url_raw(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            return $url;
        }

        return in_array(strtolower($scheme), wp_allowed_protocols(), true) ? $url : '';
    }
}

if (!function_exists('get_home_url')) {
    /**
     * A fixed home URL, because the design seed is derived from it.
     *
     * Every derived value hangs off this string, so a suite that let it vary
     * would assert on numbers that changed with the environment. Fixing it is
     * also the point being tested elsewhere: the same site always resolves to
     * the same design.
     */
    function get_home_url(?int $blog_id = null, string $path = ''): string
    {
        return 'https://wppilot.test' . $path;
    }
}

if (!function_exists('add_action')) {
    /**
     * Hook registration is a no-op here.
     *
     * The suite loads modules that register hooks at file scope. It asserts on
     * what the registered callbacks compute, never on the registration itself, so
     * recording the callback would be state no test reads.
     */
    function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }
}

if (!function_exists('add_filter')) {
    /**
     * Filters, unlike actions, are recorded and really run.
     *
     * They started as the same no-op add_action still is, and that stopped being
     * honest once the design checker began asking extensions to contribute
     * rules: the whole point of that seam is that a filter changes the answer,
     * and a double that always returns the input can only ever prove the
     * unextended path. Actions stay a no-op because nothing here asserts on a
     * side effect.
     */
    function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['wp_filter'] ??= [];
        $GLOBALS['wp_filter'][$hook][$priority][] = ['callback' => $callback, 'args' => $accepted_args];

        return true;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Run the recorded callbacks for a hook, lowest priority first.
     *
     * @param mixed ...$args
     */
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $registered = $GLOBALS['wp_filter'][$hook] ?? [];
        if ($registered === []) {
            return $value;
        }

        ksort($registered);
        foreach ($registered as $callbacks) {
            foreach ($callbacks as $entry) {
                $accepted = (int) $entry['args'];
                $value = $accepted <= 1
                    ? $entry['callback']($value)
                    : $entry['callback']($value, ...array_slice($args, offset: 0, length: $accepted - 1));
            }
        }

        return $value;
    }
}

if (!function_exists('remove_all_filters')) {
    function remove_all_filters(string $hook): bool
    {
        unset($GLOBALS['wp_filter'][$hook]);

        return true;
    }
}

if (!class_exists('WP_Ability')) {
    /**
     * Stand-in for the Abilities API's ability object.
     *
     * GoBuildWP's risk classification, confirmation gate, and MCP tool listing all
     * read an ability rather than a plain array, so exercising them without a
     * WordPress install needs a real object with those accessors. Only the ones
     * that decision logic reads are modelled — execute() and the permission
     * callback run WordPress and belong in an integration suite.
     */
    class WP_Ability
    {
        /**
         * @param array<string, mixed> $meta
         * @param array<string, mixed> $input_schema
         * @param array<string, mixed> $output_schema
         */
        public function __construct(
            private string $name,
            private array $meta = [],
            private array $input_schema = [],
            private string $category = '',
            private string $label = '',
            private string $description = '',
            private array $output_schema = [],
        ) {
        }

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_label(): string
        {
            return $this->label;
        }

        public function get_description(): string
        {
            return $this->description;
        }

        public function get_category(): string
        {
            return $this->category;
        }

        /** @return array<string, mixed> */
        public function get_meta(): array
        {
            return $this->meta;
        }

        /** @return array<string, mixed> */
        public function get_input_schema(): array
        {
            return $this->input_schema;
        }

        /** @return array<string, mixed> */
        public function get_output_schema(): array
        {
            return $this->output_schema;
        }
    }
}


/**
 * The WordPress 7.1 ability registry, reduced to the one method GoBuildWP reads.
 *
 * The point of the real class here is that it answers with everything registered, ahead of the
 * discovery filters `wp_get_abilities()` runs, so the double is seeded by hand: a test that
 * cares about the difference wants to say what the registry holds and what discovery reports
 * without the two being the same list by construction.
 */
if (!class_exists('WP_Abilities_Registry')) {
    final class WP_Abilities_Registry
    {
        /** @var array<string, WP_Ability> */
        private array $abilities = [];

        private static ?self $instance = null;

        public static function get_instance(): self
        {
            return self::$instance ??= new self();
        }

        /** @param array<string, WP_Ability> $abilities */
        public static function seed_for_tests(array $abilities): void
        {
            self::get_instance()->abilities = $abilities;
        }

        /** @return array<string, WP_Ability> */
        public function get_all_registered(): array
        {
            return $this->abilities;
        }
    }
}

/**
 * WordPress 7.1's client-facing schema translation, in the two transformations GoBuildWP relies
 * on it for: a property-level `required: true` is hoisted into the parent's `required` array,
 * and the PHP-only keys that no JSON Schema validator understands are dropped.
 */
if (!function_exists('wp_prepare_json_schema_for_client')) {
    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    function wp_prepare_json_schema_for_client(array $schema, string $schema_profile = 'draft-04'): array
    {
        unset($schema['sanitize_callback'], $schema['validate_callback'], $schema['arg_options']);

        /** @var mixed $properties */
        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            /** @var mixed $child */
            foreach ($properties as $name => $child) {
                if (!is_array($child)) {
                    continue;
                }
                if (($child['required'] ?? null) === true) {
                    unset($child['required']);
                    if (!in_array($name, $required, true)) {
                        $required[] = $name;
                    }
                }
                $properties[$name] = wp_prepare_json_schema_for_client($child, $schema_profile);
            }
            $schema['properties'] = $properties;
            if ($required !== []) {
                $schema['required'] = $required;
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = wp_prepare_json_schema_for_client($schema['items'], $schema_profile);
        }

        return $schema;
    }
}

if (!function_exists('get_option')) {
    /** @return mixed */
    function get_option(string $option, mixed $default_value = false): mixed
    {
        return array_key_exists($option, WPPilot_Test_State::$options)
            ? WPPilot_Test_State::$options[$option]
            : $default_value;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $option, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
    {
        if (array_key_exists($option, WPPilot_Test_State::$options)) {
            return false;
        }

        WPPilot_Test_State::$options[$option] = $value;
        $GLOBALS['wppilot_test_option_autoload'][$option] = $autoload;

        return true;
    }
}

if (!function_exists('update_option')) {
    /**
     * Reproduces the early return in WordPress core, quirk included.
     *
     * `get_option()` answers false for an option that does not exist, and the
     * equality check below runs BEFORE the "does not exist" branch. So writing
     * boolean false to an option nobody has ever set compares equal, returns
     * early, and never reaches add_option() — nothing is stored at all.
     *
     * A double that simply assigned would hide that, and hiding it is how the
     * bug this models shipped in the first place.
     */
    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        $old_value = get_option($option);

        if ($value === $old_value || serialize($value) === serialize($old_value)) {
            return false;
        }

        if ($old_value === false && !array_key_exists($option, WPPilot_Test_State::$options)) {
            return add_option($option, $value, '', $autoload);
        }

        WPPilot_Test_State::$options[$option] = $value;
        if ($autoload !== null) {
            $GLOBALS['wppilot_test_option_autoload'][$option] = $autoload;
        }

        return true;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        if (!array_key_exists($option, WPPilot_Test_State::$options)) {
            return false;
        }
        unset(WPPilot_Test_State::$options[$option]);
        return true;
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'wppilot-test-salt-' . $scheme;
    }
}

if (!function_exists('wp_slash')) {
    function wp_slash(mixed $value): mixed
    {
        return is_string($value) ? addslashes($value) : $value;
    }
}

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $class): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $class) ?? '';
    }
}

if (!function_exists('wp_get_nav_menu_object')) {
    function wp_get_nav_menu_object(int $menu_id): object|false
    {
        return in_array($menu_id, WPPilot_Test_State::$nav_menus, true)
            ? (object) ['term_id' => $menu_id, 'name' => 'Menu ' . $menu_id]
            : false;
    }
}

if (!function_exists('is_object_in_term')) {
    function is_object_in_term(int $object_id, string $taxonomy, int $term_id): bool|WP_Error
    {
        return $taxonomy === 'nav_menu'
            && in_array($term_id, WPPilot_Test_State::$nav_menu_memberships[$object_id] ?? [], true);
    }
}

if (!function_exists('wp_update_nav_menu_item')) {
    /** @param array<string, mixed> $args */
    function wp_update_nav_menu_item(int $menu_id, int $item_id, array $args): int|WP_Error
    {
        WPPilot_Test_State::$nav_menu_updates[] = compact('menu_id', 'item_id', 'args');
        return $item_id > 0 ? $item_id : 999;
    }
}

if (!function_exists('wp_setup_nav_menu_item')) {
    function wp_setup_nav_menu_item(WP_Post $item): object
    {
        $item->title = $item->post_title;
        $item->url = (string) get_post_meta($item->ID, '_menu_item_url', true);
        $item->type = (string) get_post_meta($item->ID, '_menu_item_type', true);
        $item->object = (string) get_post_meta($item->ID, '_menu_item_object', true);
        $item->object_id = (int) get_post_meta($item->ID, '_menu_item_object_id', true);
        $item->menu_item_parent = (int) get_post_meta($item->ID, '_menu_item_menu_item_parent', true);
        $item->target = (string) get_post_meta($item->ID, '_menu_item_target', true);
        $classes = get_post_meta($item->ID, '_menu_item_classes', true);
        $item->classes = is_array($classes) ? $classes : [];
        return $item;
    }
}

if (!function_exists('wp_next_scheduled')) {
    /** @return int|false */
    function wp_next_scheduled(string $hook, array $args = [])
    {
        return WPPilot_Test_State::$cron[$hook] ?? false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        WPPilot_Test_State::$cron[$hook] = $timestamp;

        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): int
    {
        $existed = isset(WPPilot_Test_State::$cron[$hook]);
        unset(WPPilot_Test_State::$cron[$hook]);

        return $existed ? 1 : 0;
    }
}

if (!function_exists('wp_remote_post')) {
    /** @param array<string, mixed> $args */
    function wp_remote_post(string $url, array $args = []): array|WP_Error
    {
        WPPilot_Test_State::$http_posts[] = ['url' => $url, 'body' => $args['body'] ?? null, 'args' => $args];

        // A test that needs a particular answer queues it; everything else gets a 204.
        if (($GLOBALS['wppilot_test_http_responses'] ?? []) !== []) {
            return array_shift($GLOBALS['wppilot_test_http_responses']);
        }

        return ['response' => ['code' => 204], 'body' => ''];
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        return $show === 'version' ? '7.0' : '';
    }
}

if (!function_exists('get_locale')) {
    function get_locale(): string
    {
        return 'en_US';
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';

        return trim($title, '-');
    }
}
