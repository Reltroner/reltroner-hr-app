# VPS Foundation Baseline

## Status

**Phase:** Phase 2 — VPS Foundation  
**Status:** COMPLETE / FROZEN  
**Target:** Reltroner HRM + Auth Platform  

This document records the verified infrastructure baseline established during Phase 2. All configurations documented herein reflect the hardened, production-capable single-VPS host prior to application deployment and container orchestration.

---

## 1. Host Specification

The foundation is hosted on a Hostinger KVM VPS configured with persistent swap and synchronized system time.

| Parameter | Specification |
| :--- | :--- |
| **Provider** | Hostinger KVM VPS |
| **Region** | Frankfurt |
| **Operating System** | Ubuntu 24.04.5 LTS |
| **Hostname** | `srv1994603` |
| **Kernel** | Linux `6.8.0-139-generic` |
| **Compute** | 1 vCPU |
| **Memory** | 4 GB plan RAM (~3.8 GiB usable) |
| **Swap** | 2 GiB persistent swap file at `/swapfile` |
| **Storage** | 50 GB plan disk (~48 GiB root filesystem) |
| **Available Storage** | ~45 GiB free (final Phase 2 verification) |
| **Time Synchronization** | NTP active and synchronized |

---

## 2. Administrative Access & SSH Hardening

Direct remote root SSH login has been disabled. Normal remote administrative access uses key-based authentication for a dedicated deployment user with sudo privilege escalation.

- **Dedicated Deployment User:** `deploy` (member of `sudo` group).
- **Authentication:** Ed25519 public-key SSH login proven; `sudo` execution proven.
- **Root Login:** Remote root SSH login disabled (`PermitRootLogin no`).
- **Reboot Verification:** SSH daemon hardening parameters survived post-update system reboot.

### SSH Daemon Directive Baseline

```text
Port 22
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
X11Forwarding no
MaxAuthTries 3
```

---

## 3. Network & Firewall (UFW) Posture

The host enforces a default-deny ingress security posture using Uncomplicated Firewall (UFW). Only strictly required management and public web ports are permitted.

- **Default Ingress:** Deny
- **Default Egress:** Allow
- **Management Rate Limiting:** SSH port 22/tcp rate limited
- **Web Profile:** `Nginx Full` profile configured (TCP 80, TCP 443 permitted)

### Port Reachability Matrix

| Port / Protocol | Service Profile | Public Exposure | State / Notes |
| :--- | :--- | :--- | :--- |
| **22 / TCP** | SSH Management | Reachable | Rate limited; key-only |
| **80 / TCP** | Nginx HTTP | Reachable | Serves HTTP 200 OK |
| **443 / TCP** | Nginx HTTPS | No listener | Allowed by UFW; service deferred |
| **5432 / TCP** | PostgreSQL | Not Reachable | Blocked / not publicly bound |
| **6379 / TCP** | Redis | Not Reachable | Blocked / not publicly bound |
| **8080 / TCP** | Keycloak Internal HTTP | Not Reachable | Blocked / not publicly bound |

---

## 4. Operating System Updates & Health

The base operating system was fully patched and validated:

- All standard Ubuntu package updates available during Phase 2 were applied.
- Post-update system reboot completed successfully.
- Final package manager inspection confirmed zero pending standard updates.

---

## 5. Web Server Baseline (Nginx)

Nginx provides the host-level web gateway foundation. During Phase 2 it serves the default HTTP site; application reverse-proxy routing is deferred.

- **Package Version:** `nginx/1.24.0` (Ubuntu package)
- **Configuration Validation:** `nginx -t` passes without warnings
- **Service Status:** Active (`running`) and enabled at system boot
- **Local Response:** `http://127.0.0.1` returns HTTP 200 OK
- **External Response:** External HTTP queries return HTTP 200 OK
- **Host Header Verification:**
  - `http://auth.reltroner.com` returns HTTP 200 OK
  - `http://hrm.reltroner.com` returns HTTP 200 OK
- **TLS Deferred:** TLS certificates and port 443 listener are explicitly deferred to subsequent phases.

---

## 6. Domain Name System (DNS) Routing

Authoritative DNS management is delegated to Cloudflare using direct routing.

- **Management Plane:** Cloudflare
- **Proxy Mode:** DNS-only (unproxied A records)
- **Record Routing:**
  - `auth.reltroner.com` A record points to the VPS
  - `hrm.reltroner.com` A record points to the VPS
- **Verification:** Resolution verified via local resolvers and independent public recursive DNS resolvers.
- **Zone Isolation:** Existing unrelated services and subdomains (`reltroner.com`, `lms.reltroner.com`, `erp.reltroner.com`, `admin.erp.reltroner.com`) were verified reachable following configuration. Unrelated zone records were untouched.

---

## 7. Production Filesystem Hierarchy & Permissions

A structured filesystem hierarchy was initialized under `/opt/reltroner` to cleanly isolate runtime configurations, application releases, persistent logs, and local backups.

### Directory Hierarchy

```text
/opt/reltroner/
├── infra/
│   ├── nginx/
│   ├── keycloak/
│   ├── postgres/
│   ├── redis/
│   └── compose/
├── apps/
│   └── hrm/
│       ├── releases/
│       └── shared/
├── backups/
├── logs/
└── scripts/
```

> [!NOTE]
> The `/opt/reltroner/apps/hrm/current` symlink intentionally does not exist yet. It will be created by a later deployment phase as a release symlink.

### Ownership & Permissions Matrix

| Path | Owner | Group | Mode / Access Policy |
| :--- | :--- | :--- | :--- |
| `/opt/reltroner/apps/hrm` | `deploy` | `deploy` | Application root |
| `/opt/reltroner/apps/hrm/releases` | `deploy` | `deploy` | Release directory |
| `/opt/reltroner/apps/hrm/shared` | `deploy` | `deploy` | Shared persistent state |
| `/opt/reltroner/infra` | `root` | `root` | Infrastructure definitions |
| `/opt/reltroner/scripts` | `root` | `root` | Operational maintenance scripts |
| `/opt/reltroner/backups` | `root` | `root` | Mode `0700` (restricted admin access) |
| `/opt/reltroner/logs` | `root` | `adm` | Mode `0750` (restricted administrative log access) |

---

## 8. Operational Tooling & Log Rotation

Core utilities and maintenance configurations required for headless operations and deployment automation are present on the host:

### Operational Packages Installed

- `git`
- `curl`
- `unzip`
- `jq`
- `rsync`
- `ca-certificates`
- `logrotate`

### Log Rotation Policy

- Default Nginx package log rotation remains active.
- Dedicated Reltroner log rotation policy configured at `/etc/logrotate.d/reltroner` and validated successfully.

```text
/opt/reltroner/logs/*.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    su root adm
}
```

---

## 9. Explicitly Deferred Work

To maintain strict architectural boundaries, the following components are explicitly out of scope for Phase 2 and remain deferred to subsequent phases:

- **Laravel HRM Application:** Not deployed.
- **Database Engine:** PostgreSQL not installed on host or containers.
- **Cache / Queue Engine:** Redis not installed.
- **Identity Provider:** Keycloak not installed.
- **Container Engine:** Docker and Docker Compose not installed.
- **SSL / TLS Termination:** No TLS certificates installed; port 443 listener inactive.
- **Secrets Management:** No application environment secrets deployed to host.
- **Disaster Recovery Testing:** No database backup/restore lifecycle executed.
- **High Availability:** Architecture is intentionally a single-VPS production-capable foundation; no multi-node HA.

---

## 10. Phase 2 Exit Status

```text
PHASE 2 — VPS FOUNDATION
STATUS: COMPLETE / FROZEN
```

The underlying virtual private server, network security boundaries, user privileges, operational directory structure, and DNS records have met all baseline requirements. Infrastructure provisioning for Phase 2 is closed.
