<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# DataForms — Nextcloud Readiness Audit

**App:** DataForms (`dataforms`) · Nextcloud 32 · Vue 3 SPA + PHP backend
**Scope:** App-Store readiness, host-instance scalability, performance, security
**Method:** multi-agent audit (6 dimensions) with adversarial verification of every finding.
**Result:** 34 confirmed findings — 5 high · 9 medium · 16 low · 4 info. No critical.

---

## 1. Executive summary

### Verdict: **NOT store-ready yet.** Two hard blockers, both in the workflow engine.

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

**H3 — CSV import fires the full automation+webhook+email chain per row**
(`lib/Service/ImportService.php`) The import loop calls `create()` per row with no
chunking/transaction/event suppression. A 1,000-row import into a register with an
on-create webhook = up to 1,000 sequential ≤10s POSTs in one HTTP request → exceeds
`max_execution_time`, leaving a partial, non-transactional import while pinning a worker.
*Fix:* move import into a background job (controller persists upload, enqueues, returns a
pollable id); per-batch transactions; a `createBulk`/`$dispatchEvents=false` variant so
per-row automations don't fire thousands of times; interim row-count guard on the endpoint.

**H4 — Record create/update touch 5 tables with no wrapping transaction (partial-write
corruption)** (`lib/Service/RecordService.php`) No `beginTransaction/commit/rollBack`
anywhere in `lib/`. `update()` is the dangerous path: it `deleteByRecord()` first, so any
throw during re-insert (deadlock, over-length `value_string` under MySQL strict mode,
connection drop) leaves the record with *fewer* values — silent data loss. Events then
fire on a half-written record.
*Fix:* inject `OCP\IDBConnection`; wrap create/update bodies in a transaction; dispatch
events **after** commit; optionally reject/truncate over-length `value_string`.

### MEDIUM

- **M1 — Register deletion orphans 8 child tables (storage leak)** (`RegisterService::delete`)
  cleans only shares/views/forms; leaves `df_automations` (despite an unused
  `deleteByRecord`), `df_rules`, `df_fields`, `df_records`, `df_record_values`,
  `df_rec_files`, `df_rec_refs`, `df_history`. *(Not a security issue — deleted registers
  are filtered by `deleted_at IS NULL`; purely a storage-hygiene leak.)* *Fix:* cascade-clean
  in `delete()`, or a retention `TimedJob` that hard-purges children of long-deleted registers.
- **M2 — Field deletion leaves dangling `machineName` references; reuse silently re-binds**
  (`FieldService::delete`) Nothing rewrites rules/forms/views/automation `set_field` targets,
  and `ensureUnique` checks only live rows — so a deleted name can be reused and a stale rule
  silently re-binds to a new, unrelated field. *Fix:* tombstone deleted machine-names; sweep
  dependent metadata on delete.
- **M3 — Relation label resolution leaks values across the sharing boundary**
  (`RecordService::resolveRelations`/`labelsForRecords`) resolves labels for a relation's
  `targetRegisterId` with **no** read gate (unlike `options()`). A Write user can store an
  arbitrary `target_record_id` into a relation and read back a display-field value from a
  register they can't access. *Fix:* add the read gate; anonymise on `NotFoundException`;
  validate relation target ids on write.
- **M4 — CSV formula injection in exports** (`ExportController`) values/labels written
  verbatim; a stored `=HYPERLINK(...)`/`=cmd|...` executes when a victim opens the export.
  *Fix:* prefix cells beginning with `= + - @`/tab/CR with a single quote (data + headers).
- **M5 — Synchronous SMTP + per-recipient notify add write-path latency** — subsumed by H2's
  queue; cap recipient-list and automations-per-register.
- **M6 — Per-register record search runs an unindexed leading-wildcard LIKE over
  `df_record_values`, twice per page** (`RecordMapper::applySearch`) no `field_id` predicate
  + leading `%` makes the only index unusable; two large scans per searched page at 100k
  records. *Fix:* scope to searchable string-field ids; prefer anchored `term%` (or
  FULLTEXT/GIN); min term length; don't re-run for the count.
- **M7 — Unified-search `FormSearchProvider` issues O(N) queries per register on every
  global search** (`FormService::searchForPicker`) loops `findAll` → `findByRegister(forms)`
  per register on every unified-search keystroke instance-wide. *Fix:* single JOIN
  (`FormMapper::searchForUser`); compute accessible register-ids once; short-circuit on
  empty/short terms.
- **M8 — Default record list (sort by `updated`) has no supporting index → filesort**
  (`RecordMapper::findByRegister`) only `[register_id]`/`[register_id, seq]` exist. *Fix:*
  add `[register_id, updated]` (and `[register_id, created]`).
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
- **Unbounded storage growth (M1):** deleted registers leak rows in 8 tables forever. Fix =
  cascade-clean or retention job.

**At 100k+ records** the read path is *mostly* fine (paginated, capped at 500, portable
SQL) but has three hotspots: **M8** (default `updated` filesort → add index), **M6**
(unindexed text search → field-scope + anchored/FULLTEXT), **L11/L10** (per-row Files/
relation lookups → batch). **M7** is the one read-path item that touches *other* apps
(unified search O(N) per global keystroke).

**Net:** safe to run for a single team today, but the synchronous automation engine must
not ship as-is. Backgrounding side effects + transactional writes + the SSRF guard converts
it from "instance-risk" to "instance-safe."

---

## 4. Store-submission checklist

**Blocking (fix before any submission):**
- [ ] **H1** — `allow_local_address => false` + redirect cap in `WebhookAction`; validate URL at save.
- [ ] **H2/H3/M5** — webhook + email (+ import) off the request thread into `IJobList`; suppress per-row automations on bulk import; cap automations/recipients.
- [ ] **H4** — transaction around `create/update`; dispatch events after commit.

**Should-fix before submission:**
- [ ] **M3** read gate in `resolveRelations` · **M4** CSV formula neutralisation · **M1/M2** cascade/purge + tombstone field names · **M8/M6/M7** add `[register_id, updated]` index, field-scope record search, single-JOIN search provider.

**App-Store metadata (`info.xml` — resolve as one block):**
- [ ] donation URLs (L1) · real screenshot returning 200 or remove (M9) · author mail (L2) · verify repo org (I1) · remove placeholder comments · align brand to **DataForms** (L14).

**Release hygiene:**
- [ ] bump/remove package.json version (L3) · signing/cert flow + Makefile `--exclude=/*.json` doesn't strip l10n (I3) · Transifex · **a11y** keyboard/contrast pass · **CI** lint + Psalm/PHPStan + write-path smoke test.

**Nice-to-have (post-launch):** L5 ReDoS limits · L4 file-id validation · L10/L11/L12 query batching · L13 seq uniqueness · L15 reference bundle size · L16 option cap · I2/I4 hygiene.

---

**Bottom line:** fix the three workflow blockers (H1–H3) + the transaction gap (H4), then
clear the `info.xml` placeholder block — and DataForms is a credible App-Store submission
and safe for a busy multi-tenant Nextcloud instance.
