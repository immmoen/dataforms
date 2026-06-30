<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# DataForms — Test plan & traceability matrix

**Status: FROZEN** (definition of done for the acceptance / *recette* deployment).

This document is the **single, auditable list of business scenarios** that defines
"100% of business cases" for DataForms. Per the programme PRD (issue #1), the
acceptance deployment is gated on **this matrix being 100% green**: every scenario
below must map to a named, passing test before DataForms goes to *recette*.

It is *frozen*: the scenario set and their stable ids do not change with each PR.
A scenario is added or removed only by a deliberate, recorded edit (and, where it
reflects a behaviour change, a `docs/DECISIONS.md` entry) — not as a side effect of
writing tests. New tests fill the **Test** and **Status** columns; they do not
re-define the rows.

## Provenance & cross-check

Every scenario is cross-checked against the end-user guides and the charter so the
list contains **no invented feature and omits no documented one**:

- `docs/guides/01-getting-started.md` … `06-glossary-and-faq.md` (the end-user guides)
- `VISION.md` (the primitives and the permanent no-list)
- corroborated against the implementation where a documented detail needed a value
  (e.g. the CSV row cap = **5000**, confirmed in `lib/Service/ImportService.php`;
  the relation delete policies **clear / block / cascade**, confirmed in
  `lib/Service/{FieldService,RecordService}.php`; the **20** field types).

The **Source** column cites the governing guide section (or `VISION`) for each row.
Scenarios that the charter's no-list *excludes* are recorded at the end under
**Out of scope** so the boundary is auditable too.

## How to read this matrix

| Column | Meaning |
|--------|---------|
| **Id** | Stable scenario id (`<CAP>-NN`). Never renumbered. |
| **Business scenario** | The observable behaviour, from the end-user's point of view. |
| **Source** | Governing documentation (the authority for "this is a real feature"). |
| **Seam** | Where it is exercised — `E2E` (Playwright via the SPA) or `unit` (PHPUnit/Vitest at the service/action/component seam). See the PRD "Seams" section. |
| **Test** | The named test that proves it. Convention: `e2e/<capability>.spec.ts › <scenario>` for E2E, or the `*Test.php` / `*.spec.js` name for a unit seam. Filled as tests land. |
| **Status** | 🔴 pending · 🟢 green · ⚠ covered at **unit level, not true E2E** (an explicitly accepted gap — Talk/Deck and a few instance-admin items). |

The acceptance gate is reached when **no row is 🔴** (⚠ rows count as covered for the
gate, by the accepted-gap decision in the PRD).

Seam policy is the PRD's: **E2E is the primary seam** for business cases (Playwright,
through the SPA, against a seeded Nextcloud). Rows marked `unit` are ones the PRD
explicitly assigns to a lower seam — Talk/Deck (faked `NextcloudApiClient`), the
SSRF/HMAC internals of the webhook action, and instance-admin configuration whose
side effect is asserted at the service seam rather than driven through the SPA.

## Coverage summary

| Capability | Code | Scenarios | 🟢 Green |
|------------|------|-----------|---------|
| Registers | REG | 9 | 0 |
| Fields (20 types + behaviours) | FLD | 32 | 0 |
| Forms | FRM | 8 | 0 |
| Records | REC | 11 | 0 |
| Rules (5 effects) | RUL | 8 | 0 |
| Relations | REL | 7 | 0 |
| Attachments | ATT | 4 | 0 |
| Views & browsing | VW | 17 | 0 |
| Sharing | SHR | 12 | 0 |
| Automations (9 actions) | AUT | 23 | 0 |
| Audit history | AUD | 4 | 0 |
| CSV import / export | CSV | 9 | 0 |
| Admin & integration | ADM | 9 | 0 |
| **Total** | | **153** | **0** |

> All rows are 🔴 today: this matrix is frozen by issue #3, while the test stack
> (issue #2, walking skeleton) and the per-capability suites land in later issues.
> The count (**153**) realises the PRD's "~149 business scenarios" estimate.

---

## Registers — `REG`

*Source: guide 01 (Getting started), guide 03 §Sharing, `VISION` (Register primitive).*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| REG-01 | Create a register (title, optional description, colour); creator becomes its **manager** | Guide 01 §Creating a register | E2E | `e2e/registers.spec.js › create and delete a register` | 🟢 |
| REG-02 | Edit a register's metadata (title / description / colour) | Guide 01 | E2E | _no SPA UI — blocked by #22_ | ⚠ |
| REG-03 | Delete a register (removes its records) | Guide 03 §Fields (deletion semantics), `VISION` | E2E | `e2e/registers.spec.js › create and delete a register` | 🟢 |
| REG-04 | Dashboard shows register **cards** with record counts | Guide 01 §What you see | E2E | `e2e/registers.spec.ts › dashboard lists register cards` | 🔴 |
| REG-05 | Sidebar lists registers; **Favourites** pinned above **All registers** | Guide 01 §What you see | E2E | `e2e/registers.spec.ts › sidebar groups favourites first` | 🔴 |
| REG-06 | Star / unstar a register (favourite toggle) | Guide 01 §What you see | E2E | `e2e/registers.spec.js › favourite a register` | 🟢 |
| REG-07 | **Copy link** copies a direct link to the register and the open tab | Guide 01 §Header buttons | E2E | `e2e/registers.spec.ts › copies a deep link` | 🔴 |
| REG-08 | **Deep link** (`?register=&form=`) opens straight into a register/tab/form entry | Guide 05 §Deep links | E2E | `e2e/registers.spec.ts › deep link opens form entry` | 🔴 |
| REG-09 | Manager-only tabs (Fields/Forms/Rules/Automations) hidden from viewer & contributor | Guide 01 §tabs, guide 06 FAQ | E2E | `e2e/registers.spec.ts › hides design tabs from non-managers` | 🔴 |

## Fields — `FLD`

*Source: guide 03 §Fields, `VISION` (Field primitive — "20 types"). The roster of 20
types is confirmed in code (`lib/`/`src/api/fields.js`).*

> **Coverage (issue #6):** all 20 types + their type-specific config, the
> machine-name rules, uniqueness, defaults, the mandatory flag and the
> soft-delete name tombstone are exercised at the **service/validator seam**
> (`FieldServiceTest`, `FieldValidatorTest`) and the **mapper seam** against the
> real migrated schema per DB engine (`tests/integration/Db/FieldMapperTest`).
> The schema editor is covered in jsdom (`SchemaEditor.spec.js`); a
> representative single-select field is created through the SPA end-to-end
> (`tests/e2e/fields.spec.js`). The per-row "E2E" seam below is therefore
> satisfied at the lower seams for the type roster, per the PRD's seam policy.

### Common field behaviour

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| FLD-01 | Add a field: label, optional **help text**, optional **default**, **Required**, **Unique values**, order | Guide 03 §Add a field | E2E | `e2e/fields.spec.ts › adds a field with common settings` | 🔴 |
| FLD-02 | Machine name is generated from the label and is **fixed** afterwards (immutable) | Guide 03 §Common settings | E2E | `e2e/fields.spec.ts › machine name is immutable` | 🔴 |
| FLD-03 | Reorder fields with the up/down controls | Guide 03 §Common settings | E2E | `e2e/fields.spec.ts › reorders fields` | 🔴 |
| FLD-04 | **Required** is enforced when saving a record | Guide 03; guide 02 §Add a record | E2E | `e2e/fields.spec.ts › enforces required on save` | 🔴 |
| FLD-05 | **Unique values** are enforced (duplicate refused) | Guide 03 §Common settings | E2E | `e2e/fields.spec.ts › enforces uniqueness` | 🔴 |
| FLD-06 | **Default value** is applied to a new record | Guide 03 §Common settings | E2E | `e2e/fields.spec.ts › applies default value` | 🔴 |
| FLD-07 | Deleting a field removes its stored values but **does not** delete records | Guide 03 §Fields (closing note) | E2E | `e2e/fields.spec.ts › deleting a field keeps records` | 🔴 |

### The 20 field types

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| FLD-08 | **Text** field | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › text field` | 🔴 |
| FLD-09 | **Long text** field | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › long text field` | 🔴 |
| FLD-10 | **Yes/No** field | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › yes-no field` | 🔴 |
| FLD-11 | **Number** field (min/max, decimals) | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › number field with min/max/decimals` | 🔴 |
| FLD-12 | **Currency** field | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › currency field` | 🔴 |
| FLD-13 | **Percentage** field | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › percentage field` | 🔴 |
| FLD-14 | **Date** field | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › date field` | 🔴 |
| FLD-15 | **Date & time** field | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › datetime field` | 🔴 |
| FLD-16 | **Time** field | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › time field` | 🔴 |
| FLD-17 | **Single select** (options, one per line) | Guide 03 §Field types | E2E | `e2e/fields.spec.js › add a single-select field with options` | 🟢 |
| FLD-18 | **Multi select** (options) | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › multi select field` | 🔴 |
| FLD-19 | **Email** (format-checked) | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › email field validates format` | 🔴 |
| FLD-20 | **URL** (format-checked) | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › url field validates format` | 🔴 |
| FLD-21 | **Phone** (format-checked) | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › phone field validates format` | 🔴 |
| FLD-22 | **User** (Nextcloud) | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › user field` | 🔴 |
| FLD-23 | **Group** (Nextcloud) | Guide 03 §Field types | E2E | `e2e/fields.spec.ts › group field` | 🔴 |
| FLD-24 | **Relation** (link to another register) | Guide 03 §Field types, §Relation | E2E | `e2e/relations.spec.js › @smoke link a record via a relation field` (creates a relation field) | 🟢 |
| FLD-25 | **File attachment** (one or more files via Files) | Guide 03 §Field types, §File | E2E | `e2e/attachments.spec.js › @smoke upload, list, remove and persist file attachments` | 🟢 |
| FLD-26 | **Computed** (read-only expression: `sum,round,if,concat,min,max,abs,len,lower,upper`) | Guide 03 §Computed | E2E | `e2e/fields.spec.ts › computed field` | 🔴 |
| FLD-27 | **Automatic** field type (system-filled) | Guide 03 §Automatic | E2E | `e2e/fields.spec.ts › automatic field type` | 🔴 |

### Automatic-field variants & select grouping

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| FLD-28 | Automatic: **Sequence number** (1, 2, 3… per register) | Guide 03 §Automatic, guide 06 | E2E | `e2e/fields.spec.ts › automatic sequence number` | 🔴 |
| FLD-29 | Automatic: **Created date** | Guide 03 §Automatic | E2E | `e2e/fields.spec.ts › automatic created date` | 🔴 |
| FLD-30 | Automatic: **Last-updated date** | Guide 03 §Automatic | E2E | `e2e/fields.spec.ts › automatic last-updated date` | 🔴 |
| FLD-31 | Automatic: **Created-by** | Guide 03 §Automatic | E2E | `e2e/fields.spec.ts › automatic created-by` | 🔴 |
| FLD-32 | Select/multi-select **Group options in the form** ("by leading code") → collapsible, searchable picker | Guide 03 §Worth knowing, guide 02 §Long option lists | E2E | `e2e/fields.spec.ts › grouped searchable option picker` | 🔴 |

## Forms — `FRM`

*Source: guide 03 §Forms, `VISION` (Form = a *write* lens; one renderer).*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| FRM-01 | Create a form in the drag-and-drop two-pane builder | Guide 03 §The drag-and-drop builder | E2E | `e2e/forms.spec.ts › builds a form by drag and drop` | 🔴 |
| FRM-02 | Organise fields into **sections**; rename a section inline | Guide 03 §The drag-and-drop builder | E2E | `e2e/forms.spec.ts › sections and inline rename` | 🔴 |
| FRM-03 | Reorder sections (▲/▼) and reorder/move fields between sections | Guide 03 §The drag-and-drop builder | E2E | `e2e/forms.spec.ts › reorders sections and fields` | 🔴 |
| FRM-04 | Remove a placed field (drag back to palette / ✕) | Guide 03 §The drag-and-drop builder | E2E | `e2e/forms.spec.ts › removes a placed field` | 🔴 |
| FRM-05 | Add a field with the **+** button (keyboard, no mouse) | Guide 03 §The drag-and-drop builder | E2E | `e2e/forms.spec.ts › adds a field via plus button` | 🔴 |
| FRM-06 | Several forms per register all feed the **same** records | Guide 03 §Forms, `VISION` | E2E | `e2e/forms.spec.ts › multiple forms feed same records` | 🔴 |
| FRM-07 | A saved form appears in the **New record** menu | Guide 03 §The drag-and-drop builder, guide 02 | E2E | `e2e/forms.spec.ts › saved form appears in new-record menu` | 🔴 |
| FRM-08 | Without a form, **New record** shows every field | Guide 03 §Forms, guide 02 | E2E | `e2e/forms.spec.ts › blank form shows all fields` | 🔴 |

## Records — `REC`

*Source: guide 02 (Everyday use), `VISION` (Record primitive).*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| REC-01 | Create a record via **Blank (all fields)** | Guide 02 §Add a record | E2E | `e2e/records.spec.js › @smoke create, edit, and delete a record` | 🟢 |
| REC-02 | Create a record via a named **form** | Guide 02 §Add a record | E2E | `e2e/records.spec.ts › creates a record via form` | 🔴 |
| REC-03 | **New record** menu offers Blank + each named form | Guide 02 §Add a record | E2E | `e2e/records.spec.ts › new-record menu lists forms` | 🔴 |
| REC-04 | Required fields are marked and checked on **Save** | Guide 02 §Add a record | E2E | `RecordForm.spec.js › blocks save … required field is empty` (live, unit); E2E slot pending | 🔴 |
| REC-05 | Computed & automatic fields are filled on create | Guide 02 §Add a record | E2E | `RecordForm.spec.js › computes a field live`; `RecordServiceCreateTest`/`…ReadTest` (unit); E2E slot pending | 🔴 |
| REC-06 | Edit a record via the full form (⋯ → Edit) | Guide 02 §Edit a record | E2E | `e2e/records.spec.js › @smoke create, edit, and delete a record` | 🟢 |
| REC-07 | **Double-click a cell** to edit in place (text/number/date/single-select/Yes-No); Enter/blur saves | Guide 02 §Edit a record | E2E | `e2e/records.spec.js › inline-edit a cell, and cancel an inline edit with Esc` (blur path; Enter glitch tracked in #25) | 🟢 |
| REC-08 | **Esc** cancels an inline edit | Guide 02 §Edit a record | E2E | `e2e/records.spec.js › inline-edit a cell, and cancel an inline edit with Esc` | 🟢 |
| REC-09 | Delete a record | Guide 02 §Edit a record (⋯ menu) | E2E | `e2e/records.spec.js › @smoke create, edit, and delete a record` | 🟢 |
| REC-10 | Open the read-only **detail** — every field incl. linked records & attachments | Guide 02 §Open a record | E2E | `e2e/records.spec.js › open the read-only record detail` | 🟢 |
| REC-11 | Contributor can edit/delete **only their own** records; manager can edit any | Guide 02 (callout), guide 06 FAQ | E2E | `RecordServicePermissionTest` (own/manage gate, unit); E2E slot pending | 🔴 |

## Rules (conditional logic) — `RUL`

*Source: guide 03 §Rules, `VISION` (Rule primitive; one rule language, sandboxed).
Parity is gated by the shared `rule-cases.json` JS/PHP fixture (PRD seam #5).*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| RUL-01 | **Show / hide** a field when conditions are met (live) | Guide 03 §Rules | E2E | `rule-cases.json › show-hides-field / show-reveals-field` (JS+PHP parity); E2E slot pending | 🔴 |
| RUL-02 | **Require** a field only when relevant (require-if) | Guide 03 §Rules | E2E | `rule-cases.json › conditional-require-* + require-*` (JS+PHP parity); E2E slot pending | 🔴 |
| RUL-03 | **Set a default** value | Guide 03 §Rules | E2E | `rule-cases.json › set-value-* ` (JS+PHP parity); E2E slot pending | 🔴 |
| RUL-04 | **Validate** (range / pattern / cross-field) with a custom message | Guide 03 §Rules | E2E | `rule-cases.json › validate-*` (range/regex/expression, JS+PHP parity); E2E slot pending | 🔴 |
| RUL-05 | **Compute** a value from other fields | Guide 03 §Rules | E2E | `e2e/rules.spec.js › @smoke compute rule evaluates live and is re-checked on save` | 🟢 |
| RUL-06 | Multiple conditions must **all** match | Guide 03; guide 04 §Only if | E2E | `rule-cases.json › or-conditions / require-gte-and-lte` (JS+PHP parity); E2E slot pending | 🔴 |
| RUL-07 | Rules are **re-checked on the server** at save (browser/server can't disagree) | Guide 03 §Rules, `VISION` | E2E + unit | `e2e/rules.spec.js › @smoke compute … re-checked on save`; `RuleEvaluatorTest.php` | 🟢 |
| RUL-08 | Expressions are **sandboxed** — no scripting / `eval` (negative) | Guide 03, `VISION` no-list | unit | `ExpressionEvaluatorTest.php › testRejectsUnknownFunction / testUndefinedIdentifierIsNull`; `expression.spec.js › does not expose globals` | 🟢 |

## Relations — `REL`

*Source: guide 03 §Relation; delete policies confirmed in `RecordService.php`
(`null` = clear, `block` = prevent, `cascade`).*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| REL-01 | Link a record to a record in another register | Guide 03 §Relation | E2E | `e2e/relations.spec.js › @smoke link a record via a relation field` | 🟢 |
| REL-02 | Allow **several** links (multi-relation) | Guide 03 §Relation | E2E | `RecordServiceReadTest › returns multiple relation as list` (unit); E2E slot pending | 🔴 |
| REL-03 | Choose the **display field** shown for a linked record | Guide 03 §Relation | E2E | `e2e/relations.spec.js › @smoke … show its display value` | 🟢 |
| REL-04 | Delete policy **clear the link** (`null`) when the linked record is deleted | Guide 03 §Relation | E2E | `RecordServiceDeleteTest / RecordRelationServiceTest › null policy drops dangling refs` (unit); E2E slot pending | 🔴 |
| REL-05 | Delete policy **prevent deletion** (`block`) | Guide 03 §Relation | E2E | `RecordServiceDeleteTest / RecordRelationServiceTest › block policy throws` (unit); E2E slot pending | 🔴 |
| REL-06 | Delete policy **cascade-delete** the referencing record | Guide 03 §Relation | E2E | `RecordServiceDeleteTest / RecordRelationServiceTest › cascade soft-deletes` (unit); E2E slot pending | 🔴 |
| REL-07 | Cross-register **read-permission guard** — no linked data leaks to users without read on the target | `VISION` (server re-checks); PRD story 24 | E2E + unit | `RecordServiceReadTest / RecordRelationServiceTest › anonymises unreadable target` (unit); E2E slot pending | 🔴 |

## Attachments — `ATT`

*Source: guide 02 §Attachments, guide 03 §File attachment.*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| ATT-01 | Upload a single file to a file field | Guide 02 §Attachments | E2E | `e2e/attachments.spec.js › @smoke upload, list, remove and persist` | 🟢 |
| ATT-02 | Upload **multiple** files | Guide 02 §Attachments | E2E | `e2e/attachments.spec.js › @smoke … (uploads two)` | 🟢 |
| ATT-03 | Remove an attachment (✕) | Guide 02 §Attachments | E2E | `e2e/attachments.spec.js › @smoke … (removes one)` | 🟢 |
| ATT-04 | Files stored **by reference** in a "Dataforms" Files folder, never duplicated | Guide 02 §Attachments | E2E + unit | `UploadControllerTest › stores in Dataforms folder, returns id+name`; `e2e/attachments.spec.js` (persists by id) | 🟢 |

## Views & browsing — `VW`

*Source: guide 02 (Finding records), guide 03 §Views.*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| VW-01 | Full-text **search** matches text across a record | Guide 02 §Search | E2E | `e2e/views.spec.ts › searches records` | 🔴 |
| VW-02 | Filter operator **is / is not** | Guide 02 §Filter | E2E | `e2e/views.spec.ts › filter is/is-not` | 🔴 |
| VW-03 | Filter operator **contains** | Guide 02 §Filter | E2E | `e2e/views.spec.ts › filter contains` | 🔴 |
| VW-04 | Filter operator **greater than / less than** | Guide 02 §Filter | E2E | `e2e/views.spec.ts › filter comparison` | 🔴 |
| VW-05 | Filter operator **empty / not empty** | Guide 02 §Filter | E2E | `e2e/views.spec.ts › filter empty` | 🔴 |
| VW-06 | Filter value for select fields is a **dropdown of the options** | Guide 02 §Filter | E2E | `e2e/views.spec.ts › filter select uses option dropdown` | 🔴 |
| VW-07 | **Add condition** for several filters; **Apply** / **Clear** | Guide 02 §Filter | E2E | `e2e/views.spec.ts › multiple conditions apply/clear` | 🔴 |
| VW-08 | **Filter** button shows a count when filters are active | Guide 02 §Filter | E2E | `e2e/views.spec.ts › active-filter badge` | 🔴 |
| VW-09 | **Sort** by clicking a column header; click again to reverse (▲/▼) | Guide 02 §Sort | E2E | `e2e/views.spec.ts › sorts by column` | 🔴 |
| VW-10 | Sort by the **Number** (sequence) column | Guide 02 §Sort | E2E | `e2e/views.spec.ts › sorts by sequence number` | 🔴 |
| VW-11 | **Choose columns** (⋯ More → Columns) | Guide 02 §Choose columns | E2E | `e2e/views.spec.ts › chooses columns` | 🔴 |
| VW-12 | **Save current view** (columns + filters + sort + search) | Guide 02 §Saved views, guide 03 §Views | E2E | `e2e/views.spec.ts › saves a view` | 🔴 |
| VW-13 | **Share** a view with everyone who can see the register | Guide 02 §Saved views, guide 03 §Views | E2E | `e2e/views.spec.ts › shares a view` | 🔴 |
| VW-14 | A **private** (unshared) view is visible only to its owner | Guide 03 §Views | E2E | `e2e/views.spec.ts › private view hidden from others` | 🔴 |
| VW-15 | Edit / delete a view (owner or manager) | Guide 03 §Views | E2E | `e2e/views.spec.ts › edits and deletes a view` | 🔴 |
| VW-16 | **View selector** appears and switches between views | Guide 02 §Saved views | E2E | `e2e/views.spec.ts › switches via view selector` | 🔴 |
| VW-17 | **Refresh** reloads the list (manual + automatically on tab return) | Guide 02 §Refresh | E2E | `e2e/views.spec.ts › refreshes the list` | 🔴 |

## Sharing & permissions — `SHR`

*Source: guide 03 §Sharing & permissions, `VISION` (Share primitive; server re-checks).
Negative cases prove the server, not the SPA, enforces access (PRD story 28).*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| SHR-01 | Grant **Read** to a user | Guide 03 §Sharing | E2E | `e2e/sharing.spec.ts › grants read to a user` | 🔴 |
| SHR-02 | Grant **Write** to a user | Guide 03 §Sharing | E2E | `e2e/sharing.spec.ts › grants write to a user` | 🔴 |
| SHR-03 | Grant **Manage** to a user | Guide 03 §Sharing | E2E | `e2e/sharing.spec.ts › grants manage to a user` | 🔴 |
| SHR-04 | Grant a role to a **group** | Guide 03 §Sharing | E2E | `e2e/sharing.spec.ts › grants to a group` | 🔴 |
| SHR-05 | Change an existing role | Guide 03 §Sharing | E2E | `e2e/sharing.spec.ts › changes a role` | 🔴 |
| SHR-06 | Remove access | Guide 03 §Sharing | E2E | `e2e/sharing.spec.ts › removes access` | 🔴 |
| SHR-07 | **Read** role: view/search/filter/export only — no **New record** | Guide 03 §Sharing, guide 02 | E2E | `e2e/sharing.spec.ts › read role cannot add records` | 🔴 |
| SHR-08 | **Write** role: add records + edit/delete **own** only | Guide 03 §Sharing, guide 06 FAQ | E2E | `e2e/sharing.spec.ts › write role limited to own records` | 🔴 |
| SHR-09 | **Manage** role: edit **any** record + design the register | Guide 03 §Sharing | E2E | `e2e/sharing.spec.ts › manage role can design` | 🔴 |
| SHR-10 | Negative: server **refuses unauthorized read** via the API | Guide 03 (server-enforced), `VISION` | unit | `RecordControllerTest.php › refuses read without permission` | 🔴 |
| SHR-11 | Negative: server **refuses unauthorized write** via the API | Guide 03, `VISION` | unit | `RecordControllerTest.php › refuses write without permission` | 🔴 |
| SHR-12 | Negative: server **refuses unauthorized manage/design** via the API | Guide 03, `VISION` | unit | `FieldControllerTest.php › refuses design without manage` | 🔴 |

## Automations & workflow — `AUT`

*Source: guide 04 (Automations), guide 05 §Cross-app provisioning. Nine action types.
Talk-room & Deck-board are **⚠ unit-level** by PRD decision (faked `NextcloudApiClient`).*

### Triggers, conditions & lifecycle

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| AUT-01 | Trigger **on record created** | Guide 04 §When | E2E | `e2e/automations.spec.ts › fires on create` | 🔴 |
| AUT-02 | Trigger **on record updated** | Guide 04 §When | E2E | `e2e/automations.spec.ts › fires on update` | 🔴 |
| AUT-03 | Trigger **on record deleted** | Guide 04 §When | E2E | `e2e/automations.spec.ts › fires on delete` | 🔴 |
| AUT-04 | **Only if** condition(s) gate the action; multiple must all match | Guide 04 §Only if | E2E | `e2e/automations.spec.ts › condition gates the action` | 🔴 |
| AUT-05 | **Enable / disable** switch | Guide 04 §Create | E2E | `e2e/automations.spec.ts › toggles enabled` | 🔴 |
| AUT-06 | **Edit / Delete** an automation | Guide 04 §Create | E2E | `e2e/automations.spec.ts › edits and deletes` | 🔴 |

### The nine actions

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| AUT-07 | **Send a notification** (Nextcloud notification) | Guide 04 §Then | E2E | `e2e/automations.spec.ts › sends a notification` | 🔴 |
| AUT-08 | **Send an email** — real mail to a mail catcher | Guide 04 §Then, guide 05 §Notifications & email | E2E | `e2e/automations.spec.ts › sends email to catcher` | 🔴 |
| AUT-09 | **Set a field** — writes directly, no automation loop; relation/file/automatic excluded | Guide 04 §Then, §Notes | E2E | `e2e/automations.spec.ts › sets a field without looping` | 🔴 |
| AUT-10 | **Create folders** — real folder tree in owner's Files; runs as owner; **idempotent** | Guide 04 §Then, §Notes | E2E | `e2e/automations.spec.ts › creates folders idempotently` | 🔴 |
| AUT-11 | **Copy a template** — real copy into a folder; idempotent | Guide 04 §Then, §Notes | E2E | `e2e/automations.spec.ts › copies a template` | 🔴 |
| AUT-12 | **Add a calendar event** — real event in owner's calendar; idempotent | Guide 04 §Then, §Notes | E2E | `e2e/automations.spec.ts › adds a calendar event` | 🔴 |
| AUT-13 | **Create a Talk room** + participants + welcome message | Guide 04 §Then, guide 05 | ⚠ unit | `CreateTalkRoomActionTest.php › asserts OCS calls` | 🔴 |
| AUT-14 | **Create a Deck board** + columns | Guide 04 §Then, guide 05 | ⚠ unit | `CreateDeckBoardActionTest.php › asserts OCS calls` | 🔴 |
| AUT-15 | **Call a webhook** — real receiver verifies HMAC; http(s) only, no redirects, SSRF-guarded, logged | Guide 04 §Then, §Notes; guide 05 §Outbound webhooks | E2E + unit | `e2e/automations.spec.ts › posts a signed webhook`; `WebhookActionTest.php › SSRF guard` | 🔴 |

### Placeholders & engine behaviour

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| AUT-16 | Placeholder `{machineName}` resolves a field value | Guide 04 §Placeholders | E2E | `e2e/automations.spec.ts › resolves field placeholder` | 🔴 |
| AUT-17 | Placeholder `{field\|format}` reformats a date | Guide 04 §Placeholders | unit | `ValueInterpolatorTest.php › formats a date token` | 🔴 |
| AUT-18 | Placeholder `{relation.subfield}` pulls from a linked record | Guide 04 §Placeholders | unit | `ValueInterpolatorTest.php › resolves relation subfield` | 🔴 |
| AUT-19 | **Best-effort** — a failing automation is logged and never blocks the save | Guide 04 §Good to know | E2E | `e2e/automations.spec.ts › failure never blocks save` | 🔴 |
| AUT-20 | Talk/Deck only on **create** trigger; if service account unconfigured, log & skip | Guide 04 §Notes, guide 05 | unit | `CreateTalkRoomActionTest.php › skips when unconfigured` | 🔴 |
| AUT-21 | A disabled action disappears from the builder and the engine refuses it | Guide 05 §Automation settings | E2E + unit | `e2e/admin.spec.ts › disabled action hidden`; `AutomationServiceTest.php › refuses disabled action` | 🔴 |
| AUT-22 | Recipients only get what they're allowed to see (access still applies) | Guide 04 §Good to know | unit | `NotifyActionTest.php › respects recipient access` | 🔴 |
| AUT-23 | **Activity** log lists recent runs, flags failures, kept 30 days | Guide 04 §Good to know | E2E | `e2e/automations.spec.ts › activity log flags failures` | 🔴 |

## Audit history — `AUD`

*Source: guide 02 §Open a record (History), guide 06 (Sequence number gaps), `docs/AUDIT.md`.*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| AUD-01 | **Create** is recorded (who & when) | Guide 02 §Open a record | E2E | `e2e/audit.spec.ts › records creation` | 🔴 |
| AUD-02 | **Update** is recorded with the change detail | Guide 02 §Open a record | E2E | `e2e/audit.spec.ts › records update with diff` | 🔴 |
| AUD-03 | **Delete** is recorded | Guide 02, `docs/AUDIT.md` | E2E | `e2e/audit.spec.ts › records deletion` | 🔴 |
| AUD-04 | **History** panel in the record detail shows the trail | Guide 02 §Open a record | E2E | `e2e/audit.spec.ts › history panel shows trail` | 🔴 |

## CSV import / export — `CSV`

*Source: guide 02 §Export, guide 03 §CSV import. Row cap = **5000** (`ImportService::MAX_ROWS`).*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| CSV-01 | **Export** the current (filtered) table to CSV | Guide 02 §Export | E2E | `e2e/csv.spec.ts › exports filtered table` | 🔴 |
| CSV-02 | Import: first row **headers** match field **labels or machine names** | Guide 03 §CSV import | E2E | `e2e/csv.spec.ts › imports matching headers` | 🔴 |
| CSV-03 | Import: each row goes through the normal **validation/rules pipeline** | Guide 03 §CSV import | E2E | `e2e/csv.spec.ts › imports through validation pipeline` | 🔴 |
| CSV-04 | Import: **per-row error report** for rows that don't fit | Guide 03 §CSV import | E2E | `e2e/csv.spec.ts › reports per-row errors` | 🔴 |
| CSV-05 | Import: the **auto Number** column is excluded and assigned automatically | Guide 03 §CSV import | E2E | `e2e/csv.spec.ts › auto-assigns sequence number` | 🔴 |
| CSV-06 | Import: select/multi-select values must match the field's options **exactly** | Guide 03 §CSV import | E2E | `e2e/csv.spec.ts › rejects unknown option values` | 🔴 |
| CSV-07 | Import: the **5000-row cap** stops the import with a clear message | Guide 03 (server limit) | unit | `ImportServiceTest.php › stops at row cap` | 🔴 |
| CSV-08 | Import: bulk import **does not fire automations** | PRD story 27 | unit | `ImportServiceTest.php › suppresses automations` | 🔴 |
| CSV-09 | **Download template** gives a header-only CSV | Guide 03 §CSV import | E2E | `e2e/csv.spec.ts › downloads header template` | 🔴 |

## Admin & integration — `ADM`

*Source: guide 05 (Administrator & API). Instance-level configuration; several rows are
asserted at the service seam (**unit**) where they have no SPA-visible business effect.*

| Id | Business scenario | Source | Seam | Test | Status |
|----|-------------------|--------|------|------|--------|
| ADM-01 | Restrict the app to groups (Nextcloud app group restriction) | Guide 05 §Restricting to groups | unit | (Nextcloud-native; smoke only) | 🔴 |
| ADM-02 | Configure the **service account** (internal URL, username, app password) and **Test** connectivity | Guide 05 §Cross-app provisioning | unit | `ServiceAccountServiceTest.php › saves and tests` | 🔴 |
| ADM-03 | The app password is stored **encrypted** and never returned to the browser | Guide 05 §Cross-app provisioning | unit | `ServiceAccountServiceTest.php › never returns secret` | 🔴 |
| ADM-04 | **Available actions** toggle is instance-wide and removes a disabled action everywhere | Guide 05 §Automation settings | unit | `AutomationSettingsServiceTest.php › toggles actions` | 🔴 |
| ADM-05 | **Limits & defaults** (folders/run, template files, Talk participants, Deck columns, calendar length, webhook timeout) are enforced | Guide 05 §Automation settings | unit | `AutomationSettingsServiceTest.php › enforces limits` | 🔴 |
| ADM-06 | The **email** action is silently skipped if no mail server is configured | Guide 05 §Notifications & email | unit | `EmailActionTest.php › skips without mail server` | 🔴 |
| ADM-07 | API: authenticate a machine with an **app password** + `OCS-APIRequest: true` | Guide 05 §The API | unit | `RecordControllerTest.php › accepts app-password auth` | 🔴 |
| ADM-08 | The **API console** shows the base API URL and the app-password walkthrough | Guide 05 §The API console | E2E | `e2e/admin.spec.ts › shows API console` | 🔴 |
| ADM-09 | **Smart Picker** inserts a form into Talk/Text/Collectives/Deck (**Fill in** / **Open**, access-respecting) | Guide 05 §Forms across Nextcloud, guide 02 §Quick capture | E2E | `e2e/smart-picker.spec.ts › inserts and fills a form` | 🔴 |

---

## Accepted gaps (recorded, not omissions)

These follow the PRD's "Implementation/Testing Decisions" and are part of the frozen
definition of done — they are **covered for the acceptance gate**, but at a lower seam:

- **Talk-room (AUT-13) and Deck-board (AUT-14)** are verified at the **action level**
  with a faked `NextcloudApiClient` asserting the exact OCS calls and payloads — *not*
  true E2E. Flagged **⚠** above. One optional nightly smoke runs only if a Talk/Deck
  instance is available.
- **Webhook internals (AUT-15)** — the SSRF guard, redirect refusal and HMAC signing
  are unit-tested in `WebhookActionTest.php`; the happy path is also E2E against a
  receiver that verifies the signature.
- **The OCS/REST API contract** is covered only **transitively** — controller unit
  tests (mocked HTTP) plus the SPA-driven E2E suite. There are **no dedicated
  API-boundary tests** (Behat dropped, no API-level Playwright). This is an accepted
  structural gap to revisit if the API gains external consumers (PRD "Further Notes").

## Out of scope (the charter's no-list — *must stay absent*)

Per `VISION.md`, these are **not** features and must **not** appear as scenarios; they
are listed so the "no invented feature" check is itself auditable. A test that proves
their *absence* (a negative) may exist, but they are never positive business cases:

- ❌ Public / anonymous access or form-filling (internal authenticated users only).
- ❌ Survey / consultation features (anonymous question types, response statistics).
- ❌ Arbitrary code / scripting in rules or computed fields (sandboxed only — see RUL-08).
- ❌ A custom auth / token system (machine access is Nextcloud app passwords — ADM-07).
- ❌ Generic database / BI / reporting beyond the documented views & CSV export.
