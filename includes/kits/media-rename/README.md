# media-rename kit

Renames a media-library attachment's files and slug and rewrites links to it in post content.

| Ability | Kind | What it does |
|---|---|---|
| `gobuildwp/rename-media-file` | destructive | Renames the attached file, `original_image` and every size file from the old stem to `new_name` (extension unchanged), updates `_wp_attached_file`, `_wp_attachment_metadata` and the slug, and rewrites links in `post_content`/`post_excerpt` of up to 50 posts. `dry_run` reports without changing. Needs `confirm: true` otherwise. |

Bulk alt text is not here: the a11y-audit kit's `wppilot/audit-media-alt` and
`wppilot/update-image-alt` already list and set it.

## Refusals

Before anything is written, the rename is refused when: a target file exists; a file of the
attachment does not start with the stem the others share; the attachment has image-optimize copies
(`_gobuildwp_kit_image_opt`); a linking post cannot be edited by the caller; more than 50 posts
link to it (or over 200 mention the stem); or the before-images exceed the ledger's snapshot
budget. A failed file move puts the files already moved back.

Links are matched without the URL scheme and only where the match ends the file name, so
`photo.jpg` does not rewrite `photo.jpg.webp`. Post meta that mentions the file (both plain and
JSON-escaped slashes) is listed in `meta_references` and never changed.

## Undo

One change-log group per call:

| Item | Strategy | What undo does |
|---|---|---|
| The attachment | `media-rename/attachment` | Refuses if the attachment no longer points at the new file; moves every file back, restores `_wp_attached_file`, the metadata and the slug, then verifies the files and path. |
| Each updated post | `kits/post-partial` | Restores `post_content` and `post_excerpt` and verifies them by fingerprint. |

## Host needs

- `Ledger::record_items()`, `Ledger::register_strategy()`, `Ledger::snapshot_budget()`, and the
  runtime's post-partial strategy registered by the host.

## Safety

Needs `upload_files`, `edit_post` on the attachment and on every linking post, and `confirm:
true` unless `dry_run`. Paths are kept relative to uploads and refused if they leave it.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/MediaRename/` in the GoBuildWP repository (not shipped), with
`tests/Unit/Kits/media-doubles.php`.
<!-- /kit-export:omit -->
