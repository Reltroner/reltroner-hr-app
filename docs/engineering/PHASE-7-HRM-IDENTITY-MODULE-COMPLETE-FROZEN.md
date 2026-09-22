# Phase 7 — HRM IDENTITY MODULE COMPLETE / FROZEN

## Status

**Phase 7 — COMPLETE / PASS / FROZEN**

**Freeze date:** 2026-09-22

Phase 7 establishes and freezes the Laravel-side OpenID Connect identity boundary for Reltroner HRM.

This freeze records the completed code-level identity implementation and its PostgreSQL 18 verification evidence. It does **not** claim full deployed browser end-to-end SSO acceptance. Real production browser redirect/callback behavior, production cookie behavior, real Keycloak interaction, RP-initiated logout, and production network/runtime behavior remain later runtime/deployment concerns.

---

## 1. Phase 7 Scope

Phase 7 implemented:

```text
external identity persistence
canonical issuer + subject trust key
UNIQUE(issuer, subject)
User relationship
OIDC transaction primitives
state
nonce
PKCE verifier
PKCE S256 challenge
authorization redirect
server-side authorization-code exchange
JWKS acquisition
RS256 signature validation
issuer validation
audience/client validation
temporal claim validation
nonce validation
environment identity-class validation
approved local identity resolution
Laravel web session establishment
session regeneration
last_login_at telemetry
identity-link audit events
safe structured identity-link audit logging
```

No RBAC rewrite occurred.

---

## 2. Identity Trust Model

Permanent external identity trust key:

```text
issuer + subject
```

Not:

```text
email
username
preferred_username
provider alone
```

Production invariant:

```text
valid OIDC identity
+ correct environment eligibility
+ approved local ExternalIdentity link
+ existing Laravel HR authorization
= possible authorized HRM access
```

A valid OIDC identity alone does not create:

```text
User
Employee
role
permission
business authority
```

---

## 3. External Identity Persistence

Table:

```text
external_identities
```

Phase 7 fields:

```text
user_id
provider
issuer
subject
email_at_link
linked_at
last_login_at
```

Database invariant:

```text
UNIQUE(issuer, subject)
```

Relationship:

```text
ExternalIdentity
→ belongsTo User
```

One local User may hold multiple distinct external identities.

Deleting a User removes related ExternalIdentity rows through the existing database foreign-key cascade.

`provider` and `email_at_link` are metadata, not authority.

---

## 4. OIDC Transaction Boundary

The browser authorization transaction contains:

```text
state
nonce
PKCE verifier
PKCE S256 challenge
created-at timestamp
```

The transaction layer supports:

```text
multiple concurrent states
TTL pruning
single-use callback state
session-backed storage
callback route blocking
```

Callback state is consumed before downstream processing and is not restored if later processing fails.

The PKCE verifier remains server-side.

---

## 5. Cryptographic Validation

Authorization Code exchange is server-side.

ID-token validation includes:

```text
RS256 requirement
kid/JWKS key selection
JWKS refresh behavior
signature verification before claim trust
issuer validation
audience validation
authorized-party/client validation where applicable
exp
iat
optional nbf
nonce
subject
expected environment identity class
```

The implementation does not expose or document:

```text
test private keys
JWK modulus/exponent material
access tokens
ID tokens
authorization codes
client secrets
PKCE verifier
```

---

## 6. Environment Eligibility vs HR Authorization

Keycloak identity classes:

```text
production_user
demo_user
```

These are identity-plane environment classifications only.

They are not:

```text
Admin
HR Manager
Employee role
business permission
```

Phase 7 preserves the existing authorization compatibility chain:

```text
session('role')
→ Employee role
→ users.role
→ existing CheckRole behavior
```

Active tests prove an authenticated SSO User without the required HR role receives:

```text
403 Forbidden
```

on a protected HR operation.

Therefore:

```text
OIDC authenticated
!=
HR authorized
```

---

## 7. Approved Identity Resolution

Resolution path:

```text
ValidatedOidcIdentity
→ exact issuer + subject lookup
→ approved ExternalIdentity
→ existing local User
```

No:

```text
email linking
firstOrCreate User
JIT User
JIT Employee
JIT role
JIT permission
```

Missing approved link:

```text
403
no Laravel authenticated session
```

Missing linked User/integrity failure:

```text
safe 500
```

After database lookup, strict PHP equality is applied:

```text
stored issuer === validated issuer
stored subject === validated subject
```

---

## 8. Session Establishment

New guest path:

```text
final link revalidation
→ clear stale role / employee_id session compatibility values
→ Auth::guard('web')->login(user, false)
→ regenerate Laravel session
→ persist last_login_at on exact approved link
→ intended redirect or dashboard
```

Same already-authenticated User:

```text
revalidate link
no re-login
no session-ID rotation
no CSRF rotation
no role clearing
no employee_id clearing
no last_login_at update
```

Different already-authenticated User:

```text
409 conflict
original session preserved
no account switch
```

If session establishment fails after login begins, including `last_login_at` persistence failure:

```text
logout
invalidate session
regenerate CSRF token
safe 500
```

---

## 9. Identity-Link Audit

Event:

```text
IdentityLinkAuditEvent
```

Actions:

```text
linked
unlinked
```

The event implements:

```text
ShouldDispatchAfterCommit
```

Therefore:

```text
committed mutation
→ audit

transaction rollback
→ no false audit
```

Log category:

```text
identity.link.audit
```

Logged fields:

```text
action
external_identity_id
user_id
trust_key_fingerprint
actor_id
actor_type
```

Fingerprint:

```text
SHA-256(issuer + NUL + subject)
```

Raw identity/credential values are not logged:

```text
issuer
subject
email
email_at_link
token
authorization code
PKCE data
client secret
session ID
cookie
password
```

ExternalIdentity observer:

```text
created → linked
deleted → unlinked
```

No update observer exists, therefore:

```text
last_login_at update
!= identity-link mutation
```

User deletion cascade is covered by:

```text
User deleting
→ snapshot ExternalIdentity rows
→ no audit yet

User deletion succeeds
→ DB cascade removes child rows

User deleted
→ one unlinked audit event per snapshotted identity
```

After-commit semantics suppress false audit records if the surrounding transaction rolls back.

---

## 10. Security / Fail-Closed Behavior

Verified negative behavior:

```text
invalid state
→ deny

empty / unknown / expired / replayed state
→ deny

provider error
→ deny

missing authorization code
→ deny

token exchange failure
→ deny

invalid signature
→ deny

wrong issuer
→ deny

wrong audience/client
→ deny

invalid temporal claims
→ deny

nonce mismatch
→ deny

wrong environment identity class
→ deny

valid OIDC identity without approved link
→ 403 / no session

authenticated session without HR business authorization
→ protected operation 403

different-user callback over existing authenticated session
→ 409 / no account switch
```

---

## 11. Phase Commit Chain

```text
b93619f
docs: freeze Phase 6 Keycloak identity boundary

2a19b6d
feat(identity): add external identity persistence foundation

866fd01
feat(identity): add OIDC transaction foundation

b23686f
feat(identity): add OIDC authorization redirect

e795e97
feat(identity): add OIDC callback validation

7b064aa
feat(identity): resolve approved OIDC identities

b7a16d9
feat(identity): establish OIDC web sessions

7890779
feat(identity): add identity-link audit events
```

Implementation baseline:

```text
78907797cc60ee0322a94a67f06bfb8f0451fc99
```

The freeze documentation commit itself will have a later SHA.

---

## 12. Final PostgreSQL 18 Evidence

Environment:

```text
PostgreSQL:
18.6

Container image:
postgres:18

Disposable database:
reltroner_hrm_phase7_test

Local-only mapping:
127.0.0.1:55432 -> 5432
```

No production or VPS database was used.

No database password is recorded here.

Migration evidence:

```text
migrate:fresh
PASS

8 migrations
PASS
```

Targeted evidence:

```text
ExternalIdentityPersistenceTest
7 passed / 19 assertions

OidcIdentityResolverTest
19 passed / 39 assertions

OidcSessionManagerTest
32 passed / 69 assertions

IdentityLinkAuditTest
17 passed / 68 assertions

OidcCallbackTest
24 passed / 73 assertions

ProfileTest
5 passed / 21 assertions

OidcTokenClientTest + JwksProviderTest + IdTokenValidatorTest
52 passed / 114 assertions

OidcRedirectTest
23 passed / 59 assertions

OidcPrimitivesTest
7 passed / 14 assertions

OidcTransactionStoreTest
10 passed / 33 assertions

AuthenticationTest
4 passed / 8 assertions
```

Full PostgreSQL suite:

```text
232 passed
0 failed
5 skipped
602 assertions
28.89s
```

The five skipped tests are Redis infrastructure integration tests disabled in this local validation environment because `RUN_REDIS_INTEGRATION` was not enabled. They are not Phase 7 identity failures.

Migration reverse/rebuild:

```text
migrate:rollback
PASS

migrate:fresh after rollback
PASS
```

The disposable PostgreSQL 18 container was removed after validation. The local PostgreSQL 17 Windows service remained untouched.

---

## 13. PostgreSQL-Specific Proofs

```text
UNIQUE(issuer, subject)
PASS

User → ExternalIdentity FK cascade
PASS

identity audit after commit
PASS

creation rollback audit suppression
PASS

User-delete rollback audit suppression
PASS

strict identity resolution
PASS

exact last_login_at persistence
PASS
```

The duplicate identity pair produced the expected PostgreSQL unique-constraint rejection.

Transaction-aware audit behavior was proven against PostgreSQL 18.

---

## 14. Process Deviation / Closure

One procedural deviation occurred:

```text
The dedicated full PostgreSQL gate immediately after Phase 7F
was not executed before subsequent Phase 7G work began.
```

This is intentionally recorded.

No history rewrite or force-push was performed.

The procedural debt was closed by the final PostgreSQL 18 validation at:

```text
78907797cc60ee0322a94a67f06bfb8f0451fc99
```

That validation covered Phase 7F, 7G, and 7H through:

```text
targeted identity tests
+
full PostgreSQL suite
+
migration rollback
+
migration rebuild
```

Final result:

```text
232 passed
0 failed
602 assertions
```

---

## 15. Preserved Legacy HRM Behavior

Preserved:

```text
User -> Employee
CheckRole
session('role') compatibility
session('employee_id') compatibility
users.role fallback
legacy password authentication
```

Phase 7 intentionally does not perform RBAC v2 migration.

---

## 16. Deferred / Not Claimed

Phase 7 does not claim completion of:

```text
full deployed browser SSO
real production Keycloak callback execution
production cookie/domain validation
RP-initiated Keycloak logout
production Redis-backed session runtime
Phase 8 legacy-login feature-flag migration
RBAC v2
multi-tenancy
generalized persistent audit foundation
Phase 14 audit table
Phase 14 correlation IDs
full production browser/network acceptance
```

These are later architecture/runtime phases, not Phase 7 defects.

---

## 17. Exit Criteria

Architecture-contract exit criteria are satisfied at the code and PostgreSQL-validation level.

```text
correctly classified identity
→ approved HRM account can be resolved

invalid callback data
→ denied

wrong environment identity
→ denied

unprovisioned production identity
→ denied

existing authorization failure
→ denied

authentication success
→ does not bypass authorization

PostgreSQL 18 compatibility
→ PASS
```

Final production-database test evidence:

```text
232 passed
0 failed
5 skipped
602 assertions
28.89s
```

---

## Final Phase Status

```text
PHASE 7
COMPLETE
PASS
FROZEN
```

Implementation baseline:

```text
78907797cc60ee0322a94a67f06bfb8f0451fc99
```

Phase 7 freezes the Laravel-side HRM identity module and approved OIDC identity boundary.

Responsibility separation remains:

```text
Keycloak
→ identity proof
→ environment eligibility

Laravel HRM
→ approved local identity linkage
→ Laravel session establishment
→ business authorization
→ record/business rules
```

Full deployed browser SSO and later runtime/deployment concerns remain intentionally deferred to their designated phases.
