# image-optimize kit

Compresses and resizes JPEG/PNG originals and writes WebP/AVIF copies with WordPress's own image
editor (GD or Imagick). Nothing is sent to an outside service.

| Ability | Kind | What it does |
|---|---|---|
| `gobuildwp/image-optimize-status` | read | Server kind and why rewrite rules are not used, editor format support, serving mode, image counts, bulk cursor. |
| `gobuildwp/image-optimize-estimate` | read | Dry run: plans up to 500 images from metadata and really encodes up to 5 in the temp folder (then deletes them) to measure the saving. |
| `gobuildwp/image-optimize-run` | destructive | Resizes past `max_dimension`, recompresses at `quality` (kept only when smaller or resized), keeps the old original as `<name>.optimize-backup.<ext>`, writes `<file>.webp`/`<file>.avif` copies of every size (kept only when smaller). Batches of up to 25 with a stored cursor and a 20 s time budget. Needs `confirm: true`. |
| `gobuildwp/image-optimize-restore` | destructive | Moves the kept original back, restores width/height/filesize, deletes the copies. Needs `confirm: true`. |
| `gobuildwp/image-optimize-serving` | write | `picture` wraps front-end `<img>` of optimised attachments in `<picture>` with AVIF/WebP `<source>`; `off` stops. |

## Serving

Markup only, through `the_content` (after core adds srcset) and `wp_get_attachment_image`.
Skipped in wp-admin, feeds, AJAX, REST/JSON and AMP. An image is wrapped only when the src and
every srcset candidate have a copy in that format. The kit never writes `.htaccess` or server
configuration: OpenLiteSpeed ignores `.htaccess` unless told to read it and nginx never reads it,
so a rule-based setup can report success and serve nothing.

## Undo

| Ability | Strategy | What undo does |
|---|---|---|
| `image-optimize-run` | `image-optimize/original` (one item per image) | Refuses if the attachment now points at another file or the kept original is gone; renames the original back, deletes the copies, restores the recorded dimensions and size, removes the record, then re-reads to verify. |
| `image-optimize-serving` | `image-optimize/serving` | Puts the previous mode back. |

`image-optimize-restore` is recorded as irreversible: running `image-optimize-run` again redoes it.

## Storage

- `_gobuildwp_kit_image_opt` post meta per optimised attachment (settings, kept original, copies).
- `gobuildwp_kit_image_opt_cursor` and `gobuildwp_kit_image_opt_serve` options.
- `delete_attachment` removes the kept original and copies with the attachment.

## Host needs

- `Ledger::record_items()`, `Ledger::capture_for()` and `Ledger::register_strategy()`.
- `wp_get_image_editor()` with GD or Imagick; WebP/AVIF copies only where the editor can write them.

## Safety

Run and restore need `upload_files`, `edit_post` on each attachment and `confirm: true`.
Serving needs `manage_options`. Paths are kept relative to uploads and refused if they leave it.
Concurrent runs on the same image are not locked.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/ImageOptimize/` in the GoBuildWP repository (not shipped), with
`tests/Unit/Kits/media-doubles.php`.
<!-- /kit-export:omit -->
