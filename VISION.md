<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# DataForms — Vision & Strategy

A short, opinionated charter. Every feature should be checkable against this
document. When in doubt, do less and stay coherent.

## North star (one sentence)

> **DataForms is an internal, no-code workspace for structured registers in
> Nextcloud: define a register once, then collect, validate, relate, browse, and
> run workflow on living records — for authenticated users.**

It is **record-centric** (a register is a living dataset a team maintains over
time), not response-centric.

## What DataForms IS

- A builder for **registers**: typed schemas a non-technical admin defines.
- A place to **enter and manage records** through conditional **forms**.
- A way to **browse** records (views, filters, sort, search, CSV).
- A **conditional-logic engine** (the differentiator), shared by the live form
  and the server.
- Soon: a **workflow** engine (triggers → actions, stages) over those records.
- **API-first**: the SPA is just one client of a documented REST API.

## What DataForms IS NOT (the permanent no-list)

These are settled. Re-opening them needs a deliberate, written decision.

- ❌ **Not a survey/consultation tool.** Surveys (anonymous, public, multilingual
  consultations, statistics-grade) are a different, deep domain. Use **EUSurvey**
  (heavy/official) or **Nextcloud Forms** (light). We *integrate via the API*; we
  do not rebuild them. No anonymous question types, no response statistics engine.
- ❌ **No public / anonymous access.** Internal, authenticated users only. Every
  endpoint re-checks per-register permissions server-side.
- ❌ **No arbitrary code / scripting** in rules or computed fields. The expression
  engine stays sandboxed with a fixed, whitelisted function set — never `eval`.
- ❌ **Not a generic database / BI tool.** Registers are line-of-business records,
  not a spreadsheet replacement or a reporting platform.
- ❌ **No reinvented auth.** Machine access uses Nextcloud **app passwords**; we
  never build our own token/credential system.

## The primitives (the spine)

Features must compose from these. We do **not** add feature-specific subsystems.

| Primitive | Meaning |
|-----------|---------|
| **Register** | A schema — what a kind of record looks like |
| **Field** | A typed slot (20 types) |
| **Record** | One instance of data |
| **Rule** | Conditional logic over fields (show/hide, require, set, validate, compute) |
| **Form** | A *write* lens — how you enter data |
| **View** | A *read* lens — how you browse data |
| **Share** | Access (Read / Write / Manage) |
| **Trigger → Action** *(planned)* | Automation on record events — the one new primitive for workflow |

Use-cases are **compositions**, not modules:

- **Workflow** = a status `Field` + `Rule`s + `Trigger → Action` + stage `Share`.
- **Quick capture** = the existing `Form` rendered inline via the smart picker.
- **Survey integration** = an external tool `POST`s responses to a register via
  the API; DataForms structures and runs workflow on them.

## Engineering principles (what keeps it maintainable)

1. **One data model.** Never fork the EAV. New capability = field/config + a
   small registered handler, never a feature-specific table.
2. **One form renderer, many hosts.** The in-app dialog, the inline widget and
   the smart picker all wrap the *same* `RecordForm`. Never a second renderer.
3. **One rule language; automation is server-only.** The rule engine is mirrored
   JS+PHP for live UX (a cost we accept). Automation does **not** get a JS mirror.
4. **Config over code.** A new field type / widget / action is data + one handler
   registered in one place — not a branch threaded through ten files.
5. **API-first; integrate, don't absorb.** The REST API is the contract; the SPA
   and any external tool are equal clients. We connect to other tools rather than
   re-implement them.
6. **Tests are the contract.** Shared JS/PHP fixtures gate the rule engine; the
   automation engine ships with the same discipline. Side-effects are where bugs
   hide.
7. **Stabilise before extend.** Close v1 acceptance (cross-DB, i18n, e2e) before
   adding the automation primitive.

## Roadmap shape (not dates)

1. **Stabilise the core** — cross-DB verification, i18n catalogs, e2e smoke,
   reuse one form renderer everywhere.
2. **API-first** — document the existing REST API (this is happening now), bless
   app-password auth, add an in-app API console.
3. **Workflow** — the `Trigger → Action` primitive: notify / email / set-field /
   assign / outbound webhook, plus stages and stage-based permissions.
4. **Integrations** — inbound (external tools writing records) is done via the
   API; outbound via webhooks. Optional connectors (e.g. import EUSurvey results
   into a register) only if a real need exists.

## How to use this document

Before building anything, ask: *does this compose from the primitives, respect
the no-list, and serve the north star?* If not, it doesn't belong here — or the
charter needs an explicit, recorded change first (see `docs/DECISIONS.md`).
