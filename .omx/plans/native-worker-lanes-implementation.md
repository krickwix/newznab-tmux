# Implementation Plan

Schema fields: `goal`, `tasks`, `validation`, `rollback`.

## Goal

Implement the first reversible native worker-lane slice by exporting Laravel distributed-lane plans and validating them with a Go dry-run worker.

## Architecture

Laravel remains the control plane. `nntmux:worker --native-plan` exports a versioned plan derived from `DistributedJobCatalog`; Go consumes that plan and performs shadow/dry-run validation only. No native DB, Redis, search, or filesystem writes are introduced in this slice.

## Tasks

### Task 1: Native plan exporter

**ID:** `T1`

**Description:** Add `NativeWorkerPlanExporter` and unit coverage for versioned plan export.

**Files:**
- `app/Services/Distributed/NativeWorkerPlanExporter.php`
- `tests/Unit/Distributed/NativeWorkerPlanExporterTest.php`

**Acceptance criteria:**
- Versioned JSON-safe plan includes job, lock, commands, mode, and timestamp.
- Existing command argument shapes are preserved.
- Secrets and raw settings are not exported.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerPlanExporterTest.php
```

Expected: PASS.

**Depends on:** none.

### Task 2: Artisan native-plan option

**ID:** `T2`

**Description:** Add `--native-plan` to `nntmux:worker`.

**Files:**
- `app/Console/Commands/NntmuxDistributedWorker.php`
- `tests/Feature/Console/NativeWorkerPlanCommandTest.php`

**Acceptance criteria:**
- Command emits valid plan JSON for known jobs.
- Unknown jobs still fail.
- Export path does not acquire locks or execute worker commands.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Feature/Console/NativeWorkerPlanCommandTest.php
```

Expected: PASS.

**Depends on:** `T1`.

### Task 3: Go dry-run worker

**ID:** `T3`

**Description:** Add Go plan parser and dry-run CLI.

**Files:**
- `native/go.mod`
- `native/internal/worker/plan.go`
- `native/internal/worker/plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/scripts/generate-worker-plan-fixtures.php`
- `native/scripts/verify-php-go-contract.sh`
- `tests/Fixtures/native-worker/metadata-refresh-plan.json`
- `tests/Fixtures/native-worker/catalog/*.json`

**Acceptance criteria:**
- Valid shadow plan parses and dry-runs.
- Committed catalog fixtures include one PHP-generated plan per distributed lane.
- PHP-generated exporter JSON for every catalog lane matches committed fixtures and is accepted by the Go dry-run validator.
- Unsupported versions fail.
- Non-shadow mode fails.
- Unknown distributed lane names fail.
- Dry-run output avoids secrets.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
native/scripts/verify-php-go-contract.sh
```

Expected: PASS.

**Depends on:** `T1`.

### Task 4: Docker test harness and docs

**ID:** `T4`

**Description:** Add containerized verification commands and operator documentation.

**Files:**
- `docker-compose.native-test.yml`
- `docs/native-worker-lanes-test-plan.md`
- `docs/distributed-workers.md`

**Acceptance criteria:**
- Compose config renders and PHP dependencies install in the CLI test container.
- Docs explain `--native-plan` and Go dry-run.
- Commands are runnable from localhost Docker.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml config
```

Expected: PASS.

**Depends on:** `T1`, `T2`, `T3`.

### Task 5: Runtime shadow validation hook

**ID:** `T5`

**Description:** Add a disabled-by-default PHP worker hook that runs the native
Go dry-run validator after the PHP lane lock is acquired.

**Files:**
- `app/Services/Distributed/NativeWorkerShadowRunner.php`
- `app/Services/Distributed/NativeWorkerShadowResult.php`
- `app/Services/Distributed/DistributedJobWorker.php`
- `config/nntmux.php`
- `.env.example`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `tests/Unit/Distributed/NativeWorkerShadowRunnerTest.php`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`

**Acceptance criteria:**
- Runtime shadow validation is opt-in and disabled by default.
- PHP remains the lock owner and command executor.
- Native dry-run receives the exported plan through stdin with argv form.
- Native failures, timeouts, or binary configuration errors fail open.
- PHP command exit codes remain authoritative.
- The hook does not run when the lane lock is held elsewhere.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php tests/Unit/Distributed/DistributedJobWorkerSignalHandlerTest.php tests/Unit/Distributed/NativeWorkerShadowRunnerTest.php
```

Expected: PASS.

**Depends on:** `T1`, `T3`.

### Task 6: Metadata-refresh integration dry-run gate

**ID:** `T6`

**Description:** Add the first service-backed native replacement gate: a
read-only MariaDB planner for the `metadata-refresh` external metadata phase
and a Redis lock smoke that uses the exported physical Redis key for the
distributed worker lock name.

**Files:**
- `docker-compose.native-test.yml`
- `native/go.mod`
- `native/internal/metadata/refresh_plan.go`
- `native/internal/metadata/refresh_plan_test.go`
- `native/internal/lock/redis_lock.go`
- `native/internal/lock/redis_lock_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/native-worker-lanes-test-plan.md`
- `docs/distributed-workers.md`

**Acceptance criteria:**
- Compose provides explicit MariaDB/Redis integration services without changing
  the unit-only `go-test` and `php-test` behavior.
- The Go planner identifies `predb`, `predb_crcs`, and `release_files`
  candidates for metadata refresh without changing table contents.
- `nntmux-worker --mysql-dsn ... --dry-run` prints metadata-refresh MySQL
  candidate counts when run against a metadata-refresh plan.
- The Redis helper acquires/releases
  the Laravel-prefixed physical key for `nntmux:distributed-worker:metadata-refresh`
  with owner-token protection.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/metadata
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
```

Expected: PASS.

**Depends on:** `T3`.

### Task 7: Hashed fix-name mutation planner

**ID:** `T7`

**Description:** Add a read-only Go planner for the hashed fix-name passes in
the `metadata-refresh` lane: `releases:fix-names 20 --category=hashed` and
`releases:fix-names 16 --category=hashed`. The planner reports candidate
renames and status-only updates without mutating `releases`, firing
`ReleaseNameFixed`, or updating search indexes.

**Files:**
- `native/internal/namefix/hashed_plan.go`
- `native/internal/namefix/hashed_plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/native-worker-lanes-test-plan.md`
- `docs/distributed-workers.md`

**Acceptance criteria:**
- CRC planning covers PreDB CRC matches, existing renamed-release CRC matches,
  CRC priority, ±5 percent size tolerance, newest-first ordering, and
  status-only `proc_crc32` misses.
- PAR hash planning covers same-hash reference matches, ±5 percent size
  tolerance, newest-first ordering, and status-only `proc_hash16k` misses.
- Integration tests snapshot table contents before and after planning to prove
  the gate remains read-only.
- CLI dry-run appends hashed fix-name counts only when a native plan includes
  method 20/16 hashed commands.
- Native writes, categorization, events, and search updates remain out of scope.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
```

Expected: PASS.

**Depends on:** `T3`, `T6`.

## Validation

- `docker compose -f docker-compose.native-test.yml config`
- `docker compose -f docker-compose.native-test.yml run --rm php-test composer install --no-interaction --prefer-dist --ignore-platform-reqs --no-plugins`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...`
- `docker compose -f docker-compose.native-test.yml run --rm php-test php native/scripts/generate-worker-plan-fixtures.php tests/Fixtures/native-worker/catalog`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go run ./cmd/nntmux-worker --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json --dry-run`
- `native/scripts/verify-php-go-contract.sh`
- `docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerPlanExporterTest.php tests/Feature/Console/NativeWorkerPlanCommandTest.php tests/Unit/Distributed/DistributedJobCatalogTest.php tests/Unit/Distributed/DistributedJobWorkerTest.php tests/Unit/Distributed/DistributedJobWorkerSignalHandlerTest.php tests/Unit/Distributed/NativeWorkerShadowRunnerTest.php`
- `git diff --check`

## Rollback

- Remove the new exporter, command option, Go module, native directory, fixture, Compose file, and documentation changes.
- Existing worker execution remains the fallback because the feature is opt-in.

## Review gates

- Product: shadow-only native lane.
- Design: versioned plan contract.
- Engineering: no native writes.
- Security/trust: no secret export.
- Guardrail/approval: write mode deferred.
- QA: Docker commands provide evidence.
- Launch: not a production replacement until later smoke gates pass.
