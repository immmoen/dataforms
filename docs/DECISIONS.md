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
