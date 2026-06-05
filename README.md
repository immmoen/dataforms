<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Dataforms

**Build registers, smart forms and views inside Nextcloud — no code required.**

Dataforms lets logged-in users design **registers** (structured, typed data
collections), the **forms** that feed them — with conditional logic — and the
**views** that browse them. It is a self-hosted, internal line-of-business tool:
a processing-activities register, a breach log, a risk register, an asset
inventory, and so on.

> 📘 **End‑user guides** (all roles, including admins): **[docs/guides/](docs/guides/README.md)**.
> Project vision & principles: [VISION.md](VISION.md).

> **Status: MVP (Phases 0–3 mostly complete).** Registers, an 18-type field
> schema editor, records with live conditional rules, a table view with search/
> filter/sort/pagination, CSV import & export, register sharing (read/write/
> manage), relations and multi-file attachments are all implemented. Standalone
> Forms, saved Views, and automation (triggers/actions) are the main remaining
> items — see the roadmap and CHANGELOG.

## Highlights (target feature set)

- **Registers** with an owned, typed field schema (text, number, currency,
  date/time, single/multi select, email/URL, user/group, file attachment,
  relation, computed and auto fields).
- **Smart forms** — a **drag-and-drop builder** (sections, field order) and
  **conditional rules** (show/hide, require-if, set-value, validation, computed)
  from a *single* rule definition evaluated **both** in the browser and on the
  server. See **[docs/FORMS_AND_USAGE.md](docs/FORMS_AND_USAGE.md)** for a plain-
  language guide to forms and how the app fits into Nextcloud.
- **Views** — paginated list/table with multi-criteria filter, sort, full-text
  search and CSV export; saved, shareable views.
- **Access control** — internal/authenticated only; per-register Read / Write /
  Manage, enforced server-side on every endpoint.
- **Automation** (later) — triggers & actions on record events.

## Tech

- Backend: PHP 8.1+, Nextcloud App Framework (`QBMapper`, OCS controllers).
- Frontend: Vue 3 + `@nextcloud/vue`, built with `@nextcloud/vite-config`.
- Storage: metadata-driven EAV with **typed value columns** — portable across
  MySQL/MariaDB, PostgreSQL and SQLite (no JSON-queried columns, no raw SQL).
- Rule engine: a sandboxed, whitelisted expression evaluator — **no `eval`**,
  no dynamic code execution — with matched JS and PHP implementations.

## Develop

```bash
# Backend deps (needs PHP 8.1+ and Composer)
composer install

# Frontend deps and a production bundle into js/
npm ci
npm run build      # or: npm run watch

# Quality gates
make lint          # php-cs-fixer + psalm + eslint + stylelint
make test          # phpunit + vitest
```

Install by symlinking/copying this directory into your Nextcloud `apps/`
(or `apps-extra/`) folder and enabling it:

```bash
occ app:enable dataforms
```

## Package & sign for the App Store

```bash
make appstore      # builds the bundle, prints the signing command
# then: occ integrity:sign-app --path=build/sign/dataforms ...
```

## Roadmap

| Phase | Scope |
|-------|-------|
| 0 | Skeleton: scaffold, `info.xml`, DI, navigation, SPA shell, CI, packaging. **(this)** |
| 1 | Registers + fields + records CRUD, list view, CSV export, group ACL. |
| 2 | Rules engine: shared JSON schema, JS + PHP evaluators, form builder. |
| 3 | Relations + attachments, record detail, saved/shared views, CSV import. |
| 4 | Automation (triggers & actions), per-record history. |
| 5 | a11y, i18n, 100k-record performance pass, docs, store submission. |

## License & funding

AGPL-3.0-or-later (see [`COPYING`](COPYING)). Free and open source; development
is funded by **voluntary donations** — configure the URLs in
[`appinfo/info.xml`](appinfo/info.xml).

## A note on independence

Dataforms is built from first principles against the project specification and
official Nextcloud developer documentation. It uses its own terminology
(Register, Record, Field, Form, Rule, View) and contains no third-party
product's code, text, trademarks or assets.
