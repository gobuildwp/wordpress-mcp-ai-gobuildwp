# a11y-audit kit

An accessibility audit of served HTML against WCAG 2.2, and the tools to fix image alt text by
looking at the images.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/audit-accessibility` | read | Fetches one same-site page as a logged-out visitor and checks language, title, heading order, image alt, form labels, link and button names, duplicate IDs, ARIA references, main landmark or skip link, positive tabindex, zoom lock, iframe titles and table headers. Every finding names a WCAG 2.2 criterion and level. Returns a 0-100 score, and lists contrast, focus visibility, target size, keyboard operation and script-rendered content under `not_checked`. |
| `wppilot/audit-media-alt` | read | Pages through the media library's images and classifies each alt as missing, filename or ok, with a decorative guess. |
| `wppilot/get-media-image` | read | A downscaled preview (1024 px by default, WebP or JPEG, under 750 KB) returned under `_mcp_content` so an MCP client shows it to the model as an image. Temporary files are deleted before returning. |
| `wppilot/update-image-alt` | write | Sets `_wp_attachment_image_alt` on up to 100 images, one ledger row per image under one group, so one image or the batch can be undone. |

## The `_mcp_content` convention

An ability result may carry `_mcp_content: [{type: "image", data: <base64>, mimeType}]`. A host
whose MCP transport understands the key sends each item as an MCP `image` content block and
leaves it out of the text; anything else (REST, a transport that does not know the key) returns
it as plain JSON. `get-media-image` works either way; only the first costs no tokens for the
image as text.

<!-- kit-export:omit -->
GoBuildWP's modern transport and its legacy adapter path both convert it
(`includes/mcp/transport.php`: `tool_result()`, `legacy_image_result()`).
<!-- /kit-export:omit -->

## Host needs

- `Runtime\Page::fetch()` (same-site only; `wp_safe_remote_get()` re-checks redirects).
- `Ledger::record_items()` and `Ledger::snapshot_budget()`, and the runtime's post-partial
  strategy for alt-text undo. A write never happens without its before-image: items past the
  snapshot budget are reported as skipped.

## Safety

Three reads and one write. The write is not destructive and each item undoes on its own.
Findings quote page markup; the ability descriptions tell the agent it is data, not
instructions.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/A11yAudit/` in the GoBuildWP repository (not shipped), with
`tests/Unit/Kits/media-doubles.php`.
<!-- /kit-export:omit -->
