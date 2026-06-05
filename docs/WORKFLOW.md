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

1. ✅ Typed events on create/update/delete *(done)*.
2. `automations` table + `Automation` domain + OCS CRUD.
3. `AutomationListener` + condition reuse + an **action registry** with the first
   action (`notify` — a Nextcloud notification; no external surface).
4. `email` and `set_field` actions.
5. `webhook` action (explicit, logged, rate-limited).
6. A rule-builder-style **UI** to define automations (reusing the condition UI).
7. Optional: stage transitions + stage-based permissions.

Each step is independently shippable and testable (shared fixtures, like the
rule engine).
