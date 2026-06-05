<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# 4. Automations & workflow

*Audience: managers.* Open a register → **Automations** tab.

An automation runs **on the server** when a record changes — to notify people,
email them, set a field, or call an external system. This is how you turn a
register into a workflow (e.g. *"when a case is marked High, alert the reviewer"*).

## Create an automation

**New automation**, then fill in three parts:

1. **When** — the trigger:
   - *When a record is created*
   - *When a record is updated*
   - *When a record is deleted*

2. **Only if** *(optional)* — one or more **conditions** on the record's fields
   (the same field / operator / value rows used in filters and rules). Leave empty
   to fire on every change. Multiple conditions must **all** match.

3. **Then** — the action:

   | Action | What it does | You provide |
   |--------|--------------|-------------|
   | **Send a notification** | A Nextcloud notification | Recipients, a message |
   | **Send an email** | An email to users (their NC address) | Recipients, subject, body |
   | **Set a field** | Sets a field on the record (e.g. advance a status) | Field + value |
   | **Call a webhook** | POSTs the record data to a URL | URL, optional secret |

Click **Add**. The automation appears in the list with a **switch** to
enable/disable it, and **Edit / Delete** actions.

## Notes per action

- **Notify / Email** — pick recipients with the searchable user picker. Email uses
  each user's configured Nextcloud email address.
- **Set field** — writes the value directly, so it never triggers more
  automations (no loops). Relation, file and automatic fields can't be set this
  way.
- **Webhook** — the only action that leaves your instance, so it's deliberate:
  - **http(s) URLs only**, with a short timeout, and every call is logged.
  - If you set a **shared secret**, the request body is signed
    (`X-DataForms-Signature: sha256=…`, HMAC‑SHA256) so the receiver can verify it.
  - The POST body is JSON: `{ automation, registerId, recordId, userId, values }`.

## Good to know

- Automations are **best‑effort** — if one fails it's logged and never blocks the
  record from being saved.
- Conditions are **sandboxed** (no scripting), and everything runs server‑side.
- Recipients only get what they're allowed to see — access rules still apply.

## Example

*Goal: email the data‑protection officer whenever a high‑seriousness fine is
logged.*
1. **When**: When a record is created.
2. **Only if**: `Seriousness` **is** `High`.
3. **Then**: Send an email → recipients: the DPO → subject: "New high fine" →
   body: a short note.

Save, and the switch is on. Done.
