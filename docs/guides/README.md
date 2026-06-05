<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# DataForms — User Guides

Plain‑language guides for everyone who uses DataForms, from a read‑only viewer to
the Nextcloud administrator. Pick the guide that matches what you do.

## What is DataForms?

DataForms lets you build **registers** — structured, typed collections of records
(a fines register, a contracts log, an asset inventory…) — and the **forms** that
feed them, entirely from the browser, with no code. You can browse, filter and
export records, link them, attach files, and (for managers) run **automations**
when records change. It's internal: only logged‑in Nextcloud users with access
can see a register.

## The three access levels

Access is granted **per register** (like sharing a file). What you can do depends
on your level:

| Level | Can do | Guide |
|-------|--------|-------|
| **Viewer** (Read) | See records; search, filter, sort, export | [Everyday use](02-everyday-use.md) |
| **Contributor** (Write) | All of the above **+** add records and edit/delete **their own** | [Everyday use](02-everyday-use.md) |
| **Manager** (Manage) | All of the above for **any** record **+** design fields, forms, rules, views, sharing and automations | [Managing registers](03-managing-registers.md), [Automations](04-automations.md) |

> **"Manager" is the register's admin** — its owner, or anyone the owner grants
> *Manage* to. It is **not** the Nextcloud server administrator. The server admin
> installs the app and can restrict it to groups — see the
> [Administrator & API guide](05-admin-and-integration.md).

## The guides

1. **[Getting started](01-getting-started.md)** — what you see when you open the
   app, navigation, and the dashboard. *(everyone)*
2. **[Everyday use](02-everyday-use.md)** — finding records, and entering/editing
   them. *(viewers & contributors)*
3. **[Managing registers](03-managing-registers.md)** — registers, fields, forms,
   rules, views, sharing, CSV import. *(managers)*
4. **[Automations & workflow](04-automations.md)** — react to record changes:
   notify, email, set a field, call a webhook. *(managers)*
5. **[Administrator & API](05-admin-and-integration.md)** — enabling the app,
   group restriction, the API, and inserting forms across Nextcloud. *(admins &
   integrators)*
6. **[Glossary & FAQ](06-glossary-and-faq.md)** — terms and common questions.

## Conventions

- **Bold** = something you click or a screen label.
- "Manager‑only" features are hidden unless you have *Manage* access — so if you
  don't see the **Fields / Forms / Rules / Automations** tabs, you're a viewer or
  contributor, which is expected.
