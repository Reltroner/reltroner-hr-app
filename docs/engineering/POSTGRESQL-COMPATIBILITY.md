# PostgreSQL Compatibility Baseline

## Status

**Phase:** Phase 1 — PostgreSQL Compatibility Gate  
**Runtime result:** PASS  
**CI status:** PASS

Production database contract:

```text
PostgreSQL 18
```

Local development may continue to use:

```text
SQLite
MySQL
```

SQLite success alone isnot considered proof of production database compatibility.

---

## Pre-Phase-1 Baseline

The repository entered Phase 1 with:

```text
34 tests
94 assertions
PASS
```

This baseline had already been verified using an isolated SQLite database.

---

## Objective

Phase 1 exists to prove that the HRM application can operate against the production database family before VPS deployment.

The required exit conditions are:

```text
PostgreSQL migrate:fresh PASS
full PHPUnit suite PASSno known production-blocking DB-specific SQL
```

Additional rollback and cross-database validation were performed as part of the compatibility work.

---

## PostgreSQL 18 Runtime Discovery

A disposable PostgreSQL 18 Docker container was used locally.

The database was:

- temporary
- bound only to localhost
-not connected to the VPS
-not connected to a production database
- destroyed after verification

Initial PostgreSQL result:

```text
migrate:fresh
PASS

existing PHPUnit suite
34 tests
94 assertions
PASS
```

The successful existing test suite didnot cover every PostgreSQL-sensitive execution path.

Two compatibility defects were discovered separately.

---

## Confirmed Defect 1 — MySQL-Specific Dashboard Aggregation

The dashboard presence aggregation used:

```sql
MOnTH(date)
```

When the actual query was executed against PostgreSQL 18, PostgreSQL retuned:

```text
SQLSTATE[42883]

function month(date) doesnot exist
```

The issue was therefore runtime-confirmed rather than inferred only from static inspection.

### Resolution

The controllernow uses Laravel query-builder month filtering instead of directly depending on the MySQL `MOnTH()` function.

Database-specific SQL generation is delegated to Laravel's database grammar.

### Preserved Behavior

Phase 1 intentionally didnot redefine the analytics semantics.

The existing behavior groups presence records by monthnumber:

```text
January 2025
+
January 2026
=
January bucket
```

It doesnot yet distinguish year + month.

Changing that reporting definition would be a separate business requirement.

---

## Confirmed Defect 2 — Foreign-Key Rollback Ordering

The primary HR migration originally attempted to drop:

```text
employees
```

before tables that contained foreign keys referencing employees:

```text
tasks
payrolls
presences
leave_requests
```

PostgreSQL rejected this rollback with a dependent-object error.

### Resolution

The migration `down()` dependency order was corrected to remove child tables before parent tables.

Conceptually:

```text
leave_requests
presences
payrolls
tasks
    ↓
employees
    ↓
roles
departments
```

The migration `up()` schema wasnot changed.

---

## Regression Protection

Anew test was added:

```text
tests/Feature/DashboardPresenceAggregationTest.php
```

It verifies:

- all expected presence status buckets exist
- relevant buckets contain 12 month positions
- month aggregation works through database-portable application logic
- same-month records from different years retain the existing aggregation semantics
- missing status/month combinations default to zero

Targeted result:

```text
1 test
7 assertions
PASS
```

---

## PostgreSQL 18 Verification

After the fixes, PostgreSQL 18 produced:

```text
migrate:fresh
PASS

targeted portability regression
1 test
7 assertions
PASS

full PHPUnit suite
35 tests
101 assertions
PASS

full migrate:rollback
PASS

migrate:fresh after rollback
PASS
```

This confirms both forward and rollback migration compatibility for the currently exercised schema.

---

## SQLite Regression Verification

SQLite was re-tested because it remains an allowed local development database.

Result:

```text
migrate:fresh
PASS

targeted portability regression
1 test
7 assertions
PASS

full PHPUnit suite
35 tests
101 assertions
PASS
```

The PostgreSQL correction therefore didnot break the existing SQLite development path.

---

## Static Portability Review

The final Phase 1 static scan foundno remaining occurrences of the targeted known database-specific SQL functions:

```text
MOnTH(
YEAR(
DAY(
DATE_FORMAT(
IFnULL(
GROUP_COnCAT(
FInD_In_SET(
JSOn_EXTRACT(
JSOn_UnQUOTE(
TIMESTAMPDIFF(
DATEDIFF(
LAST_InSERT_ID(
UnIX_TIMESTAMP(
strftime(
julianday(
```

A clean static scan doesnot prove universal SQL portability.

Runtime PostgreSQL testing remains the authoritative release gate.

---

## PostgreSQL CI Release Gate

The repositorynow contains:

```text
.github/workflows/postgresql-compatibility.yml
```

The workflow provisions PostgreSQL 18 and performs:

```text
PHP 8.4 setup
PostgreSQL PHP extensions
ephemeral APP_KEY generation
Composer validation
Composer dependency installation
PostgreSQL connectivity/version verification
migrate:fresh
targeted portability regression
full PHPUnit suite
full migrate:rollback
migrate:fresh after rollback
```

The CI database credentials are disposable test credentials only.

The workflow doesnot use production credentials.

---

## Baseline After Phase 1

PostgreSQL 18:

```text
35 tests
101 assertions
PASS
```

SQLite:

```text
35 tests
101 assertions
PASS
```

This supersedes the previous:

```text
34 tests
94 assertions
PASS
```

only because one intentional database-portability regression test was added.

---

## Files Changed in Phase 1

```text
.gitignore

app/Http/Controllers/DashboardController.php

database/migrations/
2025_05_12_083807_create_human_resources_app.php

tests/Feature/
DashboardPresenceAggregationTest.php

.github/workflows/
postgresql-compatibility.yml

docs/engineering/
POSTGRESQL-COMPATIBILITY.md
```

---

## Repository Hygiene

The repositorynow intentionally ignores:

```text
/.discovery
```

`.discovery/` contains local engineering material such as:

- repository discovery reports
- temporary database evidence
- runtime diagnostics
- local verification output
- scratch engineering artifacts

Permanent engineering decisions belong in tracked documentation such as:

```text
docs/engineering/
```

---

## Deferred Work

Phase 1 doesnot claim to solve:

- Keycloak SSO
- Redis production configuration
- RBAC redesign
- record-level authorization
- `/dashboard/presence` route authentication
- state-changing GET routes
- multi-tenancy
- audit logging
- modularization
- reporting redesign
- search
- analytics
- AI

These remain assigned to later phases of the govening architecture plan.

---

## Phase 1 Exit Evidence

Local PostgreSQL 18 evidence:

```text
PostgreSQL migrate:fresh             PASS
PostgreSQL targeted regression       PASS
PostgreSQL full PHPUnit              35 / 101 PASS
PostgreSQL migrate:rollback          PASS
PostgreSQL rebuild                   PASS
SQLite targeted regression           PASS
SQLite full PHPUnit                  35 / 101 PASS
Known SQL portability scan           CLEAn
```

CI gate:

```text
DEFINED
REMOTE EXECUTION PASS
Final Phase Status
PHASE 1
COMPLETE
FROZEN

The PostgreSQL 18 release gate completed successfully in remote CI.

Remote CI evidence:

Workflow: PostgreSQL Compatibility
Branch: main
Commit: 4abe192
Result: SUCCESS
