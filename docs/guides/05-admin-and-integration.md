<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# 5. Administrator & API

*Audience: Nextcloud administrators and integrators.* This covers the
**instance‑level** admin tasks (distinct from a register "manager") and how to
connect DataForms to other tools.

---

## Installing & enabling

DataForms is a standard Nextcloud app.
- Enable it in **Settings → Apps** (or via `occ app:enable dataforms`).
- It needs no extra services. It stores data in the Nextcloud database and
  attachments via the Files app.
- Supported: current Nextcloud releases, PHP 8.1+. Works on MySQL/MariaDB,
  PostgreSQL and SQLite.

## Restricting the app to groups

To make DataForms available only to certain teams: **Settings → Apps →
DataForms → Limit to groups**. This is Nextcloud's built‑in app group
restriction — no extra configuration in the app.

> This is separate from per‑register sharing. The app restriction controls **who
> can open the app at all**; sharing controls **who sees each register**.

## Notifications & email

The workflow **notify** action uses Nextcloud notifications (no setup). The
**email** action uses your instance's configured **mail server**
(**Settings → Administration → Basic settings → Email server**); if email isn't
configured, email actions are silently skipped.

---

## The API

DataForms is **API‑first**: the whole app runs on a REST API, and external tools
can use the same endpoints — for example to push survey results or records in
from another system. Access is always **as an authenticated user**; there is no
anonymous access.

### The API console
**Settings → Administration → DataForms** shows:
- the **base API URL** for your instance,
- a three‑step **app‑password** walkthrough,
- a copy‑paste example, and pointers to the reference.

### Authenticating a machine
External callers use a Nextcloud **app password** (not a login password):
1. **Settings → Security → Create new app password**.
2. Send it as HTTP Basic auth (`username` + app password) with the header
   `OCS-APIRequest: true`.
App passwords are revocable and rate‑limited.

### Reference
- Full endpoint list + examples: **[`docs/API.md`](../API.md)**.
- Machine‑readable core spec (Postman/Swagger): **[`openapi.json`](../../openapi.json)**.

### "Integrate, don't absorb"
DataForms is **not** a survey tool. For surveys use **EUSurvey** (heavy/official)
or **Nextcloud Forms** (light), and let them **POST results into a register** via
the API. See `VISION.md`.

---

## Forms across Nextcloud (Smart Picker)

A form can be dropped into any rich‑text field — a **Talk** message, a **Text** /
**Collectives** page, a **Deck** card.

1. Type **`/`** (or the picker button) and choose **DataForms**.
2. **Search** a form and pick it.
3. A **card** is inserted (form name + register) with two buttons:
   - **Fill in** — opens the form **over the current page** and submits it,
     without leaving the chat/document.
   - **Open** — opens it in the app.

Only people who can access the register see/insert it; the card respects access.

## Deep links

Every register and tab has a shareable URL (use **Copy link** in a register
header). A link of the form
`…/apps/dataforms/?register=<id>&form=<id>` opens straight into a form's entry
screen — handy in emails, Talk, or Deck.

## Outbound webhooks

The workflow **webhook** action lets DataForms notify other systems on record
events (http(s), logged, optionally HMAC‑signed). See
[Automations → Webhook](04-automations.md#notes-per-action). This is the
sanctioned way to push events out; there are no outbound calls otherwise.
