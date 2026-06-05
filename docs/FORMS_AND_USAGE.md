<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Dataforms — Forms & Usage Guide

This guide explains, in plain language, **how forms work in Dataforms**, how to
build them with the drag‑and‑drop builder, and **how the app fits into the rest
of Nextcloud**.

---

## 1. The big picture: registers, fields, forms, views

Dataforms is built from four layers. It helps to keep them distinct:

| Layer | What it is | Who edits it |
|-------|-----------|--------------|
| **Register** | A collection of records (e.g. "GDPR Fines Register"). | Owner / manager |
| **Fields** | The *schema* — the typed columns every record can have (text, date, select, currency, relation, …). | Manager only |
| **Forms** | One or more *data‑entry layouts* over those fields — which fields show, in what order, grouped into sections, with conditional rules. | Manager only |
| **Records** | The actual entries people add and edit. | Anyone with Write access |

A register always has **one schema** (its fields) but can have **several forms**
— for example a short "Quick add" form and a long "Full intake" form — all
feeding the same underlying records.

> **You don't need a form to start.** If a register has no forms, the **New
> record** button simply shows *every* field. Forms are how you present a
> tailored, friendlier subset.

---

## 2. How a form works

A **form** is a saved arrangement of fields:

- It chooses **which fields** appear (you can omit fields that aren't relevant
  to that workflow).
- It groups them into **sections** with optional headings.
- It sets the **order** fields appear in.
- It runs the register's **conditional rules** live as you type.

When someone clicks **New record → "<your form name>"**, Dataforms renders that
layout. The same renderer is reused when they **edit** an existing record, so
data entry and editing always look identical.

### Conditional rules (the smart part)

Rules are defined once (in the **Rules** tab) and run **both in the browser and
on the server**, so the live form and the saved data can never disagree. A rule
has **conditions** (e.g. *Case Status = "Decision appealed"*) and an **effect**:

- **Show / hide** a field or section.
- **Require** a field only when it's relevant.
- **Set a default** value.
- **Validate** (range, pattern, cross‑field) with a custom message.
- **Compute** a value from other fields (read‑only).

Because rules are evaluated by a **sandboxed expression engine** (no arbitrary
code execution), they're safe and identical on client and server.

### What gets stored

A form only stores *layout* — the list of sections and the field machine‑names
in each. The record values themselves live in the register's typed data store.
That means you can **redesign a form at any time without touching existing
records**, and deleting a form never deletes data.

---

## 3. Building a form (drag‑and‑drop)

Open a register → **Forms** tab → **New form** (or click an existing form to
edit it). You land in the **WYSIWYG builder**:

```
┌── Back   [ Form name… ]                       3 fields placed   [ Save form ] ─┐
│                                                                                │
│  AVAILABLE FIELDS          │   ▼ Section: "Identification"                     │
│  ┌──────────────────────┐  │   ┌───────────────────────────────────────────┐  │
│  │ ⠿ Reference Number  T │  │   │ ⠿ Supervisory Authority      [ Choose… ▾ ]│  │
│  │ ⠿ Industry      MULTI │  │   │ ⠿ Date of Decision           [ dd/mm/yyyy]│  │
│  │ ⠿ Case Summary  LONG  │  │   └───────────────────────────────────────────┘  │
│  └──────────────────────┘  │   ▼ Section: "Outcome"                            │
│  (drag a field →)          │   ┌── drag fields here ───────────────────────┐  │
│                            │   └───────────────────────────────────────────┘  │
│                            │   [ + Add section ]                               │
└────────────────────────────────────────────────────────────────────────────────┘
```

- **Add a field:** drag it from **Available fields** into a section.
- **Reorder / move:** drag a placed field up/down, or into another section.
- **Remove a field:** click its **×**, or drag it back to the palette.
- **Sections:** rename inline, reorder with the ▲/▼ buttons, delete with the
  trash icon (its fields return to the palette).
- **Preview:** each placed field shows a mock of its real control (Yes/No
  radios, a dropdown, a text box, a file button…), so you see the form taking
  shape as you build.

Click **Save form**. It now appears under **New record** for anyone with Write
access.

> A field can sit in **only one section** of a given form. Fields you leave in
> the palette simply won't appear on that form (but stay available on others).

---

## 4. Using Dataforms across Nextcloud

Dataforms is a standard, **internal** Nextcloud app — it's for **logged‑in users
only**. There are deliberately **no public links and no anonymous submission**
(that's a security/compliance choice baked into the app). Here's how it connects
to the rest of your instance:

### Navigation & deep links
- It appears in the Nextcloud **top‑bar app menu** like Files or Calendar.
- Every register and tab has a **shareable deep link** (the URL hash reflects
  the open register + tab). Use the **Copy link** button in a register's header
  to paste a direct link into Talk, an email, or a Deck card. Whoever opens it
  must be logged in *and* have access to that register.

### People: users & groups
- **Sharing / access control:** a register's owner can share it with **users or
  groups** at three levels — **Read**, **Write** (add/edit their own records),
  or **Manage** (also edit the schema, forms, rules, and others' records). All
  checks are enforced server‑side on every request.
- **App‑level restriction:** an administrator can limit the *whole app* to
  specific groups via **Settings → Apps → Dataforms → Limit to groups**.
- **User/Group fields:** a field can reference a Nextcloud user or group.

### Files
- **File‑attachment fields** store one or more files **via the Nextcloud Files
  API**, referenced by file id (never copied into the app's database). Uploads
  land in a "Dataforms" folder in your own Files and are linked from the record.

### Data in and out
- **CSV import & export** round‑trip your data, so you can prepare records in a
  spreadsheet and bring them in, or pull the current view out for reporting.
- Everything is served through the app's **OCS REST API**
  (`/ocs/v2.php/apps/dataforms/api/v1/…`), the same interface the UI uses — handy
  if you ever want to integrate another internal tool.

### What it intentionally does **not** do
- No public/anonymous forms, no external webhooks by default, no telemetry. Any
  future automation (notifications, email, webhooks) will be explicit and
  user‑configured.

---

## 5. Quick recipes

- **"I just want to capture data fast."** Skip forms — define fields, then use
  **New record** (it shows all fields).
- **"Different teams need different intake screens."** Build several forms over
  the same register (e.g. *Quick add* vs *Full intake*).
- **"Some fields only matter sometimes."** Add a **rule** to show/require them
  conditionally, then place them in a section — empty sections hide themselves.
- **"This list of options is huge."** For a select/multi‑select with many
  options, turn on **grouping** in the field editor (e.g. group GDPR provisions
  by Article) — the data‑entry picker becomes collapsible and searchable.
- **"I need a running case number."** Add an **Automatic → Sequence number**
  field; it counts 1, 2, 3… within that register.

---

*This document is original to the Dataforms project and licensed
AGPL‑3.0‑or‑later.*
