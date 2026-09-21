# Keycloak Production Foundation

## Status

**Phase 5 — COMPLETE / FROZEN**

The production identity plane for Reltroner has been deployed, hardened, persisted in PostgreSQL, exposed through HTTPS, protected by a reverse proxy, tested across service restarts and a full VPS reboot, and extended with explicit production/demo/service identity classes.

This phase establishes Keycloak as the authentication and identity plane. It does **not** move HRM business authorization into Keycloak. Laravel HRM remains responsible for business roles, permissions, policies, and record ownership.

## Scope

Phase 5 establishes:

- native Keycloak production runtime
- PostgreSQL-backed Keycloak persistence
- private Keycloak backend networking
- public HTTPS access through Nginx
- production hostname configuration
- secure platform administration
- `reltroner` application realm
- password policy
- OTP/MFA capability
- recovery authentication codes
- brute-force protection
- session policy
- identity-class roles
- operational identity groups
- PostgreSQL-backed Keycloak backup coverage
- health checks
- restart behavior
- full VPS reboot persistence

The following remain outside Phase 5:

- `hrm-web` OIDC client implementation
- `hrm-demo-web` OIDC client implementation
- Laravel SSO integration
- HRM business RBAC
- production-vs-demo client eligibility enforcement
- demo HRM database deployment
- demo CRUD implementation

Those belong to later application-integration phases.

## Production Baseline

- Keycloak: **26.7.4**
- JVM: **OpenJDK 21.0.12**
- Operating system: **Ubuntu 24.04 LTS**
- Runtime model: **native Keycloak distribution**
- Runtime profile: **prod**
- Service manager: **systemd**
- Public hostname: `auth.reltroner.com`
- Database: PostgreSQL 18
- Keycloak database: `keycloak_db`
- Keycloak database role: `keycloak_app`

No Docker or Podman runtime was introduced for Keycloak.

The production runtime is installed under:

```text
/opt/reltroner/infra/keycloak
```

Pinned release:

```text
/opt/reltroner/infra/keycloak/releases/26.7.4
```

Stable runtime symlink:

```text
/opt/reltroner/infra/keycloak/current
```

## Service Account

Keycloak runs as a dedicated operating-system account:

```text
keycloak
```

The account uses a non-login shell and exists only to run the identity service. The service does not run as root.

## Production Build

The Keycloak distribution was built before runtime startup using production-oriented build configuration with PostgreSQL support, health endpoints, and metrics support.

Runtime startup uses:

```text
kc.sh start --optimized
```

The active runtime reported:

```text
Profile prod activated
```

## Systemd Service

Keycloak is managed by:

```text
/etc/systemd/system/keycloak.service
```

Runtime configuration is loaded through:

```text
/etc/keycloak/keycloak.env
```

The environment file is protected as:

```text
root:keycloak
0640
```

It contains sensitive runtime configuration and must never be committed to Git or included in public documentation.

The service includes:

```text
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=full
RestrictSUIDSGID=true
LockPersonality=true
UMask=0027
LimitNOFILE=65536
```

The service is:

```text
active
enabled
```

## Graceful JVM Shutdown Classification

During an early controlled restart, Keycloak completed a graceful shutdown but the launcher process returned:

```text
143
```

The journal showed:

```text
Keycloak stopped
Main process exited ... status=143
```

Systemd initially classified that otherwise graceful JVM termination as an exit-code failure.

A dedicated drop-in was therefore created:

```text
/etc/systemd/system/keycloak.service.d/exit-status.conf
```

with:

```ini
[Service]
SuccessExitStatus=143
```

The effective setting is:

```text
SuccessExitStatus=0 143
```

Subsequent controlled restarts completed with:

```text
Result=success
ActiveState=active
SubState=running
```

without false `Failed with result 'exit-code'` events.

## Network Boundary

Keycloak application HTTP listens only on loopback:

```text
127.0.0.1:8080
```

The management interface listens only on loopback:

```text
127.0.0.1:9000
```

The embedded Keycloak/Infinispan cluster transport also remains loopback-only:

```text
127.0.0.1:7800
```

The actual Linux listener representation may appear as IPv4-mapped loopback:

```text
[::ffff:127.0.0.1]
```

This is still loopback-only.

External tests confirmed that the following ports are not publicly reachable:

```text
7800
8080
9000
```

PostgreSQL and Redis also remained private:

```text
5432 public access: BLOCKED
6379 public access: BLOCKED
```

## Public Reverse Proxy

Nginx is the public ingress layer.

Public listeners:

```text
80/tcp
443/tcp
```

Keycloak itself is not exposed directly.

Traffic path:

```text
Internet
    |
    v
auth.reltroner.com
    |
    v
Nginx :443
    |
    v
Keycloak 127.0.0.1:8080
```

Nginx terminates TLS and forwards requests to Keycloak over localhost HTTP.

Forwarded proxy identity headers are explicitly overwritten by Nginx rather than blindly trusting client-supplied values.

Keycloak is configured for the canonical external hostname:

```text
https://auth.reltroner.com
```

and accepts trusted proxy headers only from the local reverse-proxy boundary.

## HTTPS

TLS is provided through Let's Encrypt and managed by Certbot.

Certificate paths are managed under:

```text
/etc/letsencrypt/live/auth.reltroner.com/
```

HTTPS verification confirmed:

```text
subject CN = auth.reltroner.com
certificate valid
```

HTTP redirects to HTTPS:

```text
HTTP -> 301 -> HTTPS
```

The certificate renewal timer is:

```text
enabled
active
```

A simulated renewal was completed successfully using:

```text
certbot renew --dry-run
```

Result:

```text
PASS
```

## Canonical OIDC Issuer

The master realm advertises:

```text
https://auth.reltroner.com/realms/master
```

The application realm advertises:

```text
https://auth.reltroner.com/realms/reltroner
```

OIDC discovery was verified from both the VPS and an external workstation.

## PostgreSQL Persistence

Keycloak uses the existing PostgreSQL production foundation.

Database:

```text
keycloak_db
```

Application role:

```text
keycloak_app
```

The role remains restricted:

```text
superuser=false
createdb=false
createrole=false
replication=false
bypassrls=false
```

Cross-database access remains denied.

A direct attempt by `keycloak_app` to connect to `hrm_db` was rejected by the PostgreSQL HBA boundary.

After first Keycloak production startup, the application schema contained:

```text
100 public tables
```

Ownership verification:

```text
keycloak_app|100
```

The table count and ownership remained unchanged after controlled restarts and a full VPS reboot.

## Secure Bootstrap Administration

A temporary bootstrap administrator was created only after the normal Keycloak service had been stopped.

The temporary account was used to establish a permanent administrative account:

```text
platform-admin
```

The permanent account exists in the `master` realm and has the Keycloak platform administrative role.

The temporary bootstrap administrator was deleted after the permanent administrator was independently verified.

No temporary bootstrap administrator remains.

The Keycloak platform administrator is conceptually separate from HRM production administrators, HRM demo administrators, infrastructure operators, and ordinary employees.

## Application Realm

The application realm is:

```text
reltroner
```

This realm is enabled and persisted in PostgreSQL.

It survived controlled Keycloak restart, repeated systemd restarts, and a full VPS reboot.

External discovery after reboot continued to return:

```text
issuer = https://auth.reltroner.com/realms/reltroner
```

## Password Policy

Both `master` and `reltroner` were hardened with the following baseline:

```text
Minimum length             14
Digits                     1
Lowercase characters       1
Uppercase characters       1
Special characters         1
Not username               enabled
Not email                  enabled
Password history           5
Hashing algorithm          argon2
```

No arbitrary periodic password-expiration requirement was introduced during this phase.

## OTP Policy

OTP capability is configured using:

```text
Type                 TOTP / time based
Hash algorithm       SHA256
Digits               6
Look-ahead window    1
Token period         30 seconds
Reusable token       disabled
```

`Configure OTP` is enabled as a required-action capability.

It is not configured as a realm-wide default action for every future user.

## Recovery Authentication Codes

Recovery Authentication Codes are enabled as a required-action capability.

The permanent Keycloak platform administrator completed recovery-code enrollment.

Recovery codes are secret authentication material. They must never be committed to Git, stored on the VPS as ordinary documentation, or exposed in screenshots/logs.

## Platform Administrator MFA

The permanent `platform-admin` account was required to complete:

```text
Update Password
Configure OTP
Recovery Authentication Codes
```

Credential verification showed:

```text
Password
OTP
Password history
Recovery authentication codes
```

A fresh administrative login subsequently required a one-time code before access to the Admin Console.

Result:

```text
platform-admin MFA: PASS
```

## Brute-Force Protection

Both `master` and `reltroner` use temporary lockout protection.

Configured baseline:

```text
Brute Force Mode                    temporary lockout
Max login failures                  10
Strategy to increase wait time      multiple
Wait increment                      1 minute
Maximum wait                        15 minutes
Failure reset time                  12 hours
Quick login check                   1000 ms
Minimum quick login wait            1 minute
Permanent lockout                   disabled
```

## Session Policy

Both realms use the following session baseline:

```text
SSO Session Idle       30 minutes
SSO Session Max        10 hours
Login timeout           5 minutes
Login action timeout    5 minutes
```

Client-specific session and refresh-token behavior remains deferred until actual OIDC application clients are created and tested.

## Identity-Plane Contract

Keycloak identity classification and HRM business authorization are intentionally separate dimensions.

Keycloak answers:

```text
Who are you?
Which identity environment are you eligible to enter?
```

Laravel HRM answers:

```text
What business role do you have?
What business action may you perform?
Which records may you access or modify?
```

The following HRM business concepts are intentionally **not** modeled as Keycloak identity-class roles during Phase 5:

```text
admin
manager
employee
hr_manager
payroll_admin
leave approver
department manager
record ownership
```

Those remain local application authorization concerns.

## Identity-Class Realm Roles

The `reltroner` realm defines the following non-composite identity-class roles.

### `production_user`

```text
Identity class eligible for production application access.
This role does not grant HRM business permissions.
```

### `demo_user`

```text
Identity class eligible for demo application access.
This role does not grant production access or HRM business permissions.
```

### `service_account`

```text
Identity class for non-human service identities.
This role does not grant HRM business permissions.
```

These roles are identity-plane classification signals only.

They are **not yet sufficient by themselves to enforce production/demo application isolation**.

Actual client eligibility enforcement belongs to the OIDC client integration phase.

## Operational Identity Groups

Operational assignment uses the following group tree:

```text
identity
├── production
├── demo
└── service
```

Role mappings:

```text
identity/production -> production_user
identity/demo       -> demo_user
identity/service    -> service_account
```

This separates machine-readable identity classification from operational identity assignment.

## Production / Demo Boundary Contract

The intended next-stage architecture is:

```text
                         Keycloak
                    auth.reltroner.com
                     realm: reltroner
                           |
               +-----------+-----------+
               |                       |
            hrm-web               hrm-demo-web
               |                       |
        production identity        demo identity
               |                       |
          Laravel HRM             Demo Laravel HRM
               |                       |
            hrm_app               hrm_demo_app
               |                       |
             hrm_db               hrm_demo_db
               |                       |
       authoritative data        synthetic data
```

Key principles:

- production and demo are identity/environment classes
- Admin/Manager/Employee remain HRM-local business roles
- production and demo will use separate OIDC clients
- demo identities must not automatically obtain production-client access
- production identities must not automatically become demo administrators
- authentication success must never imply HRM authorization success
- demo data must not share the authoritative production data plane
- demo CRUD must operate on synthetic, resettable data
- demo isolation must not rely on scattered `is_demo` checks in business queries

## Demo Data Isolation Contract

The target demo data plane is:

```text
hrm_demo_app -> hrm_demo_db
```

The intended database access matrix is:

| Role | `hrm_db` | `hrm_demo_db` |
| --- | --- | --- |
| `hrm_app` | ALLOW | DENY |
| `hrm_demo_app` | DENY | ALLOW |

The demo database should be synthetic, deterministic, resettable, and non-authoritative.

Phase 5 defines this contract only. Actual demo database provisioning and Laravel demo CRUD belong to later application/deployment phases.

## Backup Coverage

Keycloak authoritative state is stored in PostgreSQL `keycloak_db`.

Therefore the existing PostgreSQL backup foundation is the authoritative Keycloak backup mechanism.

After the final Phase 5 configuration, a fresh automated PostgreSQL backup generation was created successfully.

The generation contained:

```text
globals.sql
keycloak_db.dump
hrm_db.dump
SHA256SUMS
```

Checksum verification returned:

```text
globals.sql: OK
keycloak_db.dump: OK
hrm_db.dump: OK
```

Both database archives passed PostgreSQL archive parsing:

```text
keycloak_db archive parse: PASS
hrm_db archive parse: PASS
```

The PostgreSQL backup timer remained:

```text
active
enabled
```

This validation proves scheduled backup artifact integrity. It does **not** claim that the final Phase 5 scheduled Keycloak backup generation was fully restored during this phase.

## Realm Export Position

Keycloak realm export is not treated as the authoritative backup mechanism.

If realm export is added later, it should be considered a supplementary configuration snapshot.

Authoritative recovery remains based on PostgreSQL backup of `keycloak_db`.

## Full VPS Reboot Verification

A full VPS reboot was performed after the Phase 5 identity configuration was completed.

After reboot:

```text
Keycloak                       active
Keycloak boot enablement       enabled
Nginx                          active
PostgreSQL                     active
Redis                          active
PostgreSQL backup timer        active
Certbot timer                  active
Keycloak Result                success
Keycloak ActiveState           active
Keycloak SubState              running
```

Health verification:

```text
health/live   UP
health/ready  UP
```

The `reltroner` realm remained available after reboot.

Keycloak PostgreSQL schema remained:

```text
100 tables
owner: keycloak_app
```

The platform administrator could still access the administration plane after the reboot, and the previously configured identity group structure remained present.

## Post-Reboot Network Verification

Publicly reachable:

```text
80/tcp   Nginx
443/tcp  Nginx
```

Private / externally blocked:

```text
5432/tcp  PostgreSQL
6379/tcp  Redis
7800/tcp  Keycloak cluster transport
8080/tcp  Keycloak HTTP backend
9000/tcp  Keycloak management
```

External verification confirmed:

```text
443  PASS / reachable

5432 BLOCKED
6379 BLOCKED
7800 BLOCKED
8080 BLOCKED
9000 BLOCKED
```

## Post-Reboot Resource State

Observed after the full reboot:

```text
RAM total      3.8 GiB
RAM available  2.9 GiB
Swap total     2.0 GiB
Swap used      0 B
```

No immediate memory-pressure or swap regression was observed.

## Health and Restart Policy

Management health endpoints remain private:

```text
http://127.0.0.1:9000/health/live
http://127.0.0.1:9000/health/ready
```

They are not exposed through the public reverse proxy.

Keycloak systemd restart policy:

```text
Restart=on-failure
RestartSec=5
```

Controlled restarts and a full VPS reboot were both verified successfully.

## Known Limitations and Deferred Work

The following are intentionally deferred:

- OIDC application clients are not yet created
- `production_user` and `demo_user` are classification roles, not yet enforced client gates
- Laravel HRM does not yet consume Keycloak authentication
- HRM business RBAC is not represented by Keycloak roles
- demo database isolation is defined but not yet provisioned
- demo reset/seeding workflow is not yet implemented
- client-specific token/session policy remains deferred until client creation
- realm export is not used as authoritative backup
- final Phase 5 scheduled backup generation was integrity-checked but not independently fully restored

These limitations are deliberate sequencing boundaries rather than failures of the Phase 5 identity-plane foundation.

## Phase 5 Exit Criteria

| Requirement | Result |
| --- | --- |
| `https://auth.reltroner.com` available | PASS |
| Keycloak production mode | PASS |
| Keycloak connected to `keycloak_db` | PASS |
| Canonical hostname configured | PASS |
| Nginx reverse proxy configured | PASS |
| TLS valid | PASS |
| HTTP redirects to HTTPS | PASS |
| `reltroner` realm created | PASS |
| Secure bootstrap admin lifecycle | PASS |
| Permanent platform administrator | PASS |
| Temporary bootstrap admin removed | PASS |
| Password policy configured | PASS |
| MFA capability prepared | PASS |
| Platform administrator MFA enforced | PASS |
| Recovery codes configured | PASS |
| Brute-force protection configured | PASS |
| Session baseline configured | PASS |
| Identity classes created | PASS |
| Identity groups created | PASS |
| Keycloak health checks | PASS |
| Restart policy configured | PASS |
| Keycloak survives restart | PASS |
| Keycloak survives full VPS reboot | PASS |
| Keycloak data persists | PASS |
| PostgreSQL ownership persists | PASS |
| Keycloak backup coverage | PASS |
| Backup integrity verified | PASS |
| Backend ports remain private | PASS |

## Freeze Rule

Phase 5 is frozen.

Changes to any of the following must be treated as identity-production changes and require explicit verification:

- Keycloak version
- Java runtime
- Keycloak service configuration
- hostname configuration
- reverse-proxy behavior
- TLS configuration
- PostgreSQL connectivity
- Keycloak database ownership
- bootstrap or platform administration model
- master-realm authentication policy
- `reltroner` authentication policy
- MFA configuration
- brute-force settings
- session policy
- identity-class role definitions
- identity group mapping
- Keycloak backup coverage
- Keycloak backend network exposure

Future phases must not weaken the private backend boundary or move HRM business authorization into Keycloak without an explicit architecture change.

## Next Architecture Phase

Phase 6 should implement the OIDC application-client boundary.

Planned client separation:

```text
hrm-web
hrm-demo-web
```

The next phase must prove that production and demo identities are handled through explicit client-access contracts.

It must still preserve the rule:

```text
Keycloak authentication != HRM business authorization
```

## Final Phase Result

```text
PHASE 5 — KEYCLOAK PRODUCTION FOUNDATION
COMPLETE / FROZEN
```
