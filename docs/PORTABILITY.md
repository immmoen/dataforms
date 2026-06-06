<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Database portability review

The app must run on **MySQL/MariaDB, PostgreSQL and SQLite** (and not break on
Oracle). This note records the review of the data layer against that requirement.

## Findings — all green

- **All access goes through the abstraction.** Every query uses `QBMapper` /
  `IQueryBuilder`; every schema change uses migration `ISchemaWrapper`. There is
  **no raw SQL**, no string-concatenated values, and no `$qb->createFunction()`
  with engine SQL.
- **Only portable functions** are used: `func()->count()`, `func()->max()`,
  `expr()->iLike()`, `expr()->in()`, `selectAlias()`. Case-insensitive search
  uses `iLike()` (portable) with `escapeLikeParameter()` for the operand.
- **No engine-specific syntax** — no `NOW()`, `JSON_*`, `RLIKE`, `GROUP_CONCAT`,
  `::` casts, `DATE_*`, prefix-index SQL, or `LIMIT a,b`. Pagination uses
  `setMaxResults()` / `setFirstResult()`.
- **Booleans are nullable** (`notnull => false`) per the Nextcloud rule that
  `NOT NULL` booleans can't default to `false` on Oracle. Timestamps are stored
  as `BIGINT` epoch seconds, not DB date types — fully portable.
- **The long-text value column index** (`df_record_values.value_string`) uses a
  64-char **prefix length** via the `lengths` option, which Doctrine applies on
  MySQL/MariaDB and omits on SQLite/PostgreSQL (which index the full value).
- **Parameterised everywhere** — all user values are bound with
  `createNamedParameter()`; no SQL injection surface.

## One pattern to keep an eye on

`RecordMapper` builds a couple of `… r.id IN (<subquery>)` clauses by embedding a
second query builder's `getSQL()` into the outer query. This is **portable** (it
emits no engine-specific syntax) and the named parameters are created on the
**outer** builder so they resolve correctly. It is verified on SQLite. It is the
only place worth re-checking when running the live cross-DB matrix below.

## Live cross-database verification — done ✅

The review above was confirmed by **running the app on each engine** (fresh
Nextcloud 32 instances, `occ app:enable dataforms` to apply all migrations, then
a CRUD + filter/search/sort pass through the OCS API):

| Database | All migrations (12 `df_*` tables) | CRUD | Filter (`IN`-subquery) | Search | Sort |
|----------|:--:|:--:|:--:|:--:|:--:|
| **SQLite** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **MySQL / MariaDB 10.11** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **PostgreSQL 16** | ✅ | ✅ | ✅ | ✅ | ✅ |

The `IN`-subquery filter/search path (the one pattern flagged above) returns
correct results on all three engines. Portability acceptance criterion: **met.**
The standard move from here is to wire this same matrix into CI.

## Performance at scale (§5) — re-verified ✅ (post-index, 0.29.0)

Re-tested against a freshly bulk-loaded **100,000-record** register (SQLite,
with the `df_record_values.value_string` index and the new
`[register_id, updated]`/`[register_id, created]` indexes from audit M8). These
numbers are **service-layer** round-trips (PHP + DB, incl. the `COUNT` each list
runs); add the HTTP + OCS envelope for the full request latency.

| Operation (100k records) | Latency |
|--------------------------|--------:|
| Bulk load of 100,000 records (chunked transactions) | ~22.5 s |
| List, page 1 (limit 25, default `updated` sort) | **~27 ms** |
| List, deep page (offset 50,000) | **~36 ms** (flat — pagination doesn't degrade) |

The default list and deep pagination are now **index-served** (the M8 indexes
removed the filesort) and stay tens-of-milliseconds even at 100k — comfortably
within the NFR.

**Known scaling caveat — ordering by a *data field*.** Sorting a 100k register
by a value-column field (e.g. a number field) is the heaviest read path: it
correlates each record to its value row (`LEFT JOIN df_record_values … ORDER BY
sv.value_*`) and the planner falls back to a large sort, which did **not** stay
sub-second in re-testing. The default `updated`/`created`/`seq` sorts (the common
case, and the only ones backed by a `df_records` index) are fast; per-field sorts
on very large registers are the exception. The fix, if data-field sorts on large
registers become common, is a covering index strategy on `df_record_values`
(e.g. `(field_id, value_number, record_id)`) or a denormalised sort column — left
as a future optimisation, tracked alongside the L10/L11 read-path items.

Filter (indexed `IN`-subquery) and full-text search were sub-second in the prior
full-round-trip run and are unchanged by the M8 work; they were not re-timed in
this pass (the harness was stopped after surfacing the data-field-sort caveat).
