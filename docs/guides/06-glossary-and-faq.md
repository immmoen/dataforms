<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# 6. Glossary & FAQ

## Glossary

| Term | Meaning |
|------|---------|
| **Register** | A collection of records of one kind (e.g. a fines register). Has a schema, forms, views and access of its own. |
| **Field** | A typed column in a register (text, date, select, currency, relation…). |
| **Record** | One entry in a register. |
| **Form** | A data‑entry layout over a register's fields (sections, order). A register can have several. |
| **Rule** | Conditional logic on a form: show/hide, require, set, validate, compute. |
| **View** | A saved set of columns + filters + sort + search; can be shared. |
| **Automation** | A server‑side *when → if → then*: on a record change, if conditions hold, run an action. |
| **Action** | What an automation does: notify, email, set a field, or call a webhook. |
| **Manager** | Someone with **Manage** access to a register — its "admin". Not the server admin. |
| **Administrator** | The Nextcloud server admin (installs the app, restricts to groups). |
| **App password** | A revocable token to use the API as a user, made in Settings → Security. |
| **Smart Picker** | Nextcloud's `/` menu for inserting things (incl. a DataForms form) into Text/Talk/etc. |
| **Sequence number** | The per‑register running number (1, 2, 3…), an *Automatic* field. |

## FAQ

**I don't see the Fields / Forms / Rules / Automations tabs.**
You have viewer or contributor access, not Manage. Those tabs are for managers
only. Ask the register's owner for **Manage** if you need to design it.

**Can outside people (no Nextcloud account) fill in a form?**
No. DataForms is internal/authenticated only, by design. For public/anonymous
collection use **EUSurvey** or **Nextcloud Forms** and import results via the API.

**Is this a survey tool?**
No — it's a register/workflow tool. See "Integrate, don't absorb" in the
[Admin & API guide](05-admin-and-integration.md#integrate-dont-absorb).

**A contributor can't edit someone else's record.**
Correct — contributors edit/delete only records **they created**. Managers can
edit any record.

**The screen looks out of date after an update.**
Press **Ctrl+F5** (hard refresh) to load the latest version. If a tab hangs while
loading, open a fresh tab.

**Why does my first record show #1 but there's a gap later?**
Numbers are assigned at creation and never reused after a deletion, so deleting a
record can leave a gap. This keeps every number stable.

**My email automation didn't send.**
Check that the instance has a **mail server** configured
(Settings → Administration → Basic settings) and that the recipients have email
addresses set on their accounts.

**How do I move a record through stages (a workflow)?**
Use a single‑select field for the status, and an automation per transition (e.g.
*on update, if Status = "Approved", notify / set a field*). A formal
transitions model (who may move what) is a planned enhancement.

**Where is my data stored?**
On your Nextcloud server (database + Files for attachments). Nothing is sent
outside the instance unless you explicitly configure a **webhook** action.

## More

- Architecture & principles: `VISION.md`
- Workflow design: `docs/WORKFLOW.md`
- API reference: `docs/API.md`
- Database portability: `docs/PORTABILITY.md`
