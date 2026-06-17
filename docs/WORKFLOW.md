<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Workflow — design

Workflow is DataForms' differentiator and the next major build. This note fixes
the design so the implementation stays small and composable (per `VISION.md`).

## Principle

Workflow is **not** a new subsystem. It is **one new primitive — `Trigger →
Action`** — plus things that already exist (a status `Field`, `Rule`s, `Share`).
It runs **server-side only** (no JS mirror), so it doesn't double the rule
engine's lockstep cost.

## The foundation (shipped)

The app now emits **typed domain events** on every record change:

- `OCA\Dataforms\Event\RecordCreatedEvent`
- `OCA\Dataforms\Event\RecordUpdatedEvent` (carries the changed field labels)
- `OCA\Dataforms\Event\RecordDeletedEvent`

Each carries `registerId`, `recordId`, `userId`, `values`, and (for updates)
`changedFields`. The automation engine — and any third-party app — subscribes to
these. Emitting events keeps automation **decoupled** from the write path.

## The model (to build)

```
Automation = { register, trigger, condition?, action }

trigger   : on_create | on_update | on_delete | on_field_change(field)
condition : the existing Rule condition AST (reused — no new language)
action    : notify | email | set_field | provision_folders
          | add_calendar_event | webhook
```

### Provisioning / cross-app actions (replacing an external flow runner)

These are the "guided" actions that let DataForms drive intake → workspace setup
*without* an external engine (Windmill/n8n) or even Nextcloud Flow — each built on
a **public** Nextcloud API (no fragile per-app coupling), each running as the
record **owner** and idempotent. The *local* ones (`provision_folders`,
`apply_template`, `add_calendar_event`) run **inline** as of 0.38.1 (see
"Execution model"); the *outbound* ones (`create_talk_room`, `create_deck_board`)
remain **deferred**.

**`provision_folders`** creates a folder tree in the owner's Files from
`{machineName}` templates (e.g. `Clients/{client_name}/Contracts`). Every path
segment is sanitised (no `/`, `\`, `..`, control/bidi chars, Windows reserved
names — a value can never escape its segment), creation is `mkdir -p`, and it is
bounded (≤ 50 templates, ≤ 10 deep, ≤ 200 folders/fire).

**`add_calendar_event`** adds an event to one of the owner's calendars via the
public `OCP\Calendar` API (`ICreateFromString`, built with VObject — no Calendar
*app* dependency). The start comes from a date/datetime field; title/description
are `{field}` templates; the event UID is derived from the record + automation, so
re-firing updates rather than duplicates.

Planned siblings: **`apply_template`** (copy template files in) and **`set_share`**
(grant access) — each added the same way, a new `IAction` in the registry, and
each must clear the same hardening bar (owner identity, deferred, bounded,
adversarially reviewed).

- **Storage:** an `automations` table holding `register_id`, `trigger`,
  `condition` (JSON, reusing the rule schema), `action_type`, `action_config`
  (JSON). One table, opaque JSON config — same pattern as fields/rules/views.
- **Engine:** an `AutomationListener` subscribes to the typed events, loads the
  register's automations, evaluates the condition with the **existing**
  `RuleEvaluator`/`ExpressionEvaluator` (no new evaluator), and runs matching
  actions through an **action registry** (one handler per action type).
- **Actions are a registry, not a switch.** `interface IAction { run(context) }`
  with one class per type, registered in one place — adding an action is a new
  class, not edits across the codebase (config-over-code).

## Stages (optional, later)

A "stage" is just a single-select `Field` (e.g. Case Status). A later addition
is a **transition map** (which stage → which, and who may move it) stored in the
field config, enforced server-side — still no new table, no new primitive.

## Execution model (0.38.1 — inline provisioning)

Actions are split by cost so a slow or hostile endpoint can never block a record
write or exhaust the PHP worker pool. Each action declares its lane via
`IAction::isDeferred()`:

- **Inline actions** (`notify`, `set_field`, `provision_folders`,
  `apply_template`, `add_calendar_event`) are run **synchronously** in
  `AutomationListener` — but only *after* the record's writes have committed
  (events are dispatched post-commit), so they never observe a half-written or
  rolled-back row. The local provisioning actions moved here in **0.38.1**: they
  are bounded (≤ `maxFolders`/`maxCreated`/`maxTemplateFiles` per fire) local
  filesystem/calendar I/O on the owner's account, fast enough to run on the
  submit thread, and — crucially — they **fire reliably even on instances
  without working cron**, which was the main pain point when they were deferred.
- **Deferred actions** (`email`, `webhook`, `create_talk_room`,
  `create_deck_board`) have slow or external side effects (SMTP, outbound HTTP,
  cross-app API calls). The listener does **not** run them; it enqueues a single
  `OCA\Dataforms\BackgroundJob\RunAutomationsJob` (an `OCP\BackgroundJob`
  `QueuedJob`). The job re-reads the register's currently-enabled automations
  against the captured value snapshot and runs only the deferred ones — picking
  up any enable/disable change made between the write and the job firing.

**Operational requirement:** because email/webhook/Talk/Deck delivery happens in
the background queue, the instance must run **system cron** (the recommended
Nextcloud setup) for timely delivery. With AJAX cron, delivery happens on the
next page load; with no cron, the *deferred* actions never run (the inline
provisioning actions are unaffected).

Both lanes record each run in the activity log (`df_automation_log`): `ok` on
success, `error` (with the message) on failure — including a Talk/Deck run whose
service account has since been removed.

**Atomicity:** record create/update/delete wrap their multi-table writes
(`df_records` + the value/file/ref tables + history) in a single DB transaction;
a mid-write failure rolls the whole change back rather than leaving orphaned or
missing value rows.

**Bulk import bypasses automations by design.** A CSV import writes rows via a
no-events path inside one transaction and is row-capped — importing 1,000 rows
must not fire 1,000 webhooks/emails. Run the relevant automations manually (or
re-save records) if a bulk load should trigger them.

## Hard limits (from the no-list)

- **Server-side only**, sandboxed condition evaluation — never `eval`.
- **Webhooks are explicit and user-configured**; outbound calls are opt-in,
  logged, time-limited, and **SSRF-guarded** — the HTTP client refuses
  internal/loopback/link-local targets (`allow_local_address => false`) and never
  follows redirects, regardless of the instance's `allow_local_remote_servers`
  setting. No outbound calls by default.
- No arbitrary scripting in actions; actions are a fixed, audited set.

## Build order

1. ✅ Typed events on create/update/delete *(0.22)*.
2. ✅ `automations` table + `Automation` domain + OCS CRUD *(0.23)*.
3. ✅ `AutomationListener` + condition reuse + **action registry** + `notify`
   action *(0.23)*.
4. ✅ `email` and `set_field` actions *(0.24 / 0.25)*.
5. ✅ `webhook` action — http(s) only, time-limited, logged, optional HMAC
   signature *(0.25)*.
6. ✅ Automations **builder UI** (manager-only tab, reusing the condition rows)
   *(0.24)*.
7. ✅ Hardening *(0.26)*: deferred actions (email/webhook) moved to a background
   job; record writes wrapped in transactions with post-commit dispatch; webhook
   SSRF guard; bulk import no longer amplifies automations.
8. ⏳ Optional: stage transitions + stage-based permissions.

The planned action set (notify · email · set-field · webhook) is complete. Each
step shipped independently and is verified via the API.
