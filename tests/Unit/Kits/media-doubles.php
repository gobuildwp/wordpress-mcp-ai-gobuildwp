<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * WordPress media and HTTP doubles for the media-edit and a11y-audit kit tests.
 *
 * Posts, meta, capabilities and ability registrations are Kit_Test_Site's (tests/doubles/
 * kit-site.php, which a receiving plugin's harness replaces with its own): meta is stored raw and
 * read back unserialized, as WordPress does, and this file defines none of those functions again.
 * What is here is only what media work adds: attachment files and metadata, the image editor,
 * uploads paths and the page fetch. Files are real files in a temporary uploads directory, so
 * "the original is still on disk" is tested against a disk.
 */

if (!class_exists('Kit_Test_Site')) {
    require_once dirname(__DIR__, 2) . '/doubles/kit-site.php';
}

final class Kit_Media_Test_State
{
    public static string $basedir = '';

    /** @var (callable(string): (object|WP_Error))|null */
    public static $editor_factory = null;

    public static bool $editor_supports = true;

    /** @var list<array<string, mixed>> */
    public static array $inserted = [];

    /** @var array<string, int> */
    public static array $attachment_counts = [];

    /** @var array<string, array{width: int, height: int, crop: bool}> */
    public static array $subsizes = ['thumbnail' => ['width' => 150, 'height' => 150, 'crop' => true]];

    /** @var array<int, string> */
    public static array $post_status = [];

    /** @var array<string, array{code: int, body: string}|WP_Error> */
    public static array $http = [];

    public static function reset(): void
    {
        self::$basedir = sys_get_temp_dir() . '/kit-media-' . bin2hex(random_bytes(5));
        mkdir(self::$basedir . '/2026/09', recursive: true);
        self::$editor_factory = null;
        self::$editor_supports = true;
        self::$inserted = [];
        self::$attachment_counts = [];
        self::$subsizes = ['thumbnail' => ['width' => 150, 'height' => 150, 'crop' => true]];
        self::$post_status = [];
        self::$http = [];
        unset($GLOBALS['wp_filter']['image_resize_dimensions']);
    }

    public static function cleanup(): void
    {
        if (self::$basedir === '' || !is_dir(self::$basedir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$basedir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(self::$basedir);
    }

    /**
     * An image attachment with a real file on disk.
     *
     * @param array<string, mixed> $meta
     */
    public static function add_image(int $id, string $relative, int $width, int $height, string $mime = 'image/jpeg', array $meta = []): WP_Post
    {
        Kit_Test_Site::insert(['ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Photo ' . $id, 'post_mime_type' => $mime]);
        $path = self::$basedir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }
        file_put_contents($path, str_repeat('x', 64));
        Kit_Test_Site::set_meta($id, '_wp_attached_file', $relative);
        Kit_Test_Site::set_meta($id, '_wp_attachment_metadata', array_merge([
            'width' => $width,
            'height' => $height,
            'file' => $relative,
            'filesize' => 64,
            'sizes' => [],
        ], $meta));
        $post = get_post($id);
        assert($post instanceof WP_Post);
        return $post;
    }
}

/**
 * A stand-in for WP_Image_Editor that tracks size through each operation and writes a real file
 * on save, sized so the preview byte cap can be exercised.
 */
final class Kit_Fake_Image_Editor
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    /** @var list<string> */
    public static array $saved_paths = [];

    public int $quality = 90;

    public ?string $force_output_mime = null;

    /** Bytes written per pixel at quality 100; scaled by quality. */
    public float $byte_factor = 0.01;

    public function __construct(public string $file, public int $width, public int $height)
    {
    }

    /** @return array{width: int, height: int} */
    public function get_size(): array
    {
        return ['width' => $this->width, 'height' => $this->height];
    }

    public function resize(int $max_w, int $max_h, bool $crop = false): bool|WP_Error
    {
        $this->calls[] = ['resize', $max_w, $max_h, $crop];
        $dims = apply_filters('image_resize_dimensions', null, $this->width, $this->height, $max_w, $max_h, $crop);
        if (is_array($dims)) {
            [$this->width, $this->height] = [(int) $dims[4], (int) $dims[5]];
            return true;
        }
        // Core refuses to enlarge.
        if (($max_w <= 0 || $max_w >= $this->width) && ($max_h <= 0 || $max_h >= $this->height)) {
            return new WP_Error('error_getting_dimensions', 'Could not calculate resized image dimensions');
        }
        $scale = min($max_w > 0 ? $max_w / $this->width : INF, $max_h > 0 ? $max_h / $this->height : INF);
        $this->width = max(1, (int) round($this->width * $scale));
        $this->height = max(1, (int) round($this->height * $scale));
        return true;
    }

    public function crop(int $x, int $y, int $w, int $h): bool
    {
        $this->calls[] = ['crop', $x, $y, $w, $h];
        [$this->width, $this->height] = [$w, $h];
        return true;
    }

    public function rotate(int $angle): bool
    {
        $this->calls[] = ['rotate', $angle];
        if ($angle % 180 !== 0) {
            [$this->width, $this->height] = [$this->height, $this->width];
        }
        return true;
    }

    public function flip(bool $horz, bool $vert): bool
    {
        $this->calls[] = ['flip', $horz, $vert];
        return true;
    }

    public function set_quality(int $quality): bool
    {
        $this->quality = $quality;
        return true;
    }

    /** @return array<string, mixed> */
    public function save(string $destfilename, ?string $mime_type = null): array
    {
        $mime = $this->force_output_mime ?? $mime_type ?? 'image/jpeg';
        $path = $destfilename;
        if ($this->force_output_mime !== null) {
            $path = preg_replace('/\.[a-z]+$/', '', $destfilename) . '.' . substr($mime, 6);
        }
        $bytes = max(1, (int) ($this->width * $this->height * $this->byte_factor * $this->quality / 100));
        file_put_contents($path, str_repeat('i', $bytes));
        self::$saved_paths[] = $path;
        $this->calls[] = ['save', $path, $mime];
        $this->file = $path;
        return ['path' => $path, 'file' => basename($path), 'width' => $this->width, 'height' => $this->height, 'mime-type' => $mime, 'filesize' => $bytes];
    }

    /**
     * @param array<string, array{width: int, height: int, crop: bool}> $sizes
     * @return array<string, array<string, mixed>>
     */
    public function multi_resize(array $sizes): array
    {
        $made = [];
        foreach ($sizes as $name => $size) {
            $made[$name] = ['file' => pathinfo($this->file, PATHINFO_FILENAME) . "-{$size['width']}x{$size['height']}.jpg", 'width' => $size['width'], 'height' => $size['height'], 'mime-type' => 'image/jpeg'];
        }
        return $made;
    }
}

if (!function_exists('get_post_mime_type')) {
    function get_post_mime_type(mixed $post = null): string|false
    {
        $post = $post instanceof WP_Post ? $post : get_post((int) $post);
        return $post instanceof WP_Post ? $post->post_mime_type : false;
    }
}

if (!function_exists('wp_attachment_is_image')) {
    function wp_attachment_is_image(mixed $post = null): bool
    {
        return str_starts_with((string) get_post_mime_type($post), 'image/');
    }
}

if (!function_exists('wp_get_upload_dir')) {
    /** @return array<string, mixed> */
    function wp_get_upload_dir(): array
    {
        return ['basedir' => Kit_Media_Test_State::$basedir, 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => false];
    }
}

if (!function_exists('path_is_absolute')) {
    function path_is_absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $path) === 1;
    }
}

if (!function_exists('get_attached_file')) {
    function get_attached_file(int $attachment_id, bool $unfiltered = false): string|false
    {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (!is_string($file) || $file === '') {
            return false;
        }
        return path_is_absolute($file) ? $file : Kit_Media_Test_State::$basedir . '/' . $file;
    }
}

if (!function_exists('_wp_relative_upload_path')) {
    function _wp_relative_upload_path(string $path): string
    {
        $base = Kit_Media_Test_State::$basedir . '/';
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}

if (!function_exists('update_attached_file')) {
    function update_attached_file(int $attachment_id, string $file): bool
    {
        return update_post_meta($attachment_id, '_wp_attached_file', _wp_relative_upload_path($file));
    }
}

if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata(int $attachment_id = 0, bool $unfiltered = false): mixed
    {
        $meta = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
        return is_array($meta) ? $meta : false;
    }
}

if (!function_exists('wp_update_attachment_metadata')) {
    /** @param array<string, mixed> $data */
    function wp_update_attachment_metadata(int $attachment_id, array $data): bool
    {
        return (bool) update_post_meta($attachment_id, '_wp_attachment_metadata', $data);
    }
}

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachment_id = 0): string|false
    {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        return is_string($file) && $file !== '' ? 'https://example.test/wp-content/uploads/' . $file : false;
    }
}

if (!function_exists('wp_image_editor_supports')) {
    /** @param array<string, mixed> $args */
    function wp_image_editor_supports(array $args = []): bool
    {
        return Kit_Media_Test_State::$editor_supports;
    }
}

if (!function_exists('wp_get_image_editor')) {
    /** @param array<string, mixed> $args */
    function wp_get_image_editor(string $path, array $args = []): object
    {
        if (Kit_Media_Test_State::$editor_factory !== null) {
            return (Kit_Media_Test_State::$editor_factory)($path);
        }
        return new WP_Error('image_no_editor', 'No editor could be selected.');
    }
}

if (!function_exists('wp_unique_filename')) {
    function wp_unique_filename(string $dir, string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $candidate = $filename;
        for ($i = 1; file_exists($dir . '/' . $candidate); $i++) {
            $candidate = "{$name}-{$i}.{$ext}";
        }
        return $candidate;
    }
}

if (!function_exists('wp_insert_attachment')) {
    /** @param array<string, mixed> $args */
    function wp_insert_attachment(array $args, string|false $file = false, int $parent_post_id = 0, bool $wp_error = false): int|WP_Error
    {
        $id = Kit_Test_Site::insert([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => stripslashes((string) ($args['post_title'] ?? '')),
            'post_parent' => $parent_post_id,
            'post_mime_type' => (string) ($args['post_mime_type'] ?? ''),
        ]);
        if (is_string($file)) {
            update_attached_file($id, $file);
        }
        Kit_Media_Test_State::$inserted[] = ['id' => $id, 'args' => $args, 'file' => $file, 'parent' => $parent_post_id];
        return $id;
    }
}

if (!function_exists('wp_generate_attachment_metadata')) {
    /** @return array<string, mixed> */
    function wp_generate_attachment_metadata(int $attachment_id, string $file): array
    {
        return ['width' => 1, 'height' => 1, 'file' => _wp_relative_upload_path($file), 'sizes' => []];
    }
}

if (!function_exists('wp_delete_attachment')) {
    function wp_delete_attachment(int $post_id, bool $force_delete = false): mixed
    {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return false;
        }
        $path = get_attached_file($post_id);
        if (is_string($path) && file_exists($path)) {
            unlink($path);
        }
        Kit_Test_Site::remove($post_id);
        return $post;
    }
}

if (!function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): bool
    {
        return file_exists($file) && unlink($file);
    }
}

if (!function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = 0): int
    {
        return $min;
    }
}

if (!function_exists('wp_get_registered_image_subsizes')) {
    /** @return array<string, array{width: int, height: int, crop: bool}> */
    function wp_get_registered_image_subsizes(): array
    {
        return Kit_Media_Test_State::$subsizes;
    }
}

if (!function_exists('wp_basename')) {
    function wp_basename(string $path, string $suffix = ''): string
    {
        return basename(str_replace('\\', '/', $path), $suffix);
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, mixed $callback, int $priority = 10): bool
    {
        foreach ($GLOBALS['wp_filter'][$hook][$priority] ?? [] as $index => $entry) {
            if ($entry['callback'] === $callback) {
                unset($GLOBALS['wp_filter'][$hook][$priority][$index]);
                if ($GLOBALS['wp_filter'][$hook][$priority] === []) {
                    unset($GLOBALS['wp_filter'][$hook][$priority]);
                }
                if ($GLOBALS['wp_filter'][$hook] === []) {
                    unset($GLOBALS['wp_filter'][$hook]);
                }
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('get_temp_dir')) {
    function get_temp_dir(): string
    {
        return rtrim(Kit_Media_Test_State::$basedir !== '' ? Kit_Media_Test_State::$basedir : sys_get_temp_dir(), '/\\') . '/';
    }
}

if (!function_exists('wp_count_attachments')) {
    function wp_count_attachments(string|array $mime_type = ''): object
    {
        return (object) Kit_Media_Test_State::$attachment_counts;
    }
}

if (!function_exists('get_post_status')) {
    function get_post_status(mixed $post = null): string|false
    {
        $id = $post instanceof WP_Post ? $post->ID : (int) $post;
        $found = get_post($id);
        return Kit_Media_Test_State::$post_status[$id] ?? ($found instanceof WP_Post ? $found->post_status : false);
    }
}

if (!function_exists('is_post_type_viewable')) {
    function is_post_type_viewable(mixed $post_type): bool
    {
        return in_array($post_type, ['post', 'page'], true);
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(mixed $post = 0): string|false
    {
        $id = $post instanceof WP_Post ? $post->ID : (int) $post;
        return get_post($id) instanceof WP_Post ? 'https://example.test/?p=' . $id : false;
    }
}

if (!function_exists('get_preview_post_link')) {
    function get_preview_post_link(mixed $post = null): ?string
    {
        $id = $post instanceof WP_Post ? $post->ID : (int) $post;
        return get_post($id) instanceof WP_Post ? 'https://example.test/?page_id=' . $id . '&preview=true' : null;
    }
}

if (!function_exists('wp_http_validate_url')) {
    function wp_http_validate_url(string $url): string|false
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : false;
    }
}

if (!function_exists('wp_safe_remote_get')) {
    /** @param array<string, mixed> $args */
    function wp_safe_remote_get(string $url, array $args = []): array|WP_Error
    {
        $response = Kit_Media_Test_State::$http[$url] ?? new WP_Error('http_request_failed', 'No double for ' . $url);
        return $response instanceof WP_Error ? $response : ['response' => ['code' => $response['code']], 'body' => $response['body']];
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(mixed $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(mixed $response): int|string
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
    }
}

