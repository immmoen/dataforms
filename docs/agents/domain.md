# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

This is a **single-context** repo: one app, one domain.

## Before exploring, read these

- **`CONTEXT.md`** at the repo root (the domain glossary / ubiquitous language). It may not exist yet — see below.
- **`docs/DECISIONS.md`** — this project's append-only architectural decision log (its ADR equivalent). Read the decisions that touch the area you're about to work in before proposing changes.
- Supporting design docs live under `docs/` — notably `VISION.md` (scope boundaries), `docs/PORTABILITY.md` (data-layer constraints), `docs/API.md` (API contract), `docs/WORKFLOW.md` and `docs/FORMS_AND_USAGE.md`.

If `CONTEXT.md` doesn't exist, **proceed silently**. Don't flag its absence; don't suggest creating it upfront. The `/domain-modeling` skill (reached via `/grill-with-docs` and `/improve-codebase-architecture`) creates it lazily when terms actually get resolved. New decisions are appended to `docs/DECISIONS.md`, not to a `docs/adr/` directory.

## File structure

```
/
├── CONTEXT.md              ← domain glossary (created lazily by /domain-modeling)
├── VISION.md               ← scope: what DataForms is and is NOT
├── docs/
│   ├── DECISIONS.md        ← append-only decision log (ADR equivalent)
│   ├── API.md              ← API contract
│   ├── PORTABILITY.md      ← data-layer constraints
│   └── ...
└── lib/  src/  tests/
```

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `CONTEXT.md`. Don't drift to synonyms the glossary explicitly avoids. The core nouns are already established in `VISION.md` and `CLAUDE.md`: **register**, **field / schema**, **record**, **form**, **view**, **rule**, **automation**.

If the concept you need isn't in the glossary yet, that's a signal — either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

## Flag decision conflicts

If your output contradicts an existing decision in `docs/DECISIONS.md`, surface it explicitly rather than silently overriding:

> _Contradicts the "Not a survey tool" decision (docs/DECISIONS.md) — but worth reopening because…_
