<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# DataForms — Nextcloud Readiness Audit

**App:** DataForms (`dataforms`) · Nextcloud 32 · Vue 3 SPA + PHP backend
**Scope:** App-Store readiness, host-instance scalability, performance, security
**Method:** multi-agent audit (6 dimensions) with adversarial verification of every finding.
**Result:** 34 confirmed findings — 5 high · 9 medium · 16 low · 4 info. No critical.

> ### ✅ Update (v0.26.0): all 5 High blockers fixed & verified
> Webhook SSRF guard (H1), webhook/email moved to a background `QueuedJob` with
> post-commit event dispatch (H2), CSV import no longer fires per-row automations
> (H3), record writes wrapped in DB transactions (H4), and the `info.xml`
> placeholders removed (H5). Confirmed by a 13-assertion functional test against
> the running instance: transactional CRUD round-trip; inline `set_field` runs
> synchronously while the webhook is deferred to a job; loopback SSRF blocked with
> `LocalServerException`; a 3-row bulk import fires **zero** automation jobs.

> ### ✅ Update (v0.27.0–0.29.0): all should-fix Mediums fixed & verified
> The two security Mediums — **M3** cross-register relation read/write leak and **M4** CSV
> formula injection — are closed (0.27.0). The scalability trio is done (0.28.0): **M6**
> field-scoped record search, **M7** single-JOIN unified search, **M8** `[register_id, updated]`/
> `[created]` sort indexes. And the data-lifecycle pair: **M2** field-name tombstoning (0.28.0)
> and **M1** register cascade-purge behind a 30-day retention `TimedJob` (0.29.0). Confirmed by
> 16 further functional assertions against the running instance. The only remaining Medium is
> **M5** (cap automations/recipients — latency polish; the H2 queue already removed its DoS
> risk). What's left is the Low/Info polish backlog and the release-readiness track (CI,
> a11y, signing, 100k perf re-run).

---

## 1. Executive summary

### Verdict (at audit time): **NOT store-ready.** Two hard blockers, both in the workflow engine. — *All five now resolved in v0.26.0; see the banner above.*

DataForms is a well-architected app with several genuinely good security properties
(no `v-html`/`innerHTML` anywhere, HMAC-signed webhooks, per-register ACLs enforced
server-side, portable QueryBuilder SQL, lazy-loaded form bundle). The data model and
rules engine are solid. **However, the entire Workflow/automation subsystem (0.23–0.25)
is architecturally unsafe for a shared Nextcloud instance:** it performs unbounded
outbound HTTP and SMTP *synchronously on the record write path*, with no SSRF guard and
no background-job offloading. This is the single largest risk and a known App-Store
reviewer red flag.

A second cluster concerns **data integrity** (no DB transaction around multi-table
writes; orphaned child rows on delete) and **read-path scalability** (missing sort index,
unindexed search). None of the read-path items block submission, but they degrade a busy
instance and should be fixed before the 100k-record target is credible.

### Top 5 must-fix

| # | Item | Why it blocks | File |
|---|------|---------------|------|
| **1** | **SSRF + data exfiltration in WebhookAction** — manager URL posted with full record `values`, no local-address guard, no redirect limit | A non-admin Manage user can reach the internal network / cloud metadata and exfiltrate record data. Hard store blocker. | `lib/Workflow/WebhookAction.php` |
| **2** | **Synchronous side-effecting actions on the write path** — webhook (≤15s) + SMTP + per-recipient notify run inline; no background queue exists | A dead webhook can pin and exhaust the PHP-FPM pool and degrade the whole instance. Hard store blocker. | `RecordService.php` → `AutomationListener.php` → actions |
| **3** | **CSV import fires the full automation chain per row** — `ImportService` loops `create()`, no batching/suppression | 1,000-row import = up to 1,000 sequential ≤10s POSTs in one request → timeout + partial import + worker pinned | `lib/Service/ImportService.php` |
| **4** | **Record create/update write 5 tables with no transaction** — `update()` deletes values then re-inserts; a mid-write throw loses data | Data-integrity corruption on the primary write path | `lib/Service/RecordService.php` |
| **5** | **info.xml placeholders** — `REPLACE_ME` donation URLs, missing screenshot, empty author mail | Reviewer rejection signals; trivial to fix | `appinfo/info.xml` |

Items 1–4 share one clean fix: **move side-effecting actions off the request thread into
an `OCP\BackgroundJob` queue, wrap the DB writes in a transaction, and assert the
local-address guard on the webhook client.** That neutralises five high findings at once.

---

## 2. Findings by severity

### CRITICAL
*None.* Two findings were proposed as critical but adversarial verification down-graded
both: the webhook SSRF's worst-case credential theft is gated by instance config; and the
"global search scans `df_record_values`" claim was **false** — that path runs only on a
single register's own record-search box.

### HIGH

**H1 — SSRF & record-data exfiltration in WebhookAction** (`lib/Workflow/WebhookAction.php`)
`run()` validates only the URL scheme, then posts the **entire record** (`values`) with no
`allow_local_address => false`, no host allow-list, no redirect limit. Reachable by any
**Manage** user (grantable via a normal share, not admin-only). External SSRF +
data-exfiltration is unconditional; internal-network/metadata access depends on the
instance's `allow_local_remote_servers` setting.
*Fix:* pass `'nextcloud' => ['allow_local_address' => false]` to `post()`; cap/disable
redirects and re-validate redirect targets; optional admin domain allow-list; validate the
URL at save time in `AutomationService::create`.
**✅ FIXED (0.26.0):** `WebhookAction` now passes `allow_local_address => false`
and `allow_redirects => false`; `AutomationService` rejects non-http(s) webhook
URLs at save time. Verified: loopback POST throws `LocalServerException`.

**H2 — Side-effecting actions run synchronously on the write path (instance-wide DoS)**
(`RecordService` → `AutomationListener` → `WebhookAction`/`EmailAction`/`NotifyAction`)
`create/update/delete` dispatch events inline; the listener runs every matched action
in-process. Webhook POSTs block ≤10s (×N automations, sequential); SMTP blocks; notify is
one insert per recipient. **No background-job infrastructure exists in the repo.** On a
bounded `pm.max_children`, a slow/black-holed webhook plus concurrent writers can saturate
the FPM pool and take down the whole instance.
*Fix:* enqueue matched work via `OCP\BackgroundJob\IJobList` (a `QueuedJob`) and dispatch
the actions in the job; route webhook + email to the queue (keep notify/set_field inline
if desired). Interim: hard-cut timeouts (connect 2s/read 3s), cap webhooks/request, add a
per-URL circuit breaker.
**✅ FIXED (0.26.0):** `IAction::isDeferred()` splits actions into inline
(notify/set_field) and deferred (email/webhook). The listener runs inline actions
synchronously and enqueues a `RunAutomationsJob` (`QueuedJob`) for deferred ones;
events dispatch only after the write commits. Requires system cron for delivery
(documented in `WORKFLOW.md`). Verified: webhook automation enqueues a job rather
than blocking the write.

**H3 — CSV import fires the full automation+webhook+email chain per row**
(`lib/Service/ImportService.php`) The import loop calls `create()` per row with no
chunking/transaction/event suppression. A 1,000-row import into a register with an
on-create webhook = up to 1,000 sequential ≤10s POSTs in one HTTP request → exceeds
`max_execution_time`, leaving a partial, non-transactional import while pinning a worker.
*Fix:* move import into a background job (controller persists upload, enqueues, returns a
pollable id); per-batch transactions; a `createBulk`/`$dispatchEvents=false` variant so
per-row automations don't fire thousands of times; interim row-count guard on the endpoint.
**✅ FIXED (0.26.0):** new `RecordService::createForImport()` writes rows without
dispatching events; `ImportService` wraps the whole file in one transaction and
caps it at 5,000 rows/request. Bulk import bypasses automations by design.
Verified: a 3-row import creates 3 records and fires **zero** automation jobs.
*(Fully backgrounding very large imports remains a post-launch nice-to-have.)*

**H4 — Record create/update touch 5 tables with no wrapping transaction (partial-write
corruption)** (`lib/Service/RecordService.php`) No `beginTransaction/commit/rollBack`
anywhere in `lib/`. `update()` is the dangerous path: it `deleteByRecord()` first, so any
throw during re-insert (deadlock, over-length `value_string` under MySQL strict mode,
connection drop) leaves the record with *fewer* values — silent data loss. Events then
fire on a half-written record.
*Fix:* inject `OCP\IDBConnection`; wrap create/update bodies in a transaction; dispatch
events **after** commit; optionally reject/truncate over-length `value_string`.
**✅ FIXED (0.26.0):** `RecordService` injects `IDBConnection` and runs
create/update/delete writes through an `atomically()` helper
(`beginTransaction`/`commit`/`rollBack`); events dispatch only after commit.
Verified: value rows are intact after the atomic create/update path.

### MEDIUM

- **M1 — Register deletion orphans 8 child tables (storage leak)** ✅ **FIXED (0.29.0)**
  (`RegisterService::delete`) cleaned only shares/views/forms; left `df_automations`,
  `df_rules`, `df_fields`, `df_records`, `df_record_values`, `df_rec_files`, `df_rec_refs`,
  `df_history` forever. *Fixed:* registers stay soft-deleted (recoverable) for a 30-day
  retention window; a daily `PurgeDeletedRegistersJob` (`TimedJob`) then calls
  `RegisterPurgeService::purge`, which hard-deletes every child row across all df_* tables —
  including **incoming** relation rows from other registers — inside one transaction.
  Verified: purge removes all of a register's rows + foreign refs, leaves unrelated registers
  intact, and respects the retention window (aged purged, recent kept).
- **M2 — Field deletion leaves dangling `machineName` references; reuse silently re-binds**
  ✅ **FIXED (0.28.0)** (`FieldService::delete`) `ensureUnique` checked only live rows, so a
  deleted name could be reused and a stale rule silently re-bind to a new, unrelated field.
  *Fixed:* fields are now soft-deleted (`df_fields.deleted_at`) — the row survives as a name
  tombstone so `machineNameExists` keeps the name reserved (a reused name gets `_2`), while
  `findByRegister`/`maxPosition` exclude it. Stored values/files/refs are still cleaned; the
  tombstone is hard-removed by register purge. Verified: name stays reserved after delete,
  active list excludes it, values cleaned. *(Stale references to a deleted field are now inert
  rather than silently re-bound; forms self-heal their field list on next save.)*
- **M3 — Relation label resolution leaks values across the sharing boundary** ✅ **FIXED (0.27.0)**
  (`RecordService::resolveRelations`/`labelsForRecords`) resolved labels for a relation's
  `targetRegisterId` with **no** read gate (unlike `options()`). A Write user could store an
  arbitrary `target_record_id` into a relation and read back a display-field value from a
  register they can't access. *Fixed:* `resolveRelations` now takes the viewing user and
  resolves labels only for target registers they can read (memoised `canRead`), anonymising
  to `#id` otherwise; `storeRefs` validates every target id is a live record in the field's
  configured target register (`RecordMapper::existingIdsInRegister`) and rejects others.
  Verified: a non-reader sees `#id`, not the value; invalid/wrong-register ids rejected on write.
- **M4 — CSV formula injection in exports** ✅ **FIXED (0.27.0)** (`ExportController`) values/labels
  were written verbatim; a stored `=HYPERLINK(...)`/`=cmd|...` executes when a victim opens the
  export. *Fixed:* a `csvSafe()` helper prefixes cells beginning with `= + - @` or a control
  char (tab/CR/LF) with a single quote, applied to both headers and data; genuine numbers
  (incl. negatives/decimals) pass through. Verified against 9 payload/number cases.
- **M5 — Synchronous SMTP + per-recipient notify add write-path latency** — subsumed by H2's
  queue; cap recipient-list and automations-per-register.
- **M6 — Per-register record search runs an unindexed leading-wildcard LIKE over
  `df_record_values`** ✅ **FIXED (0.28.0)** (`RecordMapper::applySearch`) had no `field_id`
  predicate, so the value subquery scanned the value table instance-wide. *Fixed:* search is
  now scoped to the register's searchable string-field ids (`field_id IN (…)`), bounding the
  scan to one register's text values; a register with no string fields short-circuits to no
  matches. Verified: term matches the right rows, numbers aren't matched as text, no-string-field
  register returns empty. *(Substring semantics preserved; FULLTEXT/GIN left as a later option.)*
- **M7 — Unified-search issues O(N) queries per register on every global search**
  ✅ **FIXED (0.28.0)** (`FormService::searchForPicker`) looped `findByRegister` per readable
  register on every keystroke instance-wide. *Fixed:* one `FormMapper::searchForUser` JOIN over
  the readable register ids (resolved once) returns all matching forms + their register titles —
  2 queries total regardless of register count. Verified: lists forms across registers, term
  filters by form or register title.
- **M8 — Default record list (sort by `updated`) has no supporting index → filesort**
  ✅ **FIXED (0.28.0)** (`RecordMapper::findByRegister`) only `[register_id]`/`[register_id, seq]`
  existed. *Fixed:* migration adds `[register_id, updated]` and `[register_id, created]` to
  `df_records`. Verified present via schema introspection.
- **M9 — Missing/broken App-Store screenshot** (`info.xml`) the `<screenshot>` raw URL points
  at a file not in `img/`. *Fix:* commit a real screenshot (confirm HTTP 200) or remove the
  line.

### LOW (16)

L1 placeholder donation URLs · L2 empty author mail · L3 version drift (info.xml 0.25.0 vs
package.json 0.1.0) · L4 unvalidated `file_id` stored on records · L5 user regex → ReDoS
(add `(*LIMIT_MATCH=…)`) · L6 `findActive` query per write even with zero automations ·
L7 double value-snapshot on update · L8 timeout window after commit (idempotency) ·
L9 filter `contains` leading-wildcard · L10 per-relation-field re-fetch · L11 `resolveFiles`
Files-API `getById` per id · L12 `countByRegister` re-runs subqueries · L13 `seq` via MAX+1
race (make `(register_id, seq)` UNIQUE) · L14 brand inconsistency Dataforms vs DataForms ·
L15 reference widget eagerly imports ~485 KB Vue vendor chunk (externalise) ·
L16 select/multiselect option arrays uncapped.

### INFO (4)

I1 unverified GitHub org for website/bugs/repository · I2 SQL reserved words
`trigger`/`condition` as column names (safe via QueryBuilder) · I3 `l10n/` ships no
compiled catalogs (strings are externalised; narrow the Makefile `--exclude=/*.json`
before shipping translations) · **I4 positive: XSS-safe frontend** (no `v-html`/`innerHTML`;
minor: validate `richObject.url` scheme before `anchor.href` in `reference.js`).

---

## 3. Scalability & instance-impact summary

**On a busy shared instance, the dominant risks are write-path, not read-path.**

- **Worker-pool exhaustion (H2/H3/M5):** every record write dispatches automations
  synchronously; one slow webhook holds an FPM worker ≤15s, multiple compound, import
  multiplies by row count. Fix = one `IJobList` queue for webhook+email; chunked import job;
  caps. Highest-leverage change in the codebase.
- **No transactional integrity (H4):** 5-table writes with no rollback; `update()` can lose
  values. Fix = transaction + dispatch after commit.
- **Unbounded storage growth (M1):** ✅ fixed (0.29.0) — 30-day retention + daily purge job.

**At 100k+ records** the read path is now in good shape: **M8** (added `[register_id, updated]`/
`[created]` indexes) ✅, **M6** (search field-scoped to one register) ✅, **M7** (unified search
collapsed to a single JOIN — the one item that touched *other* apps) ✅. The remaining read-path
items are **L11/L10** (per-row Files/relation lookups → batch), which are low-impact polish.

**Net:** ✅ **resolved in 0.26.0.** Backgrounding the side-effecting actions, wrapping the
writes in transactions, and adding the SSRF guard converted the automation engine from
"instance-risk" to "instance-safe." The remaining scalability items (M1 storage leak; M6/
M7/M8 read-path indexes) are degradation, not instance threats, and stay on the backlog.

---

## 4. Store-submission checklist

**Blocking (fix before any submission) — ✅ all done in 0.26.0:**
- [x] **H1** — `allow_local_address => false` + `allow_redirects => false` in `WebhookAction`; non-http(s) URL rejected at save.
- [x] **H2/H3** — webhook + email moved off the request thread into a `RunAutomationsJob` (`IJobList`); bulk import bypasses automations and is row-capped; events dispatch post-commit.
- [x] **H4** — `atomically()` transaction around `create/update/delete`; events dispatched after commit.

**Should-fix before submission — ✅ all done:**
- [x] **M3** read gate in `resolveRelations` + target-id validation on write *(0.27.0)*.
- [x] **M4** CSV formula neutralisation in exports *(0.27.0)*.
- [x] **M6** field-scoped record search · **M7** single-JOIN unified search · **M8** `[register_id, updated]`/`[created]` indexes *(0.28.0)*.
- [x] **M2** field-name tombstoning *(0.28.0)* · **M1** register cascade-purge + 30-day retention job *(0.29.0)*.
- [ ] **M5** cap automations-per-register / recipient-list (latency polish; the queue from H2 already removed the DoS risk).

**App-Store metadata (`info.xml`):**
- [x] **H5** — removed `REPLACE_ME` donation URLs, the broken screenshot reference, and the empty author `mail` attribute; version bumped to 0.26.0.
- [ ] add a real `<screenshot>` (full URL, HTTP 200) and donation links once a public repo/pages exist (L1/M9) · verify repo org (I1) · align brand to **DataForms** (L14).

**Release hygiene (Track B):**
- [x] **PHP CI gate now runs and is green** — fixed the psalm config (it could never run: stale stub path) and the unit bootstrap (OCP autoload); cs-fixer clean, psalm "No errors found!" at errorLevel 2, phpunit 38/38.
- [x] **a11y (WCAG 2.1 AA) pass** over all Vue components — 20 findings fixed (labels/names, keyboard operability, error/status announcements, decorative-icon hiding, colour-not-only).
- [x] **100k-record perf re-verified** — default list/deep-page index-served at ~27/36 ms (see PORTABILITY.md §5); surfaced a data-field-sort scaling caveat.
- [ ] **Frontend CI lint** — `@nextcloud/eslint-config` / `@nextcloud/stylelint-config` peer-dep chain is missing from `package.json` (incl. a beta-only resolver), so `eslint`/`stylelint` can't run yet; build + vitest are green. Add the peer deps + regenerate the lockfile, then clear whatever the linters surface.
- [ ] App **signing/cert** flow + Makefile `--exclude=/*.json` doesn't strip l10n (I3) · Transifex · bump/remove package.json version (L3).

**Nice-to-have (post-launch):** data-field sort covering index (PORTABILITY §5) · L5 ReDoS limits · L4 file-id validation · L10/L11/L12 query batching · L13 seq uniqueness · L15 reference bundle size · L16 option cap · I2/I4 hygiene.

---

**Bottom line:** the three workflow blockers (H1–H3), the transaction gap (H4), and the
`info.xml` placeholders (H5) are **fixed and verified in 0.26.0** — DataForms is now safe
for a busy multi-tenant Nextcloud instance and a credible App-Store submission. The
remaining Medium/Low items (relation read gate, CSV-injection guard, cascade-delete, the
three read-path indexes, brand/metadata polish, CI) are the should-fix backlog, none of
them instance-threatening.
