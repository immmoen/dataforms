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
action    : notify | email | set_field | provision_folders | webhook
```

### Provisioning actions (replacing an external flow runner)

`provision_folders` is the first of the actions that let DataForms drive
intake → workspace setup *without* an external engine (Windmill/n8n) or even
Nextcloud Flow. It creates a folder tree in the **record author's** Files from
`{machineName}` templates filled with the record's values (e.g. a client-intake
form creates `Clients/{client_name}/Contracts`). Every path segment is sanitised
(no `/`, `\`, `..`, control chars — a field value can never escape its segment),
creation is `mkdir -p`/idempotent, and it runs only inside the author's own
Files, so it can do nothing the author couldn't do by hand. Planned siblings:
`apply_template` (copy template files in) and `set_share` (grant access) — each
added the same way, a new `IAction` class in the registry, and each must clear
the same hardening bar.

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

## Execution model (0.26 — hardened)

Actions are split by cost so a slow or hostile endpoint can never block a record
write or exhaust the PHP worker pool:

- **Inline actions** (`notify`, `set_field`) are cheap, internal and loop-safe.
  They run **synchronously** in `AutomationListener` — but only *after* the
  record's writes have committed (events are dispatched post-commit), so they
  never observe a half-written or rolled-back row.
- **Deferred actions** (`email`, `webhook`, `provision_folders`) have slow or
  external side effects (SMTP, outbound HTTP, filesystem I/O). The listener does
  **not** run them; it enqueues a single
  `OCA\Dataforms\BackgroundJob\RunAutomationsJob` (an `OCP\BackgroundJob`
  `QueuedJob`). The job re-reads the register's currently-enabled automations
  against the captured value snapshot and runs only the deferred ones — picking
  up any enable/disable change made between the write and the job firing.
  Each action declares its lane via `IAction::isDeferred()`.

**Operational requirement:** because email/webhook delivery happens in the
background queue, the instance must run **system cron** (the recommended
Nextcloud setup) for timely delivery. With AJAX cron, delivery happens on the
next page load; with no cron, deferred actions never run.

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
