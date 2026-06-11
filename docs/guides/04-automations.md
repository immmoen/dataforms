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

3. **Then** — the action. There are **nine**, grouped below:

   | Action | What it does | You provide |
   |--------|--------------|-------------|
   | **Send a notification** | A Nextcloud notification | Recipients, a message |
   | **Send an email** | An email to users (their NC address) | Recipients, subject, body |
   | **Set a field** | Sets a field on the record (e.g. advance a status) | Field + value |
   | **Create folders** | A folder tree in the record owner's Files | Base folder + one folder path per line |
   | **Copy a template** | Copies a template folder's contents into a folder | Source folder + destination |
   | **Add a calendar event** | An event in the owner's calendar | Title, the date field to start from, (optional) calendar name + duration |
   | **Create a Talk room** | A Talk conversation, its participants, a welcome message | Room name, the user/group field for participants, message |
   | **Create a Deck board** | A Deck board and its columns | Board title, columns |
   | **Call a webhook** | POSTs the record data to a URL | URL, optional secret |

Click **Add**. The automation appears in the list with a **switch** to
enable/disable it, and **Edit / Delete** actions.

## Placeholders (tokens)

Text fields in an action (folder paths, the event/room/board title, the message)
can include **placeholders** filled from the record:

- `{machineName}` — a field's value, e.g. `{title}`.
- `{field|format}` — a date reformatted, e.g. `{meeting_date|Ymd}` → `20260701`,
  `{meeting_date|d-m-Y}` → `01-07-2026`.
- `{relation.subfield}` — a field from a **linked** record, e.g. `{subgroup.code}`
  when the record relates to an "ESG Subgroups" register that has a `code` field.

## Notes per action

- **Notify / Email** — pick recipients with the searchable user picker. Email uses
  each user's configured Nextcloud email address.
- **Set field** — writes the value directly, so it never triggers more
  automations (no loops). Relation, file and automatic fields can't be set this
  way.
- **Create folders / Copy a template / Add a calendar event** run as the **record
  owner** (the person who created the record), in *their* Files / calendar — never
  as whoever triggered the change. They are **idempotent**: re-running reuses the
  existing folders / event instead of duplicating them.
- **Create a Talk room / Create a Deck board** are **composite** actions (they make
  several API calls in one step) and run as a shared **service account** that an
  administrator configures first — see *5. Admin & integration*. If it isn't
  configured, these two actions simply do nothing. They only run on the **create**
  trigger, so each record gets its workspace exactly once. Participants are added
  only if they actually exist.
- **Webhook** — the only action that leaves your instance, so it's deliberate:
  - **http(s) URLs only**, with a short timeout, and every call is logged.
  - It refuses internal/loopback addresses and never follows redirects (SSRF
    protection).
  - If you set a **shared secret**, the request body is signed
    (`X-DataForms-Signature: sha256=…`, HMAC‑SHA256) so the receiver can verify it.
  - The POST body is JSON: `{ automation, registerId, recordId, userId, values }`.

## Good to know

- The list of actions you can pick is set by your **administrator** (Settings →
  Administration → DataForms). If an action you expect is missing, ask them to
  enable it; *Create a Talk room* and *Create a Deck board* also need the cross-app
  service account to be set up.
- Automations are **best‑effort** — if one fails it's logged and never blocks the
  record from being saved.
- **See what happened** — the **Activity** button at the top of the Automations
  tab lists recent runs and flags the ones that **failed** (with the error), so a
  broken automation doesn't fail silently. Activity is kept for 30 days.
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
