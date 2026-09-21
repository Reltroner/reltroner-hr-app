# Reltroner HRM + Auth Platform

## Final Architecture Design & End-to-End Engineering Phases

**Status:** Architecture Baseline for Implementation

**Primary applications:** `auth.reltroner.com`, `hrm.reltroner.com`

**Architecture style:** Laravel Modular Monolith + Centralized SSO Identity Provider

**Production database:** PostgreSQL 18 only

**Local database:** SQLite or MySQL allowed

**Cache / session / queue:** Redis

**Identity provider:** Keycloak

**Deployment target:** Hostinger VPS

**Open-source policy:** Runtime architecture must use open-source components

**Current verified HRM baseline:** 34 tests, 94 assertions, PASS

**Architecture contract revision:** Phase 6 expanded to the OIDC Dual-Client Boundary & Identity Contract. The final end-to-end production target remains unchanged; this revision clarifies identity/environment boundaries, defense-in-depth requirements, deferred Laravel runtime gates, and acceptance sequencing.

---

# 1. Purpose

This document defines the final target architecture and the engineering path for evolving the existing `reltroner-hr-app` repository into a production-ready HR platform connected to a centralized Keycloak Single Sign-On service.

The immediate scope is intentionally limited to two public systems:

- `auth.reltroner.com`

- `hrm.reltroner.com`

Other Reltroner applications such as LMS, ERP API, Finance, search, analytics, and AI may later consume the same identity platform, but they are not required to complete the first production milestone.

The purpose is not to rewrite the current HRM application. The strategy is:

\> Discover → preserve baseline → harden → integrate SSO → validate PostgreSQL → productionize → modularize incrementally.

---

# 2. Architecture Principles

## 2.1 HRM remains a modular monolith

The HRM application remains one Laravel application and one deployable business system.

Do not split HR business domains into independent network services such as:

- Employee Service

- Payroll Service

- Attendance Service

- Leave Service

- Role Service

Internal modules are allowed and encouraged, but they live inside the same Laravel repository and process boundary.

## 2.2 Authentication, environment eligibility, and business authorization are separate decisions

The identity boundary has three distinct layers:

| Layer | Question | Authority |
|---|---|---|
| Authentication | Who is this identity? | Keycloak |
| Environment eligibility | May this identity enter production or demo? | Keycloak identity class, re-verified by the consuming Laravel environment |
| Business authorization | What may this user do, in which organization, on which record, and through which transition? | Laravel HRM |

The invariant is:

```text
Keycloak authentication
        !=
environment eligibility
        !=
HRM business authorization
```

Keycloak is responsible for:

- login
- password policy
- account authentication
- MFA
- SSO session
- OIDC identity
- global user identity
- client/environment eligibility classification

HRM remains responsible for:

- Employee relationship
- company / organization membership
- HR role
- domain permissions
- payroll authorization
- leave approval authorization
- attendance rules
- record ownership
- tenant isolation
- business workflow authorization

`production_user` does not mean HRM administrator.

`demo_user` does not mean demo administrator.

A valid Keycloak login must not automatically create an HRM authenticated session or business authority.

The production safety invariant is:

```text
valid OIDC identity
+ correct environment eligibility
+ approved local HRM identity linkage
+ valid HRM authorization
= possible HRM authenticated access
```

If any required layer fails, the request fails closed.

---

## 2.3 PostgreSQL is the production contract

Local development may use:

- SQLite

- MySQL

Production must use:

- PostgreSQL

A release is not considered production-ready merely because SQLite tests pass.

Every production release must pass PostgreSQL migration and application tests.

## 2.4 Infrastructure services are not business microservices

The following are infrastructure dependencies, not business microservices:

- Keycloak

- PostgreSQL

- Redis

- Nginx

- queue workers

- Laravel Horizon

- backup jobs

- monitoring

The business application remains a modular monolith.

## 2.5 Compatibility is removed intentionally, not accidentally

Current HRM behavior includes legacy compatibility around:

- `session('role')`

- `session('employee_id')`

- `users.role`

- `users.employee_id`

- `User -> Employee`

- `Employee -> Role`

- `CheckRole`

These must not disappear as incidental cleanup during SSO integration.

Migration away from them must be explicit, tested, staged, and reversible.

## 2.6 AI is never the source of truth

Future AI capability may explain or retrieve data, but authoritative business values must come from deterministic application logic and database queries.

The LLM must not become the system of record.

---

# 3. Current HRM Baseline

The current repository is an existing Laravel application with:

- Laravel 12

- Blade-based server rendering

- Laravel session authentication

- custom `CheckRole` middleware

- Eloquent models

- controller-based business orchestration

- Department

- Employee

- Role

- Attendance / Presence

- Leave Request

- Payroll

- Task

- Dashboard

- Profile

- authentication flows

The pre-change behavioral checkpoint is:

```text

Tests:       34 passed

Assertions:  94

Result:      PASS

```

This baseline must remain recorded throughout the migration.

A future test count may increase. A reduction in passing baseline behavior must be intentional and explained.

---

# 4. Final Production Topology

```mermaid

flowchart TB

    U[User Browser]

    U -->|HTTPS 443| NX[Nginx Reverse Proxy]

    NX --> AUTH[auth.reltroner.com]

    NX --> HRM[hrm.reltroner.com]

    AUTH --> KC[Keycloak]

    HRM --> PHP[Laravel HRM / PHP-FPM]

    KC --> PGK[(PostgreSQL: keycloak_db)]

    PHP --> PGH[(PostgreSQL: hrm_db)]

    PHP --> R[(Redis)]

    PHP --> FS[(Private Storage)]

    W[Laravel Queue Workers / Horizon] --> R

    W --> PGH

    W --> FS

    S[Laravel Scheduler] --> PHP

    B[Backup Jobs] --> PGK

    B --> PGH

    B --> FS

    B --> BK[(External Backup Storage)]

    PHP -. OIDC Authorization Code .-> KC

```

---

# 5. Public and Private Network Boundaries

## 5.1 Public

Only services required by users should be publicly exposed.

```text

80/tcp   -> redirect to HTTPS

443/tcp  -> Nginx only

22/tcp   -> SSH, restricted

```

Public hostnames:

```text

auth.reltroner.com

hrm.reltroner.com

```

## 5.2 Private

The following services must not be directly exposed to the internet:

```text

PostgreSQL

Redis

Keycloak internal HTTP port

PHP-FPM

Horizon dashboard unless protected

internal metrics endpoints

backup volumes

```

Example internal topology:

```text

127.0.0.1:8080   Keycloak

127.0.0.1:5432   PostgreSQL

127.0.0.1:6379   Redis

unix socket       PHP-FPM

```

If Docker networking is used, equivalent private container networking should be used instead of binding infrastructure ports publicly.

---

# 6. DNS and TLS

Required DNS:

```text

auth.reltroner.com  -> VPS public IP

hrm.reltroner.com   -> VPS public IP

```

Nginx terminates TLS.

Certificates should use an open-source ACME client and Let's Encrypt.

Required behavior:

```text

http://auth.reltroner.com

        ↓

https://auth.reltroner.com

http://hrm.reltroner.com

        ↓

https://hrm.reltroner.com

```

TLS termination belongs at Nginx.

The demo hostname is a Phase 6 identity-contract endpoint and is deployed only when the demo application plane is required:

```text
hrm-demo.reltroner.com
```

Its absence before demo deployment does not invalidate a Keycloak-side authorization contract, but full demo browser/application acceptance remains deferred until the approved endpoint exists.

Backend services may communicate over the private host/container network.

---

# 7. Production Data Plane

One PostgreSQL server may serve both Keycloak and HRM to conserve VPS resources.

Isolation must occur using separate databases and separate database users.

```text

PostgreSQL Instance

│

├── keycloak_db

│     owner: keycloak_app

│

└── hrm_db

      owner: hrm_app

```

Do not:

- share one application DB user

- allow HRM to query Keycloak tables

- allow Keycloak to query HRM tables

- use PostgreSQL superuser credentials in application `.env`

- expose PostgreSQL publicly

If a demo application plane is deployed, it must use a physically distinct application database and credential:

```text
hrm_demo_app
  -> hrm_demo_db only
```

Production and demo application credentials must not have cross-database access.

Demo should use deterministic synthetic data rather than a production copy with scattered demo row flags.

Suggested permissions:

```text

keycloak_app

  -> keycloak_db only

hrm_app

  -> hrm_db only

backup_operator

  -> backup permissions only

```

---

# 8. Environment Database Matrix

| Environment | Allowed Database | Purpose |

|---|---|---|

| Local development | SQLite | Fast local development |

| Local development | MySQL | Optional compatibility |

| Local integration | PostgreSQL | Production compatibility |

| CI | PostgreSQL | Mandatory release gate |

| Staging | PostgreSQL | Production rehearsal |

| Production | PostgreSQL | Mandatory |

| Demo (when deployed) | PostgreSQL | Isolated synthetic-data environment |

SQLite must never be treated as proof of PostgreSQL compatibility.

---

# 9. PostgreSQL Compatibility Rules

The application must avoid database-specific SQL unless the query is explicitly isolated behind a database-aware implementation.

Known migration concern:

The current dashboard aggregation uses the MySQL-style function:

```sql

MONTH(date)

```

Production PostgreSQL requires compatible behavior such as:

```sql

EXTRACT(MONTH FROM date)

```

Prefer a portable query abstraction when feasible.

Release validation must detect:

- MySQL-only functions

- SQLite-only assumptions

- case-sensitivity differences

- boolean differences

- date/time behavior differences

- index differences

- `GROUP BY` behavior differences

- migration rollback ordering

- foreign-key dependency ordering

---

# 10. Redis Architecture

Redis becomes the production runtime state infrastructure.

Primary responsibilities:

```text

Laravel sessions

cache

queue

rate limiting

distributed locks

temporary application state

Horizon queue backend

```

Target production settings conceptually:

```env

SESSION_DRIVER=redis

CACHE_STORE=redis

QUEUE_CONNECTION=redis

```

Redis is not the system of record.

If Redis is lost, authoritative HR business data must remain in PostgreSQL.

---

# 11. Queue Architecture

Initial queue topology:

```text

critical

default

notifications

imports

reports

maintenance

```

Future optional:

```text

search-indexing

ai-indexing

analytics

```

Resource-conscious VPS startup:

```text

1 supervisor

1-2 total workers initially

```

Scale only from observed queue latency and CPU/RAM metrics.

---

# 12. Keycloak Identity Architecture

Keycloak is the centralized identity provider for Reltroner applications.

Application realm:

```text
reltroner
```

Phase 6 defines two HRM web clients inside the same realm:

```text
hrm-web       -> production HRM environment
hrm-demo-web  -> demo HRM environment
```

Both are server-side confidential OpenID Connect clients.

The realm also defines environment identity classes:

```text
production_user
demo_user
service_account
```

These are identity-plane classifications only. They are not HRM business roles.

Human identity classification follows this administrative invariant:

```text
human identity:
EXACTLY ONE OF
production_user
demo_user
```

A human identity with both classifications is invalid unless a later architecture decision explicitly defines and audits a dual-environment exception.

Service identity classification follows:

```text
service identity:
service_account
```

Interactive service identities must not be treated as HRM human users.

The Keycloak platform administrator remains separated from application identities and is managed through the administrative realm rather than by granting HRM environment roles.

The design intentionally keeps one realm because production and demo currently share one identity lifecycle, password/MFA policy, and administrative plane. A separate demo realm should be introduced only if a real requirement demands independently isolated identity lifecycle or simultaneously independent SSO sessions.

Future clients may include:

```text
lms-web
erp-api
finance-web
reltroner-studio
automation
```

Do not create separate realms per application without a real security or organizational boundary.

---

# 13. HRM OIDC Dual-Client Contract

The HRM identity plane uses two confidential server-side web clients.

| Setting | `hrm-web` | `hrm-demo-web` |
|---|---|---|
| Protocol | OpenID Connect | OpenID Connect |
| Environment | Production | Demo |
| Client authentication | ON | ON |
| Standard Flow / Authorization Code | ON | ON |
| PKCE | Required, S256 | Required, S256 |
| Implicit Flow | OFF | OFF |
| Direct Access Grants | OFF | OFF |
| Service Account Roles | OFF | OFF |
| Authorization Services | OFF | OFF |
| Standard Token Exchange | OFF | OFF |
| JWT Authorization Grant | OFF | OFF |
| Device Authorization Grant | OFF | OFF |
| CIBA | OFF | OFF |
| Full Scope Allowed | OFF | OFF |
| Client secret | Unique | Unique |

Client secrets must never be reused:

```text
hrm-web secret
!=
hrm-demo-web secret
```

The demo runtime must never receive the production client secret.

### Production URI contract

```text
Root URL:
https://hrm.reltroner.com

Home URL:
https://hrm.reltroner.com

Redirect URI:
https://hrm.reltroner.com/auth/keycloak/callback

Post-logout redirect:
https://hrm.reltroner.com/
```

### Demo URI contract

```text
Root URL:
https://hrm-demo.reltroner.com

Home URL:
https://hrm-demo.reltroner.com

Redirect URI:
https://hrm-demo.reltroner.com/auth/keycloak/callback

Post-logout redirect:
https://hrm-demo.reltroner.com/
```

Redirects must remain exact.

The following are forbidden:

```text
*
https://*.reltroner.com/*
shared production/demo callback
localhost callback on production clients
```

`Web Origins` should remain empty when the server-rendered Laravel application does not make browser-side CORS calls directly to Keycloak. If a browser-side Keycloak API requirement appears later, allow only the exact required origin.

### Minimal scopes and token boundary

Requested baseline scopes:

```text
openid
profile
email
```

Environment identity claims must be intentionally scoped rather than exposing every realm role.

Dedicated client scopes are:

```text
hrm-production-identity
hrm-demo-identity
```

The production identity scope is linked only to `hrm-web` and exposes only the production identity classification required by the application contract.

The demo identity scope is linked only to `hrm-demo-web` and exposes only the demo identity classification required by the application contract.

The canonical environment evidence may be represented by the scoped identity claim:

```text
reltroner_identity_class
```

with the expected environment-specific value.

This claim is an environment boundary signal only. It is never a source of HRM business permission.

Do not include unrelated realm roles, groups, address, phone, or `offline_access` unless a later concrete requirement proves they are necessary.

Before any production cutover, use client-scope evaluation and representative tokens to verify that claims remain minimal and no cross-environment identity classification leaks into the wrong client.

---

# 14. Browser Authentication and Environment-Gate Flow

The browser authentication path has two independent enforcement layers.

## Gate A — Keycloak client/environment gate

Client-specific browser flow bindings:

```text
hrm-web
  -> browser-hrm-production-v2

hrm-demo-web
  -> browser-hrm-demo-v2
```

The flow performs normal browser authentication and then evaluates the environment identity class.

Conceptually:

```text
hrm-web
  -> authenticated identity
  -> production_user present?
       YES -> authorization may continue
       NO  -> DENY

hrm-demo-web
  -> authenticated identity
  -> demo_user present?
       YES -> authorization may continue
       NO  -> DENY
```

The environment gate must be evaluated for both:

```text
fresh authentication
existing Keycloak SSO cookie
```

An already-authenticated SSO cookie must never bypass the client-specific environment check.

## Gate B — Laravel callback gate

Keycloak-side denial is not sufficient as the only production control.

After authorization-code exchange, Laravel must independently validate:

```text
authorization response state
PKCE verifier
ID-token signature
issuer
audience / authorized client
expiry / temporal claims
nonce
expected environment identity claim
local HRM identity linkage
business authorization
```

The callback state machine is:

```text
GET /login or SSO entry
  -> generate state
  -> generate nonce
  -> generate PKCE verifier
  -> derive S256 challenge
  -> store transient values in server-side session
  -> redirect to Keycloak

GET /auth/keycloak/callback?code=...&state=...
  -> state matches?
       NO -> reject
  -> exchange code with verifier + confidential client credential
  -> validate ID token
  -> nonce matches?
       NO -> reject
  -> issuer/client/environment valid?
       NO -> reject
  -> resolve identity by issuer + subject
  -> approved local HRM linkage exists?
       NO -> 403 / no HRM session
  -> establish local session
  -> HRM business authorization remains authoritative
```

The overall path is:

```mermaid
sequenceDiagram
    participant B as Browser
    participant H as HRM
    participant K as Keycloak
    participant D as HRM PostgreSQL

    B->>H: GET protected HRM route
    H-->>B: Redirect to SSO entry
    B->>H: GET /auth/keycloak/redirect
    H-->>B: Authorization request + state + nonce + PKCE challenge
    B->>K: Authentication / existing SSO session
    K->>K: Evaluate client-specific environment gate
    K-->>B: Approved callback with authorization code
    B->>H: GET /auth/keycloak/callback
    H->>K: Exchange code + PKCE verifier + client credential
    K-->>H: ID token + access token
    H->>H: Validate state / nonce / issuer / audience / environment
    H->>D: Resolve issuer + subject -> approved local User
    D-->>H: Local User / Employee relationship
    H->>H: Establish Laravel session
    H->>H: Apply HRM authorization
    H-->>B: Continue to authorized HRM destination
```

Keycloak authentication success is necessary but not sufficient for HRM access.

---

# 15. Identity Mapping

The permanent external identity key is:

```text
issuer + subject
```

Do not use email, username, or `preferred_username` as the primary trust anchor.

Email may change.

The OIDC `sub` value is stable only within its issuer namespace, therefore both values are retained.

Preferred final model:

```text
users
│
└── external_identities
      ├── id
      ├── user_id
      ├── provider
      ├── issuer
      ├── subject
      ├── email_at_link
      ├── linked_at
      ├── last_login_at
      └── timestamps
```

Required identity uniqueness:

```text
UNIQUE(issuer, subject)
```

`provider` may remain useful as application metadata, but it must not replace the issuer namespace in the trust key.

For the current Keycloak realm, the canonical issuer is:

```text
https://auth.reltroner.com/realms/reltroner
```

This keeps local HRM identity independent from mutable profile fields and prevents email changes from creating a new authority relationship.

---

# 16. First Login / Account Linking Policy

The system must define deterministic first-login behavior.

Production authentication must never convert identity proof directly into business authority.

Forbidden pattern:

```text
valid Keycloak login
  -> firstOrCreate user by email
  -> immediately authenticate
```

Preferred production behavior:

1. Validate the OIDC callback completely.
2. Resolve identity by `(issuer, subject)`.
3. If an approved identity link exists, resolve its local User.
4. If no approved identity link exists, deny HRM access unless an explicitly controlled migration/provisioning workflow authorizes linking.
5. Never auto-link an unverified email.
6. Never create Employee, Membership, HR role, or business permissions merely because OIDC authentication succeeded.
7. Record identity-link creation and removal in audit logs.

Production invariant:

```text
valid OIDC identity
+ production_user
+ approved local HRM linkage
= possible HRM session

valid OIDC identity
+ production_user
+ no approved HRM linkage
= 403 / no HRM authenticated session
```

A controlled migration stage may use verified email as a matching hint for a pre-provisioned account, but email is not the permanent identity key and the callback must not silently create production authority.

A future demo environment may use a more permissive synthetic-data provisioning policy, but that is a demo-specific policy and must not be inherited by production.

---

# 17. Local User Remains Required

Keycloak does not replace the HRM `users` table.

Local User remains necessary for:

- Employee relationship

- application authorization

- organization membership

- audit ownership

- record ownership

- domain references

- local preferences

- application-specific state

The identity chain becomes:

```text

Keycloak User

     │

     │ sub

     ▼

ExternalIdentity

     │

     ▼

Local User

     │

     ▼

Employee

     │

     ▼

Role / Permissions / Organization

```

---

# 18. Authentication vs Authorization Boundary

```mermaid

flowchart LR

    K[Keycloak]

    U[Local User]

    E[Employee]

    R[Role / Permission]

    P[Policy]

    O[Record Ownership]

    K -->|OIDC identity| U

    U --> E

    E --> R

    R --> P

    P --> O

```

Keycloak does not decide whether a user may:

- approve a specific leave request

- read another employee's payroll

- edit another employee's attendance

- delete an HR record

- modify protected roles

Those remain HRM business authorization decisions.

---

# 19. Legacy RBAC Compatibility

The current application uses a compatibility role chain.

Existing role resolution must be preserved during initial SSO integration.

Conceptually:

```text

session('role')

      ↓

employee->role->title

      ↓

users.role

```

Current `CheckRole` may also write:

```text

session('role')

session('employee_id')

```

During SSO migration:

```text

Keycloak Authentication

        ↓

Local User

        ↓

Existing User/Employee relationship

        ↓

Existing CheckRole compatibility

```

Do not combine SSO migration with a simultaneous RBAC rewrite.

---

# 20. Long-Term Authorization Target

After SSO is stable, move gradually toward:

```text

User

  ↓

Membership

  ↓

Organization

  ↓

Role

  ↓

Permission

  ↓

Policy

  ↓

Record Ownership

```

Example permissions:

```text

employee.view

employee.create

employee.update

attendance.self.checkin

attendance.view.self

attendance.manage

leave.request

leave.view.self

leave.approve

payroll.view.self

payroll.view.all

payroll.process

task.view.self

task.manage

report.view

audit.view

```

This is a later phase.

It must not block SSO deployment.

---

# 21. Logout Architecture

Logout has two layers:

```text
Laravel local session
Keycloak SSO session
```

Production target:

```text
hrm-web
  -> invalidate local production HRM session
  -> regenerate CSRF token
  -> optional/required RP-initiated Keycloak logout according to policy
  -> exact production post_logout_redirect_uri
```

Demo target:

```text
hrm-demo-web
  -> invalidate local demo HRM session
  -> regenerate CSRF token
  -> Keycloak logout according to demo policy
  -> exact demo post_logout_redirect_uri
```

Because production and demo use one Keycloak realm, a global Keycloak logout may affect another client session in the same realm. That is acceptable while human production/demo classifications remain mutually exclusive.

If future requirements demand simultaneously independent production and demo SSO sessions for the same person, evaluate a stronger identity-plane separation such as a dedicated demo realm. Do not introduce that complexity without a concrete requirement.

The Laravel session plane must also remain environment-specific.

Do not share a broad parent-domain session cookie such as:

```text
SESSION_DOMAIN=.reltroner.com
```

between production and demo.

Target session separation:

```text
production:
  host-only cookie
  production-specific cookie name
  production APP_KEY
  production Redis session namespace

demo:
  host-only cookie
  different cookie name
  different APP_KEY
  different Redis session namespace
```

The current application contains both GET and POST logout routes.

Do not remove legacy GET logout until:

- all callers are identified
- templates are migrated
- SSO logout works
- tests cover the new behavior

---

# 22. SSO Failure Behavior

If Keycloak is unavailable:

```text

new authentication fails

existing valid Laravel sessions may continue until expiry

```

Production readiness therefore requires:

- Keycloak restart policy

- Keycloak health checks

- PostgreSQL persistence

- service monitoring

- resource limits

- backup

- recovery documentation

One VPS deployment is production-capable but not highly available.

---

# 23. HRM Modular Monolith Target

```text

app/

├── Modules/

│   ├── Identity/

│   │   ├── Http/

│   │   ├── Oidc/

│   │   ├── Actions/

│   │   └── Models/

│   │

│   ├── Organization/

│   │   ├── Organizations/

│   │   ├── Memberships/

│   │   └── Permissions/

│   │

│   ├── People/

│   │   ├── Employees/

│   │   ├── Departments/

│   │   └── Roles/

│   │

│   ├── Attendance/

│   ├── Leave/

│   ├── Payroll/

│   ├── Work/

│   ├── Documents/

│   ├── Reporting/

│   ├── Analytics/

│   ├── Search/

│   ├── Assistant/

│   ├── Audit/

│   ├── Notifications/

│   └── Integrations/

│

├── Shared/

│   ├── Auth/

│   ├── Contracts/

│   ├── Support/

│   └── ValueObjects/

│

└── Providers/

```

Do not move the whole codebase in one refactor.

Modules must be extracted incrementally.

---

# 24. Internal Module Rule

A module may contain:

```text

Http/Controllers

Http/Requests

Models

Policies

Actions

Queries

Services

Events

Listeners

Jobs

DTOs

```

but only when needed.

Simple CRUD does not require every layer.

Preferred rule:

```text

Controller

    ↓

Request validation

    ↓

Action / Service only for meaningful business orchestration

    ↓

Model / Query

    ↓

Database

```

Avoid architecture for architecture's sake.

---

# 25. Domain Boundaries

## Identity

Owns:

- OIDC redirect

- callback

- external identity mapping

- local session establishment

- SSO logout

- identity synchronization

Does not own:

- payroll permission

- leave approval rules

- HR record ownership

## Organization

Future owner of:

- organizations

- membership

- organization context

- tenant isolation

## People

Owns:

- employees

- departments

- roles during compatibility phase

## Attendance

Owns:

- presence

- check-in

- check-out

- attendance validation

## Leave

Owns:

- leave requests

- approval workflow

## Payroll

Owns:

- salary calculation

- payroll records

- payroll workflow

## Work

Owns:

- tasks

- task state transitions

## Audit

Owns:

- sensitive activity logging

- authorization-relevant change history

---

# 26. Record-Level Authorization

Route role authorization is not sufficient.

The following questions must be answered independently:

```text

May this role access this feature?

May this user access this particular record?

May this user perform this particular state transition?

```

Final authorization should move toward Policies.

Examples:

```text

PayrollPolicy

LeaveRequestPolicy

PresencePolicy

TaskPolicy

EmployeePolicy

RolePolicy

```

Index query filtering and record-level policy checks must agree.

---

# 27. Current Security Risks to Resolve

Known risk candidates include:

- unauthenticated dashboard presence endpoint

- public employee endpoint exposing employee data

- state-changing GET routes

- GET logout

- incomplete record ownership checks

- legacy session role priority

- weak `users.employee_id` schema relationship

- protected role update inconsistency

- application-only payroll duplicate protection

- sensitive payroll data in error logs

- MySQL-specific dashboard date aggregation

- attendance date/datetime inconsistency

- stale/unwired route source

- legacy RBAC tests that disable middleware

Each should receive:

```text

reproduction

risk classification

targeted test

minimal fix

regression test

```

---

# 28. Production Data Integrity

Important constraints should exist at the database level whenever practical.

Examples:

```text

employees.email UNIQUE

presences UNIQUE(employee_id, date)

external_identities UNIQUE(provider, subject)

```

Future candidates:

```text

payrolls UNIQUE(employee_id, payment_date)

roles scoped uniqueness

organization-scoped uniqueness

```

Do not add constraints before checking existing production data for violations.

---

# 29. Audit Architecture

Sensitive changes must generate audit records.

Minimum events:

```text

SSO identity linked

SSO identity unlinked

employee created

employee updated

employee deleted

role changed

permission changed

leave approved

leave rejected

attendance manually edited

payroll created

payroll updated

payroll deleted/reversed

```

Audit records should include:

```text

actor

action

subject type

subject id

before

after

timestamp

request correlation id

IP where appropriate

reason when required

```

Passwords and tokens must never be logged.

---

# 30. Application Secrets

Secrets belong outside the repository.

Examples:

```text

APP_KEY

database password

Redis credentials

OIDC client secret

Keycloak bootstrap credentials

SMTP credentials

backup credentials

```

Do not commit `.env.production`.

---

# 31. Token and Session Handling

Preferred design:

```text
Browser
  ↓
Laravel server
  ↓
Keycloak Authorization Code + PKCE exchange
  ↓
server-side token validation
  ↓
issuer + subject identity resolution
  ↓
environment eligibility verification
  ↓
Laravel session cookie
```

The browser should primarily use Laravel's secure session cookie for HRM.

Session cookie requirements:

```text
Secure
HttpOnly
SameSite appropriate to flow
HTTPS only
session rotation after authentication
host-only where practical
environment-specific cookie name
```

Do not store access tokens in browser localStorage for the server-rendered HRM.

Production and demo must not share a Laravel session plane.

If both environments use the same Redis server, use distinct logical namespace/prefixing and credentials/permissions where practical so that:

```text
production Laravel session
!=
demo Laravel session
```

Environment identity claims are validated as admission signals only. They do not replace local HRM roles, permissions, organization membership, policy evaluation, or record ownership.

---

# 32. CSRF and HTTP Semantics

State mutation should ultimately use:

```text

POST

PUT

PATCH

DELETE

```

not GET.

Target examples:

```text

POST /leave_requests/{id}/approve

POST /leave_requests/{id}/reject

PATCH /tasks/{task}/status

POST /logout

```

Migration must inspect all current callers before changing route contracts.

---

# 33. Rate Limiting

Apply rate limiting to:

- authentication entrypoints where appropriate

- callback misuse

- public API endpoints

- high-cost exports

- search

- future AI endpoints

Rate limit counters may use Redis.

---

# 34. Health Architecture

Health checks should separate:

```text

liveness

readiness

dependencies

```

Examples:

### Liveness

```text

Laravel process responds

Keycloak process responds

```

### Readiness

```text

PostgreSQL reachable

Redis reachable

required migrations applied

application configuration valid

```

Avoid exposing sensitive dependency details publicly.

---

# 35. Observability

Minimum production observability:

```text

structured application logs

Nginx access/error logs

Laravel exception logs

Keycloak logs

PostgreSQL logs

queue failure visibility

request correlation ID

disk usage monitoring

memory monitoring

CPU monitoring

backup status

```

Start lightweight.

---

# 36. Backup Architecture

Backup scope:

```text

PostgreSQL keycloak_db

PostgreSQL hrm_db

uploaded HRM files

Keycloak realm configuration where useful

Nginx configuration

deployment configuration

environment-secret recovery procedure

```

Backups must be copied off-VPS.

A backup that exists only on the same VPS is not sufficient disaster recovery.

---

# 37. Restore Testing

Required periodic procedure:

1. provision temporary recovery environment

2. restore PostgreSQL

3. restore uploaded files

4. start Keycloak

5. start HRM

6. test login

7. test representative HR flows

8. record restore duration and issues

---

# 38. Deployment Filesystem

```text

/opt/reltroner/

├── infra/

│   ├── nginx/

│   ├── keycloak/

│   ├── postgres/

│   ├── redis/

│   └── compose/

│

├── apps/

│   └── hrm/

│       ├── current/

│       ├── releases/

│       └── shared/

│

├── backups/

├── logs/

└── scripts/

```

Alternative layouts are valid if they preserve:

- ownership clarity

- repeatable deployment

- secret isolation

- rollback capability

- persistent data separation

---

# 39. Container Strategy

The architecture is compatible with Docker Compose.

A reasonable production stack may contain:

```text

nginx

keycloak

postgres

redis

hrm-php

hrm-worker

hrm-scheduler

```

Containerization is an operational choice, not an architectural goal.

Do not add Kubernetes.

---

# 40. CI Pipeline

A release pipeline should perform:

```text

composer validation

composer install

frontend dependency install

frontend build

lint

static analysis

unit tests

feature tests

PostgreSQL migration test

PostgreSQL application test

security/dependency scan

artifact/package preparation

```

Minimum production release gate:

```text

PostgreSQL migration PASS

full test suite PASS

no unexpected source diff

```

SQLite-only success is not enough.

---

# 41. PostgreSQL CI Workflow

```text

CI runner

   │

   ├── start PostgreSQL

   ├── create clean hrm_test database

   ├── migrate:fresh

   ├── run test suite

   └── destroy test database

```

CI must never reuse production credentials.

---

# 42. Release Artifact

The production deployment should be built from a known Git commit.

A release should record:

```text

Git SHA

release timestamp

migration list

test result

dependency lock hashes

frontend build result

deployment operator

```

Production should not be edited manually in-place.

---

# 43. Low-Risk Deployment Sequence

```text

1. backup / verify recovery point

2. fetch immutable release

3. composer install --no-dev

4. build frontend assets

5. verify environment

6. run safe migrations

7. switch release

8. clear/rebuild caches

9. restart PHP / queue workers

10. health check

11. smoke test

12. monitor logs

```

Rollback must be planned before migration.

---

# 44. Database Migration Discipline

Production migrations must be:

- additive where possible

- backward compatible across release transition

- tested on PostgreSQL

- safe for existing rows

- aware of lock duration

- reversible where realistically possible

Do not rewrite old migrations merely to make history look cleaner.

Use new corrective migrations for already-deployed systems.

---

# 45. Zero / Low Downtime Principles

Target:

```text

low downtime

predictable rollback

data safety

```

Use expand-and-contract migration when needed:

```text

add new column/table

deploy compatible application

backfill

switch reads/writes

validate

remove old structure in later release

```

---

# 46. End-to-End Engineering Phases

## Phase 0 — Freeze the Known-Good Baseline

### Goal

Ensure existing HRM behavior is reproducible before architecture changes.

### Tasks

- preserve `.discovery/ARCHITECTURE-CONTRACT.md`

- preserve `.discovery/AGENT-READ-FIRST.md`

- record current Git SHA

- preserve test baseline

- verify clean tracked working tree

- capture current route map

- capture current migration map

- capture current environment contract

### Exit Criteria

```text

34 tests

94 assertions

PASS

```

No unexplained tracked source modifications.

---

## Phase 1 — PostgreSQL Compatibility Gate

### Goal

Make PostgreSQL a proven application target before deployment.

### Tasks

- provision local/CI PostgreSQL

- create disposable HRM test DB

- run `migrate:fresh`

- run complete test suite

- identify SQL portability problems

- replace MySQL-specific dashboard month aggregation

- verify date/time behavior

- verify unique constraints

- verify migration ordering

- run targeted database integration tests

### Explicitly Inspect

```text

DashboardController

Presence migrations

HR migration rollback

raw SQL

date casts

numeric payroll fields

```

### Exit Criteria

```text

PostgreSQL migrate:fresh PASS

full tests PASS

no production-blocking DB-specific SQL

```

---

## Phase 2 — VPS Foundation

### Goal

Prepare the host before application deployment.

### Tasks

- update OS

- create non-root deployment user

- SSH key authentication

- restrict root login

- firewall

- time synchronization

- swap if appropriate

- install Nginx

- install selected runtime stack

- create `/opt/reltroner`

- log rotation

- backup directory

- verify DNS

### Exit Criteria

```text

SSH hardened

only intended ports public

Nginx responds

disk/memory baseline recorded

```

---

## Phase 3 — PostgreSQL Production Foundation

### Goal

Deploy persistent production storage.

### Tasks

- install PostgreSQL

- restrict listener

- create `keycloak_db`

- create `keycloak_app`

- create `hrm_db`

- create `hrm_app`

- restrict privileges

- configure backup process

- test backup

- test restore

### Exit Criteria

```text

Keycloak DB isolated

HRM DB isolated

no public DB exposure

backup PASS

restore test PASS

```

---

## Phase 4 — Redis Production Foundation

### Goal

Provide session/cache/queue infrastructure.

### Tasks

- install Redis

- bind privately

- configure authentication/private networking

- configure memory policy

- verify Laravel connection

- configure Laravel session/cache/queue

- add queue failure visibility

### Exit Criteria

```text

Redis inaccessible publicly

Laravel session PASS

cache PASS

queue PASS

```

---

## Phase 5 — Deploy Keycloak at auth.reltroner.com

### Goal

Create the production identity plane.

### Tasks

- deploy Keycloak production mode

- connect Keycloak to `keycloak_db`

- configure hostname

- configure Nginx reverse proxy

- configure HTTPS

- create realm `reltroner`

- create bootstrap admin securely

- configure password policy

- prepare MFA capability

- configure backup

- create health/restart policy

### Exit Criteria

```text

https://auth.reltroner.com available

Keycloak survives restart

Keycloak data persists

TLS valid

DB persistent

```

---

## Phase 6 — OIDC Dual-Client Boundary & Identity Contract

### Goal

Create an explicit, testable OIDC identity contract between Keycloak and the production/demo HRM environments, using separate confidential clients, exact redirect boundaries, minimal token scopes, environment eligibility checks, and Laravel-side authorization separation.

Phase 6 is a security-boundary phase, not merely a client-creation task.

It establishes:

```text
authentication
!=
environment eligibility
!=
business authorization
```

### 6A — OIDC Contract Freeze

Define and freeze:

```text
realm
client IDs
hostnames
callback routes
post-logout routes
identity classes
token-claim contract
subject identity key
environment invariants
```

Required identity invariants:

```text
human identity:
EXACTLY ONE OF
production_user
demo_user

service identity:
service_account

platform-admin:
administrative identity only
not an HRM environment classification
```

A user holding both production and demo identity classes is an invalid classification unless an explicit architecture exception exists.

### 6B — Production Client: `hrm-web`

Configure:

```text
OpenID Connect
confidential client
Client authentication ON
Authorization Code / Standard Flow ON
PKCE required
PKCE method S256
exact production callback
exact production post-logout URI
unique production secret
```

Disable unrelated grant surfaces:

```text
Direct Access Grants
Implicit Flow
Service Account Roles
Standard Token Exchange
JWT Authorization Grant
Device Authorization Grant
OIDC CIBA
Authorization Services
```

### 6C — Demo Client: `hrm-demo-web`

Apply the same security baseline using:

```text
separate client ID
separate client secret
separate callback
separate post-logout URI
separate browser-flow override
```

Demo must not reuse production secret material.

### 6D — Minimal Client Scopes

Set:

```text
Full Scope Allowed = OFF
```

Use dedicated environment scopes:

```text
hrm-production-identity -> hrm-web
hrm-demo-identity       -> hrm-demo-web
```

Inspect effective access/ID tokens before freeze.

Required result:

```text
production client:
  production identity evidence only

demo client:
  demo identity evidence only

no unnecessary all-realm-role leakage
no offline_access unless explicitly required
no unrelated group/profile claims
```

The environment identity claim is admission evidence only and never business authorization.

### 6E — Keycloak Client Access Gates

Use client-specific browser flow overrides:

```text
hrm-web
  -> browser-hrm-production-v2

hrm-demo-web
  -> browser-hrm-demo-v2
```

Required role-gate tests:

```text
production_user -> hrm-web       ALLOW
demo_user       -> hrm-web       DENY

demo_user       -> hrm-demo-web  ALLOW
production_user -> hrm-demo-web  DENY

unclassified    -> both          DENY
service identity-> both          DENY
both prod+demo  -> INVALID / audit failure
```

The environment check must also be tested using an already-active Keycloak SSO cookie so a valid realm session cannot bypass the client-specific gate.

### 6F — Laravel OIDC Contract

Phase 6 defines the mandatory downstream Laravel contract even when Laravel deployment occurs in Phase 7.

Laravel must:

```text
generate and verify state
generate and verify nonce
generate PKCE verifier
use S256 challenge
exchange code server-side
validate token signature
validate issuer
validate audience / authorized client
validate expiry / temporal claims
validate expected environment identity
resolve issuer + subject
require approved local HRM linkage
```

Stable external identity key:

```text
issuer + subject
```

Email and username are not trust anchors.

### 6G — Authorization Separation Contract

Production must prove:

```text
valid Keycloak authentication
+ correct environment eligibility
+ no approved HRM identity linkage
= no HRM authenticated session
```

It must also prove:

```text
valid HRM session
+ missing business permission
= operation denied
```

The callback must not JIT-create production business authority.

### 6H — Logout and Session Boundary

Freeze separate production/demo contracts for:

```text
post-logout redirect
Laravel cookie name
host-only cookie scope
APP_KEY
Redis session namespace
local-session invalidation
Keycloak logout behavior
```

Production and demo Laravel sessions must never be implicitly shared through `.reltroner.com`.

### 6I — Persistence / Restart

Verify:

```text
Keycloak service restart
client persistence
realm-role persistence
browser-flow persistence
PostgreSQL-backed identity state
full VPS reboot
backup generation
backup integrity
private listener persistence
```

### 6J — Documentation / Freeze

Freeze evidence without recording:

```text
client secrets
passwords
authorization codes
access tokens
refresh tokens
private keys
recovery codes
```

### Phase 6 Sequencing Rule

Phase 6 contains both Keycloak-side controls and downstream Laravel contracts.

When the approved Laravel runtime endpoint is not yet deployed, it is valid to freeze the completed Keycloak-side boundary while explicitly carrying Laravel runtime enforcement into Phase 7 and Phase 10.

This is not a waiver of the final requirement.

The status model is:

```text
Keycloak dual-client boundary:
  may become PASS / FROZEN after 6A-6E, 6I, and documentation evidence

Laravel runtime enforcement:
  contract frozen in 6F-6H
  implementation/evidence required in Phase 7 and Phase 10

Full end-to-end identity acceptance:
  not complete until all deferred runtime gates pass
```

### Keycloak-Side Exit Criteria

Before freezing the Keycloak-side Phase 6 baseline:

```text
hrm-web exists and is confidential
hrm-demo-web exists and is confidential

Authorization Code Flow enabled
PKCE S256 enforced
Implicit Flow disabled
Direct Access Grants disabled
alternate grants disabled

production and demo use separate secrets
redirect URIs are exact
post-logout URIs are exact
no wildcard production redirects

Full Scope Allowed disabled
minimal client claims verified
cross-environment claim leakage absent

production_user can enter production
demo_user cannot enter production

demo_user can enter demo
production_user cannot enter demo

unclassified identities denied
service identities denied
both prod+demo classification rejected/audited

existing SSO session cannot bypass environment boundary

OIDC authorization returns a valid code to the approved callback contract
code-to-token exchange succeeds with the correct confidential client + PKCE

OIDC configuration survives Keycloak restart
client/role/flow state persists in keycloak_db
backup/recovery point exists
```

### Deferred Runtime Gates

These remain mandatory before full end-to-end SSO acceptance:

```text
Laravel rejects invalid state
Laravel rejects invalid/missing nonce
Laravel rejects wrong issuer
Laravel rejects wrong audience/client
Laravel rejects wrong environment identity claim

valid production identity without approved HRM linkage
  -> 403 / no HRM session

valid production identity with approved HRM linkage
  -> local session may be established

HRM user without business permission
  -> operation denied

logout invalidates local session
production/demo session planes remain isolated
```

If the callback hostname is not yet serving the Laravel application, record:

```text
Keycloak client contract        PASS
Authorization request           PASS
Code issuance                   PASS
Code-to-token exchange          PASS when independently verified
Runtime Laravel callback        DEFERRED until approved endpoint exists
```

Do not weaken redirect rules or introduce wildcard callbacks merely to make an undeployed application endpoint appear complete.

---

## Phase 7 — HRM Identity Module

### Goal

Implement the Laravel side of the OIDC identity contract without rewriting HR authorization.

This phase implements the runtime controls frozen by Phase 6 sections 6F and 6G.

### Tasks

- add `external_identities` table
- persist canonical issuer and subject
- enforce `UNIQUE(issuer, subject)`
- add model relationship
- implement SSO redirect
- generate/store state
- generate/store nonce
- generate/store PKCE verifier
- implement callback
- validate state
- validate nonce
- validate token signature
- validate issuer
- validate audience / authorized client
- validate expiry / temporal claims
- validate expected environment identity
- exchange code server-side
- resolve Keycloak issuer + subject
- link only to an approved local User
- deny unprovisioned production identities
- establish Laravel session only after all identity gates pass
- rotate session after authentication
- add identity-link audit event
- add SSO tests
- ensure production login does not JIT-create business authority

### Must Preserve

```text
User -> Employee
CheckRole
session role compatibility
employee_id compatibility
existing HR domain behavior
```

Do not combine SSO migration with an RBAC rewrite.

### Mandatory Negative Tests

```text
invalid state                       -> DENY
missing/invalid nonce               -> DENY
wrong issuer                        -> DENY
wrong audience/client               -> DENY
wrong environment identity claim    -> DENY
valid OIDC + no approved HRM link   -> 403 / no session
valid session + no business right   -> operation DENY
```

### Exit Criteria

A correctly classified Keycloak identity can authenticate into the expected HRM account through an approved issuer+subject linkage, while invalid callback data, wrong environment identity, unprovisioned identities, and existing HR authorization failures remain denied.

Authentication success must not bypass existing authorization.

---

## Phase 8 — Transitional Dual Authentication

### Goal

Reduce migration risk.

For a limited migration period:

```text

SSO primary

legacy login retained behind explicit feature flag

```

### Tasks

- add feature flag/environment control

- retain local login only where required

- migrate test/admin accounts

- link known users

- monitor failed linking

- document rollback

### Exit Criteria

All intended production users can authenticate through Keycloak.

---

## Phase 9 — Production HRM Deployment

### Goal

Deploy `hrm.reltroner.com` using PostgreSQL and Redis.

### Tasks

- deploy known Git release

- configure production environment

- enforce PostgreSQL

- configure Redis

- configure HTTPS/trusted proxies

- run PostgreSQL migrations

- build assets

- cache production config/routes/views

- configure storage

- configure queue workers

- configure scheduler

- configure Nginx

- smoke test

### Production Environment

```text

APP_ENV=production

APP_DEBUG=false

DB_CONNECTION=pgsql

SESSION_DRIVER=redis

CACHE_STORE=redis

QUEUE_CONNECTION=redis

```

### Exit Criteria

```text

https://hrm.reltroner.com responds

SSO login works

PostgreSQL is active DB

Redis session works

queue works

scheduler works

health check works

```

---

## Phase 10 — End-to-End SSO Acceptance

Phase 10 is the full runtime acceptance point for the identity contract.

All Phase 6 deferred Laravel/runtime gates must be closed here.

### Anonymous HRM access

```text
anonymous
-> HRM protected page
-> Keycloak
-> login
-> environment eligibility check
-> HRM callback
-> Laravel callback validation
-> approved local identity linkage
-> local session
-> protected HRM page
```

### Existing Keycloak session

```text
already authenticated at Keycloak
-> HRM
-> SSO
-> client-specific environment gate still evaluated
-> no unnecessary password prompt
-> HRM callback
-> local session only if all Laravel gates pass
```

Mandatory cross-environment SSO-cookie test:

```text
demo_user with active Keycloak SSO cookie
-> production hrm-web
-> DENY

production_user with active Keycloak SSO cookie
-> demo hrm-demo-web
-> DENY
```

### Environment matrix

| Test | Expected |
|---|---|
| `production_user` -> `hrm-web` | ALLOW OIDC |
| `demo_user` -> `hrm-web` | DENY |
| `production_user` -> `hrm-demo-web` | DENY |
| `demo_user` -> `hrm-demo-web` | ALLOW OIDC |
| no environment identity role -> `hrm-web` | DENY |
| no environment identity role -> `hrm-demo-web` | DENY |
| service identity interactive login | DENY |
| both production + demo roles | INVALID / audit failure |
| wrong redirect URI | DENY |
| wildcard redirect attempt | DENY |

### Laravel callback validation

| Test | Expected |
|---|---|
| invalid state | DENY |
| invalid/missing nonce | DENY |
| wrong issuer | DENY |
| wrong client/audience | DENY |
| expired/invalid token | DENY |
| wrong environment identity claim | DENY |
| PKCE mismatch | DENY |
| valid production identity but no approved HRM user linkage | 403 / no session |
| valid production identity + approved HRM user | authentication may continue |
| HRM user lacks business permission | operation 403 |

### Logout

```text
HRM logout
-> Laravel session invalidated
-> CSRF/session state rotated as required
-> Keycloak logout according to policy
-> exact environment post-logout redirect
-> protected HRM route requires authentication
```

Production and demo Laravel sessions must remain separate if both application planes are deployed.

### Identity stability

Change mutable Keycloak profile data such as email.

Expected:

```text
same issuer
same Keycloak sub
-> same local HRM User
```

### Authorization

Valid Keycloak authentication and valid environment eligibility must not automatically imply HRM business authorization.

### Exit Criteria

All scenarios PASS.

Only after this phase may the identity path be described as end-to-end accepted.

---

## Phase 11 — SSO Cutover

### Goal

Make Keycloak the production authentication authority.

### Tasks

- switch `/login` primary behavior to SSO

- disable local password authentication for normal production users

- retain operational recovery procedure

- remove transitional feature flag after observation period

- update tests

### Exit Criteria

Normal production authentication is Keycloak-only.

---

## Phase 12 — Authorization Hardening

### Goal

Repair authorization gaps independently from authentication.

### Priority Work

1. protect `/dashboard/presence`

2. real middleware-enabled RBAC tests

3. record ownership Policies

4. payroll authorization

5. leave authorization

6. attendance authorization

7. task authorization

8. protected role update rules

9. public employee endpoint review

### Required Test Style

Do not disable the middleware being tested.

Examples:

```text

Admin may access

HR Manager may access

Employee forbidden

Employee may view own record

Employee forbidden from another employee record

cross-organization access forbidden

```

---

## Phase 13 — HTTP and CSRF Hardening

### Goal

Eliminate state-changing GET behavior.

### Tasks

Migrate:

```text

leave approve

leave reject

task status change

logout

```

to appropriate mutation verbs.

Update:

- Blade forms

- JS callers

- routes

- tests

- CSRF behavior

---

## Phase 14 — Audit Foundation

### Goal

Make sensitive business changes traceable.

### Tasks

- implement audit layer

- audit SSO link

- audit Employee changes

- audit Role changes

- audit Leave approval/rejection

- audit manual Attendance modification

- audit Payroll mutation

- add correlation IDs

- redact secrets

---

## Phase 15 — Queue / Horizon Productionization

### Goal

Move non-interactive work away from request latency.

### Tasks

- install/configure Horizon if selected

- protect dashboard

- configure queues

- configure worker limits

- configure retry policy

- configure failed jobs

- add deployment worker restart

- monitor queue latency

---

## Phase 16 — Incremental Modularization

### Goal

Move toward modular monolith without behavior rewrite.

### Suggested Order

```text

Identity

People / Departments

Attendance

Leave

Work

Payroll

Audit

Reporting

```

Each module extraction must preserve behavior and pass full regression testing.

---

## Phase 17 — Organization / Multi-Tenancy

### Goal

Prepare HRM for multiple companies.

### Core Model

```text

User

 └── Membership

      └── Organization

           └── Employee

```

Potential organization-scoped tables:

```text

employees

departments

roles

tasks

presences

leave_requests

payrolls

documents

audit_logs

```

### Mandatory Tests

```text

Organization A cannot read Organization B

Organization A cannot mutate Organization B

indexes scoped by organization

unique constraints correctly scoped

```

---

## Phase 18 — RBAC v2

### Goal

Move from role-name compatibility toward permission-based authorization.

### Migration Model

```text

legacy role strings

       ↓

compatibility mapping

       ↓

roles

       ↓

permissions

       ↓

Policies

```

Do not remove legacy role fields until callers, tests, and production accounts are migrated.

---

## Phase 19 — Reporting and Deterministic Analytics

### Goal

Provide business insight without using an LLM as calculator.

```text

PostgreSQL

   ↓

Read Queries

   ↓

Analytics Engine

   ↓

Structured Metrics

   ↓

Dashboard / Export

```

Candidate metrics:

- headcount

- active/inactive employees

- hires

- attendance rate

- lateness

- absence

- leave utilization

- payroll total

- salary distribution

- bonus/deduction

- task completion

- overdue workload

---

## Phase 20 — Search

### Goal

Provide scalable application search.

Initial:

```text

PostgreSQL / Laravel database search

```

Future:

```text

SearchEngine contract

      ↓

Meilisearch

```

Search documents must include authorization metadata.

---

## Phase 21 — AI Assistant

### Goal

Add an optional permission-aware HR assistant.

```mermaid

flowchart TB

    Q[User Question]

    A[HRM Assistant]

    Z[Authorization / Organization Scope]

    T[Tool Router]

    D[Deterministic Queries]

    S[Statistics]

    R[Search / RAG]

    L[Open-source LLM]

    O[Answer + Provenance]

    Q --> A

    A --> Z

    Z --> T

    T --> D

    T --> S

    T --> R

    D --> L

    S --> L

    R --> L

    L --> O

```

Never allow arbitrary model-generated SQL execution.

Controlled tools should expose narrow operations such as:

```text

GetMyAttendanceSummary

GetMyLeaveBalance

ListMyTasks

GetDepartmentHeadcount

GetPayrollSummary

SearchAuthorizedDocuments

```

---

# 47. Engineering Change Protocol

Every implementation task must follow:

```text

Discover

   ↓

Understand

   ↓

Define invariant

   ↓

Write / update test

   ↓

Minimal implementation

   ↓

Targeted test

   ↓

Full PostgreSQL test

   ↓

Inspect diff

   ↓

Deploy

   ↓

Observe

```

---

# 48. Required AI Agent Pre-Change Response

Before editing code, the Agent must state:

## Task Interpretation

What exact behavior is requested?

## Current Execution Path

```text

Route

-> middleware

-> controller

-> action/service

-> model/query

-> database

-> response

```

## Files Involved

Exact files.

## Invariants

What must remain unchanged?

## Existing Tests

Which tests actually prove behavior?

## Coverage Gaps

What is not currently tested?

## Risks

What can regress?

## Proposed Minimal Change

Smallest safe implementation.

Only then should the Agent modify code.

---

# 49. Definition of Done for Any Engineering Phase

A phase is complete only when:

```text

implementation complete

tests complete

PostgreSQL compatibility verified

security boundary verified

Git diff reviewed

deployment documentation updated

rollback known

production health verified

```

---

# 50. Final Production Acceptance Checklist

## Infrastructure

- [ ] Nginx only public application gateway
- [ ] TLS valid
- [ ] PostgreSQL private
- [ ] Redis private
- [ ] Keycloak internal port private
- [ ] Keycloak management port private
- [ ] SSH restricted
- [ ] firewall active

## Keycloak / OIDC Identity Boundary

- [ ] production mode
- [ ] PostgreSQL persistence
- [ ] realm `reltroner`
- [ ] `hrm-web` confidential client
- [ ] `hrm-demo-web` confidential client contract
- [ ] separate production/demo client secrets
- [ ] Authorization Code / Standard Flow only
- [ ] PKCE S256 required
- [ ] Direct Access Grants disabled
- [ ] Implicit Flow disabled
- [ ] alternate unused grants disabled
- [ ] exact production redirect URI
- [ ] exact demo redirect URI
- [ ] no wildcard production redirects
- [ ] exact post-logout URIs
- [ ] Full Scope Allowed disabled
- [ ] minimal environment client scopes verified
- [ ] no cross-environment identity-claim leakage
- [ ] `production_user` / `demo_user` exclusivity enforced or audited
- [ ] service identities cannot enter interactive HRM flows
- [ ] client-specific browser-flow bindings verified
- [ ] existing SSO cookie cannot bypass environment gate
- [ ] restart persistence validated
- [ ] full reboot persistence validated
- [ ] backup validated

## HRM Identity Integration

- [ ] production PostgreSQL
- [ ] Redis session
- [ ] Redis cache
- [ ] Redis queue
- [ ] APP_DEBUG=false
- [ ] secure cookies
- [ ] external identity mapping uses issuer + subject
- [ ] local User retained
- [ ] Employee relationship retained
- [ ] callback validates state
- [ ] callback validates nonce
- [ ] callback validates token signature
- [ ] callback validates issuer
- [ ] callback validates audience/client
- [ ] callback validates expected environment identity
- [ ] PKCE verifier validated by code exchange
- [ ] unprovisioned production identity does not receive HRM session
- [ ] no JIT production business authority
- [ ] current authorization compatibility preserved
- [ ] production/demo Laravel session planes isolated when both are deployed
- [ ] PostgreSQL full tests PASS

## End-to-End Identity Acceptance

- [ ] production positive login PASS
- [ ] demo positive login PASS when demo runtime is deployed
- [ ] wrong-environment identity denied
- [ ] unclassified identity denied
- [ ] service identity denied
- [ ] invalid dual production+demo classification detected
- [ ] existing SSO-cookie cross-environment bypass test PASS
- [ ] invalid state/nonce/issuer/audience/environment tests PASS
- [ ] valid identity without approved local linkage denied
- [ ] valid identity without business permission denied at operation boundary
- [ ] logout validated
- [ ] mutable email does not change issuer+subject identity linkage

## Security

- [ ] unauthorized dashboard endpoint fixed
- [ ] record ownership tested
- [ ] state-changing GET routes migrated
- [ ] sensitive logs reviewed
- [ ] secrets excluded from repository
- [ ] rate limits defined
- [ ] audit trail enabled for sensitive operations

## Operations

- [ ] scheduler configured
- [ ] queue worker configured
- [ ] failed jobs visible
- [ ] backup offsite
- [ ] restore procedure tested
- [ ] health checks
- [ ] log rotation
- [ ] disk monitoring
- [ ] memory monitoring

---

# 51. Final System Boundary

```text

                           RELTRONER IDENTITY

                         auth.reltroner.com

                              Keycloak

                                 │

                                 │ OIDC

                                 ▼

                       hrm.reltroner.com

                       Laravel Modular Monolith

                                 │

             ┌───────────────────┼────────────────────┐

             │                   │                    │

             ▼                   ▼                    ▼

      Business Modules       PostgreSQL             Redis

             │              source of truth     runtime state

             │

      ┌──────┼──────────────────────────────┐

      ▼      ▼        ▼       ▼      ▼      ▼

    People Attendance Leave Payroll Work  Audit

                                 │

                                 ▼

                          Reporting/Search/AI

                             future modules

```

Responsibility boundaries:

```text

KEYCLOAK

Who are you?

HRM

Which company are you in?

What may you do?

Which record may you access?

What business transition is allowed?

POSTGRESQL

What is the authoritative business state?

REDIS

What temporary/runtime state helps the application operate efficiently?

```

---

# 52. Final Engineering Strategy

The architecture deliberately avoids two extremes.

It does not remain:

```text

simple CRUD forever

```

and it does not jump into:

```text

premature microservices

Kubernetes

many databases per domain

distributed business transactions

```

Instead:

```text

Existing HRM

    ↓

PostgreSQL-compatible HRM

    ↓

Production VPS foundation

    ↓

Keycloak SSO

    ↓

Redis runtime

    ↓

Hardened authorization

    ↓

Audit + reliable operations

    ↓

Modular monolith

    ↓

Organization tenancy

    ↓

Permission RBAC

    ↓

Reporting / Analytics

    ↓

Search

    ↓

Permission-aware AI

```

---

# 53. Engineering Milestone Sequence

This section records the canonical implementation order from the architecture baseline. Individual steps may already be completed in the live engineering history; the sequence remains the dependency model.

The milestone is:

> **PostgreSQL Production Compatibility + Keycloak SSO Foundation**

Concrete order:

```text
1. Prove HRM on PostgreSQL locally/CI.
2. Remove PostgreSQL blockers.
3. Provision PostgreSQL on VPS.
4. Deploy Keycloak at auth.reltroner.com.
5. Create `reltroner` realm.
6. Freeze the dual-client OIDC identity boundary.
7. Implement HRM external identity mapping.
8. Implement redirect/callback/login session.
9. Preserve existing CheckRole compatibility.
10. Deploy HRM at hrm.reltroner.com using PostgreSQL.
11. Validate real browser SSO end-to-end, including negative identity gates.
12. Establish/verify Redis session/cache/queue integration.
13. Close Phase 6 deferred runtime gates through Phase 7/10 acceptance.
14. Freeze Production Foundation v1.
```

Only after this checkpoint should the project continue into broader authorization hardening and modularization.

---

# 53A. Architecture Revision Note

The earlier Phase 6 label `Create hrm-web OIDC Client` was superseded by `OIDC Dual-Client Boundary & Identity Contract`. This is a sequencing clarification only. The final Production Foundation v1 end-state defined below remains the target and is not reduced.

---

# 54. Production Foundation v1 Definition

Production Foundation v1 is reached when all of the following are true:

```text

auth.reltroner.com

    Keycloak

    HTTPS

    PostgreSQL

    persistent

    restart-safe

hrm.reltroner.com

    Laravel

    HTTPS

    PostgreSQL

    Redis

    SSO

    local authorization

    queue

    scheduler

Authentication

    Keycloak primary

Authorization

    HRM local

Database

    PostgreSQL production only

Local development

    SQLite or MySQL allowed

Testing

    PostgreSQL release gate

Recovery

    backup + restore procedure

Architecture

    modular-monolith trajectory preserved

```

At this point the platform is ready for subsequent business expansion without needing an architectural rewrite.
