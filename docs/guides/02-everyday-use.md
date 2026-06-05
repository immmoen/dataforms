<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# 2. Everyday use — finding and entering records

*Audience: viewers (Read) and contributors (Write).*

---

## Part A — Finding records *(everyone)*

Open a register → **Records** tab. You get a table you can resize‑scroll
horizontally; the header row and the actions column stay put as you scroll.

### Search
Type in the **Search records** box (top left). It matches text across the record.

### Filter
Click **Filter** to open the condition bar:
1. Pick a **field**, an **operator** (is / is not / contains / greater than /
   empty…), and a **value**.
2. For single/multi‑select fields the value is a **dropdown of the options**.
3. **Add condition** for more; **Apply** to run, **Clear** to reset.
The **Filter** button shows a count when filters are active.

### Sort
Click any **column header** to sort; click again to reverse. An ▲/▼ shows the
direction. You can sort by the **Number** (sequence) column to see records 1, 2,
3…

### Choose columns
**⋯ More → Columns** — tick the fields you want as columns.

### Saved views
A **view** remembers your columns, filters, sort and search.
- **⋯ More → Save current view…** — name it; optionally **share** it with everyone
  who can see the register.
- When views exist, a **view selector** appears next to Search to switch between
  them.

### Open a record
**Click a row** to open the read‑only **detail** — every field, including linked
records and attachments. At the bottom, expand **History** to see who created or
changed it and when.

### Export
**⋯ More → Export to CSV** downloads the current (filtered) table as a CSV file
(opens cleanly in Excel).

### Refresh
**⋯ More → Refresh** reloads the list. It also refreshes automatically when you
return to the tab.

---

## Part B — Entering records *(contributors, Write access)*

If you can't see a **New record** button, you have viewer access only.

### Add a record
Click **+ New record**. If the register has **forms**, this is a menu — choose
**Blank (all fields)** or a named form. Fill it in and **Save**.

- Required fields are marked and checked when you save.
- **Conditional logic** runs live: some fields appear, become required, or fill in
  automatically as you type.
- **Computed** and **auto** fields (like the sequence number) are filled for you.

### Edit a record
Two ways:
- **Double‑click a cell** in the table to edit it in place (works for text,
  numbers, dates, single‑select and Yes/No). Press **Enter** or click away to
  save, **Esc** to cancel.
- Or open the row's **⋯ menu → Edit** for the full form.

> Contributors can edit/delete **only the records they created**. Managers can
> edit any record.

### Attachments
A file field shows **Add file(s)** — upload one or more files from your computer.
They're stored in your Nextcloud Files (in a "Dataforms" folder) and linked here,
never duplicated. Remove one with the ✕ next to it.

### Long option lists
For big pick‑lists (e.g. GDPR articles), the picker is **grouped and searchable** —
expand a group, or type to jump straight to an option, and tick several at once.

### Import many at once
Managers can bulk‑import records from a CSV — see
[Managing registers → CSV import](03-managing-registers.md#csv-import).

### Quick capture from elsewhere
A form can be inserted into a Talk message or a Text/Collectives page and filled
**without opening the app** — see
[Administrator & API → Forms across Nextcloud](05-admin-and-integration.md#forms-across-nextcloud-smart-picker).
