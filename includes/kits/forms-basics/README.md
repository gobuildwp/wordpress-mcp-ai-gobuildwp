# forms-basics kit

The forms and submissions of WPForms, Contact Form 7, Gravity Forms and Forminator. Read-only.
Only the active plugins' abilities are registered (bootstrap lists their files); the kit is skipped
when none is active.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/wpforms-list-forms` | read | Forms: id, title, slug, status, dates, entry count (null on Lite). `status`, `search`, `orderby`, `order`, `limit` 50 (max 500), `offset`. |
| `wppilot/wpforms-list-entries` | read | One form's entries with redacted answers. WPForms Pro only (`wpforms_pro_required` on Lite). `form_id` required; `status`, `date_after`/`date_before`, `starred`, `viewed`, `limit` 20 (max 100), `offset`. |
| `wppilot/cf7-list-forms` | read | Forms: id, title, slug, status, locale, hash, tag count, modified. `status`, `locale`, `search`, `limit` 50 (max 500), `offset`. |
| `wppilot/cf7-list-entries` | read | One form's Flamingo messages with redacted answers and subject (`cf7_flamingo_inactive` without Flamingo 2.4+). `status`, `date_from`/`date_to`, `limit`, `offset`. |
| `wppilot/gravityforms-list-forms` | read | Forms: id, title, status, dates, active entry count. `status`, `search`, `orderby`, `order`, `limit` 50 (max 500), `offset`. |
| `wppilot/gravityforms-list-entries` | read | One form's entries with redacted answers (multi-input fields by input label); `ip` always `[REDACTED]`. `status`, `date_from`/`date_to`, `starred`, `read`, `orderby`, `order`, `limit`, `offset`. |
| `wppilot/forminator-list-forms` | read | Forms: id, title, status, answer-field count, entry count, shortcode. `status`, `limit` 20 (max 100), `page`. |
| `wppilot/forminator-list-entries` | read | One form's entries with redacted answers; spam and drafts left out. `date_from`/`date_to`, `limit`, `offset`. |

## Redaction (src/redaction.php)

Withheld whole, as `[REDACTED]` with a `redacted` reason (an empty answer stays empty):

- what the Pro edition's shared form-entry rule withholds: password fields, card and payment fields
  (`creditcard`, Stripe, Authorize.net, Square, PayPal Commerce), file uploads, camera and post
  images, signatures, and a field whose label says password, card number, CVV/CVC or IBAN;
- email and phone fields (`email`, `phone`, `tel`), and a field whose label asks for an email
  address or a phone/mobile number;
- the submitter's IP.

Cut out of every other answer (and a Contact Form 7 subject): email addresses and runs of seven or
more digits with the separators people type in a phone number (`contact_in_text`). Date, time,
number, price and similar fields keep their digits. Answers longer than 2,000 bytes are cut.

There is no `include_sensitive` option. The Pro edition's own entry abilities return the withheld
values to a person who explicitly approves, and each form plugin's admin screen always shows them.

## Vendor data

- **WPForms 1.8+**: forms are `wpforms` posts; entries (Pro only) through
  `wpforms()->obj('entry')->get_entries()` with `date` as [start, end]; answers from the entry's
  `fields` JSON (id, type, name, value). A concrete `form_id` is required and checked with
  `wpforms_current_user_can('wpforms_view_entries', $form_id)`.
- **Contact Form 7 5.8+ / Flamingo 2.4+**: forms are `wpcf7_contact_form` posts read through
  `WPCF7_ContactForm`; messages through `Flamingo_Inbound_Message::find()` / `count()` on the
  channel in the form's `_flamingo` meta (or the channel named after the form's slug). Needs
  `flamingo_edit_inbound_messages`.
- **Gravity Forms 2.7+**: `GFAPI::get_forms()`, `get_form()`, `get_entries()`; counts from
  `GFFormsModel::get_entry_table_name()`; `GFAPI::current_user_can_any(['gravityforms_view_entries'])`.
- **Forminator** (verified 1.57.3): `Forminator_Form_Model::get_all_paged()`,
  `Forminator_Base_Form_Model::get_model()`, `Forminator_Form_Entry_Model::query_entries()`;
  permission is Forminator's own admin capability.

## Host needs

`Runtime\can_run()`, and `Runtime\unclaimed()` (runtime 1.2).

<!-- kit-export:omit -->
Inside GoBuildWP this kit carries the list-forms abilities GoBuildWP Pro 1.10.0 registered, with the
same input and output, and basic, redacted list-entries abilities. Pro keeps its own list-entries
(compact rows, the `include_sensitive` opt-in and scoped search), which register first and answer
on a site running Pro.

## Tests

`tests/Unit/Kits/FormsBasics/` in the GoBuildWP repository (not shipped).
<!-- /kit-export:omit -->
