<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
