# Phase 6 — KEYCLOAK-SIDE COMPLETE / FROZEN

## Status

**Phase 6 — KEYCLOAK-SIDE COMPLETE / PASS / FROZEN**

**Freeze date:** 2026-09-22

Phase 6 establishes and freezes the Keycloak-side OpenID Connect boundary between the Reltroner HRM production and demo environments.

This freeze covers the Keycloak-side identity boundary only. The downstream Laravel runtime enforcement defined by Phase 6F, Phase 6G, and Phase 6H remains mandatory and is intentionally carried forward into Phase 7 and Phase 10 according to the architecture contract.

This document therefore does **not** claim full end-to-end Laravel SSO acceptance.

---

## 1. Realm and Issuer

```text
Realm:
reltroner

Issuer:
https://auth.reltroner.com/realms/reltroner
```

The realm is persisted in PostgreSQL and served by the production Keycloak runtime.

---

## 2. OIDC Clients

### Production

```text
Client ID:
hrm-web

Root URL:
https://hrm.reltroner.com

Valid redirect URI:
https://hrm.reltroner.com/auth/keycloak/callback

Valid post-logout redirect:
https://hrm.reltroner.com/
```

### Demo

```text
Client ID:
hrm-demo-web

Root URL:
https://hrm-demo.reltroner.com

Valid redirect URI:
https://hrm-demo.reltroner.com/auth/keycloak/callback

Valid post-logout redirect:
https://hrm-demo.reltroner.com/
```

Both clients are confidential clients.

Production and demo use separate client secrets.

Secret values are intentionally excluded from this document.

Verification result:

```text
SECRET_UNIQUENESS=PASS
```

---

## 3. OIDC Flow Contract

Both clients use the production OIDC contract:

```text
Authorization Code Flow
PKCE S256
Client authentication enabled
Implicit Flow disabled
Direct Access Grants disabled
Alternate grants disabled unless explicitly approved
```

Redirect URIs are exact.

No wildcard production redirect is used.

---

## 4. Identity Classification Contract

Keycloak defines the following environment identity classes:

```text
production_user
demo_user
service_account
```

These roles represent identity-plane classification only.

They do **not** grant HRM business authorization.

Operational identity assignment uses:

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

HRM business authorization remains a Laravel concern.

---

## 5. Client-Specific Browser Authentication Flows

Production:

```text
hrm-web
→ browser-hrm-production-v2
```

Demo:

```text
hrm-demo-web
→ browser-hrm-demo-v2
```

The environment gates are evaluated after authentication and are not bypassed by possession of a valid realm session.

### Production gate

```text
missing production_user -> DENY
demo_user present       -> DENY
service_account present -> DENY
```

### Demo gate

```text
missing demo_user       -> DENY
production_user present -> DENY
service_account present -> DENY
```

---

## 6. Minimal Client Scope Boundary

Both clients use:

```text
Full Scope Allowed = OFF
```

Dedicated environment scopes:

```text
hrm-production-identity -> hrm-web
hrm-demo-identity       -> hrm-demo-web
```

### Production environment mapper

```text
Mapper type:
User Realm Role

Mapper name:
reltroner-production-identity-class

Token claim:
reltroner_identity_class

Claim JSON type:
String

Multivalued:
ON

Add to ID token:
ON

Add to access token:
ON

Add to userinfo:
OFF
```

Production scope restriction:

```text
production_user
```

### Demo environment mapper

```text
Mapper type:
User Realm Role

Mapper name:
reltroner-demo-identity-class

Token claim:
reltroner_identity_class

Claim JSON type:
String

Multivalued:
ON

Add to ID token:
ON

Add to access token:
ON

Add to userinfo:
OFF
```

Demo scope restriction:

```text
demo_user
```

Effective-token inspection verified that representative identities do not receive the opposite environment identity claim.

The normal tested token scope did not include `offline_access`.

No unnecessary cross-environment identity leakage was observed.

---

## 7. Runtime Access Matrix

Verified Keycloak-side behavior:

| Identity classification | `hrm-web` | `hrm-demo-web` |
| --- | --- | --- |
| `production_user` | ALLOW | DENY |
| `demo_user` | DENY | ALLOW |
| unclassified | DENY | DENY |
| `service_account` | DENY | DENY |
| `production_user + demo_user` | DENY | DENY |

This proves the environment boundary is enforced at the Keycloak client authentication layer.

---

## 8. Dual-Classification Runtime Test

A dedicated production test identity was temporarily placed into the invalid dual-classification state:

```text
production_user
demo_user
```

Runtime result:

```text
hrm-web      -> DENY
hrm-demo-web -> DENY
```

Keycloak recorded runtime rejection evidence using user events:

```text
LOGIN_ERROR
error=access_denied
```

for both clients.

After the test, the temporary `demo_user` assignment was removed.

Final production test identity state:

```text
production_user present
demo_user absent
service_account absent
```

Result:

```text
dual classification rejection: PASS
audit evidence: PASS
test-state restoration: PASS
```

---

## 9. Existing SSO Cookie Boundary Test

### Production session → demo client

A production identity first completed successful authentication against:

```text
hrm-web
```

The same browser session then reused the already-active Keycloak realm SSO session and requested:

```text
hrm-demo-web
```

Result:

```text
DENY
LOGIN_ERROR
error=access_denied
```

The existing Keycloak SSO session did not bypass the demo environment gate.

### Demo session → production client

A demo identity first completed successful authentication against:

```text
hrm-demo-web
```

The same browser session then reused the already-active Keycloak realm SSO session and requested:

```text
hrm-web
```

Result:

```text
DENY
LOGIN_ERROR
error=access_denied
```

The existing Keycloak SSO session did not bypass the production environment gate.

Overall result:

```text
existing SSO cookie boundary: PASS
```

---

## 10. Event and Audit Position

Keycloak user-event persistence is enabled.

Observed retention:

```text
1 day
```

Relevant stored event categories include:

```text
Login
Login error
Restart authentication
Restart authentication error
Code to token error
Token exchange
Token exchange error
Logout
Logout error
```

Admin-event persistence is currently disabled.

Authentication rejection evidence required for the Phase 6 environment boundary is available through Keycloak user events.

No client secrets, passwords, access tokens, refresh tokens, private keys, recovery codes, or authorization codes are recorded in this document.

---

## 11. Callback and Deployment Position

The Keycloak authorization contract has been verified independently of Laravel runtime deployment.

Production authorization reaches the approved production callback contract.

Demo authorization reaches the approved demo callback contract.

At freeze time, the final demo Laravel runtime/hostname is not yet serving the application callback.

This is intentionally classified as:

```text
Keycloak client contract     PASS
Authorization request        PASS
Code issuance                PASS
Code-to-token exchange       PASS from prior Phase 6 verification
Laravel runtime callback     DEFERRED
```

No wildcard redirect or weakened redirect rule was introduced to simulate an undeployed application endpoint.

---

## 12. Persistence and Runtime Health

Phase 6 relies on the PostgreSQL-backed Keycloak production foundation.

Previously verified:

```text
Keycloak service restart persistence
full VPS reboot persistence
client persistence
realm-role persistence
browser-flow persistence
PostgreSQL-backed identity state
backup generation
backup integrity
private listener persistence
```

Final Phase 6 runtime health verification on 2026-09-22 showed:

```text
keycloak.service = active
keycloak.service = enabled

Keycloak runtime = 26.7.4
runtime profile = prod

health/ready = UP
health/live  = UP
```

Observed private listeners:

```text
PostgreSQL  127.0.0.1:5432
Redis       127.0.0.1:6379
Keycloak    127.0.0.1:8080
Management  127.0.0.1:9000
```

The Keycloak runtime journal reported:

```text
Keycloak 26.7.4
Profile prod activated
```

The `deploy` operating-system account is not used as the Keycloak runtime account, and direct launcher execution by `deploy` is not required for Phase 6 acceptance.

---

## 13. Deferred Laravel Runtime Contract

The following requirements remain mandatory downstream and are not waived:

```text
state generation and validation
nonce generation and validation
PKCE verifier lifecycle
server-side code exchange
token signature validation
issuer validation
audience / authorized-client validation
expiry / temporal claim validation
expected environment identity validation
issuer + subject identity resolution
approved local HRM linkage
local session establishment
business authorization separation
production/demo session isolation
logout behavior
```

Stable external identity key:

```text
issuer + subject
```

Email and username are not trust anchors.

---

## 14. Phase 6 Exit Criteria

| Requirement | Result |
| --- | --- |
| `hrm-web` exists and is confidential | PASS |
| `hrm-demo-web` exists and is confidential | PASS |
| Authorization Code Flow enabled | PASS |
| PKCE S256 enforced | PASS |
| Implicit Flow disabled | PASS |
| Direct Access Grants disabled | PASS |
| Alternate grants disabled | PASS |
| production/demo secrets are separate | PASS |
| exact redirect URIs | PASS |
| exact post-logout URIs | PASS |
| no wildcard production redirects | PASS |
| Full Scope Allowed disabled | PASS |
| dedicated environment scopes | PASS |
| effective token claims inspected | PASS |
| cross-environment claim leakage absent | PASS |
| `production_user` → production | PASS |
| `demo_user` → production denied | PASS |
| `demo_user` → demo | PASS |
| `production_user` → demo denied | PASS |
| unclassified identity denied | PASS |
| service identity denied | PASS |
| dual production/demo classification rejected | PASS |
| dual-classification audit evidence | PASS |
| existing SSO session cannot bypass environment gate | PASS |
| authorization reaches approved callback contract | PASS |
| confidential-client + PKCE token exchange | PASS |
| Keycloak restart persistence | PASS |
| full VPS reboot persistence | PASS |
| PostgreSQL identity-state persistence | PASS |
| backup/recovery point exists | PASS |
| backup integrity verified | PASS |
| private listener persistence | PASS |

---

## 15. Freeze Rule

The Keycloak-side Phase 6 baseline is frozen.

Changes to any of the following require explicit re-verification:

```text
hrm-web
hrm-demo-web
client secrets
redirect URIs
post-logout URIs
PKCE configuration
client scopes
environment claim mappers
identity-class roles
identity groups
browser-flow overrides
production/demo eligibility gates
Keycloak event policy
Keycloak persistence
Keycloak backend network exposure
```

Phase 7 must consume this identity contract without weakening it.

---

## 16. Final Result

```text
PHASE 6 — OIDC DUAL-CLIENT BOUNDARY & IDENTITY CONTRACT

KEYCLOAK-SIDE:
COMPLETE / PASS / FROZEN

LARAVEL CONTRACT:
FROZEN

LARAVEL RUNTIME IMPLEMENTATION:
DEFERRED TO PHASE 7 / PHASE 10

FULL END-TO-END SSO ACCEPTANCE:
NOT YET COMPLETE

NEXT ACTIVE PHASE:
PHASE 7 — HRM IDENTITY MODULE
```

---

## Engineering Progress After Freeze

```text
Discovery                      100% COMPLETE
6A OIDC Contract Freeze        100% PASS
6B hrm-web                     100% PASS
6C hrm-demo-web                100% PASS
6D Minimal Client Scopes       100% PASS
6E Client Access Gates         100% PASS
6F Laravel OIDC Contract       100% FROZEN
6G Authorization Separation    100% FROZEN
6H Logout / Session Boundary   100% FROZEN
6I Persistence / Restart       100% PASS
6J Documentation / Freeze      100% COMPLETE

KEYCLOAK-SIDE PHASE 6          100% COMPLETE / PASS / FROZEN
NEXT ACTIVE ENGINEERING PHASE  PHASE 7 — HRM IDENTITY MODULE
```
