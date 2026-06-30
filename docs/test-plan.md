<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# DataForms — Cahier de recette exploratoire

**Public : testeur·euse QA en recette manuelle.** Ce document liste, par capacité,
les **scénarios métier à vérifier à la main** sur une instance de recette. Ce
n'est plus une matrice de tests automatisés ni un *gate* « 100 % vert » — la
confiance automatisée est portée par le **garde-fou de couverture (« gate B »)**
décrit dans `docs/DECISIONS.md` (tout fichier de production touché par une PR doit
être couvert à 100 %). Ici, c'est l'**acceptation humaine** : on exécute, on
observe, on consigne.

> **Convention vivante.** Quand une fonctionnalité ou un test évolue, on met à jour
> ce cahier dans la **même PR**. Il reste le miroir fidèle du produit.

## Posture exploratoire

- **Ne te limite pas au scripté.** Les scénarios ci-dessous sont le *socle* à
  couvrir ; suis ton flair, sors des sentiers, tente les cas limites et les
  enchaînements inhabituels. Tout comportement surprenant se note (colonne *Notes*).
- **Pense « utilisateur ».** Chaque ligne décrit un comportement observable. Les
  **étapes détaillées** sont dans le guide cité en colonne *Réf. guide*
  (`docs/guides/01…06`) — le cahier dit *quoi* vérifier, le guide dit *comment*.
- **Consigne le résultat** dans la colonne **OK/KO** (`☐` → `OK` / `KO`) et décris
  tout écart dans *Notes QA* (étapes pour reproduire, capture, gravité ressentie).

## Environnement de recette

- **URL** : http://localhost:8080 — Nextcloud avec l'app DataForms activée.
- **Comptes** (chacun dans un onglet/navigation privée distincte pour ne pas
  mélanger les sessions) :

  | Compte | Mot de passe | Rôle | Sert à |
  |--------|--------------|------|--------|
  | `admin` | `admin` | Administrateur | Réglages d'admin, compte de service, tout |
  | `qa` | `qa-password` | Utilisateur standard | Création de registres, droits Read/Write/Manage |
  | `qa2` | `qa-password` | Utilisateur standard | **Destinataire de partage** (tests inter-comptes) |

- DataForms est **interne / authentifié uniquement** : pas d'accès public/anonyme à tester.

## Chartes d'exploration (au-delà du scripté)

Sessions libres recommandées, en plus des scénarios :

1. **Permissions** : pour chaque rôle (lecture / écriture / gestion) et chaque
   partage, vérifier que l'UI *et* le serveur refusent ce qui n'est pas autorisé
   (forcer une action interdite doit renvoyer 403/404, pas planter).
2. **Logique conditionnelle** : empiler règles (show/hide, require-if, set-value,
   validation, computed) et conditions d'automatisation, et chercher les
   incohérences entre le navigateur et le re-contrôle serveur.
3. **Données limites** : champs vides, très longs, caractères spéciaux/bidi,
   nombres extrêmes, dates invalides, imports CSV malformés ou volumineux.
4. **Cycle de vie** : créer → éditer → partager → automatiser → supprimer un
   registre, en vérifiant que l'historique et les effets de bord suivent.

## Comment lire les tableaux

| Colonne | Sens |
|---------|------|
| **Id** | Identifiant stable du scénario (`<CAP>-NN`). Sert d'ancre de traçabilité. |
| **Scénario à vérifier** | Le comportement observable, du point de vue utilisateur. |
| **Réf. guide** | Où lire les étapes détaillées (guide utilisateur ou `VISION`). |
| **OK/KO** | À remplir : `OK` si conforme, `KO` sinon. |
| **Notes QA** | Observations, écarts, étapes de repro. |

L'**annexe « Couverture automatisée »** (fin du document) indique, pour chaque Id,
ce qui est déjà vérifié par les tests — pour concentrer l'effort manuel là où il
compte le plus (et non comme un critère de blocage).

## Registers — `REG`

*Source: guide 01 (Getting started), guide 03 §Sharing, `VISION` (Register primitive).*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| REG-01 | Create a register (title, optional description, colour); creator becomes its **manager** | Guide 01 §Creating a register | ☐ | |
| REG-02 | Edit a register's metadata (title / description / colour) | Guide 01 | ☐ | |
| REG-03 | Delete a register (removes its records) | Guide 03 §Fields (deletion semantics), `VISION` | ☐ | |
| REG-04 | Dashboard shows register **cards** with record counts | Guide 01 §What you see | ☐ | |
| REG-05 | Sidebar lists registers; **Favourites** pinned above **All registers** | Guide 01 §What you see | ☐ | |
| REG-06 | Star / unstar a register (favourite toggle) | Guide 01 §What you see | ☐ | |
| REG-07 | **Copy link** copies a direct link to the register and the open tab | Guide 01 §Header buttons | ☐ | |
| REG-08 | **Deep link** (`?register=&form=`) opens straight into a register/tab/form entry | Guide 05 §Deep links | ☐ | |
| REG-09 | Manager-only tabs (Fields/Forms/Rules/Automations) hidden from viewer & contributor | Guide 01 §tabs, guide 06 FAQ | ☐ | |

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

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| FLD-01 | Add a field: label, optional **help text**, optional **default**, **Required**, **Unique values**, order | Guide 03 §Add a field | ☐ | |
| FLD-02 | Machine name is generated from the label and is **fixed** afterwards (immutable) | Guide 03 §Common settings | ☐ | |
| FLD-03 | Reorder fields with the up/down controls | Guide 03 §Common settings | ☐ | |
| FLD-04 | **Required** is enforced when saving a record | Guide 03; guide 02 §Add a record | ☐ | |
| FLD-05 | **Unique values** are enforced (duplicate refused) | Guide 03 §Common settings | ☐ | |
| FLD-06 | **Default value** is applied to a new record | Guide 03 §Common settings | ☐ | |
| FLD-07 | Deleting a field removes its stored values but **does not** delete records | Guide 03 §Fields (closing note) | ☐ | |

### The 20 field types

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| FLD-08 | **Text** field | Guide 03 §Field types | ☐ | |
| FLD-09 | **Long text** field | Guide 03 §Field types | ☐ | |
| FLD-10 | **Yes/No** field | Guide 03 §Field types | ☐ | |
| FLD-11 | **Number** field (min/max, decimals) | Guide 03 §Field types | ☐ | |
| FLD-12 | **Currency** field | Guide 03 §Field types | ☐ | |
| FLD-13 | **Percentage** field | Guide 03 §Field types | ☐ | |
| FLD-14 | **Date** field | Guide 03 §Field types | ☐ | |
| FLD-15 | **Date & time** field | Guide 03 §Field types | ☐ | |
| FLD-16 | **Time** field | Guide 03 §Field types | ☐ | |
| FLD-17 | **Single select** (options, one per line) | Guide 03 §Field types | ☐ | |
| FLD-18 | **Multi select** (options) | Guide 03 §Field types | ☐ | |
| FLD-19 | **Email** (format-checked) | Guide 03 §Field types | ☐ | |
| FLD-20 | **URL** (format-checked) | Guide 03 §Field types | ☐ | |
| FLD-21 | **Phone** (format-checked) | Guide 03 §Field types | ☐ | |
| FLD-22 | **User** (Nextcloud) | Guide 03 §Field types | ☐ | |
| FLD-23 | **Group** (Nextcloud) | Guide 03 §Field types | ☐ | |
| FLD-24 | **Relation** (link to another register) | Guide 03 §Field types, §Relation | ☐ | |
| FLD-25 | **File attachment** (one or more files via Files) | Guide 03 §Field types, §File | ☐ | |
| FLD-26 | **Computed** (read-only expression: `sum,round,if,concat,min,max,abs,len,lower,upper`) | Guide 03 §Computed | ☐ | |
| FLD-27 | **Automatic** field type (system-filled) | Guide 03 §Automatic | ☐ | |

### Automatic-field variants & select grouping

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| FLD-28 | Automatic: **Sequence number** (1, 2, 3… per register) | Guide 03 §Automatic, guide 06 | ☐ | |
| FLD-29 | Automatic: **Created date** | Guide 03 §Automatic | ☐ | |
| FLD-30 | Automatic: **Last-updated date** | Guide 03 §Automatic | ☐ | |
| FLD-31 | Automatic: **Created-by** | Guide 03 §Automatic | ☐ | |
| FLD-32 | Select/multi-select **Group options in the form** ("by leading code") → collapsible, searchable picker | Guide 03 §Worth knowing, guide 02 §Long option lists | ☐ | |

## Forms — `FRM`

*Source: guide 03 §Forms, `VISION` (Form = a *write* lens; one renderer).*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| FRM-01 | Create a form in the drag-and-drop two-pane builder | Guide 03 §The drag-and-drop builder | ☐ | |
| FRM-02 | Organise fields into **sections**; rename a section inline | Guide 03 §The drag-and-drop builder | ☐ | |
| FRM-03 | Reorder sections (▲/▼) and reorder/move fields between sections | Guide 03 §The drag-and-drop builder | ☐ | |
| FRM-04 | Remove a placed field (drag back to palette / ✕) | Guide 03 §The drag-and-drop builder | ☐ | |
| FRM-05 | Add a field with the **+** button (keyboard, no mouse) | Guide 03 §The drag-and-drop builder | ☐ | |
| FRM-06 | Several forms per register all feed the **same** records | Guide 03 §Forms, `VISION` | ☐ | |
| FRM-07 | A saved form appears in the **New record** menu | Guide 03 §The drag-and-drop builder, guide 02 | ☐ | |
| FRM-08 | Without a form, **New record** shows every field | Guide 03 §Forms, guide 02 | ☐ | |

## Records — `REC`

*Source: guide 02 (Everyday use), `VISION` (Record primitive).*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| REC-01 | Create a record via **Blank (all fields)** | Guide 02 §Add a record | ☐ | |
| REC-02 | Create a record via a named **form** | Guide 02 §Add a record | ☐ | |
| REC-03 | **New record** menu offers Blank + each named form | Guide 02 §Add a record | ☐ | |
| REC-04 | Required fields are marked and checked on **Save** | Guide 02 §Add a record | ☐ | |
| REC-05 | Computed & automatic fields are filled on create | Guide 02 §Add a record | ☐ | |
| REC-06 | Edit a record via the full form (⋯ → Edit) | Guide 02 §Edit a record | ☐ | |
| REC-07 | **Double-click a cell** to edit in place (text/number/date/single-select/Yes-No); Enter/blur saves | Guide 02 §Edit a record | ☐ | |
| REC-08 | **Esc** cancels an inline edit | Guide 02 §Edit a record | ☐ | |
| REC-09 | Delete a record | Guide 02 §Edit a record (⋯ menu) | ☐ | |
| REC-10 | Open the read-only **detail** — every field incl. linked records & attachments | Guide 02 §Open a record | ☐ | |
| REC-11 | Contributor can edit/delete **only their own** records; manager can edit any | Guide 02 (callout), guide 06 FAQ | ☐ | |

## Rules (conditional logic) — `RUL`

*Source: guide 03 §Rules, `VISION` (Rule primitive; one rule language, sandboxed).
Parity is gated by the shared `rule-cases.json` JS/PHP fixture (PRD seam #5).*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| RUL-01 | **Show / hide** a field when conditions are met (live) | Guide 03 §Rules | ☐ | |
| RUL-02 | **Require** a field only when relevant (require-if) | Guide 03 §Rules | ☐ | |
| RUL-03 | **Set a default** value | Guide 03 §Rules | ☐ | |
| RUL-04 | **Validate** (range / pattern / cross-field) with a custom message | Guide 03 §Rules | ☐ | |
| RUL-05 | **Compute** a value from other fields | Guide 03 §Rules | ☐ | |
| RUL-06 | Multiple conditions must **all** match | Guide 03; guide 04 §Only if | ☐ | |
| RUL-07 | Rules are **re-checked on the server** at save (browser/server can't disagree) | Guide 03 §Rules, `VISION` | ☐ | |
| RUL-08 | Expressions are **sandboxed** — no scripting / `eval` (negative) | Guide 03, `VISION` no-list | ☐ | |

## Relations — `REL`

*Source: guide 03 §Relation; delete policies confirmed in `RecordService.php`
(`null` = clear, `block` = prevent, `cascade`).*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| REL-01 | Link a record to a record in another register | Guide 03 §Relation | ☐ | |
| REL-02 | Allow **several** links (multi-relation) | Guide 03 §Relation | ☐ | |
| REL-03 | Choose the **display field** shown for a linked record | Guide 03 §Relation | ☐ | |
| REL-04 | Delete policy **clear the link** (`null`) when the linked record is deleted | Guide 03 §Relation | ☐ | |
| REL-05 | Delete policy **prevent deletion** (`block`) | Guide 03 §Relation | ☐ | |
| REL-06 | Delete policy **cascade-delete** the referencing record | Guide 03 §Relation | ☐ | |
| REL-07 | Cross-register **read-permission guard** — no linked data leaks to users without read on the target | `VISION` (server re-checks); PRD story 24 | ☐ | |

## Attachments — `ATT`

*Source: guide 02 §Attachments, guide 03 §File attachment.*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| ATT-01 | Upload a single file to a file field | Guide 02 §Attachments | ☐ | |
| ATT-02 | Upload **multiple** files | Guide 02 §Attachments | ☐ | |
| ATT-03 | Remove an attachment (✕) | Guide 02 §Attachments | ☐ | |
| ATT-04 | Files stored **by reference** in a "Dataforms" Files folder, never duplicated | Guide 02 §Attachments | ☐ | |

## Views & browsing — `VW`

*Source: guide 02 (Finding records), guide 03 §Views.*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| VW-01 | Full-text **search** matches text across a record | Guide 02 §Search | ☐ | |
| VW-02 | Filter operator **is / is not** | Guide 02 §Filter | ☐ | |
| VW-03 | Filter operator **contains** | Guide 02 §Filter | ☐ | |
| VW-04 | Filter operator **greater than / less than** | Guide 02 §Filter | ☐ | |
| VW-05 | Filter operator **empty / not empty** | Guide 02 §Filter | ☐ | |
| VW-06 | Filter value for select fields is a **dropdown of the options** | Guide 02 §Filter | ☐ | |
| VW-07 | **Add condition** for several filters; **Apply** / **Clear** | Guide 02 §Filter | ☐ | |
| VW-08 | **Filter** button shows a count when filters are active | Guide 02 §Filter | ☐ | |
| VW-09 | **Sort** by clicking a column header; click again to reverse (▲/▼) | Guide 02 §Sort | ☐ | |
| VW-10 | Sort by the **Number** (sequence) column | Guide 02 §Sort | ☐ | |
| VW-11 | **Choose columns** (⋯ More → Columns) | Guide 02 §Choose columns | ☐ | |
| VW-12 | **Save current view** (columns + filters + sort + search) | Guide 02 §Saved views, guide 03 §Views | ☐ | |
| VW-13 | **Share** a view with everyone who can see the register | Guide 02 §Saved views, guide 03 §Views | ☐ | |
| VW-14 | A **private** (unshared) view is visible only to its owner | Guide 03 §Views | ☐ | |
| VW-15 | Edit / delete a view (owner or manager) | Guide 03 §Views | ☐ | |
| VW-16 | **View selector** appears and switches between views | Guide 02 §Saved views | ☐ | |
| VW-17 | **Refresh** reloads the list (manual + automatically on tab return) | Guide 02 §Refresh | ☐ | |

## Sharing & permissions — `SHR`

*Source: guide 03 §Sharing & permissions, `VISION` (Share primitive; server re-checks).
Negative cases prove the server, not the SPA, enforces access (PRD story 28).*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| SHR-01 | Grant **Read** to a user | Guide 03 §Sharing | ☐ | |
| SHR-02 | Grant **Write** to a user | Guide 03 §Sharing | ☐ | |
| SHR-03 | Grant **Manage** to a user | Guide 03 §Sharing | ☐ | |
| SHR-04 | Grant a role to a **group** | Guide 03 §Sharing | ☐ | |
| SHR-05 | Change an existing role | Guide 03 §Sharing | ☐ | |
| SHR-06 | Remove access | Guide 03 §Sharing | ☐ | |
| SHR-07 | **Read** role: view/search/filter/export only — no **New record** | Guide 03 §Sharing, guide 02 | ☐ | |
| SHR-08 | **Write** role: add records + edit/delete **own** only | Guide 03 §Sharing, guide 06 FAQ | ☐ | |
| SHR-09 | **Manage** role: edit **any** record + design the register | Guide 03 §Sharing | ☐ | |
| SHR-10 | Negative: server **refuses unauthorized read** via the API | Guide 03 (server-enforced), `VISION` | ☐ | |
| SHR-11 | Negative: server **refuses unauthorized write** via the API | Guide 03, `VISION` | ☐ | |
| SHR-12 | Negative: server **refuses unauthorized manage/design** via the API | Guide 03, `VISION` | ☐ | |

## Automations & workflow — `AUT`

*Source: guide 04 (Automations), guide 05 §Cross-app provisioning. Nine action types.
Talk-room & Deck-board are **⚠ unit-level** by PRD decision (faked `NextcloudApiClient`).*

### Triggers, conditions & lifecycle

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| AUT-01 | Trigger **on record created** | Guide 04 §When | ☐ | |
| AUT-02 | Trigger **on record updated** | Guide 04 §When | ☐ | |
| AUT-03 | Trigger **on record deleted** | Guide 04 §When | ☐ | |
| AUT-04 | **Only if** condition(s) gate the action; multiple must all match | Guide 04 §Only if | ☐ | |
| AUT-05 | **Enable / disable** switch | Guide 04 §Create | ☐ | |
| AUT-06 | **Edit / Delete** an automation | Guide 04 §Create | ☐ | |

### The nine actions

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| AUT-07 | **Send a notification** (Nextcloud notification) | Guide 04 §Then | ☐ | |
| AUT-08 | **Send an email** — real mail to a mail catcher | Guide 04 §Then, guide 05 §Notifications & email | ☐ | |
| AUT-09 | **Set a field** — writes directly, no automation loop; relation/file/automatic excluded | Guide 04 §Then, §Notes | ☐ | |
| AUT-10 | **Create folders** — real folder tree in owner's Files; runs as owner; **idempotent** | Guide 04 §Then, §Notes | ☐ | |
| AUT-11 | **Copy a template** — real copy into a folder; idempotent | Guide 04 §Then, §Notes | ☐ | |
| AUT-12 | **Add a calendar event** — real event in owner's calendar; idempotent | Guide 04 §Then, §Notes | ☐ | |
| AUT-13 | **Create a Talk room** + participants + welcome message | Guide 04 §Then, guide 05 | ☐ | |
| AUT-14 | **Create a Deck board** + columns | Guide 04 §Then, guide 05 | ☐ | |
| AUT-15 | **Call a webhook** — real receiver verifies HMAC; http(s) only, no redirects, SSRF-guarded, logged | Guide 04 §Then, §Notes; guide 05 §Outbound webhooks | ☐ | |

### Placeholders & engine behaviour

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| AUT-16 | Placeholder `{machineName}` resolves a field value | Guide 04 §Placeholders | ☐ | |
| AUT-17 | Placeholder `{field\|format}` reformats a date | Guide 04 §Placeholders | ☐ | |
| AUT-18 | Placeholder `{relation.subfield}` pulls from a linked record | Guide 04 §Placeholders | ☐ | |
| AUT-19 | **Best-effort** — a failing automation is logged and never blocks the save | Guide 04 §Good to know | ☐ | |
| AUT-20 | Talk/Deck only on **create** trigger; if service account unconfigured, log & skip | Guide 04 §Notes, guide 05 | ☐ | |
| AUT-21 | A disabled action disappears from the builder and the engine refuses it | Guide 05 §Automation settings | ☐ | |
| AUT-22 | Recipients only get what they're allowed to see (access still applies) | Guide 04 §Good to know | ☐ | |
| AUT-23 | **Activity** log lists recent runs, flags failures, kept 30 days | Guide 04 §Good to know | ☐ | |

## Audit history — `AUD`

*Source: guide 02 §Open a record (History), guide 06 (Sequence number gaps), `docs/AUDIT.md`.*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| AUD-01 | **Create** is recorded (who & when) | Guide 02 §Open a record | ☐ | |
| AUD-02 | **Update** is recorded with the change detail | Guide 02 §Open a record | ☐ | |
| AUD-03 | **Delete** is recorded | Guide 02, `docs/AUDIT.md` | ☐ | |
| AUD-04 | **History** panel in the record detail shows the trail | Guide 02 §Open a record | ☐ | |

## CSV import / export — `CSV`

*Source: guide 02 §Export, guide 03 §CSV import. Row cap = **5000** (`ImportService::MAX_ROWS`).*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| CSV-01 | **Export** the current (filtered) table to CSV | Guide 02 §Export | ☐ | |
| CSV-02 | Import: first row **headers** match field **labels or machine names** | Guide 03 §CSV import | ☐ | |
| CSV-03 | Import: each row goes through the normal **validation/rules pipeline** | Guide 03 §CSV import | ☐ | |
| CSV-04 | Import: **per-row error report** for rows that don't fit | Guide 03 §CSV import | ☐ | |
| CSV-05 | Import: the **auto Number** column is excluded and assigned automatically | Guide 03 §CSV import | ☐ | |
| CSV-06 | Import: select/multi-select values must match the field's options **exactly** | Guide 03 §CSV import | ☐ | |
| CSV-07 | Import: the **5000-row cap** stops the import with a clear message | Guide 03 (server limit) | ☐ | |
| CSV-08 | Import: bulk import **does not fire automations** | PRD story 27 | ☐ | |
| CSV-09 | **Download template** gives a header-only CSV | Guide 03 §CSV import | ☐ | |

## Admin & integration — `ADM`

*Source: guide 05 (Administrator & API). Instance-level configuration; several rows are
asserted at the service seam (**unit**) where they have no SPA-visible business effect.*

| Id | Scénario à vérifier (manuellement) | Réf. guide (étapes détaillées) | OK/KO | Notes QA |
|----|----------------------------------|-------------------------------|:-----:|---------|
| ADM-01 | Restrict the app to groups (Nextcloud app group restriction) | Guide 05 §Restricting to groups | ☐ | |
| ADM-02 | Configure the **service account** (internal URL, username, app password) and **Test** connectivity | Guide 05 §Cross-app provisioning | ☐ | |
| ADM-03 | The app password is stored **encrypted** and never returned to the browser | Guide 05 §Cross-app provisioning | ☐ | |
| ADM-04 | **Available actions** toggle is instance-wide and removes a disabled action everywhere | Guide 05 §Automation settings | ☐ | |
| ADM-05 | **Limits & defaults** (folders/run, template files, Talk participants, Deck columns, calendar length, webhook timeout) are enforced | Guide 05 §Automation settings | ☐ | |
| ADM-06 | The **email** action is silently skipped if no mail server is configured | Guide 05 §Notifications & email | ☐ | |
| ADM-07 | API: authenticate a machine with an **app password** + `OCS-APIRequest: true` | Guide 05 §The API | ☐ | |
| ADM-08 | The **API console** shows the base API URL and the app-password walkthrough | Guide 05 §The API console | ☐ | |
| ADM-09 | **Smart Picker** inserts a form into Talk/Text/Collectives/Deck (**Fill in** / **Open**, access-respecting) | Guide 05 §Forms across Nextcloud, guide 02 §Quick capture | ☐ | |

---

## Accepted gaps (recorded, not omissions)

Some behaviours are deliberately verified **at the action seam** rather than driven
through the SPA. The QA need not re-test these by hand unless probing a regression;
they are listed for transparency:

- **Talk-room (AUT-13) and Deck-board (AUT-14)** are verified at the **action level**
  with a faked `NextcloudApiClient` asserting the exact OCS calls and payloads — *not*
  true E2E. One optional nightly smoke runs only if a Talk/Deck instance is available.
- **Webhook (AUT-15)** — a real local receiver is **refused by the action's own SSRF
  guard** (private addresses are blocked by design), so a local E2E is infeasible. The
  SSRF guard, redirect refusal and HMAC signing are asserted at the action seam
  (`WebhookActionTest.php`). To recette manually, point the webhook at a **public**
  HTTPS receiver you control and confirm it gets a signed `X-DataForms-Signature`.
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


## Annexe — couverture automatisée (indicatif)

Pour information : ce que les tests vérifient déjà aujourd'hui. Sert à
**prioriser** la recette manuelle (un scénario 🔴 mérite plus d'attention
qu'un 🟢), pas à bloquer l'acceptation. Mis à jour avec les tests (gate B).

| Id | Couverture | Test |
|----|-----------|------|
| REG-01 | 🟢 automatisé | `e2e/registers.spec.js › create and delete a register` |
| REG-02 | ⚠ unit | _no SPA UI — blocked by #22_ |
| REG-03 | 🟢 automatisé | `e2e/registers.spec.js › create and delete a register` |
| REG-04 | 🔴 non automatisé | `e2e/registers.spec.ts › dashboard lists register cards` |
| REG-05 | 🔴 non automatisé | `e2e/registers.spec.ts › sidebar groups favourites first` |
| REG-06 | 🟢 automatisé | `e2e/registers.spec.js › favourite a register` |
| REG-07 | 🔴 non automatisé | `e2e/registers.spec.ts › copies a deep link` |
| REG-08 | 🔴 non automatisé | `e2e/registers.spec.ts › deep link opens form entry` |
| REG-09 | 🔴 non automatisé | `e2e/registers.spec.ts › hides design tabs from non-managers` |
| FLD-01 | 🔴 non automatisé | `e2e/fields.spec.ts › adds a field with common settings` |
| FLD-02 | 🔴 non automatisé | `e2e/fields.spec.ts › machine name is immutable` |
| FLD-03 | 🔴 non automatisé | `e2e/fields.spec.ts › reorders fields` |
| FLD-04 | 🔴 non automatisé | `e2e/fields.spec.ts › enforces required on save` |
| FLD-05 | 🔴 non automatisé | `e2e/fields.spec.ts › enforces uniqueness` |
| FLD-06 | 🔴 non automatisé | `e2e/fields.spec.ts › applies default value` |
| FLD-07 | 🔴 non automatisé | `e2e/fields.spec.ts › deleting a field keeps records` |
| FLD-08 | 🔴 non automatisé | `e2e/fields.spec.ts › text field` |
| FLD-09 | 🔴 non automatisé | `e2e/fields.spec.ts › long text field` |
| FLD-10 | 🔴 non automatisé | `e2e/fields.spec.ts › yes-no field` |
| FLD-11 | 🔴 non automatisé | `e2e/fields.spec.ts › number field with min/max/decimals` |
| FLD-12 | 🔴 non automatisé | `e2e/fields.spec.ts › currency field` |
| FLD-13 | 🔴 non automatisé | `e2e/fields.spec.ts › percentage field` |
| FLD-14 | 🔴 non automatisé | `e2e/fields.spec.ts › date field` |
| FLD-15 | 🔴 non automatisé | `e2e/fields.spec.ts › datetime field` |
| FLD-16 | 🔴 non automatisé | `e2e/fields.spec.ts › time field` |
| FLD-17 | 🟢 automatisé | `e2e/fields.spec.js › add a single-select field with options` |
| FLD-18 | 🔴 non automatisé | `e2e/fields.spec.ts › multi select field` |
| FLD-19 | 🔴 non automatisé | `e2e/fields.spec.ts › email field validates format` |
| FLD-20 | 🔴 non automatisé | `e2e/fields.spec.ts › url field validates format` |
| FLD-21 | 🔴 non automatisé | `e2e/fields.spec.ts › phone field validates format` |
| FLD-22 | 🔴 non automatisé | `e2e/fields.spec.ts › user field` |
| FLD-23 | 🔴 non automatisé | `e2e/fields.spec.ts › group field` |
| FLD-24 | 🟢 automatisé | `e2e/relations.spec.js › @smoke link a record via a relation field` (creates a relation field) |
| FLD-25 | 🟢 automatisé | `e2e/attachments.spec.js › @smoke upload, list, remove and persist file attachments` |
| FLD-26 | 🔴 non automatisé | `e2e/fields.spec.ts › computed field` |
| FLD-27 | 🔴 non automatisé | `e2e/fields.spec.ts › automatic field type` |
| FLD-28 | 🔴 non automatisé | `e2e/fields.spec.ts › automatic sequence number` |
| FLD-29 | 🔴 non automatisé | `e2e/fields.spec.ts › automatic created date` |
| FLD-30 | 🔴 non automatisé | `e2e/fields.spec.ts › automatic last-updated date` |
| FLD-31 | 🔴 non automatisé | `e2e/fields.spec.ts › automatic created-by` |
| FLD-32 | 🔴 non automatisé | `e2e/fields.spec.ts › grouped searchable option picker` |
| FRM-01 | 🔴 non automatisé | `e2e/forms.spec.ts › builds a form by drag and drop` |
| FRM-02 | 🔴 non automatisé | `e2e/forms.spec.ts › sections and inline rename` |
| FRM-03 | 🔴 non automatisé | `e2e/forms.spec.ts › reorders sections and fields` |
| FRM-04 | 🔴 non automatisé | `e2e/forms.spec.ts › removes a placed field` |
| FRM-05 | 🔴 non automatisé | `e2e/forms.spec.ts › adds a field via plus button` |
| FRM-06 | 🔴 non automatisé | `e2e/forms.spec.ts › multiple forms feed same records` |
| FRM-07 | 🔴 non automatisé | `e2e/forms.spec.ts › saved form appears in new-record menu` |
| FRM-08 | 🔴 non automatisé | `e2e/forms.spec.ts › blank form shows all fields` |
| REC-01 | 🟢 automatisé | `e2e/records.spec.js › @smoke create, edit, and delete a record` |
| REC-02 | 🔴 non automatisé | `e2e/records.spec.ts › creates a record via form` |
| REC-03 | 🔴 non automatisé | `e2e/records.spec.ts › new-record menu lists forms` |
| REC-04 | 🔴 non automatisé | `RecordForm.spec.js › blocks save … required field is empty` (live, unit); E2E slot pending |
| REC-05 | 🔴 non automatisé | `RecordForm.spec.js › computes a field live`; `RecordServiceCreateTest`/`…ReadTest` (unit); E2E slot pending |
| REC-06 | 🟢 automatisé | `e2e/records.spec.js › @smoke create, edit, and delete a record` |
| REC-07 | 🟢 automatisé | `e2e/records.spec.js › inline-edit a cell, and cancel an inline edit with Esc` (blur path; Enter glitch tracked in #25) |
| REC-08 | 🟢 automatisé | `e2e/records.spec.js › inline-edit a cell, and cancel an inline edit with Esc` |
| REC-09 | 🟢 automatisé | `e2e/records.spec.js › @smoke create, edit, and delete a record` |
| REC-10 | 🟢 automatisé | `e2e/records.spec.js › open the read-only record detail` |
| REC-11 | 🔴 non automatisé | `RecordServicePermissionTest` (own/manage gate, unit); E2E slot pending |
| RUL-01 | 🔴 non automatisé | `rule-cases.json › show-hides-field / show-reveals-field` (JS+PHP parity); E2E slot pending |
| RUL-02 | 🔴 non automatisé | `rule-cases.json › conditional-require-* + require-*` (JS+PHP parity); E2E slot pending |
| RUL-03 | 🔴 non automatisé | `rule-cases.json › set-value-* ` (JS+PHP parity); E2E slot pending |
| RUL-04 | 🔴 non automatisé | `rule-cases.json › validate-*` (range/regex/expression, JS+PHP parity); E2E slot pending |
| RUL-05 | 🟢 automatisé | `e2e/rules.spec.js › @smoke compute rule evaluates live and is re-checked on save` |
| RUL-06 | 🔴 non automatisé | `rule-cases.json › or-conditions / require-gte-and-lte` (JS+PHP parity); E2E slot pending |
| RUL-07 | 🟢 automatisé | `e2e/rules.spec.js › @smoke compute … re-checked on save`; `RuleEvaluatorTest.php` |
| RUL-08 | 🟢 automatisé | `ExpressionEvaluatorTest.php › testRejectsUnknownFunction / testUndefinedIdentifierIsNull`; `expression.spec.js › does not expose globals` |
| REL-01 | 🟢 automatisé | `e2e/relations.spec.js › @smoke link a record via a relation field` |
| REL-02 | 🔴 non automatisé | `RecordServiceReadTest › returns multiple relation as list` (unit); E2E slot pending |
| REL-03 | 🟢 automatisé | `e2e/relations.spec.js › @smoke … show its display value` |
| REL-04 | 🔴 non automatisé | `RecordServiceDeleteTest / RecordRelationServiceTest › null policy drops dangling refs` (unit); E2E slot pending |
| REL-05 | 🔴 non automatisé | `RecordServiceDeleteTest / RecordRelationServiceTest › block policy throws` (unit); E2E slot pending |
| REL-06 | 🔴 non automatisé | `RecordServiceDeleteTest / RecordRelationServiceTest › cascade soft-deletes` (unit); E2E slot pending |
| REL-07 | 🔴 non automatisé | `RecordServiceReadTest / RecordRelationServiceTest › anonymises unreadable target` (unit); E2E slot pending |
| ATT-01 | 🟢 automatisé | `e2e/attachments.spec.js › @smoke upload, list, remove and persist` |
| ATT-02 | 🟢 automatisé | `e2e/attachments.spec.js › @smoke … (uploads two)` |
| ATT-03 | 🟢 automatisé | `e2e/attachments.spec.js › @smoke … (removes one)` |
| ATT-04 | 🟢 automatisé | `UploadControllerTest › stores in Dataforms folder, returns id+name`; `e2e/attachments.spec.js` (persists by id) |
| VW-01 | 🟢 automatisé | `e2e/views.spec.js › @smoke search, sort, filter and save a view` |
| VW-02 | 🔴 non automatisé | `RecordMapperTest › filter eq/neq` (unit); E2E slot pending |
| VW-03 | 🔴 non automatisé | `RecordMapperTest › contains filter` (unit); E2E slot pending |
| VW-04 | 🟢 automatisé | `e2e/views.spec.js › @smoke search, sort, filter and save a view` |
| VW-05 | 🔴 non automatisé | `RecordMapperTest › isEmpty/isNotEmpty` (unit); E2E slot pending |
| VW-06 | 🔴 non automatisé | `RecordsFilterBar.spec › value options for select` (unit); E2E slot pending |
| VW-07 | 🟢 automatisé | `e2e/views.spec.js › @smoke search, sort, filter and save a view` (apply); `RecordsFilterBar.spec` (clear) |
| VW-08 | 🟢 automatisé | `e2e/views.spec.js › @smoke search, sort, filter and save a view` |
| VW-09 | 🟢 automatisé | `e2e/views.spec.js › @smoke search, sort, filter and save a view` |
| VW-10 | 🔴 non automatisé | `RecordServiceListTest › auto-sequence sort remap` (unit); E2E slot pending |
| VW-11 | 🔴 non automatisé | `records/viewState.spec` + `RecordsView.spec › toggles column visibility` (unit); E2E slot pending |
| VW-12 | 🟢 automatisé | `e2e/views.spec.js › @smoke search, sort, filter and save a view` |
| VW-13 | 🔴 non automatisé | `ViewServiceTest`/`ViewMapperTest › own+shared` (unit); E2E slot pending |
| VW-14 | 🔴 non automatisé | `ViewMapperTest › own plus shared, private excluded` (unit); E2E slot pending |
| VW-15 | 🔴 non automatisé | `ViewServiceTest › owner/manager edit+delete` (unit); E2E slot pending |
| VW-16 | 🟢 automatisé | `e2e/views.spec.js › @smoke search, sort, filter and save a view` |
| VW-17 | 🔴 non automatisé | `RecordsView.spec › refreshes on tab/window return` (unit); E2E slot pending |
| SHR-01 | 🟢 automatisé | `e2e/sharing.spec.js › @smoke grant a role to a user` |
| SHR-02 | 🔴 non automatisé | `ShareServiceTest › add … read implied` (unit, any role); E2E slot pending |
| SHR-03 | 🔴 non automatisé | `ShareServiceTest › add … manage` (unit); E2E slot pending |
| SHR-04 | 🔴 non automatisé | `ShareServiceTest › updates existing group share` + `ShareMapperTest` (unit); E2E slot pending |
| SHR-05 | 🔴 non automatisé | `ShareDialog.spec › changes a role` + `ShareServiceTest › setPermissions` (unit); E2E slot pending |
| SHR-06 | 🔴 non automatisé | `ShareDialog.spec › removes a share` + `ShareServiceTest › remove` (unit); E2E slot pending |
| SHR-07 | 🔴 non automatisé | `RegisterService` permission gate (100%) + `RecordServicePermissionTest` (unit); E2E slot pending |
| SHR-08 | 🔴 non automatisé | `RecordServicePermissionTest › creator vs manager` (unit); E2E slot pending |
| SHR-09 | 🔴 non automatisé | `RegisterService.findManageable` + `RecordServicePermissionTest` (unit); E2E slot pending |
| SHR-10 | 🟢 automatisé | `RecordControllerTest › refuses unauthorized read via the API` (404) |
| SHR-11 | 🟢 automatisé | `RecordControllerTest › refuses unauthorized write via the API` (403) |
| SHR-12 | 🟢 automatisé | `FieldControllerTest › destroy maps Forbidden` (403) |
| AUT-01 | 🟢 automatisé | `e2e/automations.spec.js › @smoke a set-field automation fires on create` |
| AUT-02 | 🔴 non automatisé | `AutomationMapperTest › findActive by trigger` + `RunAutomationsJobTest` (unit); E2E slot pending |
| AUT-03 | 🔴 non automatisé | `AutomationMapperTest`/`AutomationListenerTest` (unit); E2E slot pending |
| AUT-04 | 🔴 non automatisé | `RunAutomationsJobTest › honours the condition` (unit); E2E slot pending |
| AUT-05 | 🔴 non automatisé | `AutomationMapperTest › findActive enabled-only` (unit); E2E slot pending |
| AUT-06 | 🟢 automatisé | `e2e/automations.spec.js › @smoke a set-field automation fires on create` (delete); `AutomationServiceTest › update` (unit) |
| AUT-07 | 🔴 non automatisé | `NotifyActionTest` (unit); E2E slot pending |
| AUT-08 | 🔴 non automatisé | `EmailActionTest › resolved addresses only` (unit); mail-catcher E2E pending |
| AUT-09 | 🟢 automatisé | `e2e/automations.spec.js › @smoke a set-field automation fires on create` |
| AUT-10 | 🔴 non automatisé | `ProvisionFoldersActionTest` (unit); folder-in-Files E2E pending |
| AUT-11 | 🔴 non automatisé | `ApplyTemplateActionTest › copies, idempotent` (unit); E2E slot pending |
| AUT-12 | 🔴 non automatisé | `CalendarEventActionTest` (guards, unit); real-calendar E2E pending |
| AUT-13 | ⚠ unit | `CreateTalkRoomActionTest › asserts the exact OCS calls` |
| AUT-14 | ⚠ unit | `CreateDeckBoardActionTest › creates board + stacks` |
| AUT-15 | ⚠ unit | `WebhookActionTest › HMAC signature + SSRF guard + no redirects` (a real local receiver is refused by the action’s own SSRF guard, so verified at the action seam) |
| AUT-16 | 🔴 non automatisé | `ValueInterpolatorTest › substitutes field values` (unit); E2E slot pending |
| AUT-17 | 🟢 automatisé | `ValueInterpolatorTest › formats a date token` |
| AUT-18 | 🟢 automatisé | `RelationResolverTest › enriches with target scalar subfields` |
| AUT-19 | 🔴 non automatisé | `RunAutomationsJobTest › records a failed run but continues` (unit); E2E slot pending |
| AUT-20 | 🟢 automatisé | `Create{Talk,Deck}*Test › throws when the service account is unconfigured` |
| AUT-21 | 🔴 non automatisé | `AutomationServiceTest › rejects a disabled action` + `AutomationConfigControllerTest` (unit); E2E slot pending |
| AUT-22 | 🟢 automatisé | `NotifyActionTest › notifies only existing recipients` |
| AUT-23 | 🟢 automatisé | `e2e/automations.spec.js › @smoke a set-field automation fires on create` |
| AUD-01 | 🟢 automatisé | `e2e/audit.spec.js › @smoke create + update are recorded and shown in the History panel` |
| AUD-02 | 🟢 automatisé | `e2e/audit.spec.js › @smoke create + update are recorded and shown in the History panel` |
| AUD-03 | 🔴 non automatisé | `RecordServiceDeleteTest` (delete logs history) + `HistoryMapperTest` (unit); E2E slot pending (the record is gone from the UI) |
| AUD-04 | 🟢 automatisé | `e2e/audit.spec.js › @smoke create + update are recorded and shown in the History panel`; `RecordDetail.spec` |
| CSV-01 | 🟢 automatisé | `e2e/csv.spec.js › @smoke import a CSV with a bad row, then export and download a template` |
| CSV-02 | 🟢 automatisé | `e2e/csv.spec.js › @smoke import a CSV with a bad row, then export and download a template`; `ImportServiceTest` |
| CSV-03 | 🟢 automatisé | `e2e/csv.spec.js › @smoke import a CSV with a bad row, then export and download a template`; `ImportServiceTest` |
| CSV-04 | 🟢 automatisé | `e2e/csv.spec.js › @smoke import a CSV with a bad row, then export and download a template`; `ImportServiceTest › per-row errors` |
| CSV-05 | 🔴 non automatisé | `ImportServiceTest` (auto field) + `RecordService` sequence assignment (unit); E2E slot pending |
| CSV-06 | 🔴 non automatisé | `FieldValidatorTest › select option membership` (unit, #6); E2E slot pending |
| CSV-07 | 🟢 automatisé | `ImportServiceTest › stops at the row cap with a message` |
| CSV-08 | 🟢 automatisé | `ImportServiceTest › goes through createForImport, never create` |
| CSV-09 | 🟢 automatisé | `e2e/csv.spec.js › @smoke import a CSV with a bad row, then export and download a template` |
| ADM-01 | 🔴 non automatisé | (Nextcloud-native; smoke only) |
| ADM-02 | 🔴 non automatisé | `ServiceAccountServiceTest.php › saves and tests` |
| ADM-03 | 🔴 non automatisé | `ServiceAccountServiceTest.php › never returns secret` |
| ADM-04 | 🔴 non automatisé | `AutomationSettingsServiceTest.php › toggles actions` |
| ADM-05 | 🔴 non automatisé | `AutomationSettingsServiceTest.php › enforces limits` |
| ADM-06 | 🔴 non automatisé | `EmailActionTest.php › skips without mail server` |
| ADM-07 | 🔴 non automatisé | `RecordControllerTest.php › accepts app-password auth` |
| ADM-08 | 🔴 non automatisé | `e2e/admin.spec.ts › shows API console` |
| ADM-09 | 🔴 non automatisé | `e2e/smart-picker.spec.ts › inserts and fills a form` |
