<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# 3. Managing a register

*Audience: managers (the register's owner or anyone granted **Manage**).*

You see four extra tabs: **Fields · Forms · Rules · Automations**. This guide
covers the first three plus sharing and import; automations have their
[own guide](04-automations.md).

---

## Fields

The **Fields** tab is the register's schema — the typed columns every record can
have.

### Add a field
**Add field** → pick a **type**, give a **label**, set options, then **Add**.

**Common settings (all types):** label · optional **help text** (shown under the
field) · optional **default value** · **Required** · **Unique values** ·
order (reorder with the up/down controls). The internal *machine name* is
generated from the label and is **fixed** afterwards (so data stays stable).

### Field types
| Group | Types |
|-------|-------|
| Basic | Text, Long text, Yes/No |
| Number | Number, Currency, Percentage (min/max, decimals) |
| Date & time | Date, Date & time, Time |
| Choice | Single select, Multi select (with options) |
| Contact | Email, URL, Phone (format‑checked) |
| People | User, Group (Nextcloud) |
| Advanced | Relation, File attachment, Computed, Automatic |

**Worth knowing:**
- **Select / Multi‑select** — enter one option per line. For long lists, turn on
  **Group options in the form** ("by leading code" e.g. *Art 6*) to make the
  data‑entry picker collapsible and searchable.
- **Relation** — link to records in another register; choose the **display field**,
  allow **several** links, and set what happens **when a linked record is
  deleted** (clear the link / prevent deletion / cascade‑delete).
- **File attachment** — one or more files via Nextcloud Files (by reference).
- **Computed** — a read‑only value from an expression over other fields
  (functions: `sum, round, if, concat, min, max, abs, len, lower, upper`).
- **Automatic** — filled by the system: **Sequence number** (1, 2, 3… per
  register), Created date, Last‑updated date, or Created‑by. *(This is where the
  auto‑number lives — it is not a "Number" field.)*

Deleting a field removes its stored values but **does not** delete records.

---

## Forms

A **form** is a tailored data‑entry layout. A register can have several (e.g.
"Quick add" and "Full intake"); they all feed the same records. Without a form,
**New record** shows every field.

### The drag‑and‑drop builder
**Forms → New form** opens a two‑pane builder:
- **Left** — a palette of available fields.
- **Right** — your form, organised into **sections**.

Drag a field from the palette into a section; drag to reorder or move between
sections; drag back to the palette (or click ✕) to remove. Each placed field
shows a preview of its control. Rename sections inline; reorder them with ▲/▼.
**Save form**. (No mouse? Use the **+** button on a palette field to add it.)

Saved forms appear in the **New record** menu on the Records tab.

---

## Rules (conditional logic)

The **Rules** tab makes forms smart. A rule has **conditions** (on other fields)
and an **effect**:
- **Show / hide** a field when conditions are met.
- **Require** a field only when relevant.
- **Set a default** value.
- **Validate** (range, pattern, cross‑field) with a custom message.
- **Compute** a value from other fields.

Rules run **live** in the form *and* are re‑checked on the server when saving, so
the screen and the saved data can never disagree. (Expressions are sandboxed —
there is no scripting.)

---

## Views

A **view** is a saved set of columns + filters + sort + search. Create one from
**Records → ⋯ More → Save current view…**; tick **share** to make it visible to
everyone who can see the register. Managers (and the view's owner) can edit or
delete it. See [Everyday use → Saved views](02-everyday-use.md#saved-views).

---

## Sharing & permissions

**Share** (top‑right of a register) opens the access dialog.

1. **Search** for a user or group — start typing a name or id; matches appear with
   an avatar.
2. Pick a **role**:
   - **Read** — view, search, filter, export.
   - **Write** — add records and edit/delete **their own**.
   - **Manage** — edit **any** record **and** design the register (fields, forms,
     rules, automations, sharing).
3. **Add**. Change a role or remove access from the list at any time.

Internal users and groups only — no public links. All limits are enforced on the
server, so they can't be bypassed via the API either.

---

## CSV import

**Records → ⋯ More → Import from CSV…**
- The first row must be **headers** matching field **labels** or machine names.
- Each row is created through the normal validation/rules pipeline; you get a
  per‑row error report for anything that doesn't fit.
- **Don't include the auto Number column** — it's assigned automatically.
- Select/multi‑select cell values must match the field's options **exactly**.

A **Download template** button gives you a header‑only CSV to start from.
