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
action    : notify | email | set_field | webhook        (start small)
```

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

## Hard limits (from the no-list)

- **Server-side only**, sandboxed condition evaluation — never `eval`.
- **Webhooks are explicit and user-configured**; outbound calls are opt-in,
  logged, and rate-limited. No outbound calls by default.
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
7. ⏳ Optional: stage transitions + stage-based permissions.

The planned action set (notify · email · set-field · webhook) is complete. Each
step shipped independently and is verified via the API.
