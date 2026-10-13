<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * WPForms, Contact Form 7 with Flamingo, Gravity Forms and Forminator stubbed down to the calls
 * the kit makes, the host the kit runs under, and the WordPress post functions it calls, defined
 * in the kit's own namespace so the rest of the suite is untouched. The vendor classes and
 * functions are global because the kit detects them with class_exists()/function_exists(); each is
 * guarded.
 */

namespace WPPilot\Tests\Unit\Kits\FormsBasics {
    use WP_Error;
    use WPPilot\Kits\Runtime\Host;
    use WPPilot\Kits\Runtime\Jobs;
    use WPPilot\Kits\Runtime\Ledger;

    final class Registrations
    {
        /** @var array<string, array<string, mixed>> */
        public static array $args = [];
    }

    final class Caps
    {
        /** @var list<string> */
        public static array $granted = [];
    }

    class FormPost extends \WP_Post
    {
        public string $post_date = '';

        public string $post_date_gmt = '';
    }

    final class Store
    {
        /** @var array<int, FormPost> */
        public static array $posts = [];

        /** @var array<int, array<string, mixed>> */
        public static array $meta = [];

        /** @var array<string, object> slug => term */
        public static array $terms = [];

        /** @var list<array<string, mixed>> */
        public static array $queries = [];

        /** @var list<array{0: string, 1: list<mixed>}> */
        public static array $sql = [];

        /** @var list<object> */
        public static array $sqlRows = [];

        public static bool $wpformsPro = true;

        /** @var list<string> */
        public static array $wpformsCaps = ['wpforms_view_entries'];

        public static function reset(): void
        {
            self::$posts = [];
            self::$meta = [];
            self::$terms = [];
            self::$queries = [];
            self::$sql = [];
            self::$sqlRows = [];
            self::$wpformsPro = true;
            self::$wpformsCaps = ['wpforms_view_entries'];
        }

        public static function post(int $id, string $type, string $title, string $status = 'publish', string $name = ''): FormPost
        {
            $post = new FormPost();
            $post->ID = $id;
            $post->post_type = $type;
            $post->post_title = $title;
            $post->post_status = $status;
            $post->post_name = $name !== '' ? $name : strtolower(str_replace(' ', '-', $title));
            $post->post_date = '2026-09-2' . ($id % 10) . ' 10:00:00';
            $post->post_date_gmt = $post->post_date;
            $post->post_modified_gmt = $post->post_date;
            return self::$posts[$id] = $post;
        }
    }

    final class FakeWpdb
    {
        public string $prefix = 'wp_';

        /** @param list<mixed> $args */
        public function prepare(string $query, array $args): string
        {
            Store::$sql[] = [$query, $args];
            return vsprintf($query, $args);
        }

        /** @return list<object> */
        public function get_results(string $sql): array
        {
            return Store::$sqlRows;
        }
    }

    final class FakeEntryHandler
    {
        /** @var list<object> */
        public array $rows = [];

        /** @var list<array<string, mixed>> */
        public array $calls = [];

        /**
         * @param array<string, mixed> $args
         * @return list<object>|int
         */
        public function get_entries(array $args = [], bool $count = false): array|int
        {
            $this->calls[] = $args;
            $rows = array_values(array_filter($this->rows, static fn(object $r): bool => (int) $r->form_id === (int) $args['form_id']));
            return $count ? count($rows) : array_slice($rows, (int) ($args['offset'] ?? 0), (int) ($args['number'] ?? 20));
        }
    }

    final class ReadHost implements Host
    {
        public function id(): string
        {
            return 'test';
        }

        public function can_manage(): bool
        {
            return true;
        }

        public function is_enabled(): bool
        {
            return true;
        }

        public function safety_profile(): string
        {
            return 'production';
        }

        public function ledger(): Ledger
        {
            throw new \LogicException('A read-only kit never records a change.');
        }

        public function jobs(): Jobs
        {
            throw new \LogicException('not used');
        }

        public function extension(string $point): mixed
        {
            return null;
        }

        public function admin_parent_slug(): string
        {
            return 'tools.php';
        }

        public function confirm_guard(string $ability_name, array $input): bool|WP_Error
        {
            return true;
        }
    }
}

namespace {
    use WPPilot\Tests\Unit\Kits\FormsBasics\FakeEntryHandler;
    use WPPilot\Tests\Unit\Kits\FormsBasics\Store;

    foreach (['WPFORMS_VERSION' => '1.9.2', 'WPCF7_VERSION' => '6.0.1', 'FLAMINGO_VERSION' => '2.6.4'] as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
    if (!defined('WPFORMS_PLUGIN_DIR')) {
        // WPForms Pro is told apart by pro/wpforms-pro.php under its plugin folder; the test adds
        // and removes that file to switch between Lite and Pro.
        define('WPFORMS_PLUGIN_DIR', sys_get_temp_dir() . '/kit-forms-basics-wpforms-' . getmypid() . '/');
    }

    if (!class_exists('WP_Query')) {
        class WP_Query
        {
            /** @var list<\WP_Post> */
            public array $posts = [];

            public int $found_posts = 0;

            /** @param array<string, mixed> $args */
            public function __construct(array $args)
            {
                Store::$queries[] = $args;
                $statuses = (array) $args['post_status'];
                $matched = array_values(array_filter(Store::$posts, static fn(\WP_Post $p): bool => $p->post_type === $args['post_type'] && in_array($p->post_status, $statuses, true)));
                $this->found_posts = count($matched);
                $this->posts = array_slice($matched, (int) $args['offset'], (int) $args['posts_per_page']);
            }
        }
    }

    if (!function_exists('wpforms')) {
        function wpforms(): object
        {
            static $wpforms = null;
            return $wpforms ??= new class () {
                public FakeEntryHandler $entry;

                public function __construct()
                {
                    $this->entry = new FakeEntryHandler();
                }

                public function obj(string $name): ?object
                {
                    return $name === 'entry' ? $this->entry : null;
                }
            };
        }
    }

    if (!function_exists('wpforms_current_user_can')) {
        function wpforms_current_user_can(string $capability, int $id = 0): bool
        {
            return in_array($capability, Store::$wpformsCaps, true);
        }
    }

    if (!class_exists('WPCF7_ContactForm')) {
        final class WPCF7_ContactForm
        {
            /** @var array<int, array<string, mixed>> id => template, locale, tags */
            public static array $forms = [];

            private function __construct(private int $id)
            {
            }

            public static function get_instance(mixed $post): ?self
            {
                $id = $post instanceof \WP_Post ? $post->ID : (int) $post;
                return isset(self::$forms[$id]) ? new self($id) : null;
            }

            public function id(): int
            {
                return $this->id;
            }

            public function title(): string
            {
                return Store::$posts[$this->id]->post_title;
            }

            public function name(): string
            {
                return Store::$posts[$this->id]->post_name;
            }

            public function locale(): string
            {
                return (string) self::$forms[$this->id]['locale'];
            }

            public function hash(): string
            {
                return substr(md5((string) $this->id), 0, 7);
            }

            public function prop(string $name): mixed
            {
                return $name === 'form' ? self::$forms[$this->id]['template'] : null;
            }

            /** @return list<object> */
            public function scan_form_tags(): array
            {
                return array_map(static fn(array $t): object => (object) ['name' => $t[0], 'basetype' => $t[1]], self::$forms[$this->id]['tags']);
            }
        }
    }

    if (!class_exists('Flamingo_Inbound_Message')) {
        final class Flamingo_Inbound_Message
        {
            /** @var list<Flamingo_Inbound_Message> */
            public static array $messages = [];

            /** @var list<array<string, mixed>> */
            public static array $finds = [];

            private static int $found = 0;

            public int $channel = 0;

            public string $subject = '';

            /** @var array<string, mixed> */
            public array $fields = [];

            /** @var array<string, mixed> */
            public array $meta = [];

            public function __construct(private int $post_id = 0)
            {
            }

            public function id(): int
            {
                return $this->post_id;
            }

            /**
             * @param array<string, mixed> $args
             * @return list<Flamingo_Inbound_Message>
             */
            public static function find(array $args = []): array
            {
                self::$finds[] = $args;
                $rows = array_values(array_filter(self::$messages, static fn(self $m): bool => $m->channel === (int) $args['channel_id']));
                self::$found = count($rows);
                return array_slice($rows, (int) ($args['offset'] ?? 0), (int) ($args['posts_per_page'] ?? 10));
            }

            public static function count(): int
            {
                return self::$found;
            }
        }
    }

    if (!class_exists('GFForms')) {
        final class GFForms
        {
            public static string $version = '2.9.1';
        }
    }

    if (!class_exists('GFFormsModel')) {
        final class GFFormsModel
        {
            public static function get_entry_table_name(): string
            {
                return 'wp_gf_entry';
            }
        }
    }

    if (!class_exists('GFAPI')) {
        final class GFAPI
        {
            /** @var array<int, array<string, mixed>> */
            public static array $forms = [];

            /** @var list<array<string, mixed>> */
            public static array $entries = [];

            /** @var list<array<string, mixed>> */
            public static array $calls = [];

            public static bool $canView = true;

            /** @return list<array<string, mixed>> */
            public static function get_forms(bool $active = true, bool $trash = false): array
            {
                return array_values(array_filter(self::$forms, static fn(array $f): bool => (bool) $f['is_active'] === $active && (bool) $f['is_trash'] === $trash));
            }

            /** @return array<string, mixed>|false */
            public static function get_form(int $id): array|false
            {
                return self::$forms[$id] ?? false;
            }

            /**
             * @param array<string, mixed> $criteria
             * @param array<string, string> $sorting
             * @param array<string, int> $paging
             * @return list<array<string, mixed>>
             */
            public static function get_entries(int $form_id, array $criteria, array $sorting, array $paging, ?int &$total = null): array
            {
                self::$calls[] = ['form_id' => $form_id, 'criteria' => $criteria, 'sorting' => $sorting, 'paging' => $paging];
                $rows = array_values(array_filter(self::$entries, static fn(array $e): bool => (int) $e['form_id'] === $form_id));
                $total = count($rows);
                return array_slice($rows, $paging['offset'], $paging['page_size']);
            }

            /** @param list<string> $caps */
            public static function current_user_can_any(array $caps): bool
            {
                return self::$canView;
            }
        }
    }

    if (!function_exists('forminator_get_admin_cap')) {
        function forminator_get_admin_cap(): string
        {
            return 'manage_forminator';
        }
    }

    if (!class_exists('Forminator_API')) {
        final class Forminator_API
        {
        }
    }

    if (!class_exists('Forminator_Form_Field_Model')) {
        final class Forminator_Form_Field_Model
        {
            /** @param array<string, mixed> $raw */
            public function __construct(public string $slug, public array $raw)
            {
            }

            /** @return array<string, mixed> */
            public function to_array(): array
            {
                return array_merge(['id' => $this->slug], $this->raw);
            }
        }
    }

    if (!class_exists('Forminator_Base_Form_Model')) {
        class Forminator_Base_Form_Model
        {
            /** @var array<int, array<string, mixed>> */
            public static array $forms = [];

            public int $id = 0;

            public string $name = '';

            public string $status = 'publish';

            /** @var list<Forminator_Form_Field_Model> */
            public array $fields = [];

            /** @var array<string, mixed> */
            public array $settings = [];

            public static function get_model(int $id): self|false
            {
                $stored = self::$forms[$id] ?? null;
                if ($stored === null) {
                    return false;
                }
                $model = new Forminator_Form_Model();
                $model->id = $id;
                $model->name = $stored['name'];
                $model->status = $stored['status'];
                $model->settings = $stored['settings'];
                foreach ($stored['fields'] as $slug => $raw) {
                    $model->fields[] = new Forminator_Form_Field_Model($slug, $raw);
                }
                return $model;
            }
        }
    }

    if (!class_exists('Forminator_Form_Model')) {
        final class Forminator_Form_Model extends Forminator_Base_Form_Model
        {
            public static function model(): object
            {
                return new class () {
                    /** @return array<string, mixed> */
                    public function get_all_paged(int $page, int $per_page, string $status): array
                    {
                        $models = [];
                        foreach (array_keys(Forminator_Base_Form_Model::$forms) as $id) {
                            $models[] = Forminator_Base_Form_Model::get_model($id);
                        }
                        return ['models' => $models, 'foundPosts' => count($models)];
                    }
                };
            }
        }
    }

    if (!class_exists('Forminator_Form_Entry_Model')) {
        final class Forminator_Form_Entry_Model
        {
            /** @var array<string, mixed> */
            public static array $last_args = [];

            /** @var list<Forminator_Form_Entry_Model> */
            public static array $entries = [];

            public int $entry_id = 0;

            public string $date_created_sql = '';

            public string $status = 'active';

            /** @var array<string, mixed> */
            public array $meta_data = [];

            public static function count_entries(int $form_id): int
            {
                return count(self::$entries);
            }

            /**
             * @param array<string, mixed> $args
             * @return array<string, mixed>
             */
            public static function query_entries(array $args, bool $get_count = false): array
            {
                self::$last_args = $args;
                return ['count' => count(self::$entries), 'data' => array_slice(self::$entries, (int) $args['offset'], (int) $args['per_page'])];
            }
        }
    }
}

namespace WPPilot\Kits\FormsBasics {
    use WPPilot\Tests\Unit\Kits\FormsBasics\Registrations;
    use WPPilot\Tests\Unit\Kits\FormsBasics\Store;

    if (!function_exists(__NAMESPACE__ . '\\get_post')) {
        function get_post(mixed $post): ?\WP_Post
        {
            return Store::$posts[$post instanceof \WP_Post ? $post->ID : (int) $post] ?? null;
        }

        /**
         * @param array<string, mixed> $args
         * @return list<\WP_Post>
         */
        function get_posts(array $args): array
        {
            Store::$queries[] = $args;
            $statuses = (array) $args['post_status'];
            return array_values(array_filter(Store::$posts, static fn(\WP_Post $p): bool => $p->post_type === $args['post_type'] && in_array($p->post_status, $statuses, true)));
        }

        function get_post_type(int $id): string|false
        {
            return isset(\Forminator_Base_Form_Model::$forms[$id]) ? 'forminator_forms' : (isset(Store::$posts[$id]) ? Store::$posts[$id]->post_type : false);
        }

        function get_post_meta(int $id, string $key = '', bool $single = false): mixed
        {
            return Store::$meta[$id][$key] ?? '';
        }

        function get_term_by(string $field, string $value, string $taxonomy): object|false
        {
            return Store::$terms[$value] ?? false;
        }

        function current_user_can(string $capability): bool
        {
            return in_array($capability, \WPPilot\Tests\Unit\Kits\FormsBasics\Caps::$granted, true);
        }

        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            Registrations::$args[$name] = $args;
            // Kept here only: the suite's shared registry stays as it was, so the names stay
            // unclaimed for the next test. The stand-aside test claims them there itself.
            return null;
        }
    }
}
