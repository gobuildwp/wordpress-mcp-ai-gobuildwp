---
name: image-optimize
description: Compress, resize and convert media-library images to WebP/AVIF on the site itself, in resumable batches, with a dry-run estimate and a kept original for restore. Activate when the user asks to optimise, compress or shrink images, convert them to WebP or AVIF, speed up image-heavy pages, fix "serve images in next-gen formats" or "properly size images" warnings, or undo an image optimisation.
---

# Optimising media-library images

Everything runs through WordPress's own image editor (GD or Imagick). No image leaves the site.

## Order of work

1. `gobuildwp/image-optimize-status`: server, which formats the editor can write, how many
   JPEG/PNG images there are, how many are done, and where the last bulk run stopped. If
   `editor_supports.webp` is false, say so: copies cannot be made on this host, only
   compression and resizing.
2. `gobuildwp/image-optimize-estimate` with the settings you plan to use. Report the measured
   `ratio`, `estimated_bytes_saved`, how many would be resized and the skip reasons. Nothing is
   written.
3. Ask before running. The run replaces originals (the old file is kept next to it as
   `<name>.optimize-backup.<ext>`), so it needs `confirm: true` after the person agrees.
4. `gobuildwp/image-optimize-run` with `confirm: true`. Without `attachment_ids` it works
   through the library `limit` images at a time and stops early after about 20 seconds. Call it
   again with the same settings until `done` is true; `next_cursor` and `remaining` show
   progress. Report `summary.bytes_saved`, failures and their reasons.
5. To show the copies to visitors, `gobuildwp/image-optimize-serving` with `mode: "picture"`
   (needs `manage_options`). Then ask the person to check a page with images.

## Settings

- `quality` 82 by default (40-100). Below 70, warn that photos may show artefacts.
- `max_dimension` 2560 by default: larger originals are scaled down; 0 never resizes.
- `formats` `["webp"]` by default. Add `"avif"` only when status says the editor supports it;
  an unsupported format is skipped and reported, not an error.
- `compress_original` true by default. The original is replaced only when the result is
  smaller or was resized.

## What it does not do

- GIF (may be animated), WebP and AVIF originals are skipped.
- Generated thumbnail sizes are not recompressed, only given WebP/AVIF copies.
- It never writes `.htaccess` or server rules. On OpenLiteSpeed `.htaccess` is often ignored,
  and nginx never reads it, so rule-based serving can look set up and serve nothing. Serving is
  markup: `<picture>` with `<source>` elements, wrapped only when every file in the image's
  srcset has a copy.
- A theme that styles `figure > img` may need a CSS tweak once images sit inside `<picture>`.
  If the layout changes, switch serving off; the copies stay on disk.

## Undo

- Each optimised image is a change-log item, so undoing the run puts the kept original back,
  restores its width, height and size, and deletes the copies.
- `gobuildwp/image-optimize-restore` does the same by attachment id (`confirm: true`). It
  refuses an image whose file was renamed or replaced since.
- Serving changes are undoable from the change log.
- Deleting an attachment also deletes its kept original and copies.
