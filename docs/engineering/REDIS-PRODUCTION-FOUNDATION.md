# Redis Production Foundation

## Status

**Phase 4 — COMPLETE / FROZEN**

The Redis production foundation for Reltroner HRM has been installed, hardened, authenticated, memory-bounded, persistence-tested, reboot-tested, externally verified, and integrated with Laravel through authenticated Redis integration CI.

This phase proves two distinct things:

1. the Redis production infrastructure is correctly configured on the VPS
2. the Laravel application contract for Redis-backed cache, session, queue, and queue failure visibility works in authenticated integration testing

The HRM Laravel application itself is not yet deployed to the production VPS in this phase. Production HRM deployment remains a later architecture phase.

## Superseding Runtime Upgrade Requirement — Redis 8.2 LTS

**Status: Phase 12.5 — IN PROGRESS / NOT YET DEPLOYED**

The Phase 4 Redis 7 production foundation below remains valid historical/frozen evidence. It is not rewritten retroactively.

A new runtime requirement introduced on 2026-10-01 upgrades the Redis server/runtime target from Redis 7 to the Redis 8.2 LTS/Extended release line, with exact initial target:

```text
Redis Open Source 8.2.10
```

This requirement is intentionally isolated from frozen application architecture.

The upgrade must preserve without semantic change:

- loopback-only binding on port 6379;
- no public Redis exposure;
- authentication requirement;
- protected mode;
- AOF persistence and `appendfsync everysec`;
- 256 MiB maxmemory unless a separate evidence-backed capacity change is approved;
- `noeviction`;
- logical Redis DB mapping 0/1/2/3 for default/cache/queue/session;
- Laravel `phpredis` client usage;
- cache/session/queue runtime contracts;
- database-backed failed jobs;
- PostgreSQL 18;
- Keycloak/OIDC identity behavior;
- Phase 11 production authentication/session-provenance invariants;
- Phase 12 authorization invariants.

Redis 7 -> Redis 8 is a major-version upgrade. Before production mutation, engineering must:

1. perform read-only discovery of the installed package source/version, service, configuration include chain, persistence paths, AOF/RDB health, memory settings, ACL/auth mode, listener state, and current Laravel Redis client/runtime;
2. review Redis 8.0/8.2 breaking changes, especially ACL category expansion;
3. prove the application against exact Redis 8.2.x in CI;
4. upgrade other dependencies only if compatibility evidence requires it;
5. create rollback and persistence-backup evidence;
6. perform controlled production cutover;
7. verify version, authentication, private binding, persistence, cache, session, queue, workers, and full application behavior after cutover.

The Phase 4 Redis 7 baseline remains the rollback reference until the Redis 8 production upgrade is separately evidence-frozen.

### Redis 8 Repository Compatibility Checkpoint

```text
branch:
infra/redis8-lts-upgrade-20261001

base:
449239c23109dbc56caf3de47b0b6e86b5f31a5e

candidate:
cc3d99ab44be9a47130f419a95a69dff7da95c3a

CI Redis image:
redis:8.2.10-alpine

Redis 8.2.10 + PostgreSQL 18 workflow:
PASS

PostgreSQL 18 compatibility workflow:
PASS

production Redis:
UNCHANGED / DISCOVERY REQUIRED
```

No Laravel, PHP, Composer dependency, PostgreSQL, Keycloak, authorization, schema, or application Redis configuration upgrade has been justified by CI evidence at this checkpoint.

A second exact-production-client CI checkpoint pinned `phpredis 6.3.0`, matching production:

```text
candidate:
80798a027f2c8aa5ba9d9ba57a996919e56c1ca2

Redis:
8.2.10

PHP:
8.4

phpredis:
6.3.0

PostgreSQL:
18

Redis integration workflow:
PASS

PostgreSQL compatibility workflow:
PASS
```

### Phase 12.5A Production Read-Only Discovery

Production discovery completed without service/package/configuration mutation.

```text
Redis server / CLI                 7.0.15
installed packages                 redis-server + redis-tools 5:7.0.15-1ubuntu0.24.04.4
package source                     Ubuntu Noble archive/security
explicit packages.redis.io source  absent
redis-server.service               active / enabled
listener                           127.0.0.1:6379 and ::1:6379 only
config entrypoint                  /etc/redis/redis.conf
production include                 /etc/redis/reltroner-production.conf
authentication                     requirepass
ACL principal                      default
custom aclfile/user directives     none
maxmemory                          256 MiB
maxmemory-policy                   noeviction
AOF                                enabled
appendfsync                        everysec
AOF health                         ok
keyspace                           DB3 = 3 expiring keys
Laravel default connection         PASS
Laravel cache connection           PASS
Laravel queue connection           PASS
Laravel session connection         PASS
production phpredis                6.3.0
active HRM release                 f86aee2371a27e69f33351662a8bb97df044f3c8
```

The Phase 4 Redis 7 configuration and runtime state remain unchanged. The next production step is backup/rollback preparation before adding the official Redis APT package source or installing Redis 8.

### Phase 12.5B Rollback / Backup Freeze

```text
PHASE_12_5B_ROLLBACK_BACKUP_PASS

backup root:
/var/backups/reltroner/redis8-upgrade-20260930T225042Z

manifest:
20 files / SHA-256 validation PASS

rollback archive:
/var/backups/reltroner/redis8-upgrade-20260930T225042Z.tar.gz

archive bytes:
1373850

archive SHA-256:
325b730983273cd2b96099896885cb4dee085b47307e199e7ab65db0d28fc280

RDB:
checksum PASS
3 keys read
3 expires
0 already expired

multipart AOF:
base RDB + incremental AOF + manifest preserved

rollback packages:
redis-server 5:7.0.15-1ubuntu0.24.04.4
redis-tools  5:7.0.15-1ubuntu0.24.04.4

post-freeze runtime:
Redis 7.0.15 active
authenticated PING PONG
default/cache/queue/session PASS
persistent configuration unchanged
AOF rewrite policy restored to 100
no Redis restart
no package install
no Redis APT source change
no application release change
```

Phase 12.5B is evidence-frozen. The next stage may add the official Redis APT source and perform exact Redis 8.2 package discovery/simulation, but must not install or restart Redis until that simulation is accepted.

### Phase 12.5C Official Repository + Exact-Pin Simulation

```text
PHASE_12_5C_APT_SIMULATION_PASS

official Redis source:
deb [signed-by=/usr/share/keyrings/redis-archive-keyring.gpg] https://packages.redis.io/deb noble main

signing key SHA-256:
6d0e38c45f40666f507fb21ccdb9a5c164efe98d551434c57cad9765ea1bd1c3

source file SHA-256:
bcdb03a9ea011fc2f1ab3129de0e8429a64c58b3f11011db70a8016e5e627d09

pin file SHA-256:
cdd32b49ba5e7f7f8c3b06d3a5e00192abd508c17c220258f09255557560d066

target package:
6:8.2.10-1rl1~noble1

pin priority:
1001

APT simulation:
redis-server 7.0.15 -> 8.2.10
redis-tools  7.0.15 -> 8.2.10

extra dependencies:
none

package removals:
none

runtime after simulation:
Redis 7.0.15
same Redis PID
AOF healthy
configuration unchanged
Laravel default/cache/queue/session PASS
application release unchanged
```

Phase 12.5C is evidence-frozen. The next stage is the first authorized production binary mutation and must install only the exact pinned Redis server/tools packages with immediate rollback capability.

### Phase 12.5D-1 Exact Redis 8.2.10 Package Preflight

```text
PHASE_12_5D1_PACKAGE_PREFLIGHT_PASS

target:
6:8.2.10-1rl1~noble1

redis-server DEB SHA-256:
4e31f0b18639933f4ef0a57634b8839fc27a9bc4781a35ca12224dfd3ca86b68

redis-tools DEB SHA-256:
96d0ad2d62c6f11635e4340ed6cb5fd63ea026c6eb9c4b6d828a5545f4c12a06

candidate service unit SHA-256:
7fcea37ab4a4dbc6c292d38ee3138353d6f996f8b2c71c89e0675effe14522aa

candidate evidence archive:
/var/backups/reltroner/redis8-candidate-20260930T231723Z.tar.gz

candidate archive SHA-256:
d9af267c8447bc2995ed668755dd26c346d6f1e58a3634c966ba73cc6e8f2500

candidate manifest:
39 files

Redis config packaging:
/etc/redis/redis.conf is a dpkg conffile
packaged Redis 8 config differs from Reltroner production config
cutover must preserve existing local config

candidate systemd semantics:
User=redis
Group=redis
ExecStart=/usr/bin/redis-server /etc/redis/redis.conf

isolated exact-package runtime:
Redis 8.2.10 PASS
unauthenticated PING -> NOAUTH
authenticated PING -> PONG
data roundtrip PASS
AOF enabled
clean shutdown PASS

production after preflight:
Redis 7.0.15
PID 289706
configuration unchanged
application release unchanged
Laravel default/cache/queue/session PASS
```

The exact Redis 8 production artifact is now preflighted. No production Redis binary or service mutation occurred during this stage.

### Phase 12.5D-2 First Production Cutover Attempt / Rollback

```text
cutover result:
FAIL / AUTOMATIC ROLLBACK EXECUTED

Redis 8 temporary package state:
redis-server 6:8.2.10-1rl1~noble1
redis-tools  6:8.2.10-1rl1~noble1

failure:
redis-server.service result=protocol

post-rollback:
redis-server 5:7.0.15-1ubuntu0.24.04.4
redis-tools  5:7.0.15-1ubuntu0.24.04.4
runtime 7.0.15
authenticated PING PONG
service active/enabled
queue active/enabled
application maintenance OFF
config byte restoration PASS
AOF/RDB health PASS
Laravel default/cache/queue/session PASS

APT safety:
redis-server held
redis-tools held
```

#### Root cause

The frozen Redis configuration contains:

```text
daemonize yes
pidfile /run/redis/redis-server.pid
```

The Redis 7 Ubuntu service unit compensated with:

```text
Type=notify
ExecStart=/usr/bin/redis-server /etc/redis/redis.conf --supervised systemd --daemonize no
```

The Redis 8.2.10 package service unit changed that to:

```text
Type=notify
ExecStart=/usr/bin/redis-server /etc/redis/redis.conf
```

Redis 8 logs prove configuration and Redis 7 persistence loading succeeded before systemd terminated the process. The service failed because the Type=notify contract no longer had the CLI override that enables Redis systemd supervision/readiness notification.

The remediation must preserve `redis.conf` and introduce a local systemd drop-in that restores the existing `--supervised systemd --daemonize no` invocation semantics. This drop-in is a compatibility seam for the Redis package transition, not a new application architecture.

### Phase 12.5D-2F Supervision Compatibility Validation

```text
PHASE_12_5D2F_SUPERVISION_COMPAT_PASS

drop-in:
/etc/systemd/system/redis-server.service.d/reltroner-supervision.conf

drop-in SHA-256:
359a6052635e45d97a978ad1197d65159e9eb5ccef797f502b99661135cd5f8a

effective Redis service command:
/usr/bin/redis-server /etc/redis/redis.conf --supervised systemd --daemonize no

exact Redis 8.2.10 transient Type=notify:
PASS

Redis 8 authenticated PING:
PONG

Redis 7 controlled restart through drop-in:
PASS

Redis 7 after compatibility validation:
7.0.15
AOF healthy
configuration unchanged
Laravel default/cache/queue/session PASS
queue active
application online
Redis packages held
```

The retry of the production Redis 8 cutover may now proceed with the drop-in treated as part of the rollback and production service baseline.

### Phase 12.5D-2 Retry #2 Successful Production Cutover

```text
PHASE_12_5D2_RETRY2_REDIS8_CUTOVER_PASS

redis-server:
6:8.2.10-1rl1~noble1

redis-tools:
6:8.2.10-1rl1~noble1

runtime:
8.2.10

PID:
305395

systemd:
Result=success

effective ExecStart:
/usr/bin/redis-server /etc/redis/redis.conf --supervised systemd --daemonize no

drop-in SHA-256:
359a6052635e45d97a978ad1197d65159e9eb5ccef797f502b99661135cd5f8a

production config:
byte-identical to frozen Redis 7 baseline

security/runtime:
unauthenticated PING -> NOAUTH
authenticated PING -> PONG
loopback-only listener
protected-mode yes
maxmemory 256 MiB
noeviction

persistence:
appendonly yes
appendfsync everysec
loading=0
RDB status ok
AOF rewrite/write status ok
Redis 7 -> Redis 8 cross-version data probes PASS

application:
Laravel default/cache/queue/session PASS
queue active
maintenance OFF
application release unchanged

APT:
redis-server held
redis-tools held
no unrelated package upgrade

fresh cutover backup:
/var/backups/reltroner/redis8-cutover-retry2-20260930T234422Z
```

The production runtime is now Redis 8.2.10. Phase 12.5E remains the final read-only acceptance/freeze step.







## Production Baseline

- Redis server: **7.0.15**
- Redis CLI: **7.0.15**
- Operating system: **Ubuntu 24.04 LTS**
- Service: `redis-server.service`
- Port: `6379`
- Listener:
  - `127.0.0.1:6379`
  - `[::1]:6379`
- Public Redis exposure: **disabled**
- Authentication: **required**
- Persistence: **AOF enabled**
- Memory ceiling: **256 MiB**
- Memory policy: `noeviction`

Redis is intentionally bound only to localhost.

There is no public firewall rule for Redis.

## Service State

Redis is managed by systemd.

Verified state:

```text
redis-server.service
active
enabled
```

The service was verified to return successfully after a full VPS reboot.

## Network Boundary

Redis listens only on loopback interfaces:

```text
127.0.0.1:6379
[::1]:6379
```

Redis does not listen on public wildcard addresses such as:

```text
0.0.0.0:6379
[::]:6379
```

External connectivity testing confirmed:

```text
6379/tcp public access: BLOCKED
```

PostgreSQL remained private during the same regression verification:

```text
5432/tcp public access: BLOCKED
```

Nginx remained reachable on the intended public HTTP gateway.

## Authentication Boundary

Redis requires authentication.

Unauthenticated verification:

```text
PING
-> NOAUTH Authentication required.
```

Authenticated verification:

```text
PING
-> PONG
```

The production Redis password is runtime infrastructure secret material.

It is not stored in Git, this document, CI configuration, or application source.

## Configuration Files

Primary Redis package configuration:

```text
/etc/redis/redis.conf
```

Pre-hardening backup:

```text
/etc/redis/redis.conf.phase4-prehardening
```

Reltroner production override:

```text
/etc/redis/reltroner-production.conf
```

Verified production override ownership and permissions:

```text
root:redis
0640
```

The production override contains sensitive Redis authentication material and must never be printed into logs, copied into Git, or published.

Persistent kernel configuration:

```text
/etc/sysctl.d/99-reltroner-redis.conf
```

## Effective Runtime Configuration

Verified effective Redis settings:

```text
bind               127.0.0.1 ::1
port               6379
protected-mode     yes
maxmemory          268435456
maxmemory-policy   noeviction
appendonly         yes
appendfsync        everysec
```

`268435456` bytes equals **256 MiB**.

The host kernel setting is:

```text
vm.overcommit_memory = 1
```

This value persisted across a full VPS reboot.

Transparent Huge Pages were observed in:

```text
always [madvise] never
```

The active mode was therefore `madvise`.

No additional THP change was required during Phase 4.

## Memory Policy

Redis has an explicit memory ceiling:

```text
256 MiB
```

The configured policy is:

```text
noeviction
```

This is intentional because the same Redis instance supports runtime state that includes:

- cache
- sessions
- queues

Silent eviction of queue or session state is therefore avoided.

If Redis reaches the configured memory ceiling, writes may fail rather than silently evict existing runtime state.

Memory utilization must be observed during later production operations.

## Persistence

Redis AOF persistence is enabled:

```text
appendonly yes
appendfsync everysec
```

Persistence health verification returned:

```text
loading:0
rdb_last_bgsave_status:ok
aof_enabled:1
aof_rewrite_in_progress:0
aof_last_write_status:ok
```

### Redis Service Restart Test

A temporary probe value was written to Redis.

After restarting `redis-server`, the probe value remained available.

Result:

```text
Redis service restart persistence: PASS
```

The probe was deleted after verification.

### Full VPS Reboot Test

A separate temporary probe was written before a full VPS reboot.

After the VPS returned, verification confirmed:

- Redis was active
- Redis remained enabled
- authentication remained enforced
- effective memory settings remained intact
- AOF remained enabled and healthy
- the probe value was recovered successfully

Result:

```text
Full VPS reboot persistence: PASS
```

The reboot probe was deleted after verification.

## PostgreSQL Coexistence

After the full VPS reboot, both PostgreSQL and Redis remained private:

```text
127.0.0.1:5432  PostgreSQL
[::1]:5432       PostgreSQL

127.0.0.1:6379  Redis
[::1]:6379       Redis
```

PostgreSQL remained active after the reboot.

No regression to the PostgreSQL production foundation was observed.

## Host Memory After Reboot

Observed host memory after the final reboot verification:

```text
RAM total:      3.8 GiB
RAM available:  3.4 GiB
Swap total:     2.0 GiB
Swap used:      0 B
```

This remained within the expected VPS baseline.

## Laravel Redis Contract

The Laravel application uses the native `phpredis` client.

No `predis/predis` dependency was introduced.

Logical Redis database layout:

```text
default  -> DB 0
cache    -> DB 1
queue    -> DB 2
session  -> DB 3
```

These logical databases provide operational separation only.

They are not independent security boundaries.

Laravel now defines explicit Redis connections for:

```text
default
cache
queue
session
```

## Production Environment Contract

The intended production application selection is:

```text
SESSION_DRIVER=redis
SESSION_CONNECTION=session

CACHE_STORE=redis
REDIS_CACHE_CONNECTION=cache

QUEUE_CONNECTION=redis
REDIS_QUEUE_CONNECTION=queue

QUEUE_FAILED_DRIVER=database-uuids

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2
REDIS_SESSION_DB=3
```

`REDIS_PASSWORD` is a runtime secret and is intentionally omitted from this document.

The repository `.env.example` documents the Redis connection contract while retaining safe database-backed defaults for ordinary local development.

Actual production environment activation belongs to the later HRM deployment phase.

## Cache Verification

Dedicated Redis integration testing verifies Laravel cache behavior through framework-supported APIs:

```text
put
get
forget
```

The cache connection uses Redis DB 1.

Result:

```text
Laravel Redis cache: PASS
```

## Session Verification

Dedicated integration testing configures:

```text
SESSION_DRIVER=redis
SESSION_CONNECTION=session
```

A session value is written during one HTTP request and recovered during a subsequent request carrying the Laravel session cookie.

The test does not depend on undocumented Redis session key internals.

The session connection uses Redis DB 3.

Result:

```text
Laravel Redis session: PASS
```

## Queue Verification

Dedicated integration testing configures Laravel queues to use Redis.

A test-only `ShouldQueue` job is dispatched to a uniquely named test queue and processed by a deterministic single-pass worker.

Successful execution is verified through an observable test marker.

The queue connection uses Redis DB 2.

Result:

```text
Laravel Redis queue: PASS
```

Queue test jobs exist only under the test namespace.

No production-only test job classes were added.

## Queue Failure Visibility

Laravel registers a `Queue::failing()` listener.

A failed queue event logs structured metadata:

```text
connection
queue
job
job_id
attempts
exception_class
exception_file
exception_line
```

The logger intentionally excludes:

```text
raw queue payload
serialized job body
exception message
environment variables
credentials
authorization headers
tokens
```

Failed job persistence remains configured as:

```text
database-uuids
```

Therefore failed jobs are persisted through the application's database-backed `failed_jobs` table rather than treated as authoritative Redis data.

Dedicated integration testing verified:

```text
failed_jobs persistence: PASS
structured queue failure logging: PASS
```

## Integration Test Isolation

Redis integration tests are explicitly gated by:

```text
RUN_REDIS_INTEGRATION=true
```

Normal local tests do not require Redis or the `phpredis` extension.

Local test baseline after Phase 4 integration:

```text
35 passed
101 assertions
5 Redis integration tests skipped
```

A forced local Redis-integration run on a Windows environment without `phpredis` failed intentionally instead of silently skipping.

This proves that enabling the Redis integration gate turns missing Redis prerequisites into a hard failure.

## Logical Database Separation Verification

The Redis integration suite verifies all four named Laravel Redis connections:

```text
default
cache
queue
session
```

A single unique probe key is written through all four connections with distinct values.

The test confirms that each connection returns only its own value:

```text
default -> db0
cache   -> db1
queue   -> db2
session -> db3
```

This directly verifies that the four Laravel connection names are mapped to distinct logical Redis databases.

Only test-owned keys are deleted during cleanup.

No `FLUSHDB` or `FLUSHALL` operation is used.

## Continuous Integration

Phase 4 Laravel Redis integration was introduced by:

```text
Commit:
e7dadc35943f764812babbb56c9e7cd41b553933

Message:
feat: add Redis production integration
```

### PostgreSQL Compatibility Workflow

GitHub Actions result:

```text
Workflow:    PostgreSQL Compatibility
Run:         #8
Run ID:      35570692310
Conclusion:  success
```

The existing PostgreSQL compatibility workflow remained intact.

Its job completed successfully, including:

- PostgreSQL 18 startup
- migration verification
- PostgreSQL connection/version verification
- portability regression test
- full PHPUnit suite
- rollback verification
- rebuild after rollback

### Redis Infrastructure Integration Workflow

GitHub Actions result:

```text
Workflow:    Redis Infrastructure Integration
Run:         #1
Run ID:      35570692311
Conclusion:  success
```

The integration environment included:

```text
PHP 8.4
phpredis
PostgreSQL 18
authenticated Redis 7
```

The workflow explicitly verified:

```text
unauthenticated Redis PING -> NOAUTH
authenticated Redis PING   -> PONG
```

The targeted Redis integration suite passed.

The workflow uses:

```text
--fail-on-skipped
```

for the targeted Redis suite so an incorrectly disabled integration gate cannot silently produce a successful Redis validation.

The full Redis-enabled suite completed with:

```text
40 passed
142 assertions
0 skipped
```

## Full VPS Reboot Verification

A full VPS reboot was performed after the production Redis configuration was completed.

After reboot:

```text
Redis service:                 active
Redis boot enablement:         enabled
PostgreSQL service:            active
vm.overcommit_memory:          1
Redis authentication:          enforced
authenticated Redis PING:      PASS
AOF probe recovery:            PASS
Redis AOF health:              PASS
Redis localhost binding:       PASS
PostgreSQL localhost binding:  PASS
```

External verification after the reboot confirmed:

```text
6379/tcp Redis:       CLOSED
5432/tcp PostgreSQL:  CLOSED
80/tcp Nginx:         OPEN
```

## Security Decisions

The following controls are intentional:

- Redis is not publicly exposed.
- Redis requires authentication.
- Redis is protected by loopback-only binding.
- The production Redis password is not stored in Git.
- The production Redis password is not stored in this document.
- Redis memory usage is explicitly bounded.
- Redis uses `noeviction` to avoid silent queue/session loss.
- AOF persistence is enabled.
- Queue payloads are not logged by the failure listener.
- Exception messages are not logged by the failure listener.
- Failed jobs remain database-backed.
- Predis was not introduced.
- Normal local tests remain independent of Redis.

## Known Operational Limitations

Redis currently uses one server instance with logical database separation.

The logical Redis databases are not separate security boundaries.

The production HRM Laravel runtime is not yet deployed to the VPS during Phase 4, so actual browser traffic against `hrm.reltroner.com` has not yet exercised this Redis instance.

Production queue worker services, scheduler services, Laravel Horizon, PHP-FPM integration, HRM Nginx configuration, and HRM HTTPS activation belong to later deployment phases.

Redis operational monitoring should be expanded during later production hardening, including:

- memory pressure
- write failures
- AOF health
- connection counts
- queue depth
- worker failures

These limitations do not change the verified Redis infrastructure, Laravel integration contract, persistence behavior, or public-exposure controls established during Phase 4.

## Phase 4 Exit Criteria

| Requirement | Result |
| --- | --- |
| Redis installed | PASS |
| Redis bound privately | PASS |
| Redis authentication required | PASS |
| Public Redis exposure blocked | PASS |
| Memory ceiling configured | PASS |
| `noeviction` policy configured | PASS |
| AOF persistence enabled | PASS |
| Redis survives service restart | PASS |
| Redis survives full VPS reboot | PASS |
| Kernel Redis memory setting persists | PASS |
| Laravel Redis connection contract | PASS |
| Logical Redis DB separation | PASS |
| Laravel cache integration | PASS |
| Laravel session integration | PASS |
| Laravel queue integration | PASS |
| Queue failure visibility | PASS |
| Failed jobs persisted to database | PASS |
| Authenticated Redis CI | PASS |
| Full Redis-enabled test suite | PASS |
| PostgreSQL regression verification | PASS |

## Freeze Rule

Phase 4 is frozen.

Changes to Redis networking, authentication, memory limits, eviction policy, persistence settings, logical database assignments, Laravel Redis connection mapping, queue failure logging, or Redis integration CI must be treated as production infrastructure or runtime-contract changes and require explicit verification before deployment.

Future application deployment phases must not weaken the Redis private-network boundary or move authoritative HR business state into Redis.

## Final Phase Result

```text
PHASE 4 — REDIS PRODUCTION FOUNDATION
COMPLETE / FROZEN
```
