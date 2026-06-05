<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# DataForms API

DataForms is **API-first**: the whole app runs on this REST API, and any
external system can use the same endpoints. This lets other tools (e.g. a survey
platform) push records into a register, or read them out, without DataForms
having to absorb their features.

> **Internal & authenticated only.** Every call authenticates as a Nextcloud
> user, and every endpoint re-checks that user's per-register permissions
> (Read / Write / Manage) server-side. There is no anonymous access.

## Base URL

```
https://<your-nextcloud>/ocs/v2.php/apps/dataforms/api/v1/
```

## Authentication

Use a Nextcloud **app password** (not your login password) with HTTP Basic auth,
and send the OCS header on every request:

1. In Nextcloud: **Settings → Security → Devices & sessions → Create new app
   password**. Copy the generated token.
2. Send it as Basic auth (`username` + the app password) plus the header
   `OCS-APIRequest: true` and `Accept: application/json`.

App passwords are revocable and rate-limited — revoke one any time from the same
screen without touching the user's real password.

### Example

```bash
curl -u "alice:APP_PASSWORD" \
     -H "OCS-APIRequest: true" -H "Accept: application/json" \
     "https://cloud.example.org/ocs/v2.php/apps/dataforms/api/v1/registers"
```

All responses use the OCS envelope; the payload is under `ocs.data`.

## Endpoints

### Registers
| Method | Path | Purpose |
|---|---|---|
| GET | `registers` | List registers the user can see |
| POST | `registers` | Create a register `{title, description?, color?, icon?}` |
| GET | `registers/{id}` | Get one register |
| PUT | `registers/{id}` | Update |
| DELETE | `registers/{id}` | Soft-delete |

### Fields (schema) — manager only
| Method | Path | Purpose |
|---|---|---|
| GET | `registers/{id}/fields` | List fields |
| POST | `registers/{id}/fields` | Add a field `{label, type, config?, mandatory?, unique?, default?}` |
| PUT | `fields/{id}` | Update (type & machine name immutable) |
| DELETE | `fields/{id}` | Delete |

### Records
| Method | Path | Purpose |
|---|---|---|
| GET | `registers/{id}/records` | List — params: `limit, offset, sort, direction, search, filter` |
| POST | `registers/{id}/records` | Create `{values: {machineName: value}}` |
| GET | `records/{id}` | Get one |
| PUT | `records/{id}` | Update `{values: {...}}` (send the full value set) |
| DELETE | `records/{id}` | Delete (honours relation integrity policies) |
| GET | `records/{id}/history` | Audit history |

### Forms / Views / Rules / Shares
| Method | Path | Purpose |
|---|---|---|
| GET/POST | `registers/{id}/forms` | Data-entry forms |
| GET/POST | `registers/{id}/views` | Saved views |
| GET/POST | `registers/{id}/rules` | Conditional rules |
| GET/POST | `registers/{id}/shares` | Access control |
| GET | `registers/{id}/sharees?search=` | User/group search for sharing |

### Create a record (example)

```bash
curl -u "alice:APP_PASSWORD" \
     -H "OCS-APIRequest: true" -H "Content-Type: application/json" -H "Accept: application/json" \
     -X POST \
     -d '{"values":{"supervisory_authority":"Ireland","reference_number":"IE-2025-001","case_status":"open"}}' \
     "https://cloud.example.org/ocs/v2.php/apps/dataforms/api/v1/registers/12/records"
```

Field **machine names** (not labels) are the keys in `values`. Get them from
`GET registers/{id}/fields` (`machineName`). Select/multi-select values must
match the field's configured options exactly; multi-value fields take arrays.

## Notes & limits

- **Pagination:** list endpoints page server-side (`limit`/`offset`); default
  page size is modest — page through large registers.
- **Filtering:** `filter` is a JSON array of `{field, op, value}` conditions.
- **Permissions:** writing needs Write on the register; schema/forms/rules/shares
  need Manage. A Write user can edit/delete only records they created.
- **Errors:** standard HTTP status in the OCS envelope (`400` validation, `403`
  forbidden, `404` not found, `422` blocked by an integrity rule).

## A formal OpenAPI spec

A machine-readable OpenAPI description of the core surface lives at
[`openapi.json`](../openapi.json) (registers + records). It can be imported into
Postman/Swagger. The full surface is documented above.
