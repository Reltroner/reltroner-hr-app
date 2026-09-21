# PostgreSQL Production Foundation

## Status

**Phase 3 — COMPLETE / FROZEN**

The production database foundation for Reltroner HRM and Keycloak has been deployed, hardened, isolated, backed up, restore-tested, reboot-tested, and externally verified.

The restore claim in this document refers to a real recovery rehearsal using manually generated PostgreSQL custom-format dumps. Automated scheduled backup generations were separately verified through checksums and PostgreSQL archive parsing; they were **not** fully restored during Phase 3.

## Production Baseline

- PostgreSQL: **18.6**
- Operating system: **Ubuntu 24.04 LTS**
- PostgreSQL cluster: `18/main`
- Cluster port: `5432`
- Listener:
  - `127.0.0.1:5432`
  - `[::1]:5432`
- Public PostgreSQL exposure: **disabled**
- Password encryption: `scram-sha-256`

PostgreSQL is intentionally bound only to localhost.

There is no public firewall rule for PostgreSQL.

## Database Isolation

Two application databases are provisioned:

| Database | Application Role |
| --- | --- |
| `keycloak_db` | `keycloak_app` |
| `hrm_db` | `hrm_app` |

Application roles are login roles without:

- superuser
- database creation
- role creation
- replication
- bypass RLS

The application access contract is:

| Role | Database | Expected |
| --- | --- | --- |
| `keycloak_app` | `keycloak_db` | ALLOW |
| `keycloak_app` | `hrm_db` | DENY |
| `hrm_app` | `hrm_db` | ALLOW |
| `hrm_app` | `keycloak_db` | DENY |

This matrix was tested successfully before and after a VPS reboot.

## Authentication Boundary

The PostgreSQL HBA baseline permits:

- local peer administration for PostgreSQL administrator operations
- `keycloak_app` to `keycloak_db` over localhost using SCRAM-SHA-256
- `hrm_app` to `hrm_db` over localhost using SCRAM-SHA-256
- local PostgreSQL replication administration

No generic application access rule is present.

Cross-database application connections are rejected by the HBA boundary.

## Backup Storage

Local PostgreSQL backup root:

```text
/opt/reltroner/backups/postgresql
```

Backup directories and artifacts are root-owned and inaccessible to ordinary application users.

Each automated generation contains:

```text
globals.sql
keycloak_db.dump
hrm_db.dump
SHA256SUMS
```

`globals.sql` is sensitive because PostgreSQL global-role information can contain password verifiers.

It must never be committed to Git or exposed publicly.

## Automated Backup Process

Backup implementation:

```text
/opt/reltroner/scripts/postgres-backup.sh
```

The backup process:

1. creates a private temporary backup generation
2. exports PostgreSQL globals
3. creates a custom-format dump of `keycloak_db`
4. creates a custom-format dump of `hrm_db`
5. validates both dump archives with `pg_restore -l`
6. generates SHA-256 checksums using relative filenames
7. validates the checksum manifest before publication
8. atomically moves the temporary generation into its final timestamped directory
9. removes expired timestamped backup generations according to the configured retention window
10. records successful completion in the system journal

The script uses strict shell execution and restrictive file permissions.

## Backup Scheduler

Systemd units:

```text
/etc/systemd/system/reltroner-postgres-backup.service
/etc/systemd/system/reltroner-postgres-backup.timer
```

The service is a root-owned oneshot service with:

```text
PrivateTmp=true
NoNewPrivileges=true
```

The timer is scheduled daily at approximately:

```text
03:15 UTC
```

with a randomized delay.

The timer is:

```text
enabled
active
```

and was verified to survive a VPS reboot.

## Restore Validation

A real database restore rehearsal was completed using manually generated PostgreSQL custom-format dumps created specifically for the recovery test.

The validation procedure included:

1. creating temporary source probe data
2. generating PostgreSQL custom-format dumps
3. creating isolated temporary recovery databases
4. restoring both dumps into the recovery databases
5. verifying recovered data
6. verifying restored table ownership
7. removing the recovery databases
8. removing temporary source probe data

Results:

```text
Keycloak backup restore: PASS
HRM backup restore: PASS
Recovered data: PASS
Recovered ownership: PASS
Cleanup: PASS
```

This restore rehearsal proves that the database backup format and recovery procedure were functional.

It did **not** constitute a full restore of a scheduled automated backup generation. Automated backup generations were validated separately for integrity as documented below.

## Backup Integrity

Automated backup generations were verified using:

```text
sha256sum -c SHA256SUMS
```

Expected and observed result:

```text
globals.sql: OK
keycloak_db.dump: OK
hrm_db.dump: OK
```

Both database archives also passed PostgreSQL archive parsing using:

```text
pg_restore -l
```

Backup generation and integrity validation continued to pass after a VPS reboot.

These checks verify that scheduled backup artifacts were created successfully, matched their checksum manifest, and were readable as PostgreSQL custom-format archives.

## Off-VPS Recovery Copy

A verified automated-backup generation was packaged and copied from the VPS to a separate workstation outside the Git repository.

Transfer integrity was verified by comparing SHA-256 hashes between:

- the VPS transport artifact
- the workstation copy

Result:

```text
SHA-256 equality: PASS
```

The temporary transport artifact on the VPS was removed after verification.

Current off-VPS copying is manual.

Daily local PostgreSQL backup is automated, but automated replication to external object storage has not yet been implemented.

This distinction must remain explicit in future operational documentation.

## Reboot Persistence Verification

A VPS reboot was performed after PostgreSQL and backup scheduling were configured.

After reboot, verification confirmed:

- PostgreSQL 18 cluster online
- PostgreSQL version unchanged
- localhost-only PostgreSQL listener
- SCRAM-SHA-256 still active
- HBA rules parsed without errors
- database ownership preserved
- application roles preserved
- valid application connections still accepted
- cross-database connections still rejected
- backup timer enabled and active
- backup service successfully generated a new backup
- backup SHA-256 validation passed

## External Port Verification

External verification after reboot produced the intended boundary:

| Port | Purpose | External State |
| --- | --- | --- |
| 22 | SSH | OPEN |
| 80 | HTTP / Nginx | OPEN |
| 443 | HTTPS | CLOSED at this phase |
| 5432 | PostgreSQL | CLOSED |
| 6379 | Redis / future runtime service at this phase | CLOSED |
| 8080 | internal/future service | CLOSED |

PostgreSQL was therefore not publicly reachable.

HTTPS configuration belonged to a later deployment phase and was intentionally not introduced during Phase 3.

## Security Decisions

The following controls are intentional:

- PostgreSQL is not publicly exposed.
- Application roles are not PostgreSQL superusers.
- Keycloak and HRM use separate databases.
- Keycloak and HRM use separate database roles.
- Cross-database application access is denied.
- Application passwords are not stored in this document.
- Database backups are root-only.
- PostgreSQL globals are treated as sensitive recovery material.
- Database backups are never stored in Git.
- Database recovery was tested through a real restore rehearsal rather than assumed.
- Automated backup artifacts were independently integrity-checked.
- At least one verified backup copy exists outside the VPS.

## Known Operational Limitations

Off-VPS backup transfer is currently manual.

The architecture requires off-VPS recovery capability, and that capability has been demonstrated, but future production hardening should automate external backup replication and retention using dedicated external backup storage.

The scheduled automated backup generation itself was not fully restored during Phase 3. Its integrity was validated through SHA-256 verification and `pg_restore -l` archive parsing, while the full data restore rehearsal used manually generated custom-format dumps.

The PostgreSQL globals recovery path was not rehearsed as a complete fresh-cluster restoration during Phase 3.

These limitations do not change the verified local backup generation, manual restore rehearsal, database isolation, or public-exposure controls established during Phase 3.

## Phase 3 Exit Criteria

| Requirement | Result |
| --- | --- |
| PostgreSQL 18 installed | PASS |
| Keycloak database isolated | PASS |
| HRM database isolated | PASS |
| Application roles restricted | PASS |
| Public PostgreSQL exposure blocked | PASS |
| Backup process configured | PASS |
| Automated backup integrity tested | PASS |
| Database restore rehearsal completed | PASS |
| Object ownership tested after restore | PASS |
| Scheduled backup configured | PASS |
| Scheduled backup survives reboot | PASS |
| Off-VPS recovery copy demonstrated | PASS |
| Post-reboot database isolation verified | PASS |

## Freeze Rule

Phase 3 is frozen.

Changes to PostgreSQL networking, HBA rules, application-role privileges, database ownership, backup permissions, backup scheduling, retention behavior, or recovery procedures must be treated as production infrastructure changes and require explicit verification before deployment.

The next architecture phase must not weaken the database isolation established here.

## Final Phase Result

```text
PHASE 3 — POSTGRESQL PRODUCTION FOUNDATION
COMPLETE / FROZEN
```
