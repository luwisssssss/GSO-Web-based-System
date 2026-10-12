# Frozen database hosting compatibility

Reviewed 2026-10-12, Asia/Manila. Schema **20261010_008** remains unchanged. Target hosting has not been selected or tested. Local rehearsal proves **MariaDB 10.4.32 behavior only**, not production compatibility or suitability.

## Exact concern

| Object | Frozen definition |
|---|---|
| Table | `request_items` |
| Generated column | `facility_guard`, STORED `int(11)` |
| Expression | `case when line_kind = 'Facility' then request_id end` |
| Unique guard | `uq_request_items_one_facility` on `facility_guard` |
| Related FK | `fk_request_items_request`: `request_items.request_id` → `resource_requests.request_id` |
| ON DELETE | `CASCADE` |
| ON UPDATE | `RESTRICT` (implicit in DDL; confirmed in information_schema) |

The FK is on **the generated column's base column `request_id`**, not on `facility_guard`. The unique generated value limits a request to one facility line while allowing multiple nonfacility lines. Removing the guard, changing the cascading action, disabling FK checks, or changing stored/virtual semantics would change the frozen schema and is prohibited in this remediation.

MariaDB supports indexed STORED/PERSISTENT generated columns. The actual frozen DDL imports successfully on local MariaDB 10.4.32, including this FK/base-column combination; repeated migration is a no-op. This is observed engine behavior, not a claim that every MariaDB release/SQL mode accepts every generated-column FK arrangement. [MariaDB generated-column reference](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns)

MySQL 8.0 documents that foreign keys on base columns of stored generated columns cannot use CASCADE, SET NULL or SET DEFAULT referential actions. The frozen `ON DELETE CASCADE` therefore presents a specific incompatibility concern. **This is an inference from the documented restriction; no MySQL server was available for an import test.** Do not certify a MySQL version without rehearsal. [MySQL FK restrictions](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html)

The other generated columns are `request_item_units.open_unit_guard` (based on `returned_at` and `unit_id`) and `request_reschedules.pending_request_id` (based on `status` and `request_id`). Their related base-column FKs use RESTRICT, not the identified cascading action. All three expressions and all 57 FK actions are checked by the rehearsal tool. No schema, migration, R11/R12 metadata, or ERD file was changed.

## Path A — maintained MariaDB

Prefer a hosting provider offering a patched **MariaDB 11.4 LTS** release, maintained by the community through May 2029. Provider-supported 10.11 or another maintained branch can be considered, subject to exact-version rehearsal. **Do not deploy MariaDB 10.4**, whose maintenance has ended. [Current MariaDB maintenance policy](https://mariadb.org/about/#maintenance-policy)

Rehearse on the chosen engine, SQL mode and account privileges: empty-schema import, repeat migration, exact metadata comparison, restoration into a separate disposable database, and application regressions in an isolated test environment. Confirm CHECK enforcement, generated uniqueness, FK deletion behavior and character/collation semantics. Keep reports and server version; local XAMPP success cannot close this host gate.

## Path B — MySQL

Use the identical unchanged frozen schema in an explicitly disposable EMPTY staging database. If import fails, retain the partial staging DDL and private diagnostic log. **STOP and report the failing constraint separately.** Do not change the schema, manually skip a statement, remove uniqueness, or disable constraints. A schema compatibility redesign would require separate authorization and a new freeze/ERD review; it is outside this task. If import succeeds, complete the same metadata, enforcement, restore and application checks before approval.

## Safe rehearsal procedure

`scripts/staging_database_check.php` is CLI-only and belongs in a **server-private deployment-tools checkout**, never the web payload. The DBA provisions a new disposable database; this command neither creates nor drops a database. Supply credentials privately through the operator process environment. The configured `GSO_DB_NAME` remains the normal application database, so comparison can refuse a matching target. In staging/production modes, the full strict configuration policy still applies; `GSO_SCHEMA_TOOL` allows a separately privileged CLI DBA account, not a web-account bypass.

```sh
# Operator provisions this EMPTY, disposable database first.
export GSO_STAGING_DB_NAME=gso_stage_APPROVED_DISPOSABLE_REHEARSAL
export GSO_STAGING_ALLOW_SCHEMA_IMPORT=1
php scripts/staging_database_check.php
```

Safety checks execute before import: CLI; explicit `gso_stage_*` identifier; exact opt-in flag `1`; target differs case-insensitively from configured application DB; zero tables/views, routines, triggers and events. Ambiguous names, normal/live/test names, matching targets, and populated databases are refused. Restrict the rehearsal credential to that disposable DB wherever possible; the tool cannot determine an operator's intent if an existing application was itself misleadingly named `gso_stage_*` and not identified as `GSO_DB_NAME`.

The tool reports SELECT VERSION()/vendor, fresh import, repeat migration, version, **22 tables / 275 columns / 57 FKs / 104 indexes / 18 UNIQUE / 11 CHECK / 3 generated**, all frozen FK actions and generated expressions. Fresh installation inserts only the schema's three system-setting rows; no users, requests or business/test records are inserted. Formatting differences in generated expressions are normalized conservatively; an unexplained mismatch is FAIL and requires review, not an automatic waiver. Counts do not replace a complete target metadata/enforcement and restore comparison.

Local tool validation passed all counts/expressions/actions on MariaDB 10.4.32. The populated-database retry returned refusal (exit 2). Unsafe-target/opt-in cases are also covered by the focused suite. The isolated rehearsal database is retained in the local audit evidence. **Host/database readiness remains UNVERIFIED.**
