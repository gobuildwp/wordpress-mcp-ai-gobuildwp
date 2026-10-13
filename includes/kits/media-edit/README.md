# media-edit kit

Resizes, crops, rotates and flips media-library images with WordPress's own image editor.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/edit-image` | write | Applies up to 10 operations in order. `copy` (default) saves a new attachment; `replace` writes a new `-e<time>` file for the same attachment the way core's Edit Image screen does. Sides capped at 8000 px, sources at 50 MP, no enlarging without `allow_upscale`. Never deletes files. |

## Undo

| Mode | Strategy | What undo does |
|---|---|---|
| `copy` | `media-edit/delete-created-attachment` | Deletes the attachment the edit created, with its files, after checking it still points at the file the edit wrote; verified by re-reading that the post and file are gone. |
| `replace` | `media-edit/restore-replaced-file` | Checks the old file is still on disk, then restores `_wp_attached_file`, `_wp_attachment_metadata` and `_wp_attachment_backup_sizes` through the runtime's post-partial restore, which re-reads and compares them. |

Both strategies are registered at boot through `Ledger::register_strategy()`, and the
before-image through `Ledger::capture_for()`, so a rollback on a later request finds them.

## Host needs

- `Ledger::capture_for()` and `Ledger::register_strategy()`.
- WordPress's image editor (`wp_get_image_editor()`) with GD or Imagick.

## Safety

A write, not destructive: the original file is never touched or removed, and both modes undo.
Needs `upload_files` plus `edit_post` on the attachment.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/MediaEdit/` in the GoBuildWP repository (not shipped), with
`tests/Unit/Kits/media-doubles.php`.
<!-- /kit-export:omit -->
