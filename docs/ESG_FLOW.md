<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# ESG meeting provisioning — DataForms blueprint

A concrete design for reproducing the EDPB "New ESG meeting" Windmill flow as a
DataForms register + automations. **Spec only — no code yet.** Review before
building.

## Scope

The Windmill flow does eight things on each new meeting-request form submission:
poll Nextcloud Forms → create a folder tree → **lock down two subfolders with
Group-Folder ACLs** → copy templates → create a Talk room + participants → set up
a Deck board → post a welcome message → add a calendar event → summarise.

**This blueprint deliberately drops the Group-Folder ACL lockdown.** That step is
confidentiality-critical (it controls who can see draft/approved minutes), uses
the Group Folders app's advanced ACL (`nc:acl-list` via WebDAV PROPPATCH with a
read-merge-verify loop), and is **not** covered by Nextcloud's public `OCP\Share`
API. It stays on the existing, hardened Windmill script — or becomes its own
separately-reviewed project. Everything else below is reproducible in DataForms
with **no confidentiality-risk operation**.

## Why DataForms fits (and where it differs)

- The **trigger disappears**: a DataForms form submission *is* the record-create
  event. No polling the Forms API, no Windmill state to dedup — the register is
  the audit log (replacing the Windmill "find latest" + "summarise" modules).
- The submission is a **typed, audited record** rather than answers-by-question-id.
- **Key difference — automations don't pass data to each other.** They run in
  order on the event, but an action cannot consume another action's output. See
  "The data-passing constraint" below; it's why Talk and Deck are *composite*
  actions, not generic single calls.

## 1. Registers & intake form

The subgroup name *and* its code both appear in the path, so the code is modelled
as a small **"ESG Subgroups" reference register** the meeting form links to — the
name→code map becomes data, not a hard-coded lookup.

**ESG Subgroups** (reference data — the official EDPB list, from the Windmill `ESG_MAP`):

| `name` | `code` |
|--------|--------|
| Borders, Travel & Law Enforcement | BTLE |
| Compliance, e-Government and Health | CEH |
| Cooperation | COOP |
| Coordinators | COORD |
| Corrective Powers Expert Subgroup | CPWR |
| Cross-Regulatory Interplay and Cooperation | CIC |
| Enforcement | ENF |
| Financial Matters | FMESG |
| International Transfers | ITS |
| IT Users | ITUSERS |
| Key Provisions | KEYP |
| Social Media | SOCM |
| Strategic Advisory | SAESG |
| Technology | TECH |

**ESG Meetings** (the intake form):

| Field | Type | machineName | Used for |
|-------|------|-------------|----------|
| Expert subgroup | **Relation → ESG Subgroups** (display `name`) | `subgroup` | `{subgroup.name}` + `{subgroup.code}` in the path |
| Meeting date | Date | `meeting_date` | path date parts + calendar start |
| Meeting title | Text | `title` | folder leaf + room/board names |
| Participants | User/group (multi) | `participants` | Talk room members |

## 2. Interpolation features (shared by every action) — ✅ built

The `{machineName}` placeholder now also supports:

**Date reformatting** (the Windmill `parseMeetingDate`):

| Token | From `2026-07-01` |
|-------|-------------------|
| `{meeting_date\|Y}` | `2026` |
| `{meeting_date\|Ymd}` | `20260701` |
| `{meeting_date\|d-m-Y}` | `01-07-2026` |

**Relation sub-fields** — `{subgroup.name}` → `Social Media`, `{subgroup.code}` →
`SOCM`. The `RelationResolver` enriches the record's values with its relation
targets' scalar fields (read-gated to registers the owner can read). So one pick
of the subgroup yields both the human name and the code in the path.

## 3. Automations (all on trigger = *record created*, run in this order)

### A1 — Create folders  (`provision_folders`, ✅ built)

- **Base folder:** `02. Expert subgroup`
- **Folders (one per line):**

```
{subgroup.name}/02. Meetings {subgroup.code}/Meetings - {meeting_date|Y} {subgroup.code}/{meeting_date|Ymd} - {title}/Agenda - {meeting_date|d-m-Y}
{subgroup.name}/02. Meetings {subgroup.code}/Meetings - {meeting_date|Y} {subgroup.code}/{meeting_date|Ymd} - {title}/Documents - {meeting_date|d-m-Y}
{subgroup.name}/02. Meetings {subgroup.code}/Meetings - {meeting_date|Y} {subgroup.code}/{meeting_date|Ymd} - {title}/Minutes - {meeting_date|d-m-Y}/Draft
{subgroup.name}/02. Meetings {subgroup.code}/Meetings - {meeting_date|Y} {subgroup.code}/{meeting_date|Ymd} - {title}/Minutes - {meeting_date|d-m-Y}/Approved minutes
{subgroup.name}/02. Meetings {subgroup.code}/Meetings - {meeting_date|Y} {subgroup.code}/{meeting_date|Ymd} - {title}/To do list - {meeting_date|d-m-Y}
```

Verified building exactly:
`…/Social Media/02. Meetings SOCM/Meetings - 2026 SOCM/20260701 - Kickoff/…`.
`mkdir -p` creates the intermediate levels; idempotent (re-firing reuses the tree).

- **Identity caveat:** `provision_folders` runs as the **record owner**. The ESG
  tree lives in a **shared group folder**, so this works only if the submitter has
  write access there (they're in the group). If not, A1 needs an optional
  *service-account identity* (build item 6).

### A2 — Copy templates  (`apply_template`, ✅ built)

- **Source:** a template folder, e.g. `Templates/ESG meeting`.
- **Destination:** the leaf `…/{meeting_date|Ymd} - {title}` (rebuilt from the same
  fields — no hand-off from A1 needed, just ordering so the folders exist first).

### A3 — Add a calendar event  (`add_calendar_event`, ✅ built)

- **Title:** `{subgroup.code} meeting — {title}`
- **Start:** `meeting_date`
- **Calendar:** the team/subgroup shared calendar (by name; blank = author default).

### A4 — Create Talk meeting  (composite, to build)

One action, because each call needs the previous call's output (the room token):

1. `POST /ocs/v2.php/apps/spreed/api/v4/room`
   `{ "roomType": 2, "roomName": "{subgroup.code} {title} - {meeting_date|d-m-Y}" }`
   → returns `token`
2. for each `participants` entry:
   `POST /ocs/v2.php/apps/spreed/api/v4/room/{token}/participants`
   `{ "newParticipant": "<id>", "source": "users" | "groups" }`
3. `POST /ocs/v2.php/apps/spreed/api/v1/chat/{token}`
   `{ "message": "Welcome to the {subgroup.code} meeting on {meeting_date|d-m-Y}." }`

*(All three verbs proven in the spike — see Appendix.)*

### A5 — Create Deck board  (composite, to build)

One action (each stack needs the board id from step 1):

1. `POST /index.php/apps/deck/api/v1.0/boards`  *(note: Deck is NOT under /ocs)*
   `{ "title": "{subgroup.code} {title}", "color": "0082C9" }` → returns `id`
2. for each column in `["Agenda", "Actions", "Decisions", "Done"]`:
   `POST /index.php/apps/deck/api/v1.0/boards/{id}/stacks`
   `{ "title": "<column>", "order": <n> }`

*(Exact column names + participant source to be confirmed against Windmill
modules `l` and `d` at build time.)*

## The data-passing constraint (read before building)

DataForms runs all matching automations for an event **in order** (by automation
id), sequentially, in one background job. So **ordering** (A1 before A2, etc.) is
fine. But **an action cannot read another action's output** — there is no shared
context between actions.

- A1, A2, A3 only need values from the **record** → plain automations.
- A4, A5 need a **prior call's output** (Talk `token`, Deck board `id`) → must be
  **single composite actions** that run their whole mini-sequence internally.

This is the one structural difference from Windmill (a flow engine with data
passing between steps). It blocks nothing here; it just means Talk and Deck are
purpose-built composites rather than generic single calls.

## Authentication model (from the spike)

The cross-app calls (A4, A5) run in DataForms' background job, which has no user
session — so they authenticate with an **admin-configured service-account
app-password** (the same model the Windmill script uses with `effectiveUser`):

- Internal base URL is the instance's own loopback — proven working at
  `http://localhost` (the container's port 80, `Host: localhost`), **not** the
  external `:8080`. Resolve from the instance URL config at runtime.
- Headers: `Authorization: Basic …`, `OCS-APIRequest: true`, `Accept: application/json`.
- The HTTP client must allow the local address for this internal call
  (`allow_local_address: true`) — the inverse of the SSRF-guarded outbound webhook,
  and scoped to the instance's own host only.
- The token is entered by an admin in DataForms admin settings and stored
  encrypted (`ICredentialsManager`); it is never logged.

## Build list & suggested order

1. ✅ **Interpolation:** `{field|date-format}` tokens + `{relation.subfield}` (the
   Subgroups register supplies name+code) — shared `ValueInterpolator` +
   `RelationResolver`. *(0.32.0 / 0.33.0)*
2. ✅ **`apply_template` action** — Files API copy; pure record-driven, no credentials. *(0.32.0)*
   *(Items 1–2 deliver A1–A3 fully, with zero credential/confidentiality risk.)*
3. ⏳ **Service-account credential subsystem** — admin setting + encrypted store + host-scoped HTTP client.
4. ⏳ **"Create Talk meeting" composite action** (A4).
5. ⏳ **"Create Deck board" composite action** (A5).
6. ⏳ **`provision_folders` optional service-account identity** — only if submitters can't write the shared group folder.

## Explicitly out of scope

- **Group-Folder ACL lockdown** (Draft / Approved-minutes restriction). Stays on
  Windmill or becomes its own project with an adversarial review. Re-implementing
  fail-closed advanced ACLs carries a real confidentiality risk and is **not** part
  of this blueprint.

## Appendix — spike evidence

A server-side (background-job-style) process made authenticated calls to the
instance's own API via `IClientService`:

| Call | Result |
|------|--------|
| `GET /ocs/v2.php/cloud/capabilities` (Basic auth) | HTTP 200 |
| `POST /ocs/v2.php/apps/spreed/api/v4/room` | HTTP 201 (room token returned) |
| `POST /ocs/v2.php/apps/spreed/api/v1/chat/{token}` | HTTP 201 |
| `POST /index.php/apps/deck/api/v1.0/boards` | HTTP 200 |

Installed: `deck 1.16.5`, `spreed 22.0.13`. Base URL that authenticated:
`http://localhost`.
