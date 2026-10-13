# block-notes kit

An agent leaves block editor Notes for a person, then reads what they resolved or replied and acts
on it. Built on WordPress 7.1's Notes; reading works from 6.9, anchoring a new note to a block
needs 7.1.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/list-block-notes` | read | Threads on a post with status, block anchor, replies, resolve/reopen history and whether an agent wrote each; optionally every block's path, name, anchor and excerpt. |
| `wppilot/add-block-note` | write | A new open thread anchored to one block, named by path or HTML anchor. |
| `wppilot/reply-block-note` | write | A reply in a thread. Does not change the thread's status. |
| `wppilot/resolve-block-note` | write | Resolves a thread with a resolution entry, as the editor's Resolve button does. |

## How WordPress stores Notes (7.1, read from core)

- A note is a comment with `comment_type = 'note'` on the post. A top-level note is a thread:
  `comment_approved` `'0'` is open (REST `hold`), `'1'` is resolved (`approve`). Replies are
  notes whose `comment_parent` is the thread. `WP_Comment_Query` leaves notes out unless asked
  for the `note` type.
- The editor resolves or reopens by setting the thread's status and adding an empty child note
  whose `_wp_note_status` comment meta is `resolved` or `reopen` (`editor.js` `useNoteActions`,
  meta registered by `wp_create_initial_comment_meta()`). This kit does the same.
- A thread is anchored to a block by its ID in the block's `metadata.noteId` attribute, a list
  since 7.1. A note on a text selection is also marked in the content with
  `<mark class="wp-note" data-id="N">`, which core strips from the front end.
- Notes may only be written on posts the user can edit (`edit_post`), on post types whose
  `editor` support carries `notes` (posts and pages in core).

## Writes and undo

- **add**: inserts the note the way the REST comments controller does (`wp_filter_comment()`
  then `wp_insert_comment()`), then rewrites only that block's delimiter comment to add the ID,
  which makes a revision. If the anchor cannot be verified afterwards, the note is deleted and
  the call fails. The post author gets the site's new-note email (`wp_notes_notify`). Undo
  (`kits/block-note`): delete the note permanently and take its ID off every block; verified by
  re-reading both. Refused while the thread has replies: deleting it would re-parent them.
- **reply**: a child note with the thread's status. Undo (`kits/block-note`): delete the reply.
- **resolve**: thread to `'1'` plus a `resolved` child. Undo (`kits/block-note-resolution`):
  thread back to `'0'` and the child deleted; verified by re-reading both. Resolving a resolved
  thread changes nothing and records nothing to undo. An inline highlight is left in place
  (the editor removes it on resolve; that is a content edit this kit does not make).

## Attribution

Notes are written as the current user, with `comment_author` "<display name> (AI agent)",
`comment_agent` naming the host and this kit, and comment meta `_wppilot_kit_note_agent`, which
`list-block-notes` reports as `is_agent`.

## Host needs

- `ledger()`: `register_strategy()` for both undo types and `capture_for()` to route each write to
  its strategy; the result supplies the IDs.

## Safety

The writes are ordinary (not destructive): they add editorial comments and one attribute, and each
is undoable. Permission is the host's plus `edit_post` on the note's post, checked before execute.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/BlockNotes/` in the GoBuildWP repository (not shipped).
<!-- /kit-export:omit -->
