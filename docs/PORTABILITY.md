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

## Remaining verification (environment-dependent)

The code review is complete and clean. What remains is **running** the migration
+ a CRUD/filter/sort pass on each engine, which needs those database servers:

```bash
# Example: spin up the app against MySQL and PostgreSQL test instances,
# run `occ maintenance:install` + `occ app:enable dataforms`, then exercise:
#   - migrations apply cleanly (df_* tables + indexes)
#   - create/list/filter/sort/search records
#   - the IN-subquery filter & search paths
```

This is the standard Nextcloud app CI matrix; wiring it up is the only open item
for the portability acceptance criterion.
