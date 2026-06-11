<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.37.0] - Automation activity log

### Added
- **Automation activity log.** The engine now records the outcome of every action
  it runs — **ok** or **error** (with the error message) — instead of leaving it
  only in `nextcloud.log`. A manager opens **Automations → Activity** to see recent
  runs for the register and spot a failing automation. New `df_automation_log`
  table, `AutomationLogService`/`AutomationLogMapper`, a manager-gated
  `automation-log` endpoint, and an Activity dialog in the builder.
- Both the inline path (notify/set-field) and the deferred background-job path
  (webhook/email/folders/calendar/Talk/Deck) log per-action; recording is
  best-effort, so a logging failure can never break an automation.

### Changed
- The daily retention job now also **trims activity older than 30 days**, and a
  register purge cascades to its activity log.

## [0.36.0] - Admin-configurable automations

### Added
- **Admin → DataForms → Automations.** The action catalogue is now curated from
  the console instead of being fixed in code: an admin can **enable/disable each
  of the nine action types** instance-wide (a disabled action disappears from the
  manager's builder, and the engine refuses to create or switch an automation to
  it). The **Talk** and **Deck** actions appear only once the cross-app service
  account is configured.
- **Tunable limits & defaults.** The previously-hardcoded operational constants
  are now admin settings (with the old values as defaults): max folders per
  *Create folders* action and per run, max template files copied, max Talk
  participants, max Deck columns, the default calendar-event length, the default
  Deck columns, and the outbound webhook timeout.
- New `WorkflowSettings` service (IAppConfig-backed), an admin-only
  `AutomationConfigController` (`/admin/automation`), and a manager-readable
  `automation-actions` endpoint that drives the builder's action list.

### Notes
- Protocol internals (API paths, the Talk room type, the path-safety rules and
  date-format whitelist) are deliberately **not** configurable — they are
  correctness-critical, not preferences.

## [0.35.2] - Release-readiness hardening

### Fixed
- **`info.xml` element order** — moved `<settings>` before `<navigations>` so the
  manifest validates against the App Store appinfo XSD (`xs:sequence` order);
  the package previously would have been rejected at upload.
- **JS lint gates restored** — added the missing `@nextcloud/eslint-config` and
  `@nextcloud/stylelint-config` peer plugins (eslint-plugin-import/vue/n/…,
  stylelint-config-recommended-scss/-vue, postcss-html). `npm run lint` and
  `npm run stylelint` now run clean; the codebase was brought to conformance.

### Changed
- **Leaner build/package** — the production build no longer emits source maps,
  and the `appstore`/krankerl packaging removes stale `js/`+`css/` chunks before
  building and excludes `*.map` and dev-only config files, so the signed tarball
  ships only current assets.
- **Licensing** — `<licence>` now uses the SPDX id `AGPL-3.0-or-later`; added a
  `LICENSES/` directory and `REUSE.toml`.
- **Docs reconciled** with the shipped feature set (README, this changelog, and
  the automations / admin guides now cover all nine actions and the cross-app
  service-account setup).

## [0.35.1] - Cross-app action security hardening

### Fixed
- Hardening from an adversarial review of the credentialed cross-app transport:
  the internal API client never follows redirects (a 30x to a loopback/metadata
  host would otherwise bypass the host guard) and logs only the exception
  class+message (never the auth header); the Talk action **always creates a fresh
  room** (no display-name reuse hijack) and **validates that each participant
  exists** before relaying ids to the elevated service account; and
  `create_talk_room` / `create_deck_board` are restricted to the **create**
  trigger so these non-idempotent provisioning actions fire exactly once.

## [0.35.0] - Talk & Deck provisioning actions

### Added
- **`create_talk_room`** — a composite action: create a Talk conversation, add
  participants from a user/group field, and post a welcome message.
- **`create_deck_board`** — create a Deck board and its columns.
- Both run through the cross-app service account (background job), interpolate
  `{field}` / `{relation.subfield}` tokens, and are idempotent where they can be
  (the Deck board is reused if a board with the same title exists).

## [0.34.0] - Cross-app service account

### Added
- An admin-configured, **encrypted** (`ICredentialsManager`) service account and
  a **host-gated `NextcloudApiClient`**, so background-job actions can call the
  instance's own OCS / Deck APIs as a service identity. New admin form under
  **Settings → Administration → DataForms**; the password is stored encrypted and
  never returned to the UI.

## [0.33.0] - Relation sub-field tokens

### Added
- **`{relationField.targetField}` interpolation** (e.g. `{subgroup.code}`) via a
  read-gated `RelationResolver`, so an action template can use the scalar fields
  of a linked record. Only resolves targets in registers the record owner can
  read.

## [0.32.0] - Date tokens & template copy

### Added
- **`{field|dateFormat}` placeholders** (e.g. `{meeting_date|Ymd}` → `20260701`)
  shared by every action, via a `ValueInterpolator`.
- **`apply_template` action** — copies a template folder's contents into a
  record's provisioned folder. Runs as the record owner, path-safe, bounded and
  idempotent (an existing target is never overwritten).

## [0.31.1] - Fix the condition value field

### Fixed
- The condition **value** field was unusable (squeezed to a sliver) in the
  automation/rule dialogs. The condition row now reflows and a value **dropdown**
  is offered for select fields.

## [0.31.0] - Calendar event action

### Added
- **`add_calendar_event`** — a guided action built on the public `OCP\Calendar`
  API: create an event in the record owner's calendar from a date field, with a
  deterministic UID so re-firing never duplicates the event.

## [0.30.0] - Folder provisioning action

### Added
- **`provision_folders`** — create a folder tree in the record owner's Files from
  `{field}` name templates. Every path segment is sanitised (`PathSafety`: no
  `/`, `\`, `..`, control/bidi chars, Windows reserved names), the action is
  bounded and idempotent (`mkdir -p`), and it runs as the **record owner** off
  the request thread.

## [0.29.0] - Audit Medium cluster, CI & accessibility

### Changed
- **Audit Medium cluster (M1/M2/M6/M7/M8):** purge of soft-deleted registers and
  their data (cascade); machine-name **tombstoning** so a reused field name can't
  re-bind a stale rule; **field-scoped** record search; a **single-JOIN** unified
  search; and `[register_id, updated]` / `[register_id, created]` indexes.
- **CI:** the PHP quality gate (php-cs-fixer + psalm + phpunit) now runs and
  passes green.
- **Accessibility:** WCAG 2.1 AA fixes across the Vue component layer.
- **Performance** re-verified at 100k records (sub-second list/search).

## [0.27.0] - Security mediums

### Fixed
- Closed a **cross-register relation information leak** — a relation's display
  label is only resolved when the viewing user can read the target register
  (M3) — and **CSV-formula injection** on export (M4).

## [0.26.0] - Automation engine hardening (High audit blockers)

### Fixed
- **Webhook SSRF guard** — refuse internal/loopback targets and never follow
  redirects (H1).
- **Background-job offload** — webhook/email actions run off the request thread,
  so a slow or hung endpoint can't block the record write or exhaust the worker
  pool (H2).
- **No automation amplification on import** — bulk CSV import never fires per-row
  automations/webhooks (H3).
- **Transaction-wrapped record writes** — a record header and its value/join
  tables are written atomically; events dispatch only after commit (H4).
- Cleared `info.xml` placeholder metadata (H5).

## [0.25.0] - Set-field & webhook actions

### Added
- **Set‑field action** — an automation can set a field on the record to a value
  (e.g. advance a status, stamp a flag). Writes the value directly, so it never
  re‑fires automations (no loops); relation/file/auto/computed fields aren't
  settable.
- **Webhook action** — the first action that leaves the instance: a POST with the
  record payload to a manager‑configured URL. http(s) only, time‑limited, logged;
  an optional shared secret signs the body (HMAC‑SHA256, `X‑DataForms‑Signature`).
- Both are exposed in the Automations builder (field+value picker for set‑field,
  URL+secret for webhook). The engine was untouched — just two new action classes
  in the registry.

This completes the planned action set (notify · email · set‑field · webhook).

## [0.24.0] - Automations builder UI & email action

### Added
- **Automations builder** — a new manager-only **Automations** tab on each
  register: create/edit/enable/delete automations with a name, a trigger
  (create / update / delete), optional conditions (the same field/operator/value
  rows as filters and rules), an action, and recipients (searchable user picker).
  No more API-only.
- **Email action** — automations can now **send an email** to chosen users
  (resolved to their Nextcloud addresses) in addition to a notification. Added as
  a new action class in the registry — the engine itself was untouched
  (config over code).

## [0.23.0] - Workflow automations (notify)

### Added
- **Automations** (workflow, the differentiator): on a record **create / update /
  delete**, when an optional **condition** holds, run an **action**. The first
  action is a **Nextcloud notification** to chosen users. Conditions reuse the
  existing rule engine (no new logic language); actions are a registry (one class
  per type), so adding an action doesn't touch the engine. Runs **server-side
  only**, best-effort (a failing automation never breaks the record write).
  (`df_automations` table, `Automation` domain, OCS CRUD under
  `registers/{id}/automations`, `AutomationListener` on the typed record events,
  and a notification `Notifier`.)

This is workflow step 2 of `docs/WORKFLOW.md`; email / set-field / webhook
actions and a builder UI follow.

### Added
- **Fill a form without leaving the page.** The inserted form card now has a
  **Fill in** button that opens the data‑entry form right over the current
  document (Text, Talk, …) and submits it — reusing the *same* RecordForm the app
  uses (one renderer). The form code is loaded on demand, so the widget stays
  tiny until you click.
- **Workflow foundation:** the app now emits typed domain events
  (`RecordCreated/Updated/DeletedEvent`) on every record change, so a future
  automation engine — or any third‑party app — can react. See
  `docs/WORKFLOW.md` for the full Trigger → Action design.

### Fixed
- **The form icon was invisible (white‑on‑white) in the Smart Picker and on the
  reference card.** Those light surfaces now use a coloured icon variant; the
  white icon stays for the dark app header.

### Added
- **API console** under **Settings → Administration → DataForms**: the app is
  API-first, and this page makes it discoverable — the base API URL, a
  three-step app-password authentication walkthrough (links straight to
  Nextcloud's Security settings), a copy-paste example, and pointers to the full
  reference. It documents and links; it reinvents nothing (machine auth reuses
  Nextcloud app passwords).
- **Docs:** `VISION.md` (north star + the permanent no-list + engineering
  principles — DataForms is a register/workflow workspace, **not** a survey
  tool), `docs/DECISIONS.md` (decisions log), `docs/API.md` (full endpoint
  reference) and `openapi.json` (machine-readable core spec).

## [0.20.0] - Interactive form card & icon fix

### Added
- **Inserted form references render as an interactive card** — form name, the
  register it belongs to, and an **Open form** button — wherever references show
  (Text, Talk, Collectives, Deck, …). A new reference-widget script
  (`dataforms-reference`) is loaded via a `RenderReferenceEvent` listener; it
  draws the card with plain DOM + inline styles (tiny, self-contained) and
  respects access (shows a notice if you can't see the form).

### Fixed
- **The app icon was black in the top bar.** It mixed `currentColor` with a
  hard-coded white shape, so Nextcloud couldn't recolour it. It's now a clean
  single-colour glyph that themes correctly (white on the dark header).

## [0.19.0] - Insert a form anywhere via the Smart Picker

### Added
- **Forms can be inserted into any rich-text field via Nextcloud's Smart Picker**
  (the `/` menu in Text, Talk, Collectives, Deck, …). "Dataforms" appears in the
  picker; search a form, pick it, and a link/card is inserted. Clicking it opens
  the form's entry screen directly (`?register=&form=` deep link the SPA now
  resolves). Inserted form links also render as **rich reference cards**
  (form name + register). Internal/authenticated only — resolving a form
  re-checks the user's read access.
- New `FormSearchProvider` (unified search) and a searchable
  `FormReferenceProvider`, registered in the app bootstrap. The records SPA opens
  a deep-linked form automatically.

## [0.18.1] - Simpler records toolbar

### Changed
- **Decluttered the records toolbar into a single row.** The separate "views"
  row is gone; everything secondary now lives in the one **⋯ More** menu —
  Refresh, **Save current view**, Delete view, Import/Export CSV, and the
  **Columns** picker. The toolbar keeps only what's used most: Search, the saved-
  view selector (shown only when views exist), Filter, and New record.

## [0.18.0] - Share with a searchable user/group picker

### Added
- **The Share dialog now searches users and groups as you type** — instead of
  having to know the exact id. Matches both the id (e.g. `del_ee`) and the
  display name (e.g. *Estonia*), shows users and groups with an avatar and a
  hint, and you pick one and choose the role. New manager-gated
  `GET registers/{id}/sharees` endpoint backed by Nextcloud's user/group
  managers. This is how you share with a specific group or a chosen set of users.

## [0.17.1] - Fix the share dialog layout

### Fixed
- **The Share dialog overflowed and the Add button was cut off.** The
  user/group + role selects ship a 260px min-width, which pushed the controls
  past the dialog edge (with a stray horizontal scrollbar) so sharing was
  effectively unusable. The add row is now a compact 2×2 grid and the selects
  shrink to fit, so the type, who, role and **Add** controls are all visible at
  any dialog width.

## [0.17.0] - Audit history & accessibility

### Added
- **Record history (audit log, §4.9).** Every create, edit and delete is recorded
  with who did it, when, and which fields changed. A collapsible **History**
  timeline appears at the bottom of the record detail. Append-only `df_history`
  table; history is read-gated like the record and best-effort (never blocks a
  save). New `GET records/{id}/history` endpoint.

### Changed
- **Accessibility pass.** The records table uses proper header semantics
  (`scope`, `aria-sort`) and the sortable headers are keyboard-operable (Tab +
  Enter/Space) with a visible focus ring. The drag-and-drop form builder gains a
  keyboard/click **“add to form”** button on every palette field (drag-and-drop
  is mouse-only), and decorative icons are hidden from screen readers.
- **i18n:** added the `l10n/` catalog directory and documented the translation
  workflow; the UI strings are wrapped with the Nextcloud translation helpers.

## [0.16.0] - Inline editing & auto-refresh

### Added
- **Inline cell editing.** Double-click a cell in the records table to edit it in
  place (text, number, currency/percentage, date/time, single-select and Yes/No);
  Enter or click-away saves, Esc cancels. Multi-value/complex fields (relation,
  files, multi-select) and read-only computed/auto fields open the full editor
  instead. Editable cells show a dashed outline on hover. A single click still
  opens the record detail (a short debounce distinguishes click from double-click).
- **Auto-refresh.** The records list reloads when you return to the tab or window,
  and a **Refresh** button sits in the toolbar — so the view no longer shows stale
  data after an import or an edit made elsewhere. (It never interrupts an open
  dialog or an in-progress inline edit.)

## [0.15.1] - Detail layout, sortable sequence & friendlier filters

### Fixed
- **Record detail: long field labels no longer overlap their values.** The
  label/value grid now has a column gap, wraps long labels, and top-aligns rows.
- **The auto "sequence" / Number column is now sortable** — click its header to
  order records 1, 2, 3 … (auto fields sort by the record's own column:
  sequence, created, updated or created-by, since they have no value column).
- **Large numbers are formatted with thousands separators** in the table and the
  record detail (e.g. `1,200,000,000`), honouring each field's decimals.

### Changed
- **Friendlier filters.** When you filter on a single/multi-select field, the
  value is now a **dropdown of that field's options** (with the right operator
  chosen automatically) instead of free text; date/number fields get the
  matching input. Auto fields are hidden from the filter list (nothing to match).

## [0.15.0] - Drag-and-drop form builder & roomier tables

### Added
- **Drag-and-drop form builder** (WYSIWYG): the Forms tab now opens a full
  two-pane builder — a palette of available fields on the left, the form canvas
  with sections on the right. Drag a field into a section, drag to reorder or
  move between sections, drag back to the palette to remove. Each placed field
  shows a live preview of its control (Yes/No, dropdown, text box, file button,
  …). Sections can be renamed and reordered. Built on the native HTML5
  drag-and-drop API (no added dependencies).

### Changed
- **Roomier records table.** The Records tab now uses (almost) the full window
  width instead of a 920px column; the other tabs stay narrow and readable. The
  table header stays pinned while scrolling, the row-actions column sticks to
  the right edge on wide tables, more rows are visible at once, and subtle zebra
  striping aids row tracking.
- **Tidier toolbar.** Import/Export CSV moved into a single "More" overflow menu
  so only Search, Filter and New record stay prominent.

### Added
- **Grouped multi-select** for long option lists: a select/multi-select with
  many options can be shown as **collapsible groups** in the data-entry form,
  with a search box, per-group select-all, and selection chips. Groups are
  derived from the option text by a chosen preset ("by leading code", e.g.
  `Art 6`; "by first word") or a custom pattern — so a 294-option list becomes
  ~33 tidy Article groups. The stored values are a flat list, unchanged; this
  is purely a data-entry aid (no record migration).
- **Per-register sequence numbers.** The "Automatic → Sequence number" field now
  counts **1, 2, 3 … within its register** (was the global row id). Numbers are
  assigned at creation and never reused after a deletion; existing records are
  backfilled in order. (`df_records.seq` column + index.)
- **Index on the text value column** (`df_record_values.value_string`) so
  filtering, sorting and search on text/select/email fields stay fast at scale
  (the 100k-record target). Uses a 64-char prefix on MySQL/MariaDB to respect
  the engine key-length limit; SQLite and PostgreSQL index the full value —
  fully portable.

### Changed
- **Data-entry users now see only the Records tab.** The Fields, Forms and Rules
  builders are manager-only, so people who just add and edit records get an
  uncluttered view; the tab bar is hidden entirely when only Records is shown.
- The **"Automatic" field type** is relabelled "Automatic value (sequence
  number, dates, author)" and lists **Sequence number** first, so the auto-number
  is easy to find (it is not a "Number"-type field).

## [0.13.0] - Multi-value relations & referential integrity

### Added
- **Relation fields can link several records** — a "multiple" toggle on a
  relation field turns the picker into a multi-select; values are stored in a
  dedicated join table (`df_rec_refs`) keyed by (record, field, position).
- **Referential integrity on delete** — each relation field declares what
  happens when a linked record is deleted: **clear the link** (default),
  **prevent the deletion** (block), or **also delete the referencing record**
  (cascade). Enforced server-side; a blocked delete returns a clear error.

### Changed
- The records table and record detail now render multi-value relations as a
  comma-separated list of labels.

### Added
- **Computed field type** — a read-only field calculated from an expression over
  other fields, evaluated server-side on save (e.g. `likelihood * impact`).
- **Auto field types** — created date, last-updated date, created-by, and a
  per-record sequence number, populated automatically.
- **Favourite registers** — star a register; favourites appear in their own
  navigation section. (Per-user, stored in user preferences.)
- **Dashboard landing**: when no register is selected, a card grid of all
  registers with colours, record counts and favourite stars.
- **Register colours** shown as dots in the navigation and as card/detail
  accents; a colour picker in the New register dialog. **Record counts** appear
  as nav counters and on the dashboard cards.

### Notes
- App-level group restriction is supported via Nextcloud's built-in app
  group-restriction (Settings → Apps → Limit to groups) — no extra config needed.

## [0.11.0] - Data-entry forms (§4.3)

### Added
- **Standalone forms**: a register can have one or more data-entry forms, each
  choosing which fields appear, in what order, grouped into **sections**. Build
  them in the new **Forms** tab (manager-only). (`df_forms` table, Form domain,
  OCS API.)
- The **New record** button becomes a menu when forms exist — pick "Blank (all
  fields)" or any form. The data-entry renderer shows the chosen form's fields
  under section headings, and the live conditional rules still apply (an empty
  section, e.g. when its only field is conditionally hidden, disappears).
- Forms are cleaned up when their register is deleted.

## [0.10.0] - Saved, shareable views + column selection

### Added
- **Saved views** (§4.6): save the current columns, filters, sort and search as
  a named view per register; switch between views from a dropdown. A view can be
  **private** or **shared** with everyone who can see the register. Owner or a
  register manager can edit/delete; cleaned up when the register is deleted.
  (`df_views` table, View domain, OCS API.)
- **Column selection** for the records table (a "Columns" picker) — replaces the
  previously hard-coded first-six-columns; the chosen columns are stored in the
  view.

## [0.9.2] - Field help text & default values

### Added
- Each field can have **help text** (shown under the field in the data-entry
  form) and a **default value** (pre-filled on new records) — both editable in
  the field dialog. Completes the §4.2 "common field config".

## [0.9.1] - Permissions, validation, filtering, sorting

### Added
- **Granular permissions** (as requested): only register **managers** can add/
  edit fields and rules; users with **write** access can create records and
  edit/delete **only the entries they created**; a manager can edit any entry.
  Enforced server-side and reflected in the UI (action buttons hidden when not
  permitted). Verified with a second user.
- **Server-side field-config validation**: email/URL format, number min/max,
  text max-length, select-option membership, and **unique-constraint
  enforcement** are now checked before persisting (previously stored but inert).
- **Multi-criteria filtering** on the records view (§4.6): a filter bar with
  field/operator/value conditions (=, ≠, contains, >, <, ≥, ≤, is empty/not
  empty), translated to portable SQL. Plus **sort by any column** (clickable
  headers) and a sortable backend.
- Rule builder gains the **`in` ("is one of")** operator the engines already
  supported.

### Fixed
- README no longer claims "Phase 0"; reflects the current MVP scope.

## [0.8.0] - File attachments: multiple files, simpler UX

### Changed
- **File-attachment fields now hold one or more files** (per the spec), stored
  in a join table and referenced by Nextcloud file id. The data-entry form shows
  the attached files as a list with per-file remove buttons and a single
  **Add file(s)** button.
- **Removed the “Choose from Files” browser popup** (it was confusing and
  unreliable). Attaching now uploads from your computer into a "Dataforms"
  folder in your Nextcloud Files. The files are still referenced by id, never
  duplicated into the app database.
- Table shows “📎 N files”; the record detail lists every file with a link.
  Deleting a field cleans up its file references.

## [0.7.3] - Yes/No fields render as Yes/No

### Fixed
- Boolean fields rendered as a single checkbox labelled with the field name
  (so only one state was visible). They now render as clear **Yes / No** radio
  buttons with the field label above; they start unselected unless a default is
  set. Verified in-browser: label + Yes/No options, selecting Yes works.

## [0.7.2] - Data-entry form fixes (regression + UX)

### Fixed
- **Labels were duplicated/tripled in the data-entry form** (a regression: the
  parent and each widget both rendered a label). FieldInput now renders only the
  control with an accessible aria-label; the single visible label comes from the
  form. Verified in-browser: one label per field.
- **Required errors showed before any input.** Validation errors now appear only
  after a save is attempted (server errors still always show).
- Boolean fields default to false instead of null, so a required Yes/No field is
  not wrongly flagged empty.

## [0.7.0] - Feedback fixes: rules editing, uploads, links, import help

### Added
- **Edit conditional rules** (not just add/delete).
- **Condition values are pickable from a select field's options** instead of
  free text (still allows a custom value).
- **Deep-linkable register URLs**: the hash reflects the open register + tab, a
  **Copy link** button is in the header, and opening such a link selects it.
- **Upload an attachment from your computer** (saved into your Nextcloud Files
  under a "Dataforms" folder, referenced by id) — in addition to picking an
  existing file. The file picker button no longer navigates away.
- **CSV import help dialog** explaining the expected format, with a
  **Download template** (header-only CSV) and an inline per-row error report.

### Fixed
- **Hidden fields were being saved.** A field hidden by a show-rule now has its
  value cleared on save — client-side and authoritatively on the server (fixes
  a hidden select persisting its first option).
- **CSV export returned “access forbidden” (403).** The download route now
  declares the CSRF exemption it needs for a top-level navigation.
- The **“Type” label appeared twice** in the add-field dialog (duplicate
  NcSelect input-label removed).
- **Yes/No fields showed as a bare checkbox** — the field label is now shown on
  the toggle (no duplicate label row).
- Improved contrast/readability of required-field and validation error text.

## [0.6.2] - Field editing, reorder & robustness

### Added
- **Edit a field** after creation: change its label, options, number config and
  required/unique flags (type and machine name stay immutable, by design).
- **Reorder fields** with up/down controls in the schema editor.
- Unit tests for the EAV value coercion (`FieldValueTest`): column mapping and
  round-tripping for every field type incl. relation and file ids.

### Changed
- All OCS API calls now use a 30s timeout, so a request that stalls behind a
  saturated HTTP/1.1 connection pool (e.g. on a busy instance with Talk's
  long-poll) surfaces an error instead of an indefinite spinner.

## [0.6.0] - Phase 3 complete: file attachments

### Added
- **File-attachment fields**: pick a file from Nextcloud Files; stored by file
  id (never as a blob), resolved to {id, name} with a link on read. Path→id
  resolution endpoint (`files/resolve`); the field renders a Files picker in the
  data-entry form and a 📎 link in the table and detail view.

### Fixed
- Record search used a non-existent `IExpressionBuilder::exists()`, causing a
  500 whenever a search term was entered. Rewritten as a portable `IN` subquery.
- Read endpoints (records/fields/rules list, relation options, file resolve)
  release the PHP session lock right after authenticating, so the SPA's
  concurrent reads no longer serialise behind each other — or behind other apps'
  long-polling on the shared session (which could otherwise stall a list load).

## [0.5.0] - Phase 3: relations, record detail, CSV import

### Added
- **Relation fields**: link a record to a record in another register. Stored by
  target record id (`value_ref_record_id`); the display label is resolved from a
  configurable display field. Schema editor picks the target register + display
  field; the data-entry form offers a searchable record picker
  (`registers/{id}/options` endpoint).
- **Record detail view**: read-only modal showing every field (incl. relations),
  opened by clicking a table row; edit from there.
- **CSV import** (`ImportService`): upload a CSV, columns matched to fields by
  header (label or machine name); each row created through the normal
  validation/computed pipeline, with a per-row error summary. Round-trips with
  the existing CSV export.

## [0.4.0] - Sharing (ACL) + rule-engine tests

### Added
- **Register sharing / access control**: `Share` domain, `ShareMapper`,
  `ShareService`, OCS `ShareController`. Real read/write/manage permission
  bitmask — owner has all; others get the OR of user/group shares. Enforced
  server-side on every record/field/rule/share endpoint. Share dialog UI
  (add user/group, Read/Write/Manage role, remove); internal users/groups only.
- Register API responses now carry `permissions` / `canWrite` / `canManage` /
  `isOwner`; record writes require the write bit, schema/rules/sharing the
  manage bit.
- **Rule-engine test suite**: shared fixtures (`tests/fixtures/rule-cases.json`)
  drive *both* the JS (`src/rules/*.spec.js`, Vitest) and PHP
  (`tests/unit/Rules/*Test.php`, PHPUnit) evaluators, proving they agree —
  plus sandbox-safety cases (unknown functions, no global access, malformed
  input). 30 JS assertions passing.

## [0.3.2] - Phase 1+2: records, conditional rules engine

### Added
- **Records**: `Record` entity, `RecordMapper` (pagination + full-text search),
  `RecordValueMapper` (typed EAV value store), `RecordService` (validation +
  computed-field evaluation), OCS `RecordController`, CSV export.
- **Conditional rules engine** (the differentiator): `df_rules` table, `Rule`
  domain, and a **sandboxed expression evaluator** (`ExpressionEvaluator`) plus
  `RuleEvaluator` — no `eval`, whitelisted function set. Mirrored exactly in JS
  (`src/rules/`) so the live form and server agree on one rule definition.
- Effects: show/hide, require-if, set-default, validate (regex/range/expr),
  compute. Conditions with and/or groups and 10 operators.
- **Frontend**: register pane tabs (Records · Fields · Rules); `RecordsView`
  (table, search, pagination, CSV); `RecordForm` (live conditional data entry —
  fields show/hide, become required, recompute as you type); `RuleBuilder`.

### Fixed
- OCS client built nested URLs via a `{path}` placeholder, which percent-encoded
  the slashes (`registers%2F1%2Ffields` → 404). Build paths literally.

## [0.2.0] - Phase 1 (in progress): registers

### Added
- EAV-with-typed-columns schema (migration): `df_registers`, `df_fields`,
  `df_records`, `df_record_values`, `df_shares`, with indexes for filter/sort.
- Register domain: `Register` entity, `RegisterMapper` (owner + shared
  visibility), `RegisterService` (server-side access control).
- OCS REST API for registers (list/create/show/update/soft-delete) under
  `/ocs/v2.php/apps/dataforms/api/v1/registers`.
- Frontend: register list in the navigation, create dialog and delete, wired
  to the OCS API via `@nextcloud/axios`.
- Fields (schema): `Field` entity + `FieldMapper`, `FieldService` (16 field
  types, machine-name generation/dedup, immutable machine names, type-specific
  config validation, ordering), OCS `FieldController` and routes.
- Frontend `SchemaEditor`: list/add/delete fields with a grouped type picker,
  per-type config (select options, number min/max/decimals), required/unique.

### Notes
- Booleans are nullable (Nextcloud portability rule: NOT NULL booleans cannot
  default to false on Oracle).
- Fields, records, the schema editor and list views are the next slices.

## [0.1.0] - Phase 0 skeleton

### Added
- App scaffold following the standard Nextcloud app skeleton.
- `appinfo/info.xml` with metadata, AGPL licence, donation entries and
  declared Nextcloud/PHP dependency ranges.
- DI bootstrap (`Application`), SPA page controller and shell template.
- Vue 3 SPA shell with navigation, built via `@nextcloud/vite-config`.
- Quality tooling: php-cs-fixer (Nextcloud coding standard), Psalm, ESLint,
  stylelint, PHPUnit and Vitest configs.
- CI workflow (lint + static analysis + tests + build) and reproducible
  packaging (`krankerl.toml`, `Makefile`).
- AGPL-3.0-or-later `COPYING` and SPDX headers on every source file.
