<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Decisions log (ADRs)

Short, dated records of *why*. Append-only; supersede rather than rewrite.

## 2026-06 — Name: "DataForms"

The product noun is **DataForms** (register / structured-data workspace).

## 2026-06 — Not a survey tool

DataForms will **not** become a survey/consultation platform. Surveys are a deep,
distinct domain already served by **EUSurvey** (heavy/official) and **Nextcloud
Forms** (light). Building survey features would (a) collide with the
internal-only constraint (surveys need public/anonymous), (b) duplicate mature
tools, and (c) bloat the app toward incoherence. We **integrate** (a survey tool
can POST responses into a register via the API); we do not rebuild.

## 2026-06 — API-first; integrate, don't absorb

The REST API is a first-class contract; the SPA is one client among equals.
External systems integrate by authenticating as a Nextcloud user (app password)
and calling the API. We expose and document the API rather than building bespoke
connectors or absorbing other products' scope.

## 2026-06 — Reuse Nextcloud auth for machine access

Machine/API credentials use Nextcloud **app passwords** (revocable, rate-limited,
audited). We do not build a custom token system.

## 2026-06 — Workflow is the differentiator (next major build)

The next significant capability is **workflow**: a `Trigger → Action` primitive
plus stages and stage-based permissions — server-side only (no JS mirror).

## 2026-06 — Coverage posture: freeze the baseline, ratchet per-touched-file ("gate B")

The PRD #1 acceptance gate ("`docs/test-plan.md` 100% green via named Playwright
tests") is **superseded**. After the #2–#16 programme took coverage from ~1%
front / ~6% back to a strong baseline, that baseline is **frozen as the floor**
and we converge to 100% **incrementally**, not by backfilling ~80 redundant E2E
tests for logic already proven at the right unit/integration seam.

Going-forward gate (**gate B**, enforced in `.github/workflows/lint.yml` via
`.github/scripts/touched_files_coverage.py`): **every production source file
*touched* in a PR must be 100% covered — the whole file, not just the changed
lines.** Scope is the single source of truth already in `tests/phpunit.xml`
`<source>` and `vitest.config.js` `coverage.include/exclude` (an out-of-scope
file never appears in the reports, so the gate skips it — no second exclude
list). PHP coverage is the **union of the unit clover and the integration clover**
(mappers are measured only by the integration suite), so a touched mapper is
judged on its real coverage. The global nightly ratchet stays a coarse unit-only
floor (it can only rise); gate B is the union-aware per-file gate.

**Disciplined coverage-ignore is allowed.** `@codeCoverageIgnore` (PHP) and
`/* c8 ignore next */` (JS) may exclude **provably-unreachable defensive code**
only (a `throw` after an exhaustive whitelist, the `default` of an exhaustive
`switch`), each with a one-line justifying comment. Without this, gate B is
impossible for files like `ExpressionEvaluator` whose last lines are dead guards.

## 2026-06 — test-plan.md is a manual exploratory recette document

`docs/test-plan.md` is **no longer an automated-traceability matrix / acceptance
gate**. It is a **manual exploratory recette guide for a QA tester**: per-scenario
preconditions, steps, expected result, and a result column to fill in, plus an
exploratory posture and charters. The "what's already automated" mapping is kept
as an appendix. **Living convention: when tests or features change, update the
recette doc in the same PR.** Automated confidence is owned by gate B above; the
recette doc owns human acceptance.
