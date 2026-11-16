---
name: media-rename
description: Rename an uploaded image or file, with all its generated sizes, its slug and the links to it in posts, with undo. Also covers finding and fixing images without alt text. Activate when the user asks to rename a media file, give images SEO-friendly file names, replace names like IMG_4032.jpg, or bulk-fix missing alt text.
---

# Renaming media files

`gobuildwp/rename-media-file` renames one attachment per call.

## Steps

1. Call it with `dry_run: true`. Show the person `files` (every file and its new name),
   `slug`, `posts` (each post whose content links to the file, with the number of links) and
   `meta_references`.
2. Ask before renaming, then call again with `confirm: true`.
3. Report `files`, `slug`, each post's `status`, and the `group`.

## Names

- `new_name` is the name without extension: `red-bicycle`, not `red-bicycle.jpg` (a matching
  extension is dropped; another one is refused). It is sanitised the way uploads are.
- Sizes keep their suffix: `IMG_4032-300x200.jpg` becomes `red-bicycle-300x200.jpg`, and a
  `-scaled` upload keeps `-scaled`.
- Refused when a file with the new name exists. Pick another name.

## What it changes and what it does not

- Changed: the files, the attachment's stored path and metadata, its slug (unless
  `update_slug: false`) and links in post content and excerpts (unless `update_content: false`),
  at most 50 posts.
- Not changed: post meta (`meta_references`, often Elementor or other builder data). Tell the
  person and offer the search-replace abilities for those. The GUID keeps the old address, as
  WordPress intends. Cached pages may show the old address until the cache is cleared.
- An image optimised by image-optimize is refused: restore it, rename, then optimise again.

## Undo

The rename and each post update are one change-log group. Undo all of them together: undoing
only the rename leaves the posts pointing at names that are gone. The file undo is refused if
the attachment was renamed again since.

## Alt text

Missing alt text is handled by the a11y-audit kit: `wppilot/audit-media-alt` lists images
without alt text, and `wppilot/update-image-alt` sets it for many images in one call, with undo.
Write alt text that describes the image in its context; leave decorative images empty.
