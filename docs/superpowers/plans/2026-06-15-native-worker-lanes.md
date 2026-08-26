# Native Worker Lanes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first reversible native worker-lane implementation slice: Laravel exports an explicit distributed-lane plan, and a Go worker validates and dry-runs that plan without mutating DB, Redis, search, or files.

**Architecture:** Laravel remains the control plane and owns settings, lane selection, locks, and domain side effects. The native side starts as a shadow/dry-run Go binary that consumes a versioned JSON worker plan from Laravel, validates supported lanes, and reports the commands it would execute. Write-mode native lane execution is out of scope for this first slice.

**Tech Stack:** Laravel 13/PHP 8.4, PHPUnit 12, Go, Docker Compose, MariaDB/Redis/Manticore integration smoke.

---

## Facts

- Branch/worktree: `feat/native-worker-lanes` at `/home/fandrieu/.config/superpowers/worktrees/newznab-tmux/feat-native-worker-lanes`.
- Base branch: `microservices-pods`, commit `18c0d1651602c6a730ec7b2f202f9d90d6f46dd2`.
- Host does not have `php`, `composer`, or `go`; all test execution must use Docker containers.
- Existing distributed lanes are resolved by `App\Services\Distributed\DistributedJobCatalog`.
- Existing lane execution is handled by `App\Services\Distributed\DistributedJobWorker`.
- Prior feasibility report recommends Go as the primary language and a shadow worker before write mode.

## Assumptions

- The first implementation slice should prove the control-plane contract, not replace a DB-writing lane yet.
- Native write-mode must wait until plan JSON, fixture parity, lock semantics, and Docker smoke are proven.
- `metadata-refresh` is the safest first planned lane because it exercises settings and command sequencing without owning the header/release lifecycle.

## Open Questions

- Whether the production deployment will package the Go binary in the PHP image or as a separate sidecar image.
- Whether native write-mode should call existing Artisan commands, write directly to DB, or own only future narrow accelerators.

## Task 1: Versioned Laravel Native Plan Exporter

**ID:** `T1`

**Files:**
- Create: `app/Services/Distributed/NativeWorkerPlanExporter.php`
- Test: `tests/Unit/Distributed/NativeWorkerPlanExporterTest.php`

**Description:** Convert an existing resolved `DistributedJobCatalog` plan into versioned JSON-safe data for a native worker.

**Acceptance criteria:**
- Export includes `version`, `generated_at`, `mode`, `job`, `lock`, and `commands`.
- Lock name exactly matches existing worker lock format: `nntmux:distributed-worker:{job}`.
- Lock metadata also includes the physical Redis key built from
  `database.redis.options.prefix`, `cache.prefix`, and the logical lock name.
- Export preserves array-valued Artisan options such as `--source: ["all"]`.
- Export does not include raw settings, secrets, DB credentials, Redis credentials, or NNTP credentials.

**Steps:**
- [x] Write `NativeWorkerPlanExporterTest::test_it_exports_metadata_refresh_plan_for_native_shadow_worker`.
- [x] Verify the test fails because `NativeWorkerPlanExporter` does not exist.
- [x] Implement `NativeWorkerPlanExporter::export(array $plan, int $lockSeconds, string $mode = 'shadow'): array`.
- [x] Run the targeted PHPUnit test in Docker.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerPlanExporterTest.php
```

Expected: one focused PHPUnit test passes.

**Depends on:** none.

## Task 2: Artisan Plan JSON Option

**ID:** `T2`

**Files:**
- Modify: `app/Console/Commands/NntmuxDistributedWorker.php`
- Test: `tests/Feature/Console/NativeWorkerPlanCommandTest.php`

**Description:** Add `--native-plan` to `nntmux:worker` so operators and native sidecars can request a resolved plan without running the lane.

**Acceptance criteria:**
- `php artisan nntmux:worker metadata-refresh --native-plan --lock-seconds=42` prints valid JSON and exits 0.
- Unknown jobs still fail before exporting.
- `--native-plan` does not acquire Redis locks and does not call `DistributedJobWorker::run`.
- JSON includes the configured lock TTL, the existing distributed lock name, and
  the physical Redis key native replacement code must use for Redis interop.

**Steps:**
- [x] Write a feature test that binds a mock `TmuxMonitorService`, invokes `nntmux:worker metadata-refresh --native-plan --lock-seconds=42`, decodes output JSON, and asserts the lock fields.
- [x] Verify the test fails because `--native-plan` is not defined.
- [x] Update the command signature and branch before worker execution to collect statistics, resolve the plan, export it, and write pretty JSON.
- [x] Run the targeted command feature test in Docker.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Feature/Console/NativeWorkerPlanCommandTest.php
```

Expected: feature test passes and no worker lock is touched.

**Depends on:** `T1`.

## Task 3: Go Native Worker Dry-Run

**ID:** `T3`

**Files:**
- Create: `native/go.mod`
- Create: `native/internal/worker/plan.go`
- Create: `native/internal/worker/plan_test.go`
- Create: `native/cmd/nntmux-worker/main.go`
- Create: `native/cmd/nntmux-worker/main_test.go`
- Create: `native/scripts/generate-worker-plan-fixtures.php`
- Create: `native/scripts/verify-php-go-contract.sh`
- Create: `tests/Fixtures/native-worker/metadata-refresh-plan.json`
- Create: `tests/Fixtures/native-worker/catalog/*.json`

**Description:** Add a Go binary that consumes Laravel native plan JSON, validates schema support, and dry-runs the plan.

**Acceptance criteria:**
- `go test ./...` passes.
- `go run ./cmd/nntmux-worker --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json --dry-run` exits 0 from the `native/` module.
- Committed catalog fixtures include one PHP-generated plan per distributed lane.
- PHP-generated exporter JSON for every catalog lane matches committed fixtures and is accepted by the Go dry-run validator.
- Invalid plan versions fail with a clear error.
- Non-shadow mode fails in this first slice.
- Unknown distributed lane names fail in this first slice.
- Dry-run output lists the job name, lock name, and command count without printing secrets.

**Steps:**
- [x] Write Go tests for valid metadata-refresh plan parsing, all catalog lane names, all committed catalog fixtures, unsupported version, non-shadow mode, empty PHP argument arrays, and dry-run summary.
- [x] Verify `go test ./...` fails because the Go package does not exist.
- [x] Add the minimal Go module, plan types, validation, and CLI.
- [x] Add generated catalog fixture workflow.
- [x] Add and run the PHP-to-Go contract check in Docker.
- [x] Run Go tests in Docker.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm php-test php native/scripts/generate-worker-plan-fixtures.php tests/Fixtures/native-worker/catalog
docker compose -f docker-compose.native-test.yml run --rm go-test go run ./cmd/nntmux-worker --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json --dry-run
native/scripts/verify-php-go-contract.sh
```

Expected: Go tests pass; dry-run prints a metadata-refresh summary and the PHP-to-Go contract proves generated catalog plans match committed fixtures before validating every lane.

**Depends on:** `T1`.

## Task 4: Docker Compose Test Harness

**ID:** `T4`

**Files:**
- Create: `docker-compose.native-test.yml`
- Create: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Provide repeatable localhost Docker commands for PHP and Go verification.

**Acceptance criteria:**
- Compose file defines `php-test` and `go-test` services.
- PHP service can install dependencies and run focused Laravel tests from a PHP 8.5 CLI Composer image.
- Go service uses an official Go image and cache volumes.
- Docs explain first-slice scope, commands, expected output, and known limitations.

**Steps:**
- [x] Add `docker-compose.native-test.yml`.
- [x] Add `docs/native-worker-lanes-test-plan.md`.
- [x] Update `docs/distributed-workers.md` with the `--native-plan` and Go dry-run workflow.
- [x] Run Docker Compose config validation.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml config
```

Expected: Compose config renders successfully.

**Depends on:** `T1`, `T2`, `T3`.

## Task 5: Opt-In Runtime Shadow Validation

**ID:** `T5`

**Files:**
- Create: `app/Services/Distributed/NativeWorkerShadowRunner.php`
- Create: `app/Services/Distributed/NativeWorkerShadowResult.php`
- Modify: `app/Services/Distributed/DistributedJobWorker.php`
- Modify: `config/nntmux.php`
- Modify: `.env.example`
- Test: `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- Test: `tests/Unit/Distributed/NativeWorkerShadowRunnerTest.php`
- Test: `native/cmd/nntmux-worker/main_test.go`

**Description:** Add a disabled-by-default PHP worker hook that runs the native
Go validator in shadow mode after the PHP lane lock is acquired and before PHP
Artisan commands execute.

**Acceptance criteria:**
- Runtime shadow validation is disabled by default.
- When enabled, the PHP worker exports the locked plan and invokes the native
  binary with argv plus stdin: `[binary, --plan, -, --dry-run]`.
- Native validation never owns Redis locks, DB writes, search writes, or PHP
  command execution.
- Native failures, missing/unexecutable binaries, and timeouts fail open and
  preserve the PHP command exit code.
- Shadow validation does not run when another PHP worker holds the lane lock.
- Native output logged by PHP is bounded and does not include command arguments
  or full plan JSON.

**Steps:**
- [x] Add Go CLI stdin tests and support `--plan -`.
- [x] Add PHP worker tests for default-off, enabled validation, lock-held skip,
  and fail-open exit-code precedence.
- [x] Add PHP runner tests for absolute executable path validation, argv/stdin
  invocation, and bounded stderr.
- [x] Implement the runner, result object, worker hook, and config defaults.
- [x] Update docs and `.env.example`.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php tests/Unit/Distributed/DistributedJobWorkerSignalHandlerTest.php tests/Unit/Distributed/NativeWorkerShadowRunnerTest.php
```

Expected: Go CLI tests pass; PHP tests prove opt-in fail-open shadow validation
without changing PHP worker ownership.

**Depends on:** `T1`, `T3`.

## Task 6: Metadata-Refresh Integration Dry-Run Gate

**ID:** `T6`

**Files:**
- Modify: `docker-compose.native-test.yml`
- Modify: `native/go.mod`
- Create: `native/internal/metadata/refresh_plan.go`
- Test: `native/internal/metadata/refresh_plan_test.go`
- Create: `native/internal/lock/redis_lock.go`
- Test: `native/internal/lock/redis_lock_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add the first real service-backed native replacement gate:
the Go worker can build a read-only `metadata-refresh` dry-run plan from
MariaDB tables and prove Redis lock acquire/release behavior in Docker Compose.

**Acceptance criteria:**
- Compose adds isolated MariaDB and Redis services with no host port bindings.
- Normal `go-test` and `php-test` services stay unit/contract-only and do not
  depend on MariaDB or Redis.
- Native metadata planner reads `predb`, `predb_crcs`, and `release_files` to
  identify SRRDB title candidates, archive CRC candidates, and filename-derived
  search queries without changing table contents.
- `nntmux-worker --mysql-dsn ... --dry-run` appends a metadata-refresh MySQL
  dry-run summary for `metadata-refresh` plans.
- Native Redis lock helper uses the exported physical Redis key for the existing
  `nntmux:distributed-worker:{job}` logical lock and releases only its own owner
  token.

**Steps:**
- [x] Add failing Go tests for metadata filename normalization and MariaDB
  candidate selection.
- [x] Add MariaDB/Redis-backed Compose integration services.
- [x] Implement read-only metadata-refresh planner and CLI summary.
- [x] Add Redis lock acquire/release integration test with non-owner release
  rejection and owner/TTL validation.
- [x] Update docs and plan artifacts.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/metadata
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
```

Expected: unit tests pass with the integration tests skipped when no DSN is
set; the integration service starts MariaDB/Redis and passes the native planner
plus lock tests.

**Depends on:** `T3`.

## Task 7: Hashed Fix-Name Mutation Planner

**ID:** `T7`

**Files:**
- Create: `native/internal/namefix/hashed_plan.go`
- Test: `native/internal/namefix/hashed_plan_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add the next service-backed replacement gate: a read-only Go
planner for the `metadata-refresh` lane's hashed fix-name passes
(`releases:fix-names 20 --category=hashed` and
`releases:fix-names 16 --category=hashed`). The planner reports the release
renames and status-only updates the PHP path would attempt, but it does not
write `releases`, fire `ReleaseNameFixed`, or update the search index.

**Acceptance criteria:**
- Native planner reads minimal MariaDB `releases`, `release_files`, `predb`,
  `predb_crcs`, and `par_hashes` data for hashed releases.
- CRC planning covers PreDB CRC matches, existing-renamed-release CRC matches,
  CRC priority ordering, ±5 percent size tolerance, deterministic newest-first
  ordering, and status-only `proc_crc32` updates for misses.
- PAR hash planning covers same-hash reference release matches, ±5 percent size
  tolerance, deterministic newest-first ordering, and status-only
  `proc_hash16k` updates for misses.
- The integration test snapshots table contents before and after the planner to
  prove the gate remains read-only.
- `nntmux-worker --mysql-dsn ... --dry-run` appends hashed fix-name candidate
  counts when a plan contains the method 20/16 hashed fix-name commands.
- Native write mode, categorization, `ReleaseNameFixed` events, and
  `Search::updateRelease()` remain out of scope for this task.

**Steps:**
- [x] Write failing Go integration tests for CRC and PAR hash mutation planning.
- [x] Verify tests fail because `native/internal/namefix` does not exist and the
  CLI does not print hashed fix-name counts.
- [x] Implement the read-only name-fix planner and dry-run summary.
- [x] Wire the planner into `nntmux-worker --mysql-dsn ... --dry-run` only when
  hashed fix-name commands are present in the native plan.
- [x] Update docs and plan artifacts.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/namefix
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
```

Expected: unit tests skip without `NNTMUX_NATIVE_MYSQL_DSN`; integration tests
start MariaDB/Redis and pass with read-only hashed fix-name planner evidence.

**Depends on:** `T3`, `T6`.

## Task 8: Hashed Fix-Name Write Contract Planner

**ID:** `T8`

**Files:**
- Modify: `native/internal/namefix/hashed_plan.go`
- Test: `native/internal/namefix/hashed_plan_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Description:** Add a read-only contract layer above the hashed fix-name
planner that enumerates the database update operations and required PHP-side
effects native write mode must eventually reproduce. This task still does not
commit DB writes, fire Laravel events, recategorize releases, or update search.

**Acceptance criteria:**
- Contract planning starts from `BuildHashedFixDryRunPlan` output and reads only
  the current `releases` rows needed to describe PHP write-mode side effects.
- Rename mutations expose the same primary release columns
  `ReleaseUpdateService::performDatabaseUpdate()` would update for
  `--set-status=true`, including status flags for `CRC32, ` and
  `PAR2 hash, ` matches.
- Category assignment is represented as an unresolved
  `CategorizationService.determineCategory(groups_id, new_title, fromname)`
  dependency instead of guessing a category in Go.
- `ReleaseNameFixed` event payload requirements and
  `Search::updateRelease()` calls are counted explicitly.
- CRC PreDB matches include the PHP path's follow-up `proc_crc32` single-column
  update and second search update.
- Misses expose single-column `proc_crc32` or `proc_hash16k` updates plus their
  required search updates.
- MariaDB table fingerprints before and after planning remain identical.
- `nntmux-worker --mysql-dsn ... --dry-run` appends write-contract counts for
  hashed fix-name commands.

**Steps:**
- [x] Write failing Go tests for the write-contract builder, including
  mutation columns, unresolved categorization, event payload, duplicate CRC
  PreDB status/search side effects, miss status updates, and read-only table
  fingerprints.
- [x] Verify the tests fail because the write-contract API and CLI summary do
  not exist.
- [x] Implement the read-only write-contract structs, builder, and summary.
- [x] Wire the CLI to print write-contract counts after hashed dry-run counts.
- [x] Run Docker Compose unit and integration verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/namefix
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
```

Expected: unit tests skip without `NNTMUX_NATIVE_MYSQL_DSN`; integration tests
start MariaDB/Redis and pass while proving the write-contract planner is
read-only.

**Depends on:** `T7`.

## Task 9: Structured Native Dry-Run Report

**ID:** `T9`

**Files:**
- Modify: `native/internal/namefix/hashed_plan.go`
- Modify: `native/internal/metadata/refresh_plan.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_test.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add a machine-readable JSON report mode for the native worker
dry-run. Text output remains the default operator view; JSON output becomes the
durable artifact for contract comparison and future write-mode gates.

**Acceptance criteria:**
- `nntmux-worker --dry-run --output=text` preserves the existing text output.
- `nntmux-worker --dry-run --output=json` emits valid JSON and no text summary
  lines.
- The JSON report includes the native job/lock/command summary without command
  arguments or secrets.
- With `--mysql-dsn`, the JSON report includes metadata-refresh candidate
  counts, hashed fix-name candidate counts, and the full hashed write-contract
  details.
- Hashed write-contract JSON includes release update columns, category
  `value_source`, required events, single-column status updates, search update
  requirements, method-order dedupe effects, and `writes: 0`.
- Invalid output formats fail before running lane planners.
- The report path remains read-only and keeps the MariaDB table fingerprint
  unchanged in integration tests.

**Steps:**
- [x] Write failing CLI tests for JSON output without MySQL and with
  MariaDB-backed hashed fix-name/write-contract details.
- [x] Verify the tests fail because `--output` and report JSON do not exist.
- [x] Implement stable JSON report structs and CLI format selection.
- [x] Add JSON tags for exported report contract types.
- [x] Update docs and run Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker ./internal/namefix ./internal/metadata
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
native/scripts/verify-php-go-contract.sh
```

Expected: text mode remains compatible with existing fixture contract checks;
JSON mode exposes the durable dry-run/write-contract report while all MariaDB
integration fingerprints remain unchanged.

**Depends on:** `T8`.

## Task 10: Rollback-Only Hashed Write Rehearsal

**ID:** `T10`

**Files:**
- Create: `native/internal/safety/mysql.go`
- Create: `native/internal/namefix/write_rehearsal.go`
- Test: `native/internal/namefix/hashed_plan_test.go`
- Modify: `native/internal/testdb/mysql_safety.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add an explicit rollback-only write rehearsal for hashed
fix-name contracts. The rehearsal executes only concrete single-column release
updates inside a transaction, rolls the transaction back, and reports release
updates that remain blocked by unresolved category/search/event side effects.
It is not committed write mode.

**Acceptance criteria:**
- Rehearsal is disabled by default and requires an explicit
  `--rehearse-writes` flag.
- Rehearsal requires `--mysql-dsn` and the safe native test DB guard
  `NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1` plus an allowlisted database
  name.
- Rehearsal executes concrete `single_column_updates` from the hashed
  write-contract inside one transaction and always rolls back.
- Release updates with unresolved `value_source` fields are not executed; they
  are counted and reported as blocked.
- The result reports attempted rows, affected rows, blocked release updates,
  rollback status, and committed writes as zero.
- Text and JSON dry-run reports include the rehearsal result only when
  requested.
- MariaDB fingerprints before and after rehearsal remain identical.
- No native code executes `ReleaseNameFixed`, categorization, search updates,
  or committed release mutations.

**Steps:**
- [x] Write failing Go integration tests for the namefix rehearsal executor and
  CLI `--rehearse-writes` text/JSON output.
- [x] Verify the tests fail because rehearsal APIs and flags do not exist.
- [x] Extract reusable safe-test-DB validation from `internal/testdb` into
  `internal/safety`.
- [x] Implement rollback-only rehearsal for concrete single-column updates.
- [x] Wire CLI text/JSON reports behind `--rehearse-writes`.
- [x] Update docs and run Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
native/scripts/verify-php-go-contract.sh
```

Expected: rehearsal tests prove real SQL updates are attempted only inside a
rolled-back transaction against the safe Compose MariaDB schema, and the default
dry-run path remains read-only and text-compatible.

**Depends on:** `T9`.

## Task 11: PHP Native Write-Contract Side-Effect Oracle

**ID:** `T11`

**Files:**
- Create: `app/Services/NameFixing/NativeHashedFixNameWriteContractResolver.php`
- Create: `app/Console/Commands/ResolveNativeWriteContract.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Unit/Distributed/NativeWriteContractResolverTest.php`
- Test: `tests/Feature/Console/NativeWriteContractResolveCommandTest.php`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add a PHP-owned read-only oracle for native hashed fix-name
write contracts. The oracle consumes the Go dry-run JSON report, resolves the
PHP categorization value for release updates, and reports required event/search
side-effect intent without dispatching events, updating search, or writing
releases.

**Acceptance criteria:**
- The resolver accepts either a full native JSON dry-run report or a raw
  `write_contract` object.
- The resolver requires a read-only contract with `writes: 0`.
- Release update category columns are resolved only when their `value_source`
  is `CategorizationService.determineCategory(groups_id, new_title, fromname)`.
- Output includes resolved category IDs, planned `ReleaseNameFixed` intent,
  search update intent, single-column update intent, blocked release updates,
  and `writes: 0`.
- Output does not include DSNs, Redis physical keys, command arguments, or
  poster/fromname values.
- The command `nntmux:native-write-contract:resolve --input=...` emits the
  resolver JSON and performs no DB writes, event dispatch, or search updates.

**Steps:**
- [x] Write failing PHP unit/feature tests for the resolver and command.
- [x] Verify the tests fail because the resolver class and command do not
  exist.
- [x] Implement the read-only resolver and console command.
- [x] Keep poster/fromname as categorization input only, not oracle output.
- [x] Update docs and run Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWriteContractResolverTest.php tests/Feature/Console/NativeWriteContractResolveCommandTest.php
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerPlanExporterTest.php tests/Feature/Console/NativeWorkerPlanCommandTest.php tests/Unit/Distributed/DistributedJobCatalogTest.php tests/Unit/Distributed/DistributedJobWorkerTest.php tests/Unit/Distributed/DistributedJobWorkerSignalHandlerTest.php tests/Unit/Distributed/NativeWorkerShadowRunnerTest.php tests/Unit/Distributed/NativeWriteContractResolverTest.php tests/Feature/Console/NativeWriteContractResolveCommandTest.php
```

Expected: the PHP oracle resolves categorization side-effect values from the
native report while the Go write path remains rollback-only and blocked for
release renames.

**Depends on:** `T10`.

## Task 12: Resolved Release-Update Write Rehearsal

**ID:** `T12`

**Files:**
- Modify: `native/internal/namefix/write_rehearsal.go`
- Test: `native/internal/namefix/hashed_plan_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Let the native rollback-only write rehearsal optionally
consume PHP oracle output from `nntmux:native-write-contract:resolve`. When a
resolved oracle is supplied, Go validates the oracle is read-only and then
executes the resolved release-update SQL plus existing single-column status
updates inside one safe-test-DB transaction that is always rolled back.

**Acceptance criteria:**
- Default `--rehearse-writes` behavior is unchanged: unresolved release updates
  remain blocked.
- A new explicit CLI option provides the PHP oracle JSON path and is accepted
  only with `--rehearse-writes`.
- The oracle JSON must have `schema_version: 1`, `dry_run: true`, top-level
  `writes: 0`, and `write_contract.writes: 0`.
- Resolved release updates are matched by `release_id`; missing or invalid
  resolved rows remain blocked and are reported.
- Only allowlisted release columns are executed. Unknown columns, unsafe column
  names, unsafe values, or mismatched release IDs fail the rehearsal and roll
  back.
- Release-update SQL and concrete single-column updates run in one transaction,
  roll back, and leave MariaDB fingerprints unchanged.
- `ReleaseNameFixed`, search updates, and committed writes remain out of scope
  and are never executed by native code.

**Steps:**
- [x] Write failing Go unit/integration tests for resolved oracle parsing and
  release-update rehearsal.
- [x] Verify tests fail because no resolved-oracle option/API exists.
- [x] Implement oracle validation and resolved release-update SQL rehearsal.
- [x] Wire the CLI option and JSON/text report fields.
- [x] Update docs and run Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker -run 'Resolved|Rehearse' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
native/scripts/verify-php-go-contract.sh
```

Expected: resolved release updates can be SQL-rehearsed only in the safe
Compose MariaDB schema and only under rollback. The default native dry-run and
unresolved rehearsal paths remain unchanged.

**Depends on:** `T11`.

## Task 13: Live Manticore Search Side-Effect Smoke

**ID:** `T13`

**Files:**
- Modify: `docker-compose.native-test.yml`
- Create: `native/docker/php-search-test.Dockerfile`
- Create: `native/docker/manticore-smoke.Dockerfile`
- Test: `tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add an opt-in Docker Compose smoke that proves the PHP-owned
search side effect native write mode will need: after a DB-side release
mutation, `ReleaseSearchIndexSync::forIds()` rehydrates the release from
MariaDB and replaces the `releases_rt` Manticore document.

**Acceptance criteria:**
- Normal PHP and Go unit/contract services do not depend on Manticore.
- The Manticore smoke is profile-gated and publishes no host ports.
- The PHP smoke runner has `pdo_mysql` and uses the isolated Compose MariaDB
  schema because the Manticore rehydrate query uses MySQL `GROUP_CONCAT`
  syntax.
- The smoke refuses to reset database/search state unless
  `NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1`, the DB name is allowlisted as a
  native test schema, and Manticore points at the Compose-only service.
- The Manticore smoke image copies `config/manticore.conf` into the container
  instead of bind-mounting it into the entrypoint path.
- The smoke creates minimal MariaDB tables, recreates Manticore indexes in a
  throwaway Manticore container, seeds a release, syncs it, mutates release
  search/category/file data through the query builder, syncs it again, and
  verifies the indexed document changed.
- Native code still does not execute committed DB writes, events, or search
  updates.

**Steps:**
- [x] Add a failing red checkpoint for the missing `php-search-test` compose
  service.
- [x] Add the profile-gated Manticore and PHP search-test services.
- [x] Add the feature smoke test.
- [x] Debug Manticore startup from container logs: avoid host config ownership
  mutation, set the `data_dir` volume target, and use HTTP `wget` healthcheck.
- [x] Run the live Manticore smoke in Docker Compose.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm php-search-test ./vendor/bin/phpunit tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php
```

Expected: the feature smoke passes against Compose MariaDB and Manticore, with
no host Manticore ports published.

**Depends on:** `T12`.

## Task 14: Resolved Write-Contract Pipeline Verifier

**ID:** `T14`

**Files:**
- Create: `native/internal/testdb/hashed_fixture.go`
- Create: `native/cmd/nntmux-test-fixture/main.go`
- Create: `native/scripts/prepare-write-contract-resolver-db.php`
- Create: `native/scripts/verify-resolved-write-contract.sh`
- Modify: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`

**Description:** Add a repeatable Docker verifier for the manual resolved
write-contract handoff: seed the deterministic hashed-fix Compose fixture,
emit Go dry-run JSON, resolve PHP-owned categorization/event/search intent,
then feed the PHP oracle back into Go's rollback-only resolved rehearsal.

**Acceptance criteria:**
- The hashed-fix integration fixture lives in one reusable Go testdb helper,
  not duplicated in shell.
- The fixture seeder refuses unsafe MariaDB targets through the existing
  `NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1` and native-test DB guard.
- PHP resolver execution uses a file-backed SQLite fixture containing the
  `settings` and `usenet_groups` tables needed by real `CategorizationService`.
- The script cleans up generated reports and SQLite files on exit.
- The script fails unless the PHP oracle resolves both release updates with no
  blocked release updates.
- The final Go rehearsal must report two attempted release updates, zero
  blocked release updates, `rolled_back: true`, and `writes_committed: 0`.

**Steps:**
- [x] Add a red checkpoint for the missing verifier script.
- [x] Extract the hashed-fix fixture schema, rows, and fingerprint helper into
  `native/internal/testdb`.
- [x] Add the `nntmux-test-fixture` Go command for deterministic fixture
  seeding in Compose.
- [x] Add the PHP resolver SQLite prep script.
- [x] Add `native/scripts/verify-resolved-write-contract.sh`.
- [x] Update existing Go integration tests to reuse the extracted fixture.
- [x] Run focused Go checks, the new verifier, and regression suites.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/testdb ./cmd/nntmux-test-fixture
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'HashedFixName|Resolved|Rehearsal' -count=1
native/scripts/verify-resolved-write-contract.sh
```

Expected: the generated Go report, PHP oracle, and Go resolved rehearsal
round-trip successfully and leave no generated artifacts under `storage/`. Run
this verifier serially with `go-integration-test` because both gates reseed the
same Compose MariaDB fixture tables.

**Depends on:** `T12`, `T13`.

## Task 15: Packaged Native Worker Image Smoke

**ID:** `T15`

**Files:**
- Create: `native/docker/nntmux-worker.Dockerfile`
- Create: `native/scripts/assert-json-path.php`
- Create: `native/scripts/verify-native-worker-image.sh`
- Modify: `docker-compose.native-test.yml`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Build the current Go native worker as a runtime container
artifact and prove that the packaged binary preserves the same non-destructive
dry-run and guarded rollback-only behavior as the Go test containers.

**Acceptance criteria:**
- Compose can build a `native-worker` image from the Go module without copying
  the full Laravel runtime into the final image.
- The runtime image runs as a non-root user.
- The `native-worker` Compose service does not set
  `NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB` by default.
- The image can dry-run a committed metadata-refresh plan fixture from a
  read-only bind mount.
- The packaged binary still refuses execution without `--dry-run`.
- The image can connect to the Compose MariaDB service after the deterministic
  hashed-fix fixture is seeded.
- The image emits a JSON write-contract report with `writes: 0`.
- `--rehearse-writes` is refused by default, then succeeds only when the
  verifier passes `NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1` explicitly.
- The guarded rehearsal reports `rolled_back: true` and
  `writes_committed: 0`.
- The verifier cleans up generated reports on exit and is documented as serial
  with `go-integration-test`.

**Steps:**
- [x] Add a red checkpoint for the missing image verifier script.
- [x] Add the multi-stage native worker Dockerfile.
- [x] Add the profile-gated `native-worker` Compose service.
- [x] Add a JSON path assertion helper for exact packaged-smoke checks.
- [x] Add `native/scripts/verify-native-worker-image.sh`.
- [x] Run the image smoke and focused regression checks.

**Verification:**

```bash
native/scripts/verify-native-worker-image.sh
docker compose -f docker-compose.native-test.yml config
```

Expected: the packaged image dry-runs the plan fixture, rejects non-dry-run
execution, refuses write rehearsal without the explicit safety env, reads the
seeded Compose MariaDB fixture, runs rollback-only rehearsal with the explicit
test guard, and leaves no generated artifacts under `storage/`.

**Depends on:** `T12`, `T14`.

## Task 16: Packaged Catalog Fixture Parity Smoke

**ID:** `T16`

**Files:**
- Modify: `native/docker/nntmux-worker.Dockerfile`
- Modify: `native/scripts/verify-native-worker-image.sh`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Extend the packaged image smoke so the built runtime artifact
validates every committed distributed-worker catalog plan fixture, not only the
metadata-refresh and hashed-fix rollback fixtures.

**Acceptance criteria:**
- The runtime image still runs as fixed non-root UID/GID without useradd
  warnings during build.
- `native/scripts/verify-native-worker-image.sh` runs every
  `tests/Fixtures/native-worker/catalog/*.json` plan through the packaged image
  with `--dry-run --output=json`.
- Each packaged catalog dry-run report is parsed by exact JSON path assertions.
- Every catalog report must have `dry_run: true`,
  `native_worker.writes: 0`, `native_worker.replacement_ready: false`, and at
  least one `native_worker.replacement_readiness.blockers` entry.
- The packaged image must reject `--require-replacement-ready` for every
  current catalog plan fixture.
- The verifier still cleans up all generated catalog reports on exit.

**Steps:**
- [x] Add packaged-image catalog fixture loop to the image verifier.
- [x] Replace the runtime image user creation with fixed non-system UID/GID.
- [x] Rerun the image smoke and diff checks.

**Verification:**

```bash
native/scripts/verify-native-worker-image.sh
git diff --check
```

Expected: every committed catalog fixture is accepted by the packaged image in
JSON dry-run mode, no writes are reported, and no generated artifacts remain
under `storage/`.

**Depends on:** `T15`.

## Task 17: Packaged Rehearsal Fingerprint Guard

**ID:** `T17`

**Files:**
- Modify: `native/cmd/nntmux-test-fixture/main.go`
- Create: `native/cmd/nntmux-test-fixture/main_test.go`
- Modify: `native/scripts/verify-native-worker-image.sh`
- Modify: `docs/native-worker-lanes-test-plan.md`

**Description:** Reuse the deterministic hashed-fix fixture helper to prove the
packaged image's rollback-only rehearsal leaves MariaDB table contents
unchanged, not just that the JSON report says it rolled back.

**Acceptance criteria:**
- `cmd/nntmux-test-fixture` exposes an `--action fingerprint` mode for the
  existing hashed-fix fixture.
- The fingerprint action uses the existing native-test MySQL guard and fixture
  advisory lock.
- The fingerprint covers every column in every hashed-fix fixture table,
  including all release metadata columns that a resolved rehearsal may update.
- The command has unit coverage for validation paths that should fail before
  opening a database connection.
- Integration coverage proves the fingerprint changes when a previously
  omitted release column changes.
- `native/scripts/verify-native-worker-image.sh` captures fingerprints before
  and after packaged-image rehearsal and fails on any diff.
- The verifier still cleans up generated fingerprint files on exit.

**Steps:**
- [x] Refactor `cmd/nntmux-test-fixture` behind a testable `run()` function.
- [x] Add validation unit tests for unsupported action and missing DSN.
- [x] Add `--action fingerprint`.
- [x] Replace narrow SQL fingerprints with deterministic all-column table
  fingerprints.
- [x] Add integration coverage for previously omitted release columns.
- [x] Compare before/after fingerprints in the packaged-image smoke.
- [x] Rerun focused Go tests, image smoke, and diff checks.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-test-fixture
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/testdb -count=1
native/scripts/verify-native-worker-image.sh
git diff --check
```

Expected: fixture command tests pass, testdb integration proves the fingerprint
covers previously omitted release columns, the packaged-image smoke confirms
the full hashed-fix table fingerprint is identical before and after rollback
rehearsal, and no generated artifacts remain under `storage/`.

**Depends on:** `T16`.

## Task 18: Binaries Safe Queue Read-Only Planner

**ID:** `T18`

**Files:**
- Create: `native/internal/binaries/safe_plan.go`
- Test: `native/internal/binaries/safe_plan_test.go`
- Test: `native/internal/binaries/mysql_integration_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add the first read-only native planner for the high-value
NNTP/header ingestion lane. The Go worker mirrors PHP
`BinariesRunner::safeBinaries()` queue construction from local MariaDB
`usenet_groups` and `short_groups` rows without contacting NNTP providers or
mutating group cursors.

**Acceptance criteria:**
- The package-level planner matches PHP safe-binaries queue boundaries:
  `update_group_headers` for new or small-backlog groups, `part_repair` plus
  bounded `get_range binaries` chunks for large backlogs.
- Planner inputs include explicit `--binaries-max-messages` and
  `--binaries-max-headers` CLI flags so tests do not depend on hidden PHP
  settings.
- The MariaDB integration test proves inactive groups and active groups missing
  `short_groups` provider rows are ignored by the read-only planner.
- MariaDB fingerprints for `usenet_groups` and `short_groups` remain unchanged.
- `nntmux-worker --plan catalog/binaries.json --dry-run --mysql-dsn ...`
  prints a binaries dry-run summary.
- JSON output includes only aggregate binaries counts and `writes: 0`; it does
  not leak DSNs, Redis keys, or raw command arguments.

**Steps:**
- [x] Add failing tests for safe-binaries queue boundaries and MariaDB
  read-only planning.
- [x] Implement `native/internal/binaries`.
- [x] Add failing CLI integration tests for text and JSON binaries reports.
- [x] Wire the binaries planner into `cmd/nntmux-worker`.
- [x] Run focused package and CLI integration tests.
- [x] Run full Go regression suites and diff checks.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/binaries -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Binaries' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
git diff --check
```

Expected: the binaries planner reports the expected six safe-queue entries for
the seeded fixture, all reported write counts stay zero, and table
fingerprints remain unchanged.

**Depends on:** `T6`, `T15`.

## Task 19: Backfill Safe Queue Read-Only Planner

**ID:** `T19`

**Files:**
- Create: `native/internal/backfill/safe_plan.go`
- Test: `native/internal/backfill/safe_plan_test.go`
- Test: `native/internal/backfill/mysql_integration_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add the matching read-only native planner for the safe
backfill lane. The Go worker mirrors PHP `BackfillRunner::safeBackfill()`
eligibility and `buildSafeBackfillQueues()` chunk construction from local
MariaDB `usenet_groups` and `short_groups` rows without running
`update_groups.php`, contacting NNTP providers, or moving group cursors.

**Acceptance criteria:**
- The package-level planner matches PHP safe-backfill queue boundaries,
  including final partial chunks down to the provider first article.
- Queue entries are interleaved by chunk across groups to match the PHP
  `ksort($queuesByChunk)` behavior.
- Invalid cursors, zero-work rows, and near-provider-floor ranges are skipped
  and counted.
- Planner inputs include explicit `--backfill-qty`,
  `--backfill-max-messages`, `--backfill-threads`, `--backfill-groups`,
  `--backfill-days`, `--backfill-safe-date`, and
  `--backfill-min-articles` flags so tests do not depend on hidden PHP
  settings.
- The MariaDB integration test proves disabled groups, missing provider rows,
  invalid provider rows, old cursor dates, and near-floor ranges are ignored by
  the read-only planner.
- MariaDB fingerprints for `usenet_groups` and `short_groups` remain
  unchanged.
- `nntmux-worker --plan catalog/backfill.json --dry-run --mysql-dsn ...`
  prints a backfill dry-run summary.
- JSON output includes only aggregate backfill counts and `writes: 0`; it does
  not leak DSNs, Redis keys, raw command arguments, or per-group command
  strings.

**Steps:**
- [x] Add failing tests for safe-backfill queue boundaries, chunk
  interleaving, skip categories, and MariaDB read-only planning.
- [x] Implement `native/internal/backfill`.
- [x] Add failing CLI integration tests for text and JSON backfill reports.
- [x] Wire the backfill planner into `cmd/nntmux-worker`.
- [x] Run focused package and CLI integration tests.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/backfill -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Backfill' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
git diff --check
```

Expected: the backfill planner reports the expected four safe-queue entries
for the seeded fixture, all reported write counts stay zero, and table
fingerprints remain unchanged.

**Depends on:** `T6`, `T18`.

## Task 20: Removecrap Read-Only Candidate Planner

**ID:** `T20`

**Files:**
- Create: `native/internal/removecrap/plan.go`
- Test: `native/internal/removecrap/plan_test.go`
- Test: `native/internal/removecrap/mysql_integration_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add a read-only native dry-run planner for the current
distributed `removecrap` fixture. The Go worker counts candidates for the
SQL-only `gibberish` and `executable` cleanup types emitted by
`DistributedJobCatalog::removeCrap()` custom settings, without invoking the
PHP delete path.

**Acceptance criteria:**
- The package-level planner matches PHP `ReleaseRemoverService` predicates for
  `gibberish` and `executable`, including the numeric `--time` adddate window.
- Unsupported cleanup types fail clearly instead of reporting misleading
  candidate counts.
- MariaDB fingerprints for `releases` and `release_files` remain unchanged.
- `nntmux-worker --plan catalog/removecrap.json --dry-run --mysql-dsn ...`
  prints a removecrap dry-run summary.
- JSON output includes aggregate cleanup counts and `writes: 0`; it does not
  leak DSNs, Redis keys, raw command arguments, release GUIDs, release IDs, or
  search names.
- Reports distinguish unique candidate releases from PHP row-operation counts,
  because file-based cleanup handlers can return multiple rows for one release.
- The dry-run path does not delete NZBs, images, search entries, collections,
  or release rows.

**Steps:**
- [x] Add failing tests for removecrap candidate counting, unsupported types,
  JSON secrecy, and MariaDB read-only planning.
- [x] Implement `native/internal/removecrap`.
- [x] Add failing CLI integration tests for text and JSON removecrap reports.
- [x] Wire the removecrap planner into `cmd/nntmux-worker`.
- [x] Run focused package and CLI integration tests.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/removecrap -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'RemoveCrap' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
git diff --check
```

Expected: the removecrap planner reports two destructive PHP commands, two
unique candidate releases, three PHP row operations, zero native writes, and
unchanged `releases` / `release_files` fingerprints.

**Depends on:** `T6`, `T19`.

## Task 21: Guarded Hashed Fix-Name Miss-Status Commit Proof

**ID:** `T21`

**Files:**
- Modify: `native/internal/safety/mysql.go`
- Modify: `native/internal/namefix/write_rehearsal.go`
- Test: `native/internal/namefix/hashed_plan_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `native/scripts/verify-resolved-write-contract.sh`
- Modify: `native/scripts/verify-native-worker-image.sh`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add the first committed-write proof for the native worker,
restricted to deterministic hashed-fix miss-status updates in the Compose
MariaDB fixture. The Go worker can commit only the safe PHP
`updateSingleColumn()` equivalents for true hashed-fix misses
(`proc_crc32 = 1` and `proc_hash16k = 1`) while blocking rename-linked status
entries and all release renames. Production replacement remains blocked
because Laravel `Search::updateRelease()` is still not executed after these
status changes.

**Acceptance criteria:**
- New committed-write mode is disabled by default and is mutually exclusive
  with rollback-only `--rehearse-writes`.
- Committed writes require `--mysql-dsn`, Redis address/owner inputs, the
  exported `plan.lock.redis_key`, the existing
  `NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1` guard, a native-test allowlisted
  schema, and a second explicit commit guard.
- The commit path acquires/releases the exported distributed-worker Redis lock
  before DB writes.
- The commit path commits only single-column updates where the column is
  `proc_crc32` or `proc_hash16k`, the value is `1`, the reason is `crc-miss`
  or `par-hash-miss`, and the release ID is not present in planned
  `release_updates`.
- Rename updates, `crc-predb-match-confirmation`, unsupported columns, unsafe
  values, duplicate commit IDs, and release-update-linked status entries are
  blocked and reported.
- SQL uses optimistic predicates so the release must still be `Other > Hashed`,
  the target status column must still be `0`, and `predb_id` must still be `0`.
- If validation or SQL execution fails, the transaction rolls back and reports
  zero committed writes.
- Integration tests prove the seeded hashed-fix fixture changes exactly as
  expected after one commit, and a second run is idempotent with zero
  additional rows affected.
- JSON/text reports expose attempted, committed, skipped, blocked, lock
  acquired, affected release IDs, and `writes_committed` without leaking DSNs,
  Redis physical keys, raw command arguments, poster/fromname values, release
  GUIDs, search names, or full SQL.
- The packaged image verifier proves committed writes are refused without the
  commit guard, then succeeds against the isolated Compose MariaDB fixture and
  checks the resulting table fingerprint.
- Native code still does not dispatch `ReleaseNameFixed`, execute search
  updates, delete files, or claim production replacement readiness.

**Steps:**
- [x] Add failing Go tests for committed miss-status writes, missing guard
  refusal, Redis lock contention, blocked rename-linked statuses, stale-row
  skips, unsafe contract rejection, JSON secrecy, and idempotent second run.
- [x] Implement the explicit commit guard, Redis lock ownership, and committed
  miss-status executor.
- [x] Wire the CLI text/JSON report path behind the committed-write flag.
- [x] Extend the resolved write-contract and packaged-image verifiers.
- [x] Update docs and run focused/full Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker -run 'Commit|Resolved|Rehearse' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
native/scripts/verify-resolved-write-contract.sh
native/scripts/verify-native-worker-image.sh
git diff --check
```

Expected: committed hashed-fix miss-status writes are possible only in the
explicit safe Compose test mode while holding the exported Redis lock. The
fixture shows only the expected `proc_crc32` / `proc_hash16k` miss-status
changes after commit, the second commit is idempotent, rename updates remain
blocked, all production write guards remain closed by default, and
`Search::updateRelease()` remains a documented blocker for real lane
replacement.

**Depends on:** `T12`, `T14`, `T15`, `T20`.

## Task 22: Native Commit Search Side-Effect Handoff

**ID:** `T22`

**Files:**
- Create: `app/Services/NameFixing/NativeHashedFixNameSearchSync.php`
- Create: `app/Services/NameFixing/NativeSearchSideEffectSyncFailed.php`
- Create: `app/Console/Commands/SyncNativeSearchSideEffects.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php`
- Test: `tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php`
- Test: `tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php`
- Create: `native/scripts/prepare-native-search-sync-smoke-db.php`
- Modify: `native/scripts/verify-resolved-write-contract.sh`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add the PHP-owned search side-effect handoff for native
hashed-fix miss-status commits. The native worker remains responsible only for
the guarded MariaDB status-column commit; PHP consumes the native commit JSON
report, validates that it represents an actual `hashed-fixnames` commit, and
calls the existing `ReleaseSearchIndexSync::forIds()` path for the committed
release IDs.

**Acceptance criteria:**
- A new Artisan command reads a native JSON report from a file or stdin and
  emits a bounded JSON result.
- The sync rejects dry-run reports, non-`hashed-fixnames` jobs, missing
  `hashed_fixnames.write_commit`, lock-not-acquired reports, and inconsistent
  `writes_committed` / committed-ID counts.
- The sync rejects duplicate or malformed committed release IDs and calls
  `ReleaseSearchIndexSync::forIds()` only for committed IDs.
- The sync refuses skipped/blocked-only reports; a no-op success is allowed only
  when there are no committed, skipped, or blocked release IDs.
- The sync performs no MariaDB writes itself and does not dispatch
  `ReleaseNameFixed`.
- Backend search failures return a nonzero command exit with sanitized failed
  counts/IDs and without leaking backend exception details.
- Output reports aggregate search-sync counts and IDs only; it does not leak
  DSNs, Redis physical keys, command arguments, release names, or full native
  commit JSON.
- PHP unit/feature tests prove the command calls `Search::updateRelease()` for
  the committed IDs and refuses uncommitted or malformed reports.
- The Manticore smoke uses the command to reindex a DB-side mutation from a
  native commit-style report and proves the indexed document changes.
- The resolved write-contract verifier runs native guarded commit and then the
  PHP search side-effect sync.

**Steps:**
- [x] Add failing PHP unit and command tests for native commit report search
  sync.
- [x] Implement the PHP sync service and Artisan command.
- [x] Extend the live Manticore smoke to invoke the command for the second
  index update.
- [x] Extend the resolved write-contract verifier with the PHP search sync
  handoff.
- [x] Update docs and run focused/full Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php
docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm php-search-test ./vendor/bin/phpunit tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php
native/scripts/verify-resolved-write-contract.sh
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
git diff --check
```

Expected: the native commit report can drive the same PHP-owned search update
side effect that `ReleaseUpdateService::updateSingleColumn()` would call after
status-only updates, without broadening native write scope or claiming release
rename replacement readiness.

**Depends on:** `T13`, `T21`.

## Task 23: PHP-Held Worker Lock Commit Handoff

**ID:** `T23`

**Files:**
- Modify: `native/internal/lock/redis_lock.go`
- Test: `native/internal/lock/redis_lock_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`
- Modify: `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Description:** Prove the native miss-status commit can run under the Redis
worker lock already held by Laravel. The PHP worker can acquire
`nntmux:distributed-worker:hashed-fixnames`, pass the Laravel lock owner token
to native, and native validates that the exported Redis key is currently held
by that owner before committing. Native must not release a PHP-held lock; the
Laravel worker remains responsible for release in its existing `finally` block.

**Acceptance criteria:**
- `--commit-miss-status` keeps the existing acquire/release behavior by
  default.
- A new held-lock mode validates `plan.lock.redis_key` is currently owned by
  `--lock-owner` before any MariaDB write.
- Held-lock mode rejects missing or mismatched owners and leaves the Redis key
  untouched.
- Held-lock mode commits only the same guarded miss-status rows as Task 21,
  reports `lock_acquired: true` plus the lock mode, and leaves the Redis key for
  Laravel to release.
- The mode remains restricted to explicit commit proof flags and the
  allowlisted native test DB guard.
- Output still does not leak DSNs, Redis physical keys, command arguments,
  release names, or full native plans.

**Steps:**
- [x] Add failing Redis lock tests for owner verification.
- [x] Add failing CLI integration tests for held-lock commit success and wrong
  owner rejection.
- [x] Implement held-lock validation without releasing the PHP-owned lock.
- [x] Update docs and run focused/full Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/lock ./cmd/nntmux-worker -run 'Held|RedisLock' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Commit' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
git diff --check
```

Expected: native can commit the same guarded miss-status rows while Laravel's
worker lock remains held by the owner token that PHP passed to native; wrong
owners fail before writes.

**Next durability slice:** add the Laravel-owned native side-effect outbox for
committed native miss-status reports so the PHP search handoff can be retried
after native DB commits. Release rename replacement, `ReleaseNameFixed`
dispatch from native-owned commits, category writes, and native search writes
remain non-goals.

## Task 24: Durable Native Search Side-Effect Outbox

**ID:** `T24`

**Files:**
- Create: `database/migrations/2026_06_15_000000_create_native_worker_side_effects_table.php`
- Modify: `native/internal/namefix/write_rehearsal.go`
- Modify: `native/internal/testdb/hashed_fixture.go`
- Test: `native/internal/namefix/hashed_plan_test.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Create: `app/Services/NameFixing/NativeSearchSideEffectOutboxSync.php`
- Modify: `app/Console/Commands/SyncNativeSearchSideEffects.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php`
- Test: `tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add a durable Laravel-owned outbox for native
`hashed-fixnames` miss-status commits. Native records the committed release IDs
for the PHP search side effect in the same MariaDB transaction as the guarded
status update. PHP then processes pending outbox rows through the existing
`ReleaseSearchIndexSync::forIds()` path and leaves failed rows retryable.

**Acceptance criteria:**
- A migration creates a bounded native side-effect outbox with a deterministic
  unique operation key, job/effect/status fields, committed release IDs, write
  counts, retry metadata, and timestamps.
- Native inserts one pending outbox row per committed miss-status release in
  the same transaction as the status update and inserts no rows for zero-write
  idempotent commits.
- Rollbacks and rejected unsafe contracts leave neither status updates nor
  pending outbox rows behind.
- The native commit JSON reports only bounded outbox counts and does not leak
  DSNs, Redis physical keys, command arguments, release names, or full native
  plans.
- A PHP command mode processes pending outbox rows without a report file,
  reuses `ReleaseSearchIndexSync::forIds()` for committed IDs, marks successful
  rows synced, and records retryable sanitized failures without marking failed
  rows processed.
- The report-file sync path from Task 22 remains supported.
- Release renames, `ReleaseNameFixed`, category writes, and native search
  writes remain non-goals.

**Steps:**
- [x] Add failing Go tests for transactional outbox insertion, rollback, and
  idempotent second commits.
- [x] Add failing PHP unit/feature tests for pending outbox processing and
  retryable failure state.
- [x] Implement the migration, native outbox write, and PHP outbox processor.
- [x] Update docs and run focused/full Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix -run 'CommitHashedFixMissStatusUpdates' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'TestRunCommitsHashedFixNameMissStatus' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php
native/scripts/verify-resolved-write-contract.sh
docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm php-search-test ./vendor/bin/phpunit tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
native/scripts/verify-native-worker-image.sh
git diff --check
```

Expected: a native committed status update cannot be separated from its pending
PHP search side-effect work; PHP can retry that work from the DB without
requiring the original native JSON report.

**Depends on:** `T21`, `T22`, `T23`.

## Task 25: PHP-Orchestrated Native Miss-Status Prepass

**ID:** `T25`

**Files:**
- Create: `app/Services/Distributed/NativeWorkerCommitRunner.php`
- Create: `app/Services/Distributed/NativeWorkerCommitResult.php`
- Modify: `app/Services/Distributed/DistributedJobWorker.php`
- Modify: `config/nntmux.php`
- Modify: `.env.example`
- Test: `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- Test: `tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add a disabled-by-default Laravel worker prepass that invokes
the native `hashed-fixnames` miss-status commit while Laravel already holds the
distributed worker lock. PHP passes the Laravel lock owner to native
`--lock-mode=held`, native commits only the proven status-only subset, PHP
processes the durable outbox from Task 24, and then the existing PHP commands
continue to own release renames, categorization, `ReleaseNameFixed`, and
remaining lane behavior.

**Acceptance criteria:**
- The native commit prepass is disabled by default and applies only to the
  `hashed-fixnames` lane.
- PHP invokes the native binary with `--commit-miss-status`,
  `--lock-mode=held`, `--lock-owner=<Laravel lock owner>`, `--output=json`,
  and plan JSON on stdin.
- MariaDB DSN and Redis address are passed to native through a minimal
  environment, not command arguments or plan JSON.
- The Go CLI accepts `NNTMUX_NATIVE_MYSQL_DSN` and
  `NNTMUX_NATIVE_REDIS_ADDR` as fallbacks for the explicit flags.
- Native commit failures fail open and preserve the existing PHP lane command
  path; disabled mode and unrelated lanes also preserve the PHP-only path.
- Successful native commits run the PHP outbox processor; outbox failures are
  reported without replacing or blocking the PHP worker path because retryable
  rows remain durable.
- Logs remain bounded and do not echo DSNs, Redis addresses, raw plan JSON,
  release names, or native command arguments.
- Existing PHP commands still run after a successful native prepass; release
  renames, category writes, events, and full native replacement remain
  follow-up work.

**Steps:**
- [x] Add failing Go test for env fallback secret transport.
- [x] Add failing PHP runner and worker tests for disabled mode, held-lock
  commit invocation, outbox sync, fail-open behavior, and output sanitization.
- [x] Implement the Go env fallback, PHP commit runner, worker hook, and config
  defaults.
- [x] Update docs and run focused Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'EnvFallback|OutputFormat' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php tests/Unit/Distributed/DistributedJobWorkerTest.php
docker compose -f docker-compose.native-test.yml run --rm php-test php -l app/Services/Distributed/NativeWorkerCommitRunner.php
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
native/scripts/verify-resolved-write-contract.sh
native/scripts/verify-native-worker-image.sh
git diff --check
```

Expected: Laravel can safely orchestrate the already-proven native
miss-status/outbox path while holding the worker lock, without placing DB/Redis
connection data in argv and without taking over PHP-owned rename/event logic.

**Depends on:** `T23`, `T24`.

## Task 26: Native Search Outbox Retry Budget

**ID:** `T26`

**Files:**
- Modify: `app/Services/NameFixing/NativeSearchSideEffectOutboxSync.php`
- Modify: `app/Services/Distributed/DistributedJobWorker.php`
- Modify: `config/nntmux.php`
- Modify: `.env.example`
- Test: `tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php`
- Test: `tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php`
- Modify: `docs/distributed-workers.md`
- Modify: `docs/native-worker-lanes-test-plan.md`

**Description:** Add a bounded retry budget for the PHP-owned native search
side-effect outbox. Rows that repeatedly fail search sync should become durable
`failed` rows instead of cycling forever as `pending`; command JSON and worker
logs should report dead-letter counts without leaking backend error text.

**Acceptance criteria:**
- `NNTMUX_NATIVE_WORKER_SEARCH_OUTBOX_MAX_ATTEMPTS` controls how many claimed
  attempts an outbox row may consume before it is marked `failed`.
- Rows below the retry budget remain `pending`, retryable, and available after
  a short delay.
- Rows at the retry budget are marked `failed`, have `processed_at` set, clear
  `available_at`, and are excluded from future pending scans.
- Sync results include `search_updates_dead_lettered` and
  `dead_lettered_release_ids`; failed backend exception messages stay redacted.
- Distributed worker output includes the dead-letter count while still
  continuing through the PHP worker path.
- This does not move search writes into Go or claim native replacement mode.

**Steps:**
- [x] Add failing unit and command tests for dead-lettering after the configured
  retry budget.
- [x] Implement the retry budget, failed-row terminal state, result fields, and
  worker log visibility.
- [x] Update docs and run focused Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php
docker compose -f docker-compose.native-test.yml run --rm php-test php -l app/Services/NameFixing/NativeSearchSideEffectOutboxSync.php
docker compose -f docker-compose.native-test.yml run --rm php-test php -l app/Services/Distributed/DistributedJobWorker.php
git diff --check
```

Expected: PHP-owned outbox processing has bounded retry semantics and visible
dead-letter reporting, while the native worker remains limited to the already
guarded miss-status commit subset.

**Depends on:** `T24`, `T25`.

## Task 27: Native Commit Report Trust Boundary

**ID:** `T27`

**Files:**
- Modify: `app/Services/Distributed/DistributedJobWorker.php`
- Test: `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- Modify: `docs/distributed-workers.md`
- Modify: `docs/native-worker-lanes-test-plan.md`

**Description:** Validate the successful native commit JSON report before PHP
logs the prepass as committed or processes the search outbox. A native process
that exits 0 but emits dry-run, wrong-job, malformed, or inconsistent commit
output should fail open and continue through the PHP worker without syncing
outbox rows.

**Acceptance criteria:**
- PHP validates `schema_version=1`, `mode=shadow`, `dry_run=false`, and
  `native_worker.job=hashed-fixnames`.
- PHP validates `hashed_fixnames.write_commit.lock_acquired=true` and
  `lock_mode=held` for the Laravel-held lock path.
- PHP validates committed write counts against `native_worker.writes`,
  `single_column_updates_committed`, `single_column_rows_affected`, and
  `committed_release_ids`.
- Invalid successful native output does not call the outbox processor, does not
  log "committed", and still runs the PHP lane command loop.
- Validation errors are bounded/redacted and do not echo raw native JSON,
  release names, DSNs, Redis addresses, or lock keys.

**Steps:**
- [x] Add failing worker test for a successful native process with invalid
  commit report output.
- [x] Implement PHP-side commit report validation before outbox sync.
- [x] Update docs and run focused Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'validates_successful_native_report|enabled_native_hashed_fixnames_commit_prepass'
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php tests/Unit/Distributed/DistributedJobWorkerTest.php
docker compose -f docker-compose.native-test.yml run --rm php-test php -l app/Services/Distributed/DistributedJobWorker.php
git diff --check
```

Expected: a zero-exit native binary cannot spoof a committed prepass without a
valid held-lock commit report.

**Depends on:** `T25`.

## Task 28: PHP-Orchestrated Native Prepass Compose Smoke

**ID:** `T28`

**Files:**
- Modify: `app/Services/Distributed/NativeWorkerCommitRunner.php`
- Modify: `native/cmd/nntmux-worker/main.go`
- Modify: `native/internal/namefix/write_rehearsal.go`
- Modify: `config/nntmux.php`
- Modify: `.env.example`
- Modify: `docker-compose.native-test.yml`
- Add: `native/docker/php-native-test.Dockerfile`
- Add: `native/scripts/verify-php-native-hashed-worker-smoke.sh`
- Test: `tests/Feature/Console/NativeWorkerMissStatusPrepassSmokeTest.php`
- Test: `tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php`
- Modify: `docs/distributed-workers.md`
- Modify: `docs/native-worker-lanes-test-plan.md`

**Description:** Prove the PHP distributed worker can orchestrate the real
packaged Go native worker under Docker Compose while holding Laravel's Redis
lock, committing only the guarded miss-status subset, syncing the PHP-owned
native search outbox, continuing through bounded PHP commands, and remaining
idempotent on a second run.

**Acceptance criteria:**
- The PHP smoke container has both `pdo_mysql` and `ext-redis`; Redis locking
  cannot silently fall back to the array cache.
- The verifier copies `/usr/local/bin/nntmux-worker` from the packaged
  `native-worker` image into a bind-mounted PHP executable path.
- PHP refuses committed native writes unless
  `NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=1`, both native test DB guard envs
  are present, and the DSN targets an allowlisted `nntmux_native_test*`
  database.
- PHP passes the Laravel lock owner through `NNTMUX_NATIVE_LOCK_OWNER` instead
  of argv, while DSN and Redis settings remain in the minimal child environment.
- The native outbox rows are immediately available for PHP sync without
  cross-container clock-skew races.
- The smoke verifies release `102` gets `proc_crc32=1`, release `301` gets
  `proc_hash16k=1`, outbox rows sync to `synced`, the PHP continuation cannot
  acquire the worker lock while native orchestration holds it, and the lock is
  released afterward.
- Worker output does not expose DSNs, Redis addresses, lock owner tokens,
  physical Redis key prefixes, or native CLI secret-bearing flags.

**Steps:**
- [x] Add failing runner tests for env-only lock owner handoff and PHP-side
  committed-write smoke guard.
- [x] Add the real Compose smoke harness and PHP feature test.
- [x] Fix the native outbox availability race and cover both CRC/PAR methods.
- [x] Run the end-to-end Docker Compose verifier.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php
native/scripts/verify-php-native-hashed-worker-smoke.sh
```

Expected: the end-to-end Compose path proves Laravel-held-lock native
miss-status prepass orchestration with the packaged binary, MariaDB fixture,
Redis lock, outbox sync, PHP continuation, and idempotent second run.

**Depends on:** `T25`, `T26`, `T27`.

## Task 29: PHP-Owned Native Hashed Rename Handoff Proof

**ID:** `T29`

**Files:**
- Add: `app/Services/NameFixing/NativeHashedFixNameRenameApplier.php`
- Add: `app/Console/Commands/ApplyNativeHashedFixNameRenames.php`
- Modify: `bootstrap/app.php`
- Modify: `config/nntmux.php`
- Modify: `.env.example`
- Test: `tests/Unit/Distributed/NativeHashedFixNameRenameApplierTest.php`
- Test: `tests/Feature/Console/NativeHashedFixNameRenameApplyCommandTest.php`
- Modify: `docs/distributed-workers.md`
- Modify: `docs/native-worker-lanes-test-plan.md`

**Description:** Add a standalone, disabled-by-default PHP handoff proof that
consumes a resolved native hashed-fixnames write contract and applies resolved
release-renaming side effects through `ReleaseUpdateService`. This proves the
ownership boundary for method `16`/`20` rename candidates without widening the
native outbox schema or wiring rename writes into `DistributedJobWorker`.

**Acceptance criteria:**
- The command is gated by
  `NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=1`.
- The applier accepts only schema version 1 resolved native write contracts with
  `dry_run=true`, `writes=0`, and `write_contract.writes=0`.
- Blocked release updates, duplicate release IDs, malformed IDs, missing event
  context, missing search-update intent, unsupported types, and stale release
  rows fail before any partial `ReleaseUpdateService` call.
- Successful output contains only aggregate counts and release IDs, not old
  names, new names, posters, DSNs, Redis keys, or command arguments.
- The native committed miss-status prepass remains unchanged and does not claim
  production replacement for release renames.

**Steps:**
- [x] Add failing unit and command tests for the PHP-owned rename handoff.
- [x] Implement the guarded applier and Artisan command.
- [x] Fix duplicate-ID handling so validation completes before rename calls.
- [x] Update docs and run focused Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeHashedFixNameRenameApplierTest.php tests/Feature/Console/NativeHashedFixNameRenameApplyCommandTest.php
docker compose -f docker-compose.native-test.yml run --rm php-test php -l app/Services/NameFixing/NativeHashedFixNameRenameApplier.php
docker compose -f docker-compose.native-test.yml run --rm php-test php -l app/Console/Commands/ApplyNativeHashedFixNameRenames.php
git diff --check
```

Expected: resolved native rename candidates can be handed back to PHP's
existing `ReleaseUpdateService` boundary under an explicit test-only guard, with
fail-closed validation and redacted command output.

**Depends on:** `T18`, `T20`, `T28`.

## Task 30: Native Hashed-Fix Replacement Readiness Guard

**ID:** `T30`

**Files:**
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/distributed-workers.md`
- Modify: `docs/native-worker-lanes-test-plan.md`

**Description:** Make replacement readiness explicit in the native hashed-fix
JSON report so the full `hashed-fixnames` lane cannot be mistaken for a native
replacement while the catalog still contains unimplemented methods and
PHP-owned rename/category/event/search side effects.

**Acceptance criteria:**
- `--require-replacement-ready` fails before any DB/write path when blockers
  remain.
- Hashed-fix JSON reports include `replacement_ready=false` whenever blockers
  remain.
- Reports list native-supported hashed fix-name methods from the current plan.
- Reports list unsupported hashed fix-name methods from the current plan and
  count unsupported command entries.
- Plan-derived readiness metadata is present for `hashed-fixnames` JSON reports
  even when no MariaDB DSN is provided.
- The full `hashed-fixnames` catalog reports methods `16` and `20` as
  supported and methods `4`, `6`, `8`, `10`, `12`, `14`, `18`, and `21` as
  unsupported.
- Reports include blocker text for unsupported methods and for PHP-owned
  rename/category/event/search side effects, without exposing command
  arguments, Redis keys, or DSNs.
- This does not add a replacement execution mode or change guarded miss-status
  commit behavior.

**Steps:**
- [x] Add a failing Go integration assertion for replacement-readiness metadata
  in the full hashed-fixnames catalog JSON report.
- [x] Add a no-DB failing test for `--require-replacement-ready` against the
  full hashed-fixnames catalog.
- [x] Add review-regression tests for unsupported-only hashed plans and no-DB
  replacement-readiness JSON metadata.
- [x] Implement plan-derived supported/unsupported method reporting.
- [x] Keep replacement readiness false while side-effect ownership remains
  outside native code.
- [x] Update docs and run focused Docker Compose verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run TestRunRequireReplacementReadyRejectsHashedFixnamesCatalogWithUnsupportedMethods -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'UnsupportedOnly|IncludesReadinessWithoutMySQL' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run TestRunPrintsHashedFixNameJSONReportWithWriteContractDetails -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'HashedFixName|Commit|Resolved|Rehearse' -count=1
git diff --check
```

Expected: the native report continues to expose dry-run/write-contract
metadata, now with explicit replacement blockers for unsupported catalog
methods and PHP-owned side effects.

**Depends on:** `T18`, `T25`, `T29`.

## Task 31: PHP-Owned Native Rename Apply End-to-End Smoke

**ID:** `T31`

**Files:**
- Create: `tests/Feature/Console/NativeHashedFixNameResolvedApplySmokeTest.php`
- Create: `native/scripts/verify-php-native-rename-apply-smoke.sh`
- Modify: `native/scripts/prepare-native-search-sync-smoke-db.php`
- Modify: `.env.example`
- Modify: `docs/distributed-workers.md`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Description:** Add the gated Compose smoke that proves native hashed-fix JSON
can cross the PHP-owned rename boundary end to end: Go dry-run JSON, PHP write
contract resolution, guarded PHP apply through `ReleaseUpdateService`, real
`ReleaseNameFixed` event dispatch, and real Manticore `releases_rt` document
replacement.

**Acceptance criteria:**
- The smoke is opt-in through `NNTMUX_NATIVE_RENAME_APPLY_SMOKE=1`.
- The verifier reseeds the deterministic hashed-fix fixture before generating
  native JSON.
- The PHP support bridge creates `settings`, `usenet_groups`, `movieinfo`,
  `videos`, and the release columns required by `Search::updateRelease()`.
- The smoke resolves two release updates with no blocked rows.
- Applying the resolved report changes releases `100` and `300` through the
  real `ReleaseUpdateService` path, including status columns and `predb_id`.
- The smoke captures `ReleaseNameFixed` for both release IDs with old/new names,
  old category, group, and poster.
- The smoke asserts Manticore documents for releases `100` and `300` contain the
  updated search name, category, poster/fromname, scalar fields, and filename
  evidence where present.
- The wrapper cleans generated artifacts on exit and is documented as serial
  with `go-integration-test`.

**Steps:**
- [x] Add the gated feature smoke and verify it fails before the setup/assertion
  gaps are fixed.
- [x] Add `usenet_groups` to the PHP/Manticore prepare bridge.
- [x] Add `native/scripts/verify-php-native-rename-apply-smoke.sh`.
- [x] Add test-only smoke env keys to `.env.example`.
- [x] Run the wrapper, focused PHP regressions, targeted PHPStan, syntax checks,
  and diff checks.

**Verification:**

```bash
native/scripts/verify-php-native-rename-apply-smoke.sh
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeHashedFixNameRenameApplierTest.php tests/Feature/Console/NativeHashedFixNameRenameApplyCommandTest.php tests/Feature/Console/NativeWriteContractResolveCommandTest.php
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpstan analyse tests/Feature/Console/NativeHashedFixNameResolvedApplySmokeTest.php native/scripts/prepare-native-search-sync-smoke-db.php --memory-limit=2G
docker compose -f docker-compose.native-test.yml run --rm php-test sh -lc 'php -l tests/Feature/Console/NativeHashedFixNameResolvedApplySmokeTest.php && php -l native/scripts/prepare-native-search-sync-smoke-db.php && bash -n native/scripts/verify-php-native-rename-apply-smoke.sh'
git diff --check
```

Expected: the wrapper reports `OK (1 test, 139 assertions)`, focused PHP tests
pass, targeted PHPStan reports no errors, syntax checks pass, and diff check is
clean.

**Depends on:** `T29`, `T30`.

## Task 32: Worker-Orchestrated PHP-Owned Native Rename Prepass

**ID:** `T32`

**Files:**
- Create: `app/Services/Distributed/NativeHashedFixNameRenamePrepassRunner.php`
- Create: `app/Services/Distributed/NativeHashedFixNameRenamePrepassResult.php`
- Create: `tests/Unit/Distributed/NativeHashedFixNameRenamePrepassRunnerTest.php`
- Create: `tests/Feature/Console/NativeWorkerRenamePrepassSmokeTest.php`
- Create: `native/scripts/verify-php-native-rename-worker-smoke.sh`
- Modify: `app/Services/Distributed/DistributedJobWorker.php`
- Modify: `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- Modify: `tests/Feature/Console/NativeWorkerMissStatusPrepassSmokeTest.php`
- Modify: `native/cmd/nntmux-worker/main.go`
- Modify: `native/cmd/nntmux-worker/main_test.go`
- Modify: `config/nntmux.php`
- Modify: `.env.example`
- Modify: `docs/distributed-workers.md`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Description:** Promote the Task 31 standalone PHP-owned rename handoff into
the real `DistributedJobWorker` lock boundary for native-supported
`hashed-fixnames` methods `16` and `20`. The worker remains fail-open and still
runs the existing PHP `releases:fix-names` command loop. Go supplies a
read-only JSON report only; PHP resolves categories, applies renames through
`ReleaseUpdateService`, dispatches `ReleaseNameFixed`, and updates search.

**Acceptance criteria:**
- The prepass is disabled by default through
  `NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_RENAME_PREPASS_ENABLED=false`.
- The prepass is scoped to the `hashed-fixnames` lane and runs after the
  miss-status prepass but before PHP commands.
- PHP sends the MariaDB DSN to native through `NNTMUX_NATIVE_MYSQL_DSN` plus
  `--mysql-dsn-env`, never through argv.
- PHP refuses to spawn native unless
  `NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=1` and the DSN targets an
  allowlisted `nntmux_native_test*` database.
- PHP validates native JSON before resolving/applying it and rejects malformed,
  non-shadow, non-dry-run, wrong-job, or write-bearing reports.
- Native JSON stdout uses a separate 1 MiB report budget so valid reports are
  not truncated by the stderr log-byte limit.
- Failures are logged with redacted/bounded output and the existing PHP command
  loop continues.
- The live smoke proves real packaged binary, MariaDB fixture, Laravel Redis
  lock, PHP rename side effects, `ReleaseNameFixed`, Manticore replacement, and
  PHP continuation under lock.

**Steps:**
- [x] Add failing unit tests for runner guard, env-only DSN handoff, validation,
  stdout report truncation, and worker fail-open ordering.
- [x] Add `--mysql-dsn-env` to the native worker CLI.
- [x] Implement the PHP rename prepass runner/result and worker hook.
- [x] Add the guarded worker-level Manticore smoke and wrapper.
- [x] Update runtime docs, test-plan evidence, env examples, and validation
  commands.
- [x] Run focused PHP/Go tests, live Compose smoke, syntax checks, targeted
  production PHPStan, formatting, and diff checks.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeHashedFixNameRenamePrepassRunnerTest.php tests/Unit/Distributed/DistributedJobWorkerTest.php tests/Feature/Console/NativeWorkerRenamePrepassSmokeTest.php
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeHashedFixNameRenameApplierTest.php tests/Feature/Console/NativeHashedFixNameRenameApplyCommandTest.php tests/Feature/Console/NativeWriteContractResolveCommandTest.php
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
native/scripts/verify-php-native-rename-worker-smoke.sh
native/scripts/verify-php-native-hashed-worker-smoke.sh
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpstan analyse app/Services/Distributed/NativeHashedFixNameRenamePrepassRunner.php app/Services/Distributed/NativeHashedFixNameRenamePrepassResult.php app/Services/Distributed/DistributedJobWorker.php config/nntmux.php --memory-limit=2G
docker compose -f docker-compose.native-test.yml run --rm php-test bash -lc 'php -l app/Services/Distributed/NativeHashedFixNameRenamePrepassRunner.php && php -l app/Services/Distributed/NativeHashedFixNameRenamePrepassResult.php && php -l tests/Feature/Console/NativeWorkerRenamePrepassSmokeTest.php && bash -n native/scripts/verify-php-native-rename-worker-smoke.sh'
docker compose -f docker-compose.native-test.yml config
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/pint --dirty
git diff --check
```

Expected: focused unit tests pass with the smoke skipped outside its guarded
environment, related rename resolver/applier tests pass, Go tests pass, the
rename smoke wrapper reports `OK (1 test, 59 assertions)` plus
`php-orchestrated native hashed-fixnames rename prepass smoke verified`, the
existing miss-status smoke still passes, targeted production PHPStan reports no
errors, formatting reports no dirty PHP files, and diff check is clean. A
broader PHPStan run that includes the existing Mockery-heavy
`DistributedJobWorkerTest` still reports unrelated Mockery type issues and is
not used as this slice's static gate.

**Depends on:** `T29`, `T31`.

## Task 33: Read-Only Native Post-TV Queue Planner

**Status:** Completed.

**Description:** Add the first postprocess native planner slice for the
`post-tv` catalog lane. Go mirrors the PHP `PostProcessRunner` bucket
selection for `multiprocessing:postprocess tv` and `ani`, reports only
aggregate queue metadata, and keeps all postprocess execution and side effects
PHP-owned.

**Acceptance criteria:**
- Native `nntmux-worker` accepts the generated `post-tv` plan with
  `--dry-run --mysql-dsn`.
- The planner mirrors TV predicates: category `5000..5999` excluding `5070`,
  `videos_id = 0`, `tv_episodes_id BETWEEN -3 AND 0`, `size > 1048576`, and
  `lookuptv=2` / command renamed-only filtering.
- The planner mirrors anime predicates: category `5070`, `anidbid IS NULL`,
  and `lookupanidb > 0`.
- `postthreadsnon`, renamed mode, TV pipeline mode, type counts, and
  bucket-entry counts are reported as sanitized aggregates.
- JSON omits DSNs, Redis physical keys, command arguments, release names,
  GUIDs, leftguid values, bucket commands, and per-release details.
- MariaDB table fingerprints prove the planner does not mutate `settings` or
  `releases`.
- `post-movies`, `post-amazon`, `post-additional`, and NFO remain follow-up
  gates.

**Steps:**
- [x] Add failing package-level MariaDB tests for TV/anime bucket selection,
  lookup settings, renamed-only filtering, unsupported types, JSON redaction,
  and no table mutation.
- [x] Add failing CLI integration tests for text and JSON `post-tv` reports.
- [x] Implement `native/internal/postprocess`.
- [x] Wire the post-tv request parser and report section into
  `cmd/nntmux-worker`.
- [x] Update the native worker test plan and verification ledger.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/postprocess -run 'PostTV|PostprocessPlanJSON' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostTV' -count=1
```

Expected: both focused Compose integration gates pass, text output includes
`postprocess mysql dry-run`, `job=post-tv`, TV/anime bucket counts, and
`writes=0`, while JSON output exposes only aggregate postprocess fields.

**Depends on:** `T3`, `T9`, `T13`.

## Task 34: Read-Only Native Post-Movies and Post-Amazon Queue Planner

**Status:** Completed.

**Description:** Expand the read-only postprocess planner beyond `post-tv` to
cover the remaining non-additional catalog postprocess lanes:
`post-movies` and `post-amazon`. Go mirrors the PHP bucket-selection
predicates for `mov` and the Amazon aggregate `ama` command, reports only
sanitized aggregate queue metadata, and keeps all postprocess execution and
metadata side effects PHP-owned.

**Acceptance criteria:**
- Native `nntmux-worker` accepts generated `post-movies` and `post-amazon`
  plans with `--dry-run --mysql-dsn`.
- Movie planning mirrors `PostProcessRunner::processMovies()`: movie category
  range, pending IMDb sentinels, movieinfo repair, `lookupimdb` gating,
  renamed filtering, `postthreadsnon`, and renamed mode.
- Amazon planning mirrors the aggregate `processAmazon()` buckets for books,
  music, console, and games, with `lookupbooks`, `lookupmusic`, `lookupgames`,
  `postthreadsamazon`, and `lookupgames=2` renamed filtering for console/game
  families.
- JSON omits DSNs, Redis physical keys, command arguments, release names,
  GUIDs, leftguid values, bucket commands, IMDb values, and per-release
  details.
- MariaDB table fingerprints prove the planner does not mutate `settings` or
  `releases`.
- `post-additional` and NFO remain follow-up gates.

**Steps:**
- [x] Add failing package-level MariaDB tests for movie and Amazon predicates,
  lookup settings, renamed filtering, JSON redaction, unsupported types, and no
  table mutation.
- [x] Add failing CLI integration tests for text and JSON reports against the
  generated `post-movies` and `post-amazon` fixtures.
- [x] Extend `native/internal/postprocess` with movie and Amazon subtype
  planners.
- [x] Extend `cmd/nntmux-worker` postprocess planner dispatch for
  `post-movies` and `post-amazon`.
- [x] Update the native worker test plan and verification ledger.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/postprocess -run 'PostMovie|PostAmazon|PostprocessPlanJSON' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostMovies|PostAmazon' -count=1
```

Expected: both focused Compose integration gates pass, text output includes
`postprocess mysql dry-run`, the relevant job names, movie/Amazon subtype
bucket counts, and `writes=0`, while JSON output exposes only aggregate
postprocess fields.

**Depends on:** `T33`.

## Task 35: Read-Only Native Post-Additional Add and NFO Queue Planner

**Status:** Completed.

**Description:** Add the read-only native planner for the `post-additional`
lane's two postprocess queue families: `multiprocessing:postprocess add` and
`multiprocessing:postprocess nfo`. Go mirrors the PHP bucket-selection
predicates from `AdditionalCandidateQuery` and `NfoService::NfoQueryString()`,
reports only sanitized aggregate queue metadata, and keeps all additional/NFO
execution side effects PHP-owned.

**Acceptance criteria:**
- Native `nntmux-worker` accepts the generated mixed `post-additional` plan with
  `--dry-run --mysql-dsn`.
- Additional planning mirrors `AdditionalCandidateQuery`: `passwordstatus=-1`,
  `haspreview=-1`, `nzbstatus=1`, `categories.disablepreview=0`, the
  `minsizetopostprocess`/`maxsizetopostprocess` bounds with PHP default and
  explicit-zero semantics, `postthreads`, and the 16-bucket cap.
- NFO planning mirrors `NfoService::NfoQueryString()`: `lookupnfo=1`, retry
  lower-bound clamping through `maxnforetries`, NFO size bounds,
  `nfothreads`, and the 16-bucket cap.
- Metadata-refresh and hashed fix-name commands embedded in the generated
  `post-additional` fixture are deferred for this lane and do not produce
  detailed `metadata_refresh` or `hashed_fixnames` reports.
- JSON omits DSNs, Redis physical keys, command arguments, release names,
  GUIDs, leftguid values, bucket commands, and per-release details.
- MariaDB table fingerprints prove the planner does not mutate `settings`,
  `categories`, or `releases`.

**Steps:**
- [x] Add failing package-level MariaDB tests for additional and NFO predicates,
  lookup settings, size/retry boundaries, unsupported types, and no table
  mutation.
- [x] Add failing CLI integration tests for text and JSON reports against the
  generated `post-additional` fixture, including deferred metadata/hashed
  assertions.
- [x] Extend `native/internal/postprocess` with additional and NFO planners.
- [x] Extend `cmd/nntmux-worker` postprocess dispatch for mixed
  `post-additional` plans without running deferred metadata/hashed reports.
- [x] Update the native worker test plan and verification ledger.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/postprocess -run 'PostAdditional|UnsupportedTypes' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostAdditional' -count=1
```

Expected: both focused Compose integration gates pass, text output includes
`postprocess mysql dry-run`, `job=post-additional`, add/NFO bucket counts, and
`writes=0`; JSON output exposes only aggregate postprocess fields and omits
deferred metadata/hashed subreports.

**Depends on:** `T34`.

## Task 36: Read-Only Native Releases Queue Planner

**Status:** Completed.

**ID:** `T36`

**Files:**
- Create: `native/internal/releases/plan.go`
- Test: `native/internal/releases/plan_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_test.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add a read-only MariaDB-backed planner for the distributed
`releases` lane. The native worker mirrors the queue-selection boundary in
`ReleasesRunner::releases()` only: active/backfill groups that have at least
one collection are counted as queued release-processing work.

**Acceptance criteria:**
- `nntmux-worker --plan catalog/releases.json --dry-run --mysql-dsn ...`
  prints a `releases mysql dry-run` summary.
- The planner reads only `settings`, `usenet_groups`, and `collections`.
- Reports include candidate groups, eligible groups, no-collection skips,
  queue entries, effective max processes, batch count, and `writes: 0`.
- JSON omits DSNs, Redis physical keys, command arguments, group names, group
  IDs, DNR command strings, collection IDs, and per-collection details.
- MariaDB table fingerprints prove the planner does not mutate `settings`,
  `usenet_groups`, or `collections`.
- The CLI rejects unsupported `releases` lane commands before printing a
  native worker dry-run summary.
- Native does not run `releases:process`, `ReleaseProcessingService`,
  categorization, NZB/file writes, collection cleanup, search updates, or any
  release-row mutation.
- `per-group`, `fixnames`, and `irc` remain deferred because they have broader
  side effects or composite behavior.

**Steps:**
- [x] Add failing package-level MariaDB tests for release group eligibility,
  no-collection skips, batching, JSON redaction, and no table mutation.
- [x] Add failing CLI tests for text and JSON `releases` reports against the
  generated catalog fixture plus unsupported command rejection.
- [x] Implement `native/internal/releases`.
- [x] Wire the releases planner into `cmd/nntmux-worker`.
- [x] Update the native worker test plan and distributed-worker docs.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/releases -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'UnsupportedReleasesCommand' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Releases' -count=1
```

Expected: focused Compose gates pass, text output includes `releases mysql
dry-run`, aggregate group/queue counts, batch count, and `writes=0`; JSON
output exposes only aggregate `releases` fields and no group or collection
details.

**Depends on:** `T18`, `T19`, `T20`, `T35`.

## Task 37: Read-Only Native Per-Group Queue Planner

**Status:** Completed.

**ID:** `T37`

**Files:**
- Create: `native/internal/pergroup/plan.go`
- Test: `native/internal/pergroup/plan_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_test.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add a read-only MariaDB-backed planner for the distributed
`per-group` lane queue envelope. The native worker mirrors the queue-selection
boundary in `ReleasesRunner::updatePerGroup()` only: active/backfill groups
are counted as queued `update_per_group` work, batched by `releasethreads`.

**Acceptance criteria:**
- `nntmux-worker --plan catalog/per-group.json --dry-run --mysql-dsn ...`
  prints a `per-group mysql dry-run` summary.
- The planner reads only `settings` and `usenet_groups`; tests include a poison
  `collections` table to prove collection rows do not affect selection.
- Reports include candidate groups, queue entries, effective max processes,
  batch count, and `writes: 0`.
- JSON omits DSNs, Redis physical keys, command arguments, group names, group
  IDs, DNR command strings, collection IDs, and child-stage details.
- MariaDB table fingerprints prove the planner does not mutate `settings`,
  `usenet_groups`, or the poison `collections` table.
- The CLI rejects unsupported `per-group` lane commands before printing a
  native worker dry-run summary.
- Native does not run `group:update-all`, binaries/header work, backfill,
  release creation, post-processing, NNTP, NZB/file writes, release mutations,
  search updates, or any child-stage command.
- `fixnames` and `irc` remain deferred because they have broader side effects
  or network behavior.

**Steps:**
- [x] Add failing package-level MariaDB tests for active/backfill selection,
  batching, JSON redaction, and no table mutation.
- [x] Add failing CLI tests for text and JSON `per-group` reports against the
  generated catalog fixture plus unsupported command rejection.
- [x] Implement `native/internal/pergroup`.
- [x] Wire the per-group planner into `cmd/nntmux-worker`.
- [x] Update the native worker test plan and distributed-worker docs.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/pergroup -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'UnsupportedPerGroupCommand' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PerGroup' -count=1
```

Expected: focused Compose gates pass, text output includes `per-group mysql
dry-run`, aggregate group/queue counts, batch count, and `writes=0`; JSON
output exposes only aggregate `per_group` fields and no group, command,
collection, or child-stage details.

**Depends on:** `T18`, `T19`, `T20`, `T33`, `T34`, `T35`, `T36`.

## Task 38: No-DB Native Fixnames Command-Envelope Readiness Report

**Status:** Completed.

**ID:** `T38`

**Files:**
- Create: `native/internal/fixnames/plan.go`
- Test: `native/internal/fixnames/plan_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add a no-DB native report for the regular distributed
`fixnames` lane. The native worker parses the exported `releases:fix-names`
command envelope, validates that it remains a regular `other`/`movies` lane,
reports aggregate command/method/flag counts, and makes replacement readiness
explicitly false.

**Acceptance criteria:**
- `nntmux-worker --plan catalog/fixnames.json --dry-run` prints a `fixnames
  dry-run` summary without opening MariaDB.
- The report parses only the exported plan; `--mysql-dsn` remains unsupported
  for regular `fixnames`.
- Reports include command count, unique methods, unique categories, limited
  commands, update/set-status/show command counts, replacement blockers, and
  `writes: 0`.
- JSON omits DSNs, Redis physical keys, raw command arguments, regular
  category labels, raw option names, release IDs, names, GUIDs, and PHP command
  payloads from the fixnames-specific report.
- The CLI rejects unsupported regular `fixnames` lane commands before printing
  a native worker dry-run summary.
- `--require-replacement-ready` fails for the current `fixnames` catalog with
  method-level blockers before any DB/write path.
- Native does not read releases, produce rename candidates, build write
  contracts, call PHP name-fixing services, contact NNTP, update search, or
  mutate any DB rows.
- `irc` remains deferred because it has external network behavior.

**Steps:**
- [x] Add failing package-level tests for full-catalog counts, unsupported
  commands, unsupported categories, non-fixnames job rejection, JSON redaction,
  and replacement blockers.
- [x] Add failing CLI tests for JSON `fixnames` reports, unsupported command
  rejection, `--mysql-dsn` rejection, and `--require-replacement-ready`.
- [x] Implement `native/internal/fixnames`.
- [x] Wire the fixnames report into `cmd/nntmux-worker`.
- [x] Update the native worker test plan and distributed-worker docs.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/fixnames -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'Fixnames|FixnamesCatalog' -count=1
```

Expected: focused Compose gates pass, text output includes `fixnames dry-run`,
aggregate command/method/flag counts, `replacement-ready=false`, and
`writes=0`; JSON output exposes only aggregate `fixnames` fields and no raw
arguments, category labels, Redis keys, DSNs, or PHP command payloads.

**Depends on:** `T30`, `T36`, `T37`.

## Task 39: No-Network Native IRC Command-Envelope Readiness Report

**Status:** Completed.

**ID:** `T39`

**Files:**
- Create: `native/internal/irc/plan.go`
- Test: `native/internal/irc/plan_test.go`
- Modify: `native/cmd/nntmux-worker/main.go`
- Test: `native/cmd/nntmux-worker/main_test.go`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Add a no-network native report for the distributed `irc`
lane. The native worker validates the exported `irc:scrape` command envelope,
reports that network execution is still required, and makes replacement
readiness explicitly false.

**Acceptance criteria:**
- `nntmux-worker --plan catalog/irc.json --dry-run` prints an `irc dry-run`
  summary without opening sockets.
- The report parses only the exported plan; it does not read IRC settings,
  credentials, channels, MariaDB, Redis, or search.
- Reports include command count, `network_required=true`, replacement blockers,
  and `writes: 0`.
- JSON omits Redis physical keys, raw command arguments, IRC server/channel/
  password settings, parser payloads, and `predb` row details from the
  IRC-specific report.
- The CLI rejects unsupported `irc` lane commands or non-empty arguments before
  printing a native worker dry-run summary.
- `--require-replacement-ready` fails for the current `irc` catalog with native
  rollout and PHP-owned search side-effect blockers.
- Dry-run does not open IRC sockets, log into IRC, write `predb`, or update
  search.

**Steps:**
- [x] Add failing package-level tests for the generated catalog fixture,
  unsupported commands, non-empty arguments, JSON redaction, and blockers.
- [x] Add failing CLI tests for JSON `irc` reports, unsupported command
  rejection, and `--require-replacement-ready`.
- [x] Implement `native/internal/irc`.
- [x] Wire the IRC report into `cmd/nntmux-worker`.
- [x] Update the native worker test plan and distributed-worker docs.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/irc -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'Irc|IrcCatalog' -count=1
```

Expected: focused Compose gates pass, text output includes `irc dry-run`,
`network-required=true`, `replacement-ready=false`, and `writes=0`; JSON output
exposes only aggregate `irc` fields and no raw arguments, Redis keys, IRC
settings, credentials, parser payloads, or `predb` details.

**Depends on:** `T38`.

## Task 40: Universal Native Replacement-Readiness Guard

**Status:** Completed.

**Description:** Make `--require-replacement-ready` a default-deny operational
gate for every exported catalog lane. Lanes with explicit readiness metadata
(`hashed-fixnames`, regular `fixnames`, and `irc`) keep their detailed blockers;
every other catalog lane fails with a lane-scoped message and a generic
`no explicit replacement-ready implementation` blocker before reports, MariaDB
planners, or write paths can run.

**Acceptance checks:**

- `--require-replacement-ready` exits `2` for `binaries`, `backfill`,
  `metadata-refresh`, `per-group`, `post-additional`, `post-amazon`,
  `post-movies`, `post-tv`, `releases`, and `removecrap`.
- The failure line uses the actual catalog lane name, for example
  `metadata-refresh catalog is not replacement-ready`.
- Generic lanes include `no explicit replacement-ready implementation` and do
  not emit JSON reports.
- Existing detailed blockers for `hashed-fixnames`, regular `fixnames`, and
  `irc` remain intact.
- Failure output does not leak command arguments, physical Redis keys, DSNs, or
  fixture internals.

**Implementation steps:**

- [x] Add a failing table-driven CLI test for generic catalog lanes.
- [x] Add a single replacement-readiness helper in `cmd/nntmux-worker`.
- [x] Preserve detailed readiness blockers for lanes that already expose them.
- [x] Update operator docs and test-plan evidence.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run RequireReplacementReady -count=1
```

Expected: the full replacement-readiness CLI group passes, proving no current
catalog lane can be mistaken for native production replacement.

**Depends on:** `T39`.

## Task 41: Opt-In Fail-Closed Hashed-Fixnames Native Prepass Policy

**Status:** Completed.

**Description:** Add an explicit fail-closed policy for configured
`hashed-fixnames` native prepasses. By default, native shadow validation and
native hashed-fixnames prepasses remain fail-open compatibility bridges. When
`NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_FAIL_CLOSED_ENABLED=true`, failures in
the configured miss-status commit prepass, committed-report validation, PHP
search outbox sync, or rename prepass return a nonzero worker exit before the
existing PHP `releases:fix-names` loop runs, including the default long-running
worker mode.

**Acceptance checks:**

- Default fail-open tests for shadow validation, miss-status prepass, search
  outbox sync, and rename prepass remain unchanged.
- Fail-closed mode exits `1` before PHP commands for native commit runner
  failure, invalid successful native commit reports, failed/dead-lettered search
  outbox rows, and rename prepass failure.
- Fail-closed mode stops the default long-running worker without sleeping or
  starting another iteration.
- Fail-closed output uses bounded/redacted messages and says
  `stopping PHP worker` rather than `continuing with PHP worker`.
- The policy is scoped to `hashed-fixnames` prepasses only; disabled prepasses,
  unrelated lanes, and normal PHP command failures keep existing behavior.
- Docs and environment examples state that this does not claim full production
  replacement readiness.

**Implementation steps:**

- [x] Add failing PHP unit tests for fail-closed commit, validation, outbox,
  thrown outbox sync, long-running worker, and rename prepass failures.
- [x] Change `DistributedJobWorker` prepass helpers to return an optional native
  exit code before PHP command execution.
- [x] Add `NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_FAIL_CLOSED_ENABLED`.
- [x] Update operator docs and test-plan evidence.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'fail_closed|fail_open|enabled_native_hashed_fixnames|long_running_worker|outbox_exception'
```

Expected: the fail-closed focused tests pass while existing fail-open tests
continue to prove default compatibility behavior, including thrown outbox sync
errors and the default non-`--once` worker loop.

**Depends on:** `T40`.

## Task 42: Read-Only Native Search Document Parity Gate

**Status:** Completed.

**Description:** Add a native read-only parity gate for eligible
`hashed-fixnames` search side-effect outbox rows. The Go worker can hydrate the
release search document fields needed by the existing PHP search index path,
normalize them to the PHP document shape, and emit only deterministic
fingerprints. Eligibility mirrors the PHP outbox sync path: pending rows and
expired `processing` rows are included. This closes part of the search
side-effect ownership gap without writing Manticore/Elasticsearch, dispatching
Laravel events, or changing replacement-readiness status.

**Acceptance checks:**

- Native `searchdoc` unit tests cover PHP-shaped normalization for category
  strings, timestamps, media IDs, filename aggregation, and stable canonical
  fingerprints.
- MariaDB integration tests seed eligible `native_worker_side_effects` rows,
  hydrate the corresponding release documents, and prove the report contains
  only release IDs and SHA-256 fingerprints.
- `nntmux-worker --dry-run --search-document-parity --output=json` reads
  eligible `hashed-fixnames` / `release-search-sync` outbox rows and emits
  `search_documents` with `writes: 0`.
- The parity report suppresses raw write-contract payload details so output
  does not expose DSNs, physical Redis keys, command arguments, release names,
  posters, or filenames.
- `--search-document-parity` requires `--dry-run`, `--mysql-dsn`, the
  `hashed-fixnames` job, and hashed fix-name planner commands. It cannot be
  combined with write rehearsal.
- `--require-replacement-ready` still fails for the full `hashed-fixnames`
  catalog because native search writes, event dispatch, rename/category
  ownership, and unsupported methods remain blocked.

**Implementation steps:**

- [x] Add failing Go unit and integration tests for the native search document
  normalizer, fingerprint report, and CLI parity output.
- [x] Implement `native/internal/searchdoc` as a read-only DB hydration and
  fingerprint package.
- [x] Wire `--search-document-parity` and `--search-document-limit` into the
  existing native worker dry-run report.
- [x] Keep parity output aggregate/fingerprint-only and reject parity plus
  `--rehearse-writes`.
- [x] Update operator docs and test-plan evidence.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/searchdoc -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/searchdoc -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run TestRunPrintsSearchDocumentParityForPendingNativeOutboxRows -count=1
```

Expected: package tests prove PHP-shaped document normalization and sanitized
fingerprints; the CLI integration test proves eligible outbox rows produce a
read-only `search_documents` JSON report while table fingerprints remain
unchanged.

**Depends on:** `T41`.

## Task 43: PHP-Owned Search Outbox Claim Hardening

**Status:** Completed.

**Description:** Harden the PHP-owned native search side-effect outbox so
successful rows do not retain stale claim leases and stale workers cannot
overwrite a newer claim or completion after a lease expires. This preserves the
existing PHP-owned search side-effect boundary and still does not move search
writes into Go.

**Acceptance checks:**

- A successfully synced outbox row clears `available_at`, clears
  `last_error_code`, sets `processed_at`, and remains excluded from future
  pending scans by `status='synced'`.
- Terminal updates for `synced`, retryable `pending`, and `failed` require the
  same claimed attempt number and `status='processing'`.
- If another worker reclaims or completes the row while an older claimant is
  still running, the older claimant cannot mark the row `synced`, retryable, or
  failed.
- Unit tests cover stale success and stale failure races without leaking
  backend error text.
- The live Manticore smoke covers the durable `--pending-outbox` command path,
  verifies `releases_rt` is updated after a DB-side release mutation, and
  asserts the synced outbox row has no lingering claim lease.

**Implementation steps:**

- [x] Add failing PHP unit tests for synced lease cleanup and stale claimant
  overwrite attempts.
- [x] Carry the claimed attempt number through PHP outbox processing and require
  it on terminal row updates.
- [x] Add a live Manticore pending-outbox smoke for
  `nntmux:native-search-side-effects:sync --pending-outbox`.
- [x] Update operator docs and test-plan evidence.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php
docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm php-search-test ./vendor/bin/phpunit tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php --filter pending_native_search_outbox
```

Expected: PHP unit/feature tests prove bounded retry, dead-letter, lease
cleanup, and stale-claim protection; the Manticore smoke proves pending outbox
sync updates the real `releases_rt` document and leaves the row `synced` with
no active lease.

**Depends on:** `T42`.

## Task 44: Structural Native Failure Output Redaction

**Status:** Completed.

**Description:** Harden PHP worker logging for native shadow validation,
miss-status prepass, rename prepass, and outbox failure diagnostics. Native
stderr/stdout is treated as hostile diagnostic text: it is structurally
sanitized for DSNs, Redis keys, lock owners, command arguments, and
release/search-name fields before the final bounded log message is emitted.

**Acceptance checks:**

- Native failure logs redact exact configured MySQL DSNs and Redis addresses.
- CLI flag forms such as `--mysql-dsn`, `--redis-addr`, and `--lock-owner` are
  redacted even when emitted by the native process.
- JSON-style `redis_key`, `lock_owner`, DSN/Redis address fields,
  `arguments`, and release metadata fields such as `old_name`, `new_name`,
  `searchname`, `filename`, and `fromname` are redacted.
- Redis physical key prefixes (`nntmux_database...`) and DSN fragments are
  redacted even when runner output was already truncated before worker logging.
- Allowed diagnostics remain: phase, lane, exit code, fail-open/fail-closed
  action, and high-level validation text.

**Implementation steps:**

- [x] Add a failing commit-prepass test for structured native output leaks.
- [x] Add a failing shadow-validation test for already-truncated native output
  fragments.
- [x] Add rename-prepass and outbox-exception coverage for the same structural
  redaction rules.
- [x] Extend `DistributedJobWorker` native message limiting to structurally
  redact sensitive patterns before byte bounding.
- [x] Update operator docs and test-plan evidence.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'redacts_structured_native_output|truncated_native_output_fragments'
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php
```

Expected: focused redaction tests prove structured and partial native output is
sanitized across shadow validation, miss-status prepass, rename prepass, and
outbox exception diagnostics. The full worker unit suite keeps existing
fail-open/fail-closed behavior intact.

**Depends on:** `T43`.

## Task 45: Final Review Hardening

**Status:** Completed.

**Description:** Address final review findings from the native-lanes closeout
pass before treating the branch as release-candidate quality.

**Acceptance checks:**

- Native `--commit-miss-status` execution is scoped to the `hashed-fixnames`
  catalog lane and rejects `metadata-refresh`, even though that lane can still
  dry-run hashed fix-name planner commands.
- MariaDB-backed Go integration tests that reset shared fixture tables use the
  shared MySQL advisory schema lock, so broad `go test ./...` runs cannot race
  on table creation.
- `nntmux:native-write-contract:resolve` enforces the same 1 MiB input cap as
  the other native JSON handoff commands.
- PHP-owned rename apply failures after earlier successful releases report
  applied release IDs without leaking release names or backend exception text.
- PHPStan runs on the changed native PHP surfaces with a prepared analysis
  SQLite settings database instead of failing during Larastan bootstrap.

**Implementation steps:**

- [x] Add a failing CLI test proving `metadata-refresh` cannot run
  `--commit-miss-status`.
- [x] Add the `hashed-fixnames` lane guard before native commit safety checks.
- [x] Align the search-document integration test with the shared schema lock.
- [x] Add oversized-input coverage and bounded reading to
  `nntmux:native-write-contract:resolve`.
- [x] Add partial rename-apply failure coverage and applied-ID reporting.
- [x] Resolve the PHPStan no-op finding in `NativeHashedFixNameSearchSync`.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'CommitMissStatus|EnvironmentFallbacks' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/searchdoc -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Feature/Console/NativeWriteContractResolveCommandTest.php tests/Unit/Distributed/NativeHashedFixNameRenameApplierTest.php tests/Feature/Console/NativeHashedFixNameRenameApplyCommandTest.php
docker compose -f docker-compose.native-test.yml run --rm -e DB_CONNECTION=sqlite -e DB_DATABASE=/var/www/html/storage/phpstan-native-lanes.sqlite php-test ./vendor/bin/phpstan analyse app/Services/Distributed/NativeWorkerCommitRunner.php app/Services/Distributed/NativeHashedFixNameRenamePrepassRunner.php app/Services/Distributed/DistributedJobWorker.php app/Services/NameFixing/NativeHashedFixNameRenameApplier.php app/Services/NameFixing/NativeSearchSideEffectOutboxSync.php app/Services/NameFixing/NativeHashedFixNameWriteContractResolver.php app/Services/NameFixing/NativeHashedFixNameSearchSync.php app/Console/Commands/ApplyNativeHashedFixNameRenames.php app/Console/Commands/ResolveNativeWriteContract.php app/Console/Commands/SyncNativeSearchSideEffects.php config/nntmux.php --memory-limit=2G
```

Expected: focused post-review gates pass, and the branch-level validation below
continues to pass.

**Depends on:** `T44`.

## Task 46: Native Per-Group Lane Execution Bridge

**Status:** Completed.

**ID:** `T46`

**Files:**
- Modify: `native/cmd/nntmux-worker/main.go`
- Modify: `native/internal/laneexec/legacy_command.go`
- Modify: `native/internal/testdb/first_lane_fixtures.go`
- Modify: `native/cmd/nntmux-test-fixture/main.go`
- Modify: `app/Services/Distributed/DistributedJobWorker.php`
- Test: `native/internal/laneexec/legacy_command_test.go`
- Test: `native/cmd/nntmux-worker/main_integration_test.go`
- Test: `native/internal/testdb/first_lanes_integration_test.go`
- Test: `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- Test: `tests/Feature/Console/NativeWorkerLaneExecutionSmokeTest.php`
- Modify: `native/scripts/verify-php-native-first-lanes-smoke.sh`
- Modify: `docs/native-worker-lanes-test-plan.md`
- Modify: `docs/distributed-workers.md`

**Description:** Move the `per-group` lane from planner-only coverage to the
same opt-in native execution bridge used by the first executable lanes. Go
still builds the queue from MariaDB and validates the held Laravel Redis lock;
leaf side effects remain owned by `php artisan group:update-all {groupId}`.

**Acceptance criteria:**
- `nntmux-worker --plan catalog/per-group.json --run-lane ... --lock-mode=held`
  validates the `multiprocessing:update-per-group` command envelope, builds the
  active/backfill group queue, maps `update_per_group {id}` to
  `php artisan group:update-all {id}`, and reports a successful native lane.
- Direct `--run-lane` still rejects unsupported worker lanes before planning.
- PHP `DistributedJobWorker` hands `per-group` to `NativeWorkerLaneRunner` only
  when `NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED=true`; native success skips
  the PHP command loop for that cycle.
- The reusable native test fixture CLI can seed `per-group` queue rows for the
  PHP-orchestrated native lane smoke.
- Docs distinguish native queue selection/dispatch from PHP-owned
  `group:update-all` side effects.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/laneexec ./cmd/nntmux-worker ./cmd/nntmux-test-fixture
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/pergroup ./internal/testdb -run 'PerGroup|FirstLaneFixturesSeedQueueRows' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PerGroup' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Feature/Console/NativeWorkerPlanCommandTest.php tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'NativeWorkerPlanCommandTest|NativeWorkerLaneRunnerTest|native_lane_execution'
```

Expected: focused Go/PHP gates pass; the per-group native lane integration
reports five dispatched commands and no failed leaf commands.

**Depends on:** `T37`.

### Task 47: Native Postprocess Lane Execution Bridge

**Status:** Completed.

**Goal:** Move the complete postprocess planner lanes (`post-tv`,
`post-movies`, and `post-amazon`) from dry-run queue planning to opt-in
native-dispatched execution while preserving PHP ownership of metadata and
release side effects.

**Files:**

- `native/cmd/nntmux-worker/main.go`
- `native/internal/laneexec/legacy_command.go`
- `native/internal/laneexec/legacy_command_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- `--run-lane` now accepts `post-tv`, `post-movies`, and `post-amazon` when
  their exported command envelopes contain `multiprocessing:postprocess`.
- Native postprocess dry-run plans are converted into executable leaf commands:
  `postprocess:tv-pipeline {bucket} {renamedMode} --mode=pipeline` and
  `postprocess:guid {type} {bucket}`.
- Lane execution concurrency defaults to the max planned postprocess thread
  count unless `--lane-max-processes` is supplied.
- Laravel's disabled-by-default native lane handoff allowlist includes the same
  three postprocess lanes.
- `post-additional` was kept out of this first postprocess bridge because its
  full lane can include deferred metadata-refresh and hashed fix-name commands;
  Task 50 adds a separate guarded bridge for its add/NFO subset.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/laneexec -run 'ParseLegacyCommand' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Post(TV|Movies|Amazon)NativeLaneQueue' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'post_tv|native_lane_execution_is_scoped'
```

Expected: parser accepts postprocess leaf command strings; fake-Artisan
integration dispatches all planned TV/anime, movie, and Amazon bucket commands
under a held Redis worker lock; PHP worker hands `post-tv` to native and does
not fall back to the original Artisan loop.

**Depends on:** `T15`, `T37`.

### Task 48: Native RemoveCrap Lane Execution Bridge

**Status:** Completed.

**Goal:** Move `removecrap` from SQL-only candidate planning to opt-in
native-dispatched execution while preserving PHP ownership of destructive
cleanup side effects.

**Files:**

- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- `--run-lane` now accepts `removecrap` when the exported command envelope
  contains `releases:remove-crap` commands.
- Native converts each cleanup request into an explicit Artisan command spec
  preserving `--type`, `--time`, and `--delete` flags.
- Cleanup command dispatch defaults to serial execution unless
  `--lane-max-processes` is supplied.
- Laravel's disabled-by-default native lane handoff allowlist includes
  `removecrap`.
- Native still only plans/counts SQL-supported cleanup families; PHP
  `releases:remove-crap` owns deletions, search updates, file deletion, and
  collection unlinking.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'RemoveCrapNativeLaneCommands' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'removecrap'
```

Expected: fake-Artisan integration dispatches the planned gibberish and
executable cleanup commands under a held Redis worker lock; PHP worker hands
`removecrap` to native and does not fall back to the original Artisan loop.

**Depends on:** `T6`, `T37`.

### Task 49: Native Fixnames and IRC Lane Execution Bridge

**Status:** Completed.

**Goal:** Move regular `fixnames` and `irc` from command-envelope reporting to
opt-in native-dispatched execution while preserving PHP ownership of rename,
IRC, and downstream side effects.

**Files:**

- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- `--run-lane` now accepts `fixnames` when the exported command envelope
  contains only `releases:fix-names` commands.
- Native converts each regular fix-name envelope into an explicit Artisan
  command spec preserving method, category, limit, update, set-status, and show
  flags.
- `--run-lane` now accepts `irc` when the exported command envelope contains
  only the no-argument `irc:scrape` command.
- These command-only lanes can run under a held Redis worker lock without
  opening MariaDB; dry-run reporting with `--mysql-dsn` remains unsupported for
  the regular `fixnames` report.
- Laravel's disabled-by-default native lane handoff allowlist includes
  `fixnames` and `irc`.
- Laravel's native lane runner omits DSN validation, `--mysql-dsn-env`, and
  `NNTMUX_NATIVE_MYSQL_DSN` for those command-only lanes.
- PHP still owns regular release rename/category/event/search side effects,
  name-fixing services, IRC socket/session behavior, `predb` writes, and search
  updates.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'FixnamesNativeLaneCommands|IrcNativeLaneCommand' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'skips_php_commands_for_fixnames|skips_php_commands_for_irc'
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php --filter 'command_only_.*without_mysql_dsn'
```

Expected: fake-Artisan integration dispatches all planned regular fix-name
commands and the IRC scraper command under held Redis worker locks; PHP worker
hands both lanes to native and does not fall back to the original Artisan loop.

**Depends on:** `T38`, `T39`, `T37`.

### Task 50: Guarded Native Post-Additional Lane Execution Bridge

**Status:** Completed.

**Goal:** Move `post-additional` add/NFO bucket work from planner-only to
opt-in native-dispatched execution without silently skipping embedded
metadata-refresh or hashed-fixname commands.

**Files:**

- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/DistributedJobWorker.php`
- `app/Services/Distributed/NativeWorkerLaneRunner.php`
- `config/nntmux.php`
- `.env.example`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- `--run-lane` now accepts `post-additional` when the exported command envelope
  contains add/NFO postprocess commands.
- Mixed `post-additional` plans containing deferred metadata-refresh or
  native-supported hashed-fixname commands require
  `--allow-deferred-post-additional`.
- Native dispatches only the planned add/NFO `postprocess:guid` leaf commands;
  deferred metadata-refresh and hashed-fixname commands are not dispatched by
  this bridge.
- Laravel only hands `post-additional` to native when both
  `NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED=true` and
  `NNTMUX_NATIVE_WORKER_POST_ADDITIONAL_DEFERRED_EXECUTION_ENABLED=true`.
- The PHP native lane runner passes `--allow-deferred-post-additional` only for
  guarded `post-additional` runs.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'PostAdditionalNativeLaneWithoutDeferredGuard' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostAdditionalNativeLaneQueue' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php --filter 'post_additional|PostAdditional'
```

Expected: direct native execution refuses mixed post-additional plans without
the guard, fake-Artisan integration dispatches only add/NFO bucket commands
under a held Redis worker lock, and PHP hands the lane to native only when the
separate deferred-command guard is enabled.

**Depends on:** `T35`, `T47`.

### Task 51: Native Metadata-Refresh and Hashed-Fixnames Lane Execution Bridge

**Status:** Completed.

**Goal:** Move the remaining command-envelope catalog lanes,
`metadata-refresh` and `hashed-fixnames`, into the same opt-in native-dispatched
execution path without claiming native ownership of metadata provider fetches,
rename writes, events, or search side effects.

**Files:**

- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/DistributedJobWorker.php`
- `app/Services/Distributed/NativeWorkerLaneRunner.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- `--run-lane` now accepts `metadata-refresh` when the exported command envelope
  contains `predb:refresh-external-metadata` and hashed `releases:fix-names`
  leaf commands.
- `--run-lane` now accepts `hashed-fixnames` when the exported command envelope
  contains only hashed `releases:fix-names` leaf commands.
- Both lanes are command-only native lane executions: direct and PHP-orchestrated
  runs require Redis lock ownership but do not require a native MySQL DSN.
- Laravel includes both lanes in the disabled-by-default
  `NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED` handoff. Existing hashed-fixnames
  native prepasses still run before the lane handoff when configured.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'MetadataRefreshNativeLaneCommands|HashedFixnamesNativeLaneCommands' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php --filter 'metadata_refresh|hashed_fixnames.*skips|command_only_.*(metadata|hashed)'
```

Expected: fake-Artisan integration dispatches the metadata refresh command, the
strong hashed fix-name commands, and the full hashed-fixnames command matrix
under held Redis worker locks. PHP hands both lanes to native when global lane
execution is enabled, omits the native MySQL DSN, and skips the original PHP
command loop after a validated native success.

Compose eval evidence:

- `native/scripts/audit-native-eval-all-workers.sh` resolves all 13 catalog
  lanes as enabled and validates each native dry-run JSON report.
- `native/scripts/run-native-eval-all-workers.sh` executes all 13 catalog lanes
  through the PHP-orchestrated native lane handoff and verifies no
  distributed-worker Redis locks remain.

**Depends on:** `T6`, `T7`, `T38`, `T41`.

### Task 52: Native RemoveCrap Committed Test Write Proof

**Status:** Completed.

**Goal:** Move `removecrap` one step past rollback-only rehearsal by proving the
native delete subset can commit in an explicitly guarded native-test schema
under the exported Redis worker lock.

**Files:**

- `native/internal/removecrap/write_rehearsal.go`
- `native/internal/removecrap/plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- `removecrap.CommitRemoveCrapWrites` commits the same linked
  `collections`, `release_files`, and `releases` delete subset previously
  covered by rollback-only rehearsal.
- `nntmux-worker --commit-lane-writes` now accepts `removecrap` in addition to
  `binaries`, `backfill`, and `releases`.
- The committed path keeps the existing native-test schema guard, committed-test
  guard, and Redis worker lock requirement.
- JSON output reports only aggregate affected-row counts and committed writes.
- Production replacement readiness remains blocked because release deletion
  event dispatch and full PHP cleanup side effects are not owned by native.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/removecrap -run 'CommitRemoveCrap|RehearseRemoveCrap' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'RemoveCrap.*(WriteRehearsal|Commits)' -count=1
```

Expected: rollback-only rehearsal leaves the fixture unchanged, committed
removecrap writes mutate only the native-test schema under the Redis worker
lock, and JSON output does not expose DSNs, Redis keys, release identifiers, or
raw command arguments.

**Depends on:** `T27`, `T48`.

### Task 53: Native Postprocess Committed Test Write Proof

**Status:** Completed.

**Goal:** Move executable postprocess lanes one step past rollback-only
rehearsal by proving the native bucket-update subset can commit in an
explicitly guarded native-test schema under the exported Redis worker lock.

**Files:**

- `native/internal/postprocess/write_rehearsal.go`
- `native/internal/postprocess/plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- `postprocess.CommitPostprocessWrites` commits the same representative
  release-row bucket updates previously covered by rollback-only rehearsal.
- `nntmux-worker --commit-lane-writes` now accepts executable postprocess
  lanes: `post-tv`, `post-movies`, `post-amazon`, and guarded
  `post-additional`.
- The committed path keeps the existing native-test schema guard,
  committed-test guard, and Redis worker lock requirement.
- Mixed `post-additional` plans still require
  `--allow-deferred-post-additional` before committing only the add/NFO subset.
- JSON output reports aggregate bucket/update/write counts plus
  `committed_release_ids` for committed postprocess rows, giving PHP the
  release set it uses for the existing search-index side-effect handoff.
- Production replacement readiness remains blocked because metadata providers,
  NZB/NFO reads, Laravel events, and full release side effects are not owned by
  native.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/postprocess -run 'CommitPostprocess|RehearsePostprocess' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'PostAdditional.*(DeferredGuard|WriteCommit)' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostTV.*(WriteRehearsal|Commits)' -count=1
```

Expected: rollback-only rehearsal leaves fixtures unchanged, committed
postprocess writes mutate only the native-test schema under the Redis worker
lock, mixed post-additional commit refuses to skip deferred commands without the
explicit guard, committed postprocess JSON includes only numeric release IDs for
changed rows, and JSON output does not expose DSNs, Redis keys, release names,
GUIDs, buckets, or raw command arguments.

**Depends on:** `T34`, `T35`, `T46`, `T47`, `T50`.

### Task 54: Native Metadata-Refresh Committed Test Write Proof

**Status:** Completed.

**Goal:** Move the metadata-refresh MariaDB write subset past rollback-only
rehearsal by proving representative `predb` and `predb_crcs` writes can commit
in an explicitly guarded native-test schema under the exported Redis worker
lock.

**Files:**

- `native/internal/metadata/write_rehearsal.go`
- `native/internal/metadata/refresh_plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- `metadata.CommitMetadataRefreshWrites` commits the same representative
  `predb` and `predb_crcs` write subset previously covered by rollback-only
  rehearsal.
- `nntmux-worker --commit-lane-writes` now accepts `metadata-refresh` when the
  exported plan contains `predb:refresh-external-metadata`.
- The committed path keeps the existing native-test schema guard,
  committed-test guard, and Redis worker lock requirement.
- JSON output reports only aggregate candidate, affected-row, and committed
  write counts.
- Production replacement readiness remains blocked because external provider
  fetches, release updates, Laravel events, search updates, hashed fix-name
  subcommands, and full metadata side effects are not owned by native.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/metadata -run 'CommitMetadataRefresh|RehearseMetadataRefresh' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'MetadataRefresh.*(WriteCommit|RefreshCommand)' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'MetadataRefresh.*(WriteRehearsal|Commits)' -count=1
```

Expected: rollback-only rehearsal leaves fixtures unchanged, committed
metadata-refresh writes mutate only the native-test schema under the Redis
worker lock, commit mode rejects metadata-refresh plans without the refresh
command, and JSON output does not expose DSNs, Redis keys, CRCs, release file
names, queries, or raw command arguments.

**Depends on:** `T7`, `T10`, `T41`, `T51`.

### Task 55: Native Per-Group Committed Test Write Proof

**Status:** Completed.

**Goal:** Move the per-group MariaDB queue-row subset past planner-only
coverage by proving representative `usenet_groups.last_updated` updates can
commit in an explicitly guarded native-test schema under the exported Redis
worker lock.

**Files:**

- `native/internal/pergroup/write_rehearsal.go`
- `native/internal/pergroup/plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- `pergroup.RehearsePerGroupWrites` and `pergroup.CommitPerGroupWrites` update
  the queued active/backfill groups' `last_updated` timestamp as a
  representative native-test write subset.
- `nntmux-worker --commit-lane-writes` now accepts `per-group` when the
  exported plan contains `multiprocessing:update-per-group`.
- The committed path keeps the existing native-test schema guard,
  committed-test guard, and Redis worker lock requirement.
- JSON output reports only aggregate queued-group, affected-row, rollback, and
  committed write counts.
- Production replacement readiness remains blocked because `group:update-all`
  still owns header downloads, backfill, release creation, post-processing,
  NNTP, file/NZB writes, release mutations, events, and search side effects.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/pergroup -run 'PerGroup|Commit|Rehearse' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'PerGroup.*(WriteCommit|Command)' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PerGroup.*(JSONReport|Commits|MariaDB|NativeLane)' -count=1
```

Expected: rollback-only rehearsal leaves fixtures unchanged, committed
per-group writes mutate only the native-test schema under the Redis worker
lock, commit mode rejects unsupported per-group command envelopes before DB
access, and JSON output does not expose DSNs, Redis keys, group names, group
IDs, raw command strings, or command arguments.

**Depends on:** `T33`, `T47`.

### Task 56: PHP-Orchestrated Native Lane Commit Handoff

**Status:** Completed.

**Goal:** Move guarded native-test lane commits beyond direct Go CLI usage by
letting the PHP distributed worker invoke `--commit-lane-writes` for every
native commit-capable catalog lane while it owns the Laravel worker lock.

**Files:**

- `app/Services/Distributed/DistributedJobWorker.php`
- `config/nntmux.php`
- `.env.example`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php`
- `docs/distributed-workers.md`

**Changes:**

- Added `NNTMUX_NATIVE_WORKER_LANE_COMMIT_ENABLED` as the generalized guarded
  lane commit switch, with `NNTMUX_NATIVE_WORKER_FIRST_LANE_COMMIT_ENABLED`
  preserved as a compatibility alias.
- `DistributedJobWorker` now invokes `NativeWorkerCommitRunner::commitLaneWrites`
  for `binaries`, `backfill`, `releases`, `per-group`, `removecrap`,
  `metadata-refresh`, `post-tv`, `post-movies`, `post-amazon`, and guarded
  `post-additional`.
- Native commit report validation now maps each lane to its real JSON commit
  key, including hyphenated lanes (`per_group_write_commit`,
  `metadata_refresh_write_commit`) and postprocess lanes'
  shared `postprocess_write_commit` key.
- `NativeWorkerCommitRunner` passes `--allow-deferred-post-additional` for
  guarded `post-additional` lane commits, and `DistributedJobWorker` continues
  with the deferred metadata-refresh and hashed fix-name PHP commands after a
  successful native add/NFO commit.
- The PHP worker still requires the existing native-test DB guard,
  committed-test guard, and held Redis worker lock through
  `NativeWorkerCommitRunner`; this remains a Compose/native-test proof path,
  not production replacement mode.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'post_additional.*(commit|lane_execution)|native_.*lane_commit|native_first_lane_commit'
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php
docker compose -f docker-compose.native-test.yml run --rm php-test php -l app/Services/Distributed/DistributedJobWorker.php
native/scripts/verify-php-native-first-lane-commit-smoke.sh
native/scripts/verify-php-native-lane-commit-smoke.sh
```

Expected: first-lane commit still works through the compatibility alias,
the PHP-orchestrated smoke proves `binaries`, `backfill`, and `releases`
skip the PHP command loop after committed native writes, the broader
PHP-orchestrated smoke proves every current commit-capable lane reaches the
same validated handoff, per-group lane commits skip the PHP command loop after
validating `per_group_write_commit`, postprocess lane commits validate
`postprocess_write_commit`, guarded `post-additional` commits continue with only
deferred PHP commands, and native commit connection/secret handling remains
environment-only.

**Depends on:** `T23`, `T52`, `T53`, `T54`, `T55`.

### Task 57: Compose Native Worker Service Deploy Proof

**Status:** Completed.

**Goal:** Prove the deployable `native-workers` Compose profile can execute
every catalog worker as a one-shot service, not only through the webapp `exec`
runner.

**Files:**

- `docker-compose.native-eval.yml`
- `native/scripts/run-native-eval-compose-workers.sh`
- `native/scripts/deploy-native-eval-compose.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- The native eval compose profile declares one `native-<lane>-worker` service
  for every `DistributedJobCatalog` lane.
- `native/scripts/run-native-eval-compose-workers.sh` seeds deterministic eval
  data, configures each lane, runs the matching one-shot service, requires
  `native lane completed <lane>`, and fails if any distributed-worker lock
  remains.
- `NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS=1` wires the same service-profile
  proof into the deploy helper after readiness and native metadata-refresh
  dry-run checks.
- The catalog sync test fails if Go support, audit, eval runners, compose
  services, or PHP-orchestrated smokes omit a catalog lane.

**Verification:**

```bash
native/scripts/sync-native-eval-nntp-from-k3s.sh --mode check --kubeconfig "$HOME/k3s.yaml" --namespace media
NNTMUX_NATIVE_EVAL_RUN_ALL_WORKERS=1 native/scripts/deploy-native-eval-compose.sh
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml --profile native-workers config --services
native/scripts/run-native-eval-compose-workers.sh
NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS=1 native/scripts/deploy-native-eval-compose.sh
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T redis sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort"
```

Expected: the NNTP check reports only redacted key names, the deploy all-worker
path completes all 13 lanes, the profile lists all 13 native worker services,
each one-shot service reports `native lane completed <lane>`, the deploy-helper
compose-worker opt-in completes the same service-profile proof, no
distributed-worker Redis locks remain afterward, and the base eval services stay
healthy.

**Depends on:** `T46`, `T47`, `T48`, `T49`, `T50`, `T51`, `T56`.

### Task 58: Production Native Replacement Backlog Gate

**Status:** Completed.

**Goal:** Keep the branch honest about the remaining production replacement
scope after all native eval workers can execute. The compose proof shows that
every lane can be launched as a native-worker service, but the replacement
audit remains intentionally default-deny until each lane's PHP-owned production
side effects have a native implementation and proof.

**Files:**

- `native/scripts/audit-native-replacement-readiness.sh`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Current lane blockers:**

- `binaries`: the native test path can commit sampled overview-derived cursor,
  binary, and part rows under guard; production header acquisition, full header
  persistence, and cursor ownership remain PHP-owned.
- `backfill`: the native test path can commit sampled overview-derived cursor,
  binary, and part rows under guard; production backfill acquisition, full
  header persistence, and cursor ownership remain PHP-owned.
- `releases`: full release creation, categorization, and release-processing
  side effects remain PHP-owned.
- `fixnames`: CRC/PAR methods `15` and `19` have native discovery and guarded
  miss-status commits; remaining regular fix-name methods are deferred to PHP,
  and full rename, category, event, and direct search side effects remain
  PHP-owned.
- `hashed-fixnames`: unsupported methods plus rename, category, event, and
  search side effects remain PHP-owned.
- `removecrap`: the native test path can delete linked `collections`,
  `release_files`, and `releases` rows under guard; release deletion events
  remain PHP-owned.
- `post-additional`: the native test path can commit representative add/NFO
  bucket updates; additional/NFO provider processing, NNTP/NZB/NFO reads,
  release events, and deferred metadata-refresh/hashed-fixnames side effects
  remain PHP-owned.
- `metadata-refresh`: native owns the metadata provider fetch, PreDB write, and
  PreDB search outbox subset; embedded hashed fix-name commands are deferred to
  PHP.
- `post-tv`, `post-movies`, `post-amazon`: the native test path can commit
  representative postprocess bucket updates; metadata-provider lookups,
  NZB/NFO reads, release events, and full postprocess side effects remain
  PHP-owned.
- `irc`: native run-lane owns socket/session parsing, guarded `predb` writes,
  and the PHP-synced `predb` search side-effect outbox; live rollout proof
  remains blocking.
- `per-group`: group update, backfill, release creation, and post-processing
  side effects remain PHP-owned.

**Changes:**

- Normal JSON dry-runs now expose `native_worker.replacement_ready` and
  `native_worker.replacement_readiness.blockers` for every catalog lane, using
  the same blocker source as the hard replacement guard.
- `native/scripts/audit-native-eval-all-workers.sh` now enforces that every
  eval lane report is a zero-write dry-run with matching `native_worker.job`,
  `replacement_ready=false`, and at least one readiness blocker.
- `--require-replacement-ready` still fails before JSON reports, MariaDB
  planners, or write paths for every current lane.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'TestRunDryRunPrintsJSONReport|RequireReplacementReady' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobCatalogTest.php --filter eval_all_worker_audit
native/scripts/audit-native-eval-all-workers.sh
native/scripts/audit-native-replacement-readiness.sh
```

Expected: normal JSON dry-run reports include native-worker replacement
readiness metadata, the all-worker eval audit proves every lane reports
`replacement_ready=false` with blockers and zero writes, all 13 catalog lanes
report `replacement guard ok`, and no lane can pass
`--require-replacement-ready` until a future task replaces that lane's listed
PHP-owned side effects with native behavior and proof.

**Depends on:** `T40`, `T57`.

### Task 59: PHP-Owned Postprocess Search Side-Effect Handoff

**Status:** Completed.

**Goal:** Consume the postprocess native commit report's
`committed_release_ids` immediately after PHP validates a guarded native
postprocess commit, so committed native-test postprocess row updates also drive
the existing PHP-owned release search-index update path before the PHP command
loop is skipped.

**Files:**

- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `native/cmd/nntmux-worker/main.go`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Postprocess lane commit validation now returns the validated numeric
  `committed_release_ids` and rejects duplicate IDs.
- After successful native commits for `post-tv`, `post-movies`, `post-amazon`,
  or guarded `post-additional`, PHP calls the existing
  `ReleaseSearchIndexSync::forIds()` path for each committed release ID before
  reporting the lane commit complete.
- Search sync failures are sanitized, return a nonzero worker exit, and do not
  fall back to the PHP postprocess command loop after native writes have
  already committed.
- The generic replacement-readiness blocker text for postprocess lanes no
  longer lists search updates as an unhandled blocker for the committed
  postprocess subset; provider lookups, NZB/NFO reads, Laravel events, deferred
  commands, and full postprocess side effects remain PHP-owned blockers.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'postprocess_lane_commit|post_additional_commands_with_deferred_guard'
```

Expected: postprocess native lane commits sync the committed release IDs
through PHP search update calls before completing, search-sync failures fail the
worker without leaking backend error text, and guarded `post-additional`
continues deferred PHP commands only after the search handoff succeeds.

**Depends on:** `T53`, `T56`, `T58`.

### Task 60: PHP-Owned Releases Search Side-Effect Handoff

**Status:** Completed.

**Goal:** Consume native `releases` commit reports' inserted release IDs so the
guarded native-test release-row creation proof also drives the existing
PHP-owned release search-index update path before the PHP releases command loop
is skipped.

**Files:**

- `native/internal/releases/write_rehearsal.go`
- `native/internal/releases/write_rehearsal_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/scripts/audit-native-replacement-readiness.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Native release commit results now report `committed_release_ids` for inserted
  release rows, while rollback-only rehearsals omit committed IDs.
- PHP lane commit validation requires `releases_write_commit.committed_release_ids`
  and checks that the count matches `release_rows_affected`, not total
  `writes_committed`, because collection-link rows are counted separately.
- After a successful guarded native `releases` commit, PHP runs
  `ReleaseSearchIndexSync::forIds()` for the committed release IDs before
  logging the lane commit complete.
- Search-sync failures return a nonzero worker exit and do not fall back to the
  PHP releases command loop after native writes have committed.
- The generic `releases` readiness blocker no longer lists search updates as an
  unhandled blocker for the committed subset; full release creation,
  categorization, and release-processing side effects remain PHP-owned
  production blockers.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/releases -run 'CommitRelease|RehearseRelease' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'syncs_search_for_committed_releases'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run '^TestRunCommitsReleasesWritesWithRedisLock$' -count=1
```

Expected: native release commits report inserted release IDs, PHP syncs those
IDs through the existing search-index path before skipping the PHP releases
command, and the direct native JSON report keeps DSNs, Redis keys, group names,
group IDs, and command details out of output.

**Depends on:** `T36`, `T53`, `T56`, `T58`.

### Task 61: PHP-Owned RemoveCrap Search Delete Handoff

**Status:** Completed.

**Goal:** Consume native `removecrap` commit reports' deleted release IDs so
guarded native-test release-row deletes also remove the corresponding search
documents before the PHP removecrap command loop is skipped.

**Files:**

- `native/internal/removecrap/write_rehearsal.go`
- `native/internal/removecrap/plan_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/scripts/audit-native-replacement-readiness.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Native removecrap commit results now report `deleted_release_ids` only after
  a successful commit; rollback-only rehearsals omit deleted IDs.
- PHP lane commit validation requires
  `removecrap_write_commit.deleted_release_ids` and checks that the count
  matches `release_rows_affected`.
- After a successful guarded native `removecrap` commit, PHP calls
  `Search::deleteRelease()` for each deleted release ID before logging the
  lane commit complete.
- Search-delete failures return a nonzero worker exit and do not fall back to
  the PHP removecrap command loop after native writes have committed.
- The generic `removecrap` readiness blocker no longer lists search deletion
  as unhandled for the committed subset. A later task also hands off
  descendant collection cleanup, and Task 63 hands off NZB/image cleanup;
  production commit still requires live rollout proof before replacement
  readiness.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/removecrap -run 'RehearseRemoveCrap|CommitRemoveCrap' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'removecrap_lane_commit_deletes_search'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run '^TestRunCommitsRemoveCrapWritesWithRedisLock$' -count=1
```

Expected: native removecrap commits report one deleted release ID per deleted
release row, PHP deletes those search documents before skipping the PHP
removecrap command loop, and the direct native JSON report keeps DSNs, Redis
keys, command arguments, GUIDs, and release names out of output.

**Depends on:** `T15`, `T48`, `T56`, `T58`.

### Task 62: PHP-Owned RemoveCrap Descendant Collection Cleanup Handoff

**Status:** Completed.

**Goal:** Consume native `removecrap` commit reports' deleted collection IDs
so guarded native-test collection-row deletes also drive the existing
PHP-owned descendant cleanup path for `parts` and `binaries` before the PHP
removecrap command loop is skipped.

**Files:**

- `native/internal/removecrap/write_rehearsal.go`
- `native/internal/removecrap/plan_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/scripts/audit-native-replacement-readiness.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Native removecrap commit results now report `deleted_collection_ids` only
  after a successful commit; rollback-only rehearsals omit deleted collection
  IDs.
- PHP lane commit validation requires
  `removecrap_write_commit.deleted_collection_ids` and checks that the count
  matches `collection_rows_affected`.
- After a successful guarded native `removecrap` commit and search deletion,
  PHP calls `CollectionCleanupService::deleteCollectionsAndDescendants()` for
  the deleted collection IDs before logging the lane commit complete.
- Collection cleanup failures return a nonzero worker exit and do not fall
  back to the PHP removecrap command loop after native writes have committed.
- The generic `removecrap` readiness blocker no longer lists descendant
  collection cleanup as unhandled for the committed subset; Task 63 hands off
  NZB/image cleanup, and production commit still requires live rollout proof
  before replacement readiness.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/removecrap -run 'RehearseRemoveCrap|CommitRemoveCrap' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'removecrap_lane_commit_deletes_search'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run '^TestRunCommitsRemoveCrapWritesWithRedisLock$' -count=1
```

Expected: native removecrap commits report one deleted collection ID per
deleted collection row, PHP runs descendant cleanup for those collection IDs
after search deletion and before skipping the PHP removecrap command loop, and
the direct native JSON report keeps DSNs, Redis keys, command arguments, GUIDs,
and release names out of output.

**Depends on:** `T15`, `T48`, `T56`, `T58`, `T61`.

### Task 63: Removecrap File Cleanup Side-Effect Handoff

**Status:** Done

**Goal:** Consume native `removecrap` commit reports' release file cleanup
side-effect rows so guarded native deletes mirror the ReleaseObserver
NZB/image cleanup behavior without leaking GUIDs in native JSON output.

**Files:**

- `native/internal/removecrap/write_rehearsal.go`
- `native/internal/removecrap/plan_test.go`
- `native/internal/testdb/removecrap_fixtures.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `native/scripts/audit-native-replacement-readiness.sh`
- `database/migrations/2026_06_15_000000_create_native_worker_side_effects_table.php`
- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Native removecrap commits now enqueue one internal
  `native_worker_side_effects` row per deleted release with
  `effect=release-file-cleanup` and the release GUID stored in `payload_text`.
- The direct native JSON commit report exposes only
  `release_file_cleanup_rows_enqueued`; it does not expose GUIDs, release
  names, command arguments, DSNs, or Redis lock internals.
- PHP lane commit validation requires the cleanup enqueue count to match
  `release_rows_affected`.
- After search deletion and descendant collection cleanup, PHP consumes the
  matching pending cleanup rows for the deleted release IDs, calls
  `NzbService::deleteNzb()` and `ReleaseImageService::delete()`, and marks the
  rows `synced` before it can skip the PHP removecrap command loop.
- The generic `removecrap` readiness blocker no longer lists NZB/image deletion
  as unhandled for the committed subset; production commit still requires live
  rollout proof before replacement readiness.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run '^TestRunCommitsRemoveCrapWritesWithRedisLock$' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'removecrap_lane_commit_deletes_search'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/removecrap -run 'RehearseRemoveCrap|CommitRemoveCrap' -count=1
```

Expected: native removecrap commits enqueue one release-file cleanup row per
deleted release row, PHP consumes and marks those rows synced after using the
existing NZB/image deletion services, and native JSON output still keeps GUIDs
and internal execution details out of the report.

**Depends on:** `T15`, `T48`, `T56`, `T58`, `T61`, `T62`.

### Task 64: Removecrap Readiness Blocker Reclassification

**Status:** Done

**Goal:** Keep `removecrap` replacement readiness fail-closed while naming the
real remaining blocker after search deletion, descendant cleanup, and
NZB/image cleanup have PHP handoffs.

**Files:**

- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/scripts/audit-native-replacement-readiness.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Audited the Laravel release deletion event surface. `Release::observe()`
  registers `ReleaseObserver`; its delete-time side effects are search
  deletion plus NZB/image cleanup, both now covered by the native commit PHP
  handoff.
- Replaced the stale generic `removecrap` blocker
  `release deletion events remain PHP-owned` with
  `removecrap production commit requires live rollout proof`.
- Kept `--require-replacement-ready` fail-closed for `removecrap`; this task
  only corrects blocker evidence and does not claim production replacement
  readiness.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run '^TestRunRequireReplacementReadyRejectsCatalogLanesWithoutExplicitReadiness$' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobCatalogTest.php --filter 'replacement_readiness_audit'
native/scripts/audit-native-replacement-readiness.sh
```

Expected: all catalog lanes still fail `--require-replacement-ready` with
bounded blocker text, and `removecrap` names missing live rollout proof as the
remaining lane-specific blocker.

### Task 65: Removecrap Production Commit Opt-In

**Status:** Done

**Goal:** Allow only the `removecrap` native commit path to target a production
DSN behind an explicit lane-scoped opt-in, while keeping replacement readiness
fail-closed until live rollout proof exists.

**Files:**

- `config/nntmux.php`
- `app/Services/Distributed/NativeWorkerCommitRunner.php`
- `native/internal/safety/mysql.go`
- `native/internal/safety/mysql_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`
- `.env.example`

**Changes:**

- Added `NNTMUX_NATIVE_WORKER_REMOVECRAP_PRODUCTION_COMMIT_ENABLED=false` as a
  disabled-by-default PHP config switch.
- When that switch is enabled for a `removecrap` commit plan, PHP skips the
  native-test DSN/env guard and passes
  `NNTMUX_NATIVE_ALLOW_PRODUCTION_COMMIT=removecrap` to the Go worker.
- Go accepts production commit mode only for job `removecrap` with the exact
  lane-scoped env value, validates that the DSN database matches
  `SELECT DATABASE()`, and keeps all other lanes on the native-test commit
  guard.
- `removecrap` replacement readiness still fails closed with
  `removecrap production commit requires live rollout proof`.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php --filter 'removecrap_production_lane_commit'
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/safety -run '^TestAllowsProductionCommitOnlyForExplicitRemoveCrapOptIn$' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'TestRunCommitsRemoveCrapWritesWith(ProductionOptIn|RedisLock)$' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run '^TestRunRequireReplacementReadyRejectsCatalogLanesWithoutExplicitReadiness$' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobCatalogTest.php --filter 'replacement_readiness_audit'
native/scripts/audit-native-replacement-readiness.sh
```

Expected: `removecrap` can run the production opt-in path without native-test
guard variables, `binaries` and other lanes cannot reuse that opt-in, and
replacement readiness remains fail-closed until live rollout evidence exists.

### Task 66: Native NNTP Group Probe for First Lanes

**Status:** Done

**Goal:** Give native `binaries` and `backfill` dry-runs a real, opt-in NNTP
provider connectivity check before implementing header fetching, while keeping
default planning read-only and replacement readiness fail-closed.

**Files:**

- `native/internal/nntp/config.go`
- `native/internal/nntp/probe.go`
- `native/internal/nntp/probe_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- Added a native NNTP config/client package that reads the existing NNTP
  environment, supports alternate-server suffixes, opens TCP/TLS, authenticates
  when credentials are configured, and issues `GROUP` probes.
- Added `--nntp-probe` as a dry-run-only flag for `binaries` and `backfill`.
  The worker probes the planned queue groups and reports aggregate success and
  failure counts in text and JSON output.
- Kept probe failures sanitized: reports do not include DSNs, NNTP server
  addresses, ports, credentials, command arguments, or group names.
- Left default `binaries` and `backfill` dry-runs unchanged: no NNTP connection
  happens unless `--nntp-probe` is explicitly set.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/nntp -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'NNTPProbe|RejectsNNTPProbe' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'TestRunProbes(Binaries|Backfill)NNTPGroups|TestRunPrints(Binaries|Backfill)JSONReport' -count=1
```

Expected: native proves provider authentication and planned-group visibility
for the first acquisition lanes without fetching headers, writing MariaDB rows,
or claiming native replacement readiness.

### Task 67: Native NNTP Overview Sampling for First Lanes

**Status:** Done

**Goal:** Move native `binaries` and `backfill` one step past provider
connectivity by fetching bounded NNTP overview rows for planned ranges in
dry-run mode, while keeping header persistence and cursor updates blocked.

**Files:**

- `native/internal/nntp/probe.go`
- `native/internal/nntp/probe_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- Added native NNTP overview sampling with bounded `OVER start-end` requests
  after selecting each planned group, with `XOVER` fallback for providers that
  do not support `OVER`.
- Added `--nntp-overview-sample=N` for `binaries` and `backfill`. The worker
  samples the first `N` overview rows from each planned `get_range` queue entry
  in dry-run mode; Task 70 later extends the same sampler to guarded native-test
  commit mode.
- Reports stay aggregate-only: `ranges`, `requested`, `received`, `parsed`,
  `malformed`, `bytes`, `lines`, `header_candidates`, `part_candidates`,
  `unique_message_ids`, `duplicate_message_ids`, and `failed`. Output does not
  include provider endpoints, credentials, group names, article subjects,
  message IDs, or command arguments.
- Left header/body persistence, part creation, binary creation, and group cursor
  updates out of native scope for this task.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/nntp -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'NNTPProbe|NNTPOverviewSample|RejectsNNTP' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'TestRunProbes(Binaries|Backfill)NNTPGroups' -count=1
```

Expected: native can parse bounded overview rows for both first acquisition
lanes through the real dry-run planner and fake NNTP server, without MariaDB
writes or replacement-readiness claims.

### Task 68: Overview-Derived Header Write Contract Counts

**Status:** Done

**Goal:** Make native overview sampling expose the aggregate write-contract
shape needed by the later header persistence step, without exposing raw overview
rows or writing MariaDB.

**Files:**

- `native/internal/nntp/probe.go`
- `native/internal/nntp/probe_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- Count parsed overview rows as aggregate header and part candidates.
- Count unique and duplicate Message-IDs after parsing standard overview rows.
- Keep malformed rows non-fatal and count them separately, so bad provider rows
  are visible without leaking subject or Message-ID text.
- Report the candidate counts in text and JSON dry-run output for both
  `binaries` and `backfill`.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/nntp -run 'SampleOverview' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'TestRunProbes(Binaries|Backfill)NNTPGroups' -count=1
```

Expected: the dry-run report names how many native header/part writes would be
eligible from the sampled overview rows, while raw subjects, Message-IDs,
provider endpoints, group names, and DB connection strings remain absent from
output.

### Task 69: Overview-Sampled Rollback Header Write Rehearsal

**Status:** Done

**Goal:** When native `binaries` or `backfill` dry-runs combine bounded NNTP
overview sampling with rollback-only write rehearsal, exercise the sampled
overview rows instead of synthetic representative rows while preserving
aggregate-only output and unchanged MariaDB state.

**Files:**

- `native/internal/nntp/probe.go`
- `native/internal/binaries/write_rehearsal.go`
- `native/internal/binaries/write_rehearsal_test.go`
- `native/internal/backfill/write_rehearsal.go`
- `native/internal/backfill/write_rehearsal_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- Retain parsed overview candidates internally with group, article, subject,
  Message-ID, byte, and line fields while keeping them out of JSON/text output.
- Added rollback-only sampled write rehearsal for `binaries`: one cursor update
  per sampled group using the highest sampled article and one binary/part
  insert shape check per sampled overview candidate.
- Added rollback-only sampled write rehearsal for `backfill`: one cursor update
  per sampled group using the lowest sampled article and one binary/part insert
  shape check per sampled overview candidate.
- Wired `--rehearse-writes --nntp-overview-sample=N` to use sampled candidates
  for the acquisition lanes. Without overview sampling, the existing synthetic
  representative rehearsal remains unchanged.
- Proved command-level JSON remains aggregate-only and rollback leaves MariaDB
  fingerprints unchanged.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/binaries -run 'OverviewSample' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/backfill -run 'OverviewSample' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/nntp -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'NNTPProbe|NNTPOverviewSample|RejectsNNTP' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'TestRunRehearses(Binaries|Backfill)OverviewSampleWritesAndRollsBack' -count=1
```

Expected: native uses real sampled overview rows for rollback-only header/part
write-shape rehearsal, without exposing raw provider row data, committing
MariaDB writes, or claiming replacement readiness.

### Task 70: Overview-Sampled Guarded Acquisition Commit Proof

**Status:** Done

**Goal:** Move the first acquisition lanes beyond rollback-only sample checks by
letting guarded native-test commit mode persist cursor, binary, and part rows
derived from sampled NNTP overview candidates while retaining the existing
Redis lock and native-test DB safety guards.

**Files:**

- `native/internal/binaries/write_rehearsal.go`
- `native/internal/binaries/write_rehearsal_test.go`
- `native/internal/backfill/write_rehearsal.go`
- `native/internal/backfill/write_rehearsal_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- Added `CommitOverviewSampleWrites` for `binaries` and `backfill`, sharing the
  sampled candidate transaction shape with rollback rehearsal but committing
  under the existing native-test guard path.
- Allowed `--nntp-overview-sample=N` with `--commit-lane-writes` for the
  acquisition lanes while keeping the flag rejected for unsupported non-dry-run
  modes.
- Routed guarded commit mode to sample-derived writes when overview sampling is
  requested; otherwise the existing representative commit proof remains
  unchanged.
- Added command-level integration coverage proving fake NNTP sampling,
  native-test committed rows, Redis lock use, aggregate-only JSON, and changed
  MariaDB fingerprints for both `binaries` and `backfill`.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/binaries -run 'CommitOverviewSample' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/backfill -run 'CommitOverviewSample' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'TestRunCommits(Binaries|Backfill)OverviewSampleWritesWithRedisLock' -count=1
```

Expected: native can sample NNTP overview rows and commit derived acquisition
cursor/header/part rows in the guarded native-test schema, without exposing raw
provider row data or claiming production replacement readiness.

### Task 71: PHP-Orchestrated Overview Sample Commit Wiring

**Status:** Done

**Goal:** Make the sampled acquisition commit proof reachable from the actual
PHP native-worker commit handoff, not only from direct Go CLI invocations.

**Files:**

- `app/Services/Distributed/NativeWorkerCommitRunner.php`
- `config/nntmux.php`
- `.env.example`
- `.env.native-eval`
- `tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- Added `NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE` /
  `nntmux.native_worker_nntp_overview_sample`.
- `NativeWorkerCommitRunner` passes `--nntp-overview-sample N` only for
  `binaries` and `backfill` lane commits when the configured value is positive.
- The PHP commit runner forwards only the native NNTP provider environment
  needed by Go (`NNTP_*` and `USE_ALTERNATE_NNTP_SERVER`) for those sampled
  acquisition commits.
- Non-acquisition commit-capable lanes ignore the sample setting, so a global
  eval setting cannot make `removecrap`, `releases`, postprocess, or other
  commit lanes fail on an unsupported native flag.
- Documented the compose/PHP env knob and left the local native-eval default at
  `0`.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php --filter 'first_lane_native_commit_with_tuning_flags|removecrap_production_lane_commit|held_lock_and_connection_settings'
```

Expected: PHP passes the overview sample flag and NNTP environment to Go for
sampled acquisition commits, keeps miss-status commit env minimal, and does not
pass the sample flag to non-acquisition commit lanes.

### Task 72: Acquisition Readiness Blocker Reclassification

**Status:** Done

**Goal:** Keep replacement-readiness evidence accurate after the sampled
overview commit proof. `binaries` and `backfill` are still not production
replacement-ready, but their blockers should name the remaining production
ownership gap instead of claiming all acquisition writes are PHP-owned.

**Files:**

- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/scripts/audit-native-replacement-readiness.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Updated generic readiness blockers for `binaries` and `backfill` to call out
  production header acquisition, full header persistence, and cursor ownership.
- Preserved `replacement_ready=false` and the default-deny
  `--require-replacement-ready` behavior for both lanes.
- Updated the backlog gate notes so the current blocker list reflects the
  guarded native-test sampled acquisition commit path.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'RequireReplacementReady|ReplacementReadiness' -count=1
```

Expected: JSON dry-runs and hard replacement guards still fail closed for
`binaries` and `backfill`, with blocker strings that match the current native
test capability.

### Task 73: Redacted K3s NNTP Check and First Compose Worker Execution

**Status:** Done

**Goal:** Prove the local Compose native-eval runtime can use the media
namespace NNTP configuration without exposing provider values, then execute the
first native worker services in the same one-shot Compose shape intended for
operator use.

**Files:**

- `.gitignore`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Added `.env.*` to `.gitignore` so synced local eval env files and backups
  stay out of `git status`; tracked `.env.example` remains tracked.
- Extended the native eval sync helper guard test to require the local env-file
  ignore rule alongside the existing redaction checks.

**Verification:**

```bash
KUBECONFIG=$HOME/k3s.yaml native/scripts/sync-native-eval-nntp-from-k3s.sh --mode check
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml --profile native-workers config --services
native/scripts/run-native-eval-first-lanes.sh
NNTMUX_NATIVE_EVAL_LANES='binaries backfill releases' native/scripts/run-native-eval-compose-workers.sh
```

Expected: the k3s check reports only redacted key names, Compose resolves all
`native-workers` services, and the `binaries`, `backfill`, and `releases`
workers complete through both the direct eval runner and the one-shot Compose
worker service runner.

### Task 74: All-Worker Native Eval Execution Proof

**Status:** Done

**Goal:** Move from first-lane validation to an all-worker execution proof.
Every distributed catalog lane should resolve as enabled, validate through the
native dry-run planner, execute through the PHP-to-Go native lane handoff, and
run through the deploy-shaped one-shot Compose `native-workers` service.

**Files:**

- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Verification:**

```bash
native/scripts/audit-native-eval-all-workers.sh
native/scripts/run-native-eval-all-workers.sh
native/scripts/run-native-eval-compose-workers.sh
```

Observed: all 13 catalog lanes completed in each path:
`binaries`, `backfill`, `releases`, `fixnames`, `hashed-fixnames`,
`removecrap`, `post-additional`, `metadata-refresh`, `post-tv`,
`post-movies`, `post-amazon`, `irc`, and `per-group`.

Expected: the audit reports `enabled=true`, `replacement_ready=false`, and
readiness blockers for every lane; both execution runners report
`native lane completed` for every lane under the startup-smoke guard. This is
an all-worker native execution proof, not a production replacement claim.

### Task 75: Fixture-Backed All-Worker Native Command-Shape Proof

**Status:** Done

**Goal:** Exercise every committed catalog fixture through native `--run-lane`
inside the eval stack, with deterministic DB fixture state for DB-backed lanes
and a fake Artisan executable for leaf command capture. This proves command
shape across all workers without invoking real NNTP, delete, postprocess,
search, IRC, or metadata side effects.

**Files:**

- `native/cmd/nntmux-worker/main.go`
- `native/scripts/run-native-eval-fixture-workers.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Ran the fixture-backed eval proof against all 13 catalog lanes.
- Moved fixture-runner held-lock setup/release to `redis-cli` in the Redis
  container instead of inline PHP `Redis()` calls.
- Added a shared native Redis client factory for CLI lock paths and disabled
  go-redis maintenance-notification handshakes, removing noisy fallback
  warnings against the Redis 7 eval service.
- Rebuilt and redeployed the mounted native eval binary before rerunning the
  fixture proof.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'NativeLane|Lock|Commit|Redis|RunLane' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobCatalogTest.php --filter 'native_eval_fixture_runner_uses_redis_cli|native_worker_coverage_lists_stay_in_sync'
native/scripts/deploy-native-eval-compose.sh
native/scripts/run-native-eval-fixture-workers.sh
```

Observed after redeploying the refreshed binary: all 13 lanes completed with
fixture command counts and no Redis `maintnotifications` fallback warnings:
`binaries`, `backfill`, `releases`, `fixnames`, `hashed-fixnames`,
`removecrap`, `post-additional`, `metadata-refresh`, `post-tv`,
`post-movies`, `post-amazon`, `irc`, and `per-group`.

### Task 76: PHP-Orchestrated All-Lane Commit Smoke

**Status:** Done

**Goal:** Prove the Laravel worker can orchestrate guarded native
`--commit-lane-writes` for every commit-capable lane without falling back to the
original PHP command loop after native writes commit.

**Files:**

- `app/Services/Distributed/NativeWorkerCommitRunner.php`
- `config/nntmux.php`
- `.env.example`
- `tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php`
- `tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Split native commit JSON stdout capture from bounded stderr log capture.
  Commit reports now use a dedicated 1 MiB budget instead of the 2 KiB stderr
  diagnostic budget, preventing large valid reports such as `removecrap` from
  being truncated before PHP validation.
- Added `NNTMUX_NATIVE_WORKER_COMMIT_REPORT_BYTES` configuration for the commit
  report cap.
- Kept the smoke assertion diagnostic output attached to nonzero worker exits.
- Mocked `removecrap` PHP-owned search, descendant cleanup, NZB deletion, and
  image deletion in the feature smoke; exact side-effect semantics remain
  covered by unit tests while the smoke continues to prove native DB commits and
  PHP handoff.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php
docker compose -f docker-compose.native-test.yml run --rm php-test sh -lc 'php -l app/Services/Distributed/NativeWorkerCommitRunner.php && php -l config/nntmux.php && php -l tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php && php -l tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test sh -lc "/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture removecrap"
docker compose -f docker-compose.native-test.yml run --rm -T -e NNTMUX_NATIVE_LANE_COMMIT_SMOKE=1 -e NNTMUX_NATIVE_LANE_COMMIT_SMOKE_JOB=removecrap -e NNTMUX_NATIVE_WORKER_BINARY="/var/www/html/storage/native-lane-commit-smoke/nntmux-worker" php-native-test ./vendor/bin/phpunit tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php --filter test_php_worker_commits_native_first_lane_writes_and_skips_php_command_loop
native/scripts/verify-php-native-lane-commit-smoke.sh
```

Observed: the all-lane commit smoke passed for `binaries`, `backfill`,
`releases`, `per-group`, `removecrap`, `metadata-refresh`, `post-tv`,
`post-movies`, `post-amazon`, and `post-additional`.

### Task 77: Known-Lane Replacement Blocker Precision

**Status:** Done

**Goal:** Keep the hard replacement-readiness guard fail-closed while removing
stale generic blocker text from lanes that now have explicit native proof paths
and lane-specific remaining production blockers.

**Files:**

- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `docs/distributed-workers.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Known generic-readiness lanes now return only their lane-specific production
  blocker in `native_worker.replacement_readiness.blockers`.
- Unknown future lanes still return the generic default-deny blocker plus
  `native replacement behavior has not been proven`.
- The hard `--require-replacement-ready` guard still exits `2` before reports,
  MariaDB planners, or write paths for every current catalog lane.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run RequireReplacementReady -count=1
native/scripts/audit-native-replacement-readiness.sh
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobCatalogTest.php --filter 'replacement_readiness_audit|native_worker_image_smoke_checks_catalog_readiness_metadata'
```

Expected: every current catalog lane still fails closed under
`--require-replacement-ready`, with the lane-specific blocker and without
secret or internal execution detail.

### Task 78: PHP-Orchestrated RemoveCrap Production Opt-In Smoke

**Status:** Done

**Goal:** Prove Laravel can drive the `removecrap` native production-opt-in
commit branch through the real native binary without relying on the native-test
commit guards, while still using the disposable native-test schema for safety.

**Files:**

- `tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php`
- `native/scripts/verify-php-native-removecrap-production-commit-smoke.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Added a production-opt-in mode to the PHP-orchestrated lane commit smoke.
  It is locked to `removecrap`, keeps the DSN pointed at
  `nntmux_native_test`, disables `native_worker_commit_test_enabled`, and
  enables only `native_worker_removecrap_production_commit_enabled`.
- Added a wrapper smoke that seeds the `removecrap` fixture under native-test
  guards, then runs the PHP worker with
  `NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=0` and the destructive/committed
  native-test guards cleared.
- Added catalog test coverage to keep the wrapper wired to the guarded
  production-opt-in path.

**Verification:**

```bash
bash -n native/scripts/verify-php-native-removecrap-production-commit-smoke.sh
docker compose -f docker-compose.native-test.yml run --rm php-test sh -lc 'php -l tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php && php -l tests/Unit/Distributed/DistributedJobCatalogTest.php'
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobCatalogTest.php --filter removecrap_production_commit_smoke_clears_native_test_guards
native/scripts/verify-php-native-removecrap-production-commit-smoke.sh
```

Observed: the wrapper rebuilt/extracted the native binary, seeded the
`removecrap` fixture, ran PHP with native-test commit guards cleared, and the
worker reported a successful native `removecrap` commit with 26 smoke
assertions.

### Task 79: Compose Native Eval First-Lane Runtime Proof

**Status:** Done

**Goal:** Refresh local compose NNTP configuration from the live k3s media
namespace, deploy the native eval stack, and execute the first native workers
(`binaries`, `backfill`, and `releases`) once through the PHP-to-native handoff.

**Files:**

- `.env.native-eval` (ignored/local secrets; refreshed by helper)
- `docker-compose.native-eval.yml`
- `native/scripts/sync-native-eval-nntp-from-k3s.sh`
- `native/scripts/deploy-native-eval-compose.sh`
- `native/scripts/run-native-eval-first-lanes.sh`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Applied 11 redacted NNTP keys from k3s deployments in namespace `media`
  using `$HOME/k3s.yaml`.
- Built and deployed the native eval compose stack, including the current
  `nntmux-native-worker:dev` binary and `nntmux-native-eval-app:latest` app
  image.
- Ran `binaries`, `backfill`, and `releases` with
  `NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=1`, proving compose packaging, the mounted
  native binary, plan export, native lane execution, and lock cleanup without
  contacting NNTP or performing real release-processing side effects.

**Verification:**

```bash
native/scripts/sync-native-eval-nntp-from-k3s.sh --mode apply --kubeconfig "$HOME/k3s.yaml" --namespace media

NNTMUX_NATIVE_EVAL_RUN_FIRST_LANES=1 \
NNTMUX_NATIVE_EVAL_LANES="binaries backfill releases" \
native/scripts/deploy-native-eval-compose.sh

docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml ps
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T redis \
  sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort"
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T webapp \
  sh -lc 'test -x /opt/nntmux-native/nntmux-worker && tail -n 20 /tmp/nntmux-native-leaf-startup-smoke.log'
```

Observed:

- NNTP sync applied 11 redacted keys from 11 deployment/env references.
- `webapp`, `mariadb`, `redis`, `manticore`, and `mailpit` were healthy.
- The deploy smoke validated `metadata-refresh` dry-run with `commands=3`.
- First-lane runner printed enabled plans and completed:
  `native lane completed binaries`, `native lane completed backfill`, and
  `native lane completed releases`.
- Redis had no remaining `nntmux:distributed-worker` locks after the run.
- The startup-smoke log recorded the expected leaf commands, including
  `binaries:part-repair`, `articles:get-range binaries`,
  `articles:get-range backfill`, and `releases:process`.

**Remaining limitation:** this is a deployable service-shape and command-dispatch
proof. Real-leaf NNTP execution still requires a provider-visible group via
`NNTMUX_NATIVE_EVAL_ALLOW_REAL_LEAVES=1` plus real article bounds.

### Task 80: NNTP Probe Watermark Report

**Status:** Done

**Goal:** Make real-leaf first-lane setup less guessy by surfacing numeric NNTP
`GROUP` watermarks from the native probe while preserving the no-secret and
no-group-name report contract.

**Files:**

- `native/internal/nntp/probe.go`
- `native/internal/nntp/probe_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- `nntp.ProbeReport` now includes `total_count`, `lowest_low`,
  `highest_high`, and unnamed per-group `count`/`low`/`high` stats.
- Text dry-runs now print `total-count`, `lowest-low`, and `highest-high`
  for successful probes.
- Unit and integration coverage asserts the new stats exist while existing
  redaction checks still forbid provider endpoints, DSNs, group names, subjects,
  and message IDs in probe JSON.
- Docs now explain that `--nntp-probe` reports numeric provider watermarks
  without exposing endpoints, credentials, or group names.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  go test ./internal/nntp ./cmd/nntmux-worker -run 'Probe|NNTPProbe' -count=1

docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go test ./cmd/nntmux-worker -run 'NNTPProbe|Binaries.*NNTP|Backfill.*NNTP' -count=1

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke build native-worker
docker cp "$(docker create nntmux-native-worker:dev):/usr/local/bin/nntmux-worker" storage/native-eval/nntmux-worker
chmod 755 storage/native-eval/nntmux-worker
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T webapp \
  sh -lc 'php artisan nntmux:worker binaries --native-plan --lock-seconds=900 > /tmp/native-binaries-probe-stats-plan.json
    NNTMUX_NATIVE_MYSQL_DSN="$NNTMUX_NATIVE_WORKER_MYSQL_DSN" \
    /opt/nntmux-native/nntmux-worker \
      --plan /tmp/native-binaries-probe-stats-plan.json \
      --dry-run \
      --mysql-dsn-env \
      --nntp-probe \
      --binaries-max-messages=10 \
      --binaries-max-headers=10 \
      --output=json'
```

Observed live probe for the k3s-synced eval stack returned one successful
provider group with numeric watermarks only:

```json
{
  "groups": 1,
  "successful": 1,
  "failed": 0,
  "total_count": 7690058276,
  "lowest_low": 338202,
  "highest_high": 7690396477,
  "stats": [{"count": 7690058276, "low": 338202, "high": 7690396477}]
}
```

**Remaining limitation:** `GROUP` succeeds for the selected real group, but
bounded `OVER`/`XOVER` sampling still failed for the tested windows. Do not
claim real NNTP header ingestion until a provider window with successful
overview rows is proven or the PHP leaf fetch path is instrumented with stronger
post-run row-count evidence.

### Task 81: Real-Provider XOVER Fallback and Sparse Overview Windows

**Status:** Done

**Goal:** Unblock real-leaf `binaries` and `backfill` execution against the
k3s-synced NNTP provider by handling providers that reject `OVER` with a `400`
response, while treating sparse article windows as empty samples instead of
hard failures.

**Files:**

- `native/internal/nntp/probe.go`
- `native/internal/nntp/probe_test.go`
- `native/cmd/nntmux-worker/main.go`
- `docs/distributed-workers.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- `OVER` responses `400`, `500`, and `501` now trigger the existing `XOVER`
  fallback path.
- `423` and `430` overview responses now count as sparse empty windows instead
  of incrementing `failed`.
- Overview sample reports now include `empty`, so operators can distinguish a
  provider-visible but sparse window from malformed rows or transport failures.
- Docs now describe `XOVER` fallback and sparse-window handling for both
  `binaries` and `backfill` overview sampling.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  gofmt -w ./internal/nntp/probe.go ./internal/nntp/probe_test.go

docker compose -f docker-compose.native-test.yml run --rm go-test \
  go test ./internal/nntp ./cmd/nntmux-worker -run 'SampleOverview|Probe|NNTPProbe' -count=1

docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go test ./cmd/nntmux-worker -run 'NNTPProbe|Binaries.*NNTP|Backfill.*NNTP|Overview' -count=1

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke build native-worker
```

Observed direct provider capability from inside the eval `webapp` container:

```text
GROUP alt.binaries.blu-ray -> 211 7690133042 337728 7690470769 alt.binaries.blu-ray
OVER 338302-338303 -> 400 Unrecognized command
XOVER 338302-338303 -> 224 Overview Information Follows
```

Observed live native overview sample after rebuilding and refreshing
`storage/native-eval/nntmux-worker`:

```text
binaries nntp overview sample
ranges=1
requested=2
received=2
empty=0
parsed=2
malformed=0
bytes=1480300
lines=11380
header-candidates=2
part-candidates=2
unique-message-ids=2
duplicate-message-ids=0
failed=0
```

Observed bounded real-leaf lane execution against `alt.binaries.blu-ray` with
the k3s-synced NNTP credentials and conservative limits:

```text
native lane completed binaries
native lane completed backfill
native lane completed releases
```

Post-run MariaDB evidence for the eval group:

```text
collections_for_group=2
binaries_for_group=2
parts_for_group=20
linked_collections=0
releases_for_group=414
```

Redis had no remaining `nntmux:distributed-worker` locks after the run.

**Remaining limitation:** the real-leaf run proves provider overview fetch,
bounded header/part ingestion through the PHP leaf commands, and clean native
handoff for the first three lanes. It does not prove that the sampled
collections became newly linked releases in that run (`linked_collections=0`),
so full native release-creation replacement remains unclaimed.

### Task 82: Deterministic Native Eval Release-Creation Proof

**Status:** Done

**Goal:** Make the first-lane compose eval seed prove more than release-lane
dispatch. The `releases` lane should have a deterministic completed collection
that the real PHP `releases:process` leaf can convert into a release through
the native lane handoff.

**Files:**

- `native/scripts/native-eval-common.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/distributed-workers.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- The shared native eval seed now deletes any previous proof rows for the
  selected eval group, then inserts one sized collection,
  `Native Eval Release Proof`, with one complete binary and one part.
- The seed remains repeatable: subsequent eval runs remove the prior proof
  release/collection state before re-inserting the candidate.
- Added a unit guard so the eval seed keeps the completed release-creation
  fixture.
- Operator docs now note that the compose eval seed includes one completed
  collection for `releases:process`.

**Verification:**

```bash
bash -n native/scripts/native-eval-common.sh

docker compose -f docker-compose.native-test.yml run --rm php-test sh -lc \
  'php -l tests/Unit/Distributed/DistributedJobCatalogTest.php &&
   ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobCatalogTest.php --filter "eval_seed"'

NNTMUX_NATIVE_EVAL_ALLOW_REAL_LEAVES=1 \
NNTMUX_NATIVE_EVAL_LANES='releases' \
NNTMUX_NATIVE_EVAL_GROUP_NAME='alt.binaries.blu-ray' \
NNTMUX_NATIVE_EVAL_GROUP_DESCRIPTION='native eval real leaf group' \
NNTMUX_NATIVE_EVAL_GROUP_BACKFILL_TARGET=500 \
NNTMUX_NATIVE_EVAL_GROUP_FIRST_RECORD=338302 \
NNTMUX_NATIVE_EVAL_GROUP_LAST_RECORD=7690376447 \
NNTMUX_NATIVE_EVAL_SHORT_GROUP_FIRST_RECORD=338202 \
NNTMUX_NATIVE_EVAL_SHORT_GROUP_LAST_RECORD=7690396477 \
native/scripts/run-native-eval-first-lanes.sh
```

Observed before the run:

```text
proof_releases_before=0
proof_collections_before=0
proof_linked_before=NULL
```

Observed lane output:

```text
releases plan: enabled=true commands=1 sleep=30
native lane completed releases
Native eval first lanes completed: releases
```

Observed post-run MariaDB evidence:

```text
id=16319
name="Native Eval Release Proof"
searchname="Native Eval Release Proof"
groups_id=10
size=1048576
totalpart=1
categories_id=2999
nzbstatus=1
release_group_links=1
```

Observed Redis lock count after the run: `0`.

**Remaining limitation:** this proves the native handoff can drive the real PHP
release-creation leaf against a deterministic completed collection. It is not a
native rewrite of release creation: categorization, release insertion,
collection cleanup, NZB creation, and search/event side effects remain
PHP-owned until a later native replacement slice implements and proves those
boundaries.

### Task 83: Current-State All-Worker Native Eval Revalidation

**Status:** Done

**Goal:** Revalidate the full native-worker catalog after the real-provider
overview fallback and deterministic release-creation seed changes. Every
distributed worker should still export an enabled plan, produce a native
dry-run report with replacement readiness fail-closed, and complete through the
deploy-shaped compose worker service.

**Files:**

- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Verification:**

```bash
native/scripts/audit-native-eval-all-workers.sh
native/scripts/run-native-eval-compose-workers.sh

docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T redis \
  sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort | wc -l"

docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml ps --status running
```

Observed `audit-native-eval-all-workers.sh` validated all 13 lanes with
`enabled=true`, `dry_run=true`, `native_worker.writes=0`, and
`replacement_ready=false`:

```text
binaries
backfill
releases
fixnames
hashed-fixnames
removecrap
post-additional
metadata-refresh
post-tv
post-movies
post-amazon
irc
per-group
```

Observed `run-native-eval-compose-workers.sh` completed the same 13
deploy-shaped one-shot services:

```text
native-binaries-worker
native-backfill-worker
native-releases-worker
native-fixnames-worker
native-hashed-fixnames-worker
native-removecrap-worker
native-post-additional-worker
native-metadata-refresh-worker
native-post-tv-worker
native-post-movies-worker
native-post-amazon-worker
native-irc-worker
native-per-group-worker
```

The final Redis distributed-worker lock count was `0`. The core eval services
were still running and healthy: `webapp`, `mariadb`, `redis`, `manticore`, and
`mailpit`.

**Remaining limitation:** this all-worker pass is a current deploy-shape and
startup-smoke execution proof for the full catalog. It intentionally does not
run the destructive or network-heavy real leaves for every lane. The separate
Task 82 proof covers real `releases:process` release creation; broader
production replacement readiness remains fail-closed per lane.

### Task 84: RemoveCrap Eval Commit Report Hardening

**Status:** Done

**Goal:** Retry the guarded `removecrap` native production-opt-in path in the
compose eval stack, fix the first report-contract issue exposed by that run,
and keep the destructive proof isolated to safe fixture data.

**Files:**

- `database/migrations/2026_06_15_000001_add_payload_text_to_native_worker_side_effects_table.php`
- `native/internal/removecrap/write_rehearsal.go`
- `native/internal/removecrap/plan_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Added the eval-visible additive migration for
  `native_worker_side_effects.payload_text`, then rebuilt/recreated the
  native eval webapp image so `php artisan migrate` could see and apply it.
- Fixed native `removecrap` commit reports to always emit
  `deleted_release_ids`, `deleted_collection_ids`, and
  `release_file_cleanup_rows_enqueued`, even when the arrays are empty. PHP
  requires those fields for commit report validation before it can safely run
  the PHP-owned side-effect cleanup.
- Added Go coverage for the rollback JSON shape and production-opt-in commit
  report arrays.
- Rebuilt the mounted eval binary at `storage/native-eval/nntmux-worker`.

**Verification:**

```bash
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml build --no-cache webapp
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml up -d --no-deps --force-recreate webapp
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T webapp php artisan migrate --force --no-interaction

docker compose -f docker-compose.native-test.yml run --rm go-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./internal/removecrap/write_rehearsal.go ./internal/removecrap/plan_test.go ./cmd/nntmux-worker/main_integration_test.go'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/removecrap -run 'RehearseRemoveCrapWritesRollsBackCandidateDeletes|CommitRemoveCrapWritesCommitsCandidateDeletes' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'RemoveCrapWritesWithProductionOptIn|RemoveCrapWritesWithRedisLock' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test \
  php -l database/migrations/2026_06_15_000001_add_payload_text_to_native_worker_side_effects_table.php
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'native_removecrap_lane_commit|removecrap_lane_commit'
native/scripts/verify-php-native-removecrap-production-commit-smoke.sh
```

Observed:

- The rebuilt eval webapp applied
  `2026_06_15_000001_add_payload_text_to_native_worker_side_effects_table`.
- The first eval retry got past schema creation but failed after the native DB
  transaction because PHP rejected an omitted empty `deleted_collection_ids`
  array. Redis locks were released.
- After the report-shape fix, the guarded compose eval retry completed without
  report validation failure and left Redis lock count at `0`.
- The live eval `removecrap` plan currently has 15 configured destructive
  commands but `candidate_releases=0`, so the compose eval retry was a clean
  no-op (`native lane commit completed removecrap: writes=0`).
- The dedicated fixture smoke seeded `removecrap` data in the native-test DB
  and passed the PHP-orchestrated production-opt-in commit path with 26
  assertions.

**Remaining limitation:** this hardens the commit contract and proves the
destructive path against fixture data. The live compose eval DB did not contain
matching `removecrap` candidates, so `removecrap` replacement readiness remains
fail-closed with `removecrap production commit requires live rollout proof`.

### Task 85: Production-Shaped Acquisition Write Fixture

**Status:** Done

**Goal:** Move native `binaries` and `backfill` overview-sample writes away
from the simplified test-only `binaries(groups_id,total_parts)` and
`parts(message_id)` schema, so guarded native acquisition commits exercise the
real NNTmux `collections`, `binaries`, and `parts` column contract.

**Files:**

- `native/internal/binaries/write_rehearsal.go`
- `native/internal/backfill/write_rehearsal.go`
- `native/internal/binaries/write_rehearsal_test.go`
- `native/internal/backfill/write_rehearsal_test.go`
- `native/internal/testdb/first_lane_fixtures.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Native sampled and representative acquisition writes now create a
  production-shaped `collections` row before inserting `binaries` and `parts`
  rows.
- Native acquisition inserts now target production column names:
  `collections_id`, `totalparts`, `currentparts`, `filenumber`, `partsize`,
  and `parts.messageid`.
- The binaries/backfill test fixtures and CLI integration fixtures now define
  production-shaped `collections`, `binaries`, and `parts` tables, preventing
  the native write contract from passing against a schema that cannot exist in
  the live app.
- The existing sampled-scope, explicit commit guard, Redis lock ownership, and
  rollback-only rehearsal behavior are unchanged.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./internal/binaries/write_rehearsal.go ./internal/backfill/write_rehearsal.go ./internal/binaries/write_rehearsal_test.go ./internal/backfill/write_rehearsal_test.go ./internal/testdb/first_lane_fixtures.go ./cmd/nntmux-worker/main_integration_test.go'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/binaries ./internal/backfill -run 'OverviewSample|Safe.*Writes' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'Binaries.*Overview|Backfill.*Overview|CommitsBinariesWrites|CommitsBackfillWrites' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/testdb -count=1
```

Observed: binaries/backfill package tests, CLI commit/rehearsal tests, and the
shared first-lane fixture tests passed against MariaDB with the
production-shaped acquisition tables.

**Remaining limitation:** this removes a fake-schema blocker from the native
acquisition write path, but it still commits only bounded sampled/representative
rows under explicit guard. Full production header parsing, collection grouping,
cursor ownership, and unbounded acquisition remain fail-closed.

### Task 86: Native Overview Part Metadata Aggregation

**Status:** Done

**Goal:** Move native `binaries` and `backfill` overview-sample persistence
closer to PHP header storage by parsing common yEnc part metadata and
aggregating sampled rows from the same file into one binary with multiple
parts.

**Files:**

- `native/internal/nntp/probe.go`
- `native/internal/nntp/subject.go`
- `native/internal/nntp/subject_test.go`
- `native/internal/nntp/probe_test.go`
- `native/internal/binaries/write_rehearsal.go`
- `native/internal/backfill/write_rehearsal.go`
- `native/internal/binaries/write_rehearsal_test.go`
- `native/internal/backfill/write_rehearsal_test.go`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Added native parsing for common overview subjects ending in yEnc
  `(part/total)` or `[part/total]`, with a conservative single-part fallback
  for unstructured subjects.
- `nntp.OverviewCandidate` now carries `BinaryName`, `PartNumber`, and
  `TotalParts` so acquisition writes can persist parsed file metadata.
- Native sampled `binaries` and `backfill` writes now group parts for the same
  parsed file into one collection/binary and store the NNTP article number in
  `parts.number` plus the parsed yEnc part number in `parts.partnumber`.
- Repeated sampled commits remain idempotent for already-seen parts: existing
  collection and binary IDs are resolved, and binary `currentparts`/`partsize`
  only increment after a new part row is inserted.
- Added MariaDB regression coverage proving two overview rows for the same
  parsed file produce one collection, one binary with `currentparts=2`, and two
  part rows.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  /usr/local/go/bin/go test ./internal/nntp -run 'ParseOverviewSubject|SampleOverview' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/binaries ./internal/backfill -run 'OverviewSample|Safe.*Writes' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'Binaries.*Overview|Backfill.*Overview|CommitsBinariesWrites|CommitsBackfillWrites' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test \
  sh -lc 'mkdir -p ../storage/native-eval && /usr/local/go/bin/go build -o ../storage/native-eval/nntmux-worker ./cmd/nntmux-worker && chmod 755 ../storage/native-eval/nntmux-worker'
NNTMUX_NATIVE_EVAL_LANES='binaries backfill' native/scripts/audit-native-eval-all-workers.sh
```

Observed: the parser tests, MariaDB package tests, CLI Redis-lock commit and
overview tests, and compose eval dry-run audit for `binaries backfill` passed.

**Remaining limitation:** this improves bounded native overview persistence and
part aggregation, but it is still not the full PHP header-storage pipeline:
collection regex grouping, blacklist/not-yEnc filtering, body-preamble probes,
part repair, and production cursor ownership remain fail-closed.

### Task 87: Production-Shaped Releases Write Contract

**Status:** Done

**Goal:** Move the guarded native `releases` write path away from synthetic
`Native.Release.Rehearsal.*` rows and toward release rows derived from the
candidate collection and linked binary aggregate data.

**Files:**

- `native/internal/releases/write_rehearsal.go`
- `native/internal/releases/write_rehearsal_test.go`
- `native/internal/releases/plan_test.go`
- `native/internal/testdb/first_lane_fixtures.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Guarded native release commits now select the first unlinked collection for a
  queued group and derive the release row from `collections.subject`,
  `collections.fromname`, `collections.date`, `collections.filesize`, and
  linked `binaries.totalparts/currentparts/partsize` aggregates.
- Native release inserts now populate production-shaped fields including
  `guid`, `leftguid`, `nzb_guid`, `name`, `searchname`, `totalpart`,
  `groups_id`, `size`, `postdate`, `fromname`, `completion`,
  `categories_id`, and `nzbstatus`.
- The selected collection is linked by ID to the inserted release, rather than
  updating any unlinked collection in the group.
- Release commit reports now always emit `committed_release_ids` as an array,
  matching the PHP commit validator contract.
- Release package, shared fixture, and CLI integration fixtures now use
  production-shaped `collections`, `binaries`, and `releases` schemas for this
  lane.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/releases ./internal/testdb -run 'Release|FirstLaneFixturesSeedQueueRows' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'Releases|UnsupportedReleasesCommand' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'test_enabled_native_lane_commit_syncs_search_for_committed_releases'
docker compose -f docker-compose.native-test.yml run --rm go-test \
  sh -lc 'mkdir -p ../storage/native-eval && /usr/local/go/bin/go build -o ../storage/native-eval/nntmux-worker ./cmd/nntmux-worker && chmod 755 ../storage/native-eval/nntmux-worker'
NNTMUX_NATIVE_EVAL_LANES='releases' native/scripts/audit-native-eval-all-workers.sh
```

Observed: native release package tests, shared fixture tests, CLI release
dry-run/rehearsal/commit tests, PHP release commit search-sync validation, and
compose eval dry-run audit for `releases` passed.

**Remaining limitation:** this makes the guarded representative release commit
use real collection/binary-derived data, but it still does not replace
`ReleaseProcessingService`: categorization, regex grouping, NZB creation,
release events, search document construction, and full production release
creation remain fail-closed.

### Task 88: Postprocess Commit Report Empty-ID Contract

**Status:** Completed.

**Goal:** Keep the native postprocess commit report compatible with PHP's
required `committed_release_ids` validator even when a guarded postprocess
commit changes zero rows.

**Files:**

- `native/internal/postprocess/write_rehearsal.go`
- `native/internal/postprocess/plan_test.go`

**Changes:**

- `postprocess.WriteRehearsalResult` now always serializes
  `committed_release_ids` instead of omitting the field for zero-write results.
- `runPostprocessWrites` initializes `CommittedReleaseIDs` as an empty slice so
  zero-write commit JSON emits `[]`, not `null`.
- Added package coverage that executes the real commit path with an empty plan
  and asserts the JSON report contains an empty `committed_release_ids` array.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/postprocess -run 'CommitPostprocess|RehearsePostprocess' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'PostTV.*(WriteRehearsal|Commits)|Postprocess' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'postprocess_lane_commit'
```

Observed: focused postprocess package tests, CLI postprocess integration tests,
and PHP postprocess lane commit validation passed.

### Task 89: Metadata-Refresh Production-Shaped Local Titles

**Status:** Completed.

**Goal:** Move the metadata-refresh native write subset away from synthetic
`native-metadata-rehearsal-*` `predb` titles by deriving local `predb` rows
from release-file names and making duplicate title writes idempotent.

**Files:**

- `native/internal/metadata/refresh_plan.go`
- `native/internal/metadata/write_rehearsal.go`
- `native/internal/metadata/refresh_plan_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`

**Changes:**

- Archive CRC candidates now include a normalized title derived from
  `release_files.name` using the existing query normalization rules.
- Archive CRC planning skips CRC rows whose file names are too short,
  obfuscated, or otherwise not searchable, rather than inventing placeholder
  predb titles.
- Metadata-refresh write rehearsal and commit now use idempotent
  `INSERT IGNORE` plus title lookup for `predb` rows, so archive CRC and search
  query paths reuse the same local title instead of creating duplicate
  synthetic rows.
- CRC inserts now use `INSERT IGNORE`; later Task 100 replaces the temporary
  SRRDB title-content CRC placeholder with provider-returned SRRDB detail CRCs.
- Package and worker integration tests assert the changed affected-row counts
  and verify committed metadata writes leave no `native-metadata-rehearsal-*`
  rows behind.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/metadata -run 'BuildRefresh|CommitMetadataRefresh|RehearseMetadataRefresh|QueryFromFileName' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'MetadataRefresh.*(WriteCommit|RefreshCommand)' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'MetadataRefresh.*(WriteRehearsal|Commits)' -count=1
```

Observed: metadata package dry-run/rehearsal/commit tests, unit CLI metadata
guards, and worker integration metadata rehearsal/commit tests passed.

### Task 90: Postprocess Numeric Metadata Sentinels

**Status:** Completed.

**Goal:** Remove fake string IDs from the guarded native postprocess commit
subset for metadata-backed postprocess fields whose production columns are
integer foreign keys.

**Files:**

- `native/internal/postprocess/write_rehearsal.go`
- `native/internal/postprocess/plan_test.go`
- `native/internal/testdb/postprocess_fixtures.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Native postprocess commit now writes `-2` for anime, music, and console
  no-match representative updates instead of the fake string
  `native-rehearsal`.
- Postprocess package and worker integration schemas now model
  `anidbid`, `musicinfo_id`, and `consoleinfo_id` as `INT` columns, matching
  the production MariaDB schema.
- Postprocess fixtures now use numeric linked metadata IDs instead of
  string-only placeholders.
- Package tests assert committed anime/music/console representative rows use
  the typed `-2` sentinel.
- The PHP-orchestrated lane commit smoke now checks the integer sentinel for
  `post-tv` and `post-amazon`, and also recognizes metadata-refresh's newer
  local title source names.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/postprocess ./internal/testdb -run 'Postprocess|PostTV|PostMovie|PostAmazon|Commit|Rehearse' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'PostTV.*(WriteRehearsal|Commits)|PostMovies|PostAmazon|Postprocess' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'postprocess_lane_commit'
NNTMUX_NATIVE_LANE_COMMIT_SMOKE_LANES='post-tv post-amazon' \
  native/scripts/verify-php-native-lane-commit-smoke.sh
```

Observed: postprocess package and worker integration tests passed, PHP
postprocess lane commit validation passed, and the PHP-orchestrated native lane
commit smoke passed for `post-tv` and `post-amazon`.

### Task 91: Acquisition Commit Requires Overview Sample

**Status:** Completed.

**Goal:** Prevent guarded native `binaries` and `backfill` commits from
falling back to synthetic `native-rehearsal:*` rows, so committed acquisition
proofs must exercise bounded NNTP overview sampling.

**Files:**

- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `native/cmd/nntmux-fake-nntp/main.go`
- `native/scripts/verify-php-native-lane-commit-smoke.sh`
- `tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`

**Changes:**

- `--commit-lane-writes` for `binaries` and `backfill` now fails closed unless
  `--nntp-overview-sample` is greater than zero.
- Dry-run and rollback-only rehearsal can still use representative acquisition
  rows; committed acquisition proofs must use sampled overview candidates.
- Added a tiny test-only fake NNTP server and wired the PHP-orchestrated lane
  commit smoke to use it for acquisition lanes.
- Updated the PHP smoke assertions to prove sampled `binaries`/`parts` rows and
  assert that synthetic `native-rehearsal:*` rows are not committed.
- Updated the same smoke to assert the production-shaped collection-derived
  `releases` row instead of the older synthetic release GUID.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'NNTPOverviewSample|CommittedAcquisitionWithoutNNTPOverviewSample' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test \
  /usr/local/go/bin/go test ./cmd/nntmux-fake-nntp -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'Binaries.*Overview|Backfill.*Overview|RejectsBinariesCommitWithoutOverviewSample|RejectsBackfillCommitWithoutOverviewSample' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test \
  sh -lc 'php -l tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php'
bash -n native/scripts/verify-php-native-lane-commit-smoke.sh
NNTMUX_NATIVE_LANE_COMMIT_SMOKE_LANES='binaries backfill' \
  native/scripts/verify-php-native-lane-commit-smoke.sh
NNTMUX_NATIVE_LANE_COMMIT_SMOKE_LANES='releases per-group removecrap metadata-refresh post-tv post-movies post-amazon post-additional' \
  native/scripts/verify-php-native-lane-commit-smoke.sh
```

Observed: Go CLI guard tests, fake NNTP compile, MariaDB/Redis overview commit
tests, PHP syntax, shell syntax, and PHP-orchestrated commit smoke for
`binaries backfill releases per-group removecrap metadata-refresh post-tv
post-movies post-amazon post-additional` passed.

### Task 92: PHP Acquisition Commit Overview Guard

**Status:** Completed.

**Goal:** Mirror the native acquisition commit guard at the PHP orchestration
boundary, so Laravel does not spawn the native binary for committed
`binaries`/`backfill` writes unless bounded NNTP overview sampling is configured.

**Files:**

- `app/Services/Distributed/NativeWorkerCommitRunner.php`
- `tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- `NativeWorkerCommitRunner::commitLaneWrites()` now fails before process spawn
  for `binaries` and `backfill` when
  `nntmux.native_worker_nntp_overview_sample` is not positive.
- The failure message names the required
  `NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE > 0` operator setting.
- Added a unit regression proving the native binary receives no argv when that
  acquisition guard trips.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test \
  sh -lc 'php -l app/Services/Distributed/NativeWorkerCommitRunner.php && php -l tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php'
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php --filter 'acquisition_lane_commit_without_overview_sample|first_lane_native_commit_with_tuning_flags|removecrap_production_lane_commit'
```

Observed: PHP syntax checks passed and the focused commit-runner tests passed,
including the no-spawn acquisition guard.

### Task 93: Regular Fixnames CRC/PAR Native Discovery

**Status:** Completed.

**Goal:** Move part of the regular `fixnames` lane beyond a command-only
PHP-owned report by letting native code perform MariaDB candidate discovery for
the CRC32 and PAR hash regular methods.

**Files:**

- `native/internal/namefix/hashed_plan.go`
- `native/internal/fixnames/plan.go`
- `native/internal/fixnames/plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Parameterized the shared native CRC/PAR hash planner so it can target the
  regular fixnames category scopes instead of only `Other > Hashed`.
- `fixnames` now marks methods `15` and `19` as native-discovery supported,
  while keeping rename/category/event/search side effects blocked.
- `nntmux-worker --plan .../fixnames.json --dry-run --mysql-dsn ...` now runs a
  read-only native MariaDB discovery pass for those methods and reports
  aggregate CRC/PAR mutation and miss-status counts.
- Added a MariaDB integration fixture with current `Other` category rows to
  prove the PHP six-hour regular fixnames window is represented without writes.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./cmd/nntmux-worker/main_test.go && /usr/local/go/bin/go test ./internal/fixnames ./cmd/nntmux-worker -run "Fixnames|RegularFix|MysqlDSN" -count=1'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./internal/namefix/hashed_plan.go ./internal/fixnames/plan.go ./internal/fixnames/plan_test.go ./cmd/nntmux-worker/main.go ./cmd/nntmux-worker/main_test.go ./cmd/nntmux-worker/main_integration_test.go && /usr/local/go/bin/go test ./internal/fixnames ./cmd/nntmux-worker -run "Fixnames|RegularFix|MysqlDSN" -count=1'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/namefix ./internal/fixnames ./cmd/nntmux-worker -run 'Fix|Hashed|Resolved|Rehearse|Commit|SearchDocumentParity' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run RequireReplacementReady -count=1
native/scripts/audit-native-replacement-readiness.sh
git diff --check
```

Observed: focused fixnames unit tests, MariaDB regular fixnames discovery,
shared hashed-fixnames regression tests, replacement-readiness tests, readiness
audit, and whitespace diff check passed. Regular fixnames remains not
replacement-ready because native rename/category/event/search side effects are
not committed.

### Task 94: Regular Fixnames Miss-Status Commit

**Status:** Completed.

**Goal:** Move the safe status-only part of regular `fixnames` methods `15`
and `19` from PHP into native committed writes, while continuing to block native
renames/category/event work.

**Files:**

- `native/internal/namefix/hashed_plan.go`
- `native/internal/namefix/write_rehearsal.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Added a regular-fixnames write contract that maps method `19` to native CRC
  planning and method `15` to native PAR hash planning.
- Generalized native miss-status commits so the same guarded transaction can
  target `fixnames` category scopes and enqueue `fixnames` search side-effect
  rows instead of only `hashed-fixnames`.
- `nntmux-worker --commit-lane-writes` now supports `fixnames` when native
  regular CRC/PAR commands are present, commits only miss-status rows, and
  blocks rename-linked release updates.
- The Laravel distributed worker now includes `fixnames` in the opt-in native
  lane commit path, validates `fixnames_write_commit`, and syncs the native
  search side-effect outbox after successful status commits.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./internal/namefix/hashed_plan.go ./internal/namefix/write_rehearsal.go ./cmd/nntmux-worker/main.go ./cmd/nntmux-worker/main_integration_test.go && /usr/local/go/bin/go test ./cmd/nntmux-worker -run TestRunCommitsRegularFixnamesMissStatusWithRedisLock -count=1'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/namefix ./internal/fixnames ./cmd/nntmux-worker -run 'Fix|Hashed|Resolved|Rehearse|Commit|SearchDocumentParity' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test \
  sh -lc 'php -l app/Services/Distributed/DistributedJobWorker.php && php -l tests/Unit/Distributed/DistributedJobWorkerTest.php && ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter "regular_fixnames_status_commit|enabled_native_lane_commit_runs_regular_fixnames"'
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'native_lane_commit|hashed_fixnames_commit_prepass|regular_fixnames_status_commit'
docker compose -f docker-compose.native-test.yml run --rm go-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run RequireReplacementReady -count=1
```

Observed: direct native regular-fixnames commit, idempotency, Redis lock
release, `fixnames` outbox scoping, hashed-fixnames regressions, PHP worker
orchestration, and replacement-readiness guard tests passed. Regular fixnames
still is not replacement-ready because native renames/category/event side
effects remain blocked.

### Task 95: IRC Native PRE Parser

**Status:** Completed.

**Goal:** Move the IRC worker beyond a command-only PHP wrapper by replacing
the PHP PRE-message parsing contract with native Go code, while keeping socket
scraping, MariaDB writes, and search indexing explicitly blocked.

**Files:**

- `native/internal/irc/plan.go`
- `native/internal/irc/plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Added native parsing for raw IRC `PRIVMSG` lines and direct PRE bot messages
  using the legacy `NEW|UPD|NUK` field contract.
- The parser now maps title, source, category, request/group, size, files,
  filename, predate, and nuke status/reason into a typed native candidate.
- Source, category, and title ignore rules are supported by the parser, and
  legacy truncation limits for `files` and nuke reason are preserved.
- `nntmux-worker --dry-run --irc-sample <file>` now parses sample IRC/PRE
  input for the `irc` job and reports aggregate counts only. Raw channel names,
  titles, server details, and credentials are not emitted in the JSON report.
- The IRC dry-run report now marks `parser_ready=true` but remains
  `replacement_ready=false` because live socket handling, predb writes, and
  search side effects still need native ownership at this stage.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./internal/irc/plan.go ./internal/irc/plan_test.go ./cmd/nntmux-worker/main.go ./cmd/nntmux-worker/main_test.go && /usr/local/go/bin/go test ./internal/irc ./cmd/nntmux-worker -run "Irc|IrcSample" -count=1'
docker compose -f docker-compose.native-test.yml run --rm go-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run RequireReplacementReady -count=1
native/scripts/audit-native-replacement-readiness.sh
git diff --check
```

Observed: focused IRC parser and CLI sample tests passed, replacement-readiness
guards still reject IRC as non-ready, the full native replacement audit passed,
and whitespace diff checks passed.

### Task 96: IRC Native Session Runner

**Status:** Completed.

**Goal:** Move the IRC worker past static parsing by adding native IRC session
handling for login, channel join, ping/pong, and incoming message parsing
against a live connection abstraction, while leaving deployment lifecycle,
MariaDB writes, and search indexing blocked.

**Files:**

- `native/internal/irc/session.go`
- `native/internal/irc/session_test.go`
- `native/internal/irc/plan.go`
- `native/internal/irc/plan_test.go`
- `native/cmd/nntmux-worker/main_test.go`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Added a native IRC session runner that writes `PASS`, `NICK`, `USER`, joins
  configured channels after numeric `001`, responds to server `PING` with
  `PONG`, and feeds `PRIVMSG` payloads through the native PRE parser.
- The runner returns aggregate session counts and parsed candidates without
  exposing configured passwords, channel names, server details, or raw titles
  in the worker JSON report.
- Added session validation for required identity and channel names.
- The IRC dry-run plan now reports `session_ready=true` alongside
  `parser_ready=true`; replacement readiness still fails closed because the
  production worker loop, `predb` writes, and search side effects are not yet
  native-owned.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./internal/irc/plan.go ./internal/irc/plan_test.go ./internal/irc/session.go ./internal/irc/session_test.go ./cmd/nntmux-worker/main_test.go && /usr/local/go/bin/go test ./internal/irc ./cmd/nntmux-worker -run "Irc|IrcSample" -count=1'
docker compose -f docker-compose.native-test.yml run --rm go-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run RequireReplacementReady -count=1
native/scripts/audit-native-replacement-readiness.sh
git diff --check
```

Observed: fake IRC server tests proved native login/join/PING/PONG/PRE parsing,
focused IRC worker report tests passed, replacement-readiness guards still
reject IRC as non-ready, the full native replacement audit passed, and
whitespace diff checks passed.

### Task 97: IRC Native Predb Write Contract

**Status:** Completed.

**Goal:** Move the IRC lane from parse/session-only native behavior to guarded
native `predb` write ownership for parsed PRE candidates, without claiming
production search indexing readiness.

**Files:**

- `native/internal/irc/write_rehearsal.go`
- `native/internal/irc/write_rehearsal_test.go`
- `native/internal/irc/plan.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Added native `predb` insert/update write handling for parsed IRC PRE
  candidates.
- The native write contract preserves key PHP behavior: insert by new title,
  update existing rows by title, update size/source/files/nuke fields, and only
  fill category when the existing category is empty.
- `nntmux-worker --dry-run --rehearse-writes --irc-sample <file>` now performs
  rollback-only IRC `predb` write rehearsal in the native test DB.
- `nntmux-worker --commit-lane-writes --irc-sample <file>` now supports the
  `irc` job under the existing native test DB and Redis lane lock guards.
- IRC JSON reports include `irc_write_rehearsal` and `irc_write_commit`
  aggregate counts only; raw titles, channel names, Redis keys, and credentials
  remain redacted from reports.
- Replacement readiness still fails closed because production IRC socket
  lifecycle, production `predb` write deployment, and search indexing side
  effects are not yet native-owned.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./internal/irc/write_rehearsal.go ./internal/irc/write_rehearsal_test.go && /usr/local/go/bin/go test ./internal/irc -run "PredbWrites|Parse|Session|BuildPlan" -count=1'
docker compose -f docker-compose.native-test.yml run --rm go-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./cmd/nntmux-worker/main.go && /usr/local/go/bin/go test ./cmd/nntmux-worker -run "Irc|IrcSample" -count=1'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./cmd/nntmux-worker/main_integration_test.go && /usr/local/go/bin/go test ./internal/irc ./cmd/nntmux-worker -run "Irc|IRCPredb|PredbWrites" -count=1'
docker compose -f docker-compose.native-test.yml run --rm go-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./internal/irc/plan.go ./internal/irc/plan_test.go ./internal/irc/session.go ./internal/irc/session_test.go ./internal/irc/write_rehearsal.go ./internal/irc/write_rehearsal_test.go ./cmd/nntmux-worker/main.go ./cmd/nntmux-worker/main_test.go && /usr/local/go/bin/go test ./internal/irc ./cmd/nntmux-worker -run "Irc|IrcSample" -count=1'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/irc ./cmd/nntmux-worker -run "Irc|IRCPredb|PredbWrites" -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run RequireReplacementReady -count=1
native/scripts/audit-native-replacement-readiness.sh
git diff --check
```

Observed: package-level rollback/commit SQL tests passed, worker JSON
rehearsal and Redis-lock commit integration tests passed, replacement-readiness
guards still reject IRC as non-ready, the full native replacement audit passed,
and whitespace diff checks passed.

### Task 98: IRC Native Predb Group Resolution

**Status:** Completed.

**Goal:** Close the `groups_id=0` gap in native IRC `predb` writes by resolving
the parsed `[RQ: request:group]` group name to `usenet_groups.id`, matching the
PHP scraper's insert/update behavior without broadening the remaining IRC
search-side-effect contract.

**Files:**

- `native/internal/irc/write_rehearsal.go`
- `native/internal/irc/write_rehearsal_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Native IRC `predb` write rehearsal/commit now resolves non-empty
  `Candidate.GroupName` values through `usenet_groups.name` inside the write
  transaction.
- New `predb` inserts store the resolved `groups_id`; existing `predb` rows
  update `groups_id` when the incoming request group resolves.
- Unknown or absent request groups remain `groups_id=0`, matching PHP's empty
  group-id behavior without failing otherwise valid PRE messages.
- Package and CLI integration fixtures now seed `usenet_groups` and assert
  both inserted and updated `predb.groups_id` values.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./internal/irc/write_rehearsal.go ./internal/irc/write_rehearsal_test.go ./cmd/nntmux-worker/main_integration_test.go && /usr/local/go/bin/go test ./internal/irc -run "PredbWrites|Parse|Session|BuildPlan" -count=1'
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/irc ./cmd/nntmux-worker -run "Irc|IRCPredb|PredbWrites" -count=1
```

Observed: direct IRC SQL tests and worker integration tests passed with resolved
group IDs on both insert and update paths. Replacement readiness remains blocked
by search indexing side effects and live production rollout proof.

### Task 99: IRC Native Run-Lane Session Execution

**Status:** Completed.

**Goal:** Move the `irc` worker's `--run-lane` path from dispatching PHP
`irc:scrape` to running the native IRC session parser and guarded `predb` write
path directly under the exported distributed-worker Redis lock.

**Files:**

- `native/internal/irc/config.go`
- `native/internal/irc/config_test.go`
- `native/internal/irc/plan.go`
- `native/internal/irc/plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/NativeWorkerLaneRunner.php`
- `tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php`
- `.env.example`
- `native/scripts/audit-native-replacement-readiness.sh`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Added native IRC runtime config from existing `SCRAPE_IRC_*` environment
  values plus native-only session bounds:
  `NNTMUX_NATIVE_WORKER_IRC_CHANNEL`,
  `NNTMUX_NATIVE_WORKER_IRC_MAX_LINES`, and
  `NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES`.
- `nntmux-worker --run-lane` for `irc` now validates the held Redis worker lock,
  opens the IRC socket natively, logs in, joins the configured channel, parses
  PRE messages, and commits parsed `predb` writes directly through Go.
- Laravel's `NativeWorkerLaneRunner` no longer treats `irc` as command-only, so
  it passes `--mysql-dsn-env` and forwards `SCRAPE_IRC_*` plus native IRC bounds
  into the worker process.
- Worker JSON reports include aggregate `irc_session`, `irc_write_commit`, and
  `native_lane` data without leaking DSNs, Redis keys, server/port, channels,
  credentials, titles, or command arguments.
- At this stage, replacement readiness blockers reflected that socket/session
  execution was native while IRC search indexing side effects and live rollout
  verification still blocked replacement-ready status.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  sh -lc '/usr/local/go/bin/gofmt -w ./internal/irc/config.go ./internal/irc/config_test.go ./internal/irc/plan.go ./internal/irc/plan_test.go ./cmd/nntmux-worker/main.go ./cmd/nntmux-worker/main_test.go ./cmd/nntmux-worker/main_integration_test.go && /usr/local/go/bin/go test ./internal/irc ./cmd/nntmux-worker -run "RuntimeConfig|NativeIrc|Irc|IRCPredb|PredbWrites|RequireReplacementReady" -count=1'
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php --filter 'native_lane_with_plan|irc|command_only'
```

Observed: focused Go tests passed against a fake IRC server that proves native
login/join/PRE parsing and `predb` insert/update commits, and focused PHP tests
passed for DB DSN requirements plus IRC environment forwarding.

### Task 100: Metadata-Refresh Native SRRDB Details Fetch

**Status:** Completed.

**Goal:** Replace the metadata-refresh SRRDB title CRC placeholder with native
provider-backed SRRDB `/details/{title}` ingestion.

**Files:**

- `native/internal/metadata/srrdb_client.go`
- `native/internal/metadata/srrdb_client_test.go`
- `native/internal/metadata/refresh_plan.go`
- `native/internal/metadata/write_rehearsal.go`
- `native/internal/metadata/refresh_plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/NativeWorkerLaneRunner.php`
- `app/Services/Distributed/NativeWorkerCommitRunner.php`
- `.env.example`
- `docs/distributed-workers.md`
- `native/scripts/audit-native-replacement-readiness.sh`

**Changes:**

- Native metadata-refresh write rehearsal and guarded commit now fetch SRRDB
  title details for selected `predb.source = srrdb` candidates before inserting
  `predb_crcs`.
- The SRRDB client uses `NNTMUX_SRRDB_BASE_URL`,
  `NNTMUX_METADATA_REFRESH_TIMEOUT`, the same user-agent as PHP, and the
  exported metadata `--sleep-ms` pacing.
- SRRDB title CRC inserts now use provider-returned valid file CRC/size pairs,
  deduplicated per title. Invalid file names, sizes, and CRCs are ignored.
- JSON reports expose aggregate `metadata_refresh_srrdb_fetch` and write-result
  detail counts without leaking provider URLs, titles, CRCs, DSNs, Redis keys,
  or command arguments.
- PHP native lane and commit runners now forward metadata refresh/SRRDB config
  into native and command-only child processes.
- Replacement readiness now blocks on the remaining PHP-owned metadata search
  providers and SRRDB archive-CRC search instead of the stale blanket external
  metadata blocker. Task 101 removes the SRRDB archive-CRC half of that
  blocker.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/metadata ./cmd/nntmux-worker -run 'Srrdb|MetadataRefresh.*(WriteRehearsal|Commits|RequireReplacementReady)' -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php --filter 'native_lane_with_plan|held_lock'
```

Observed: the focused tests use local SRRDB HTTP fixtures and prove
provider-derived CRC rows are written in native commit mode.

### Task 101: Metadata-Refresh Native SRRDB Archive-CRC Search

**Status:** Completed.

**Goal:** Move the SRRDB archive-CRC lookup part of metadata-refresh from PHP
to native write rehearsal and guarded commit.

**Files:**

- `native/internal/metadata/srrdb_client.go`
- `native/internal/metadata/srrdb_client_test.go`
- `native/internal/metadata/refresh_plan.go`
- `native/internal/metadata/write_rehearsal.go`
- `native/internal/metadata/refresh_plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `docs/distributed-workers.md`
- `native/scripts/audit-native-replacement-readiness.sh`

**Changes:**

- Native now calls SRRDB
  `search/archive-crc:{crc}/archive-size:{size}` for selected local
  `release_files` CRC/size candidates when the metadata-refresh source set
  includes `all` or `srrdb`.
- Provider search hits create or reuse `predb` rows with `source = srrdb`, then
  insert the searched archive CRC/size into `predb_crcs` for those provider
  titles.
- The previous `native-archive-crc` filename-derived placeholder write path no
  longer runs for SRRDB archive candidates.
- Aggregate reports now include SRRDB archive queried/found/failed/hit counts.
  Raw release titles, CRC values, DSNs, Redis keys, provider URLs, and command
  arguments remain out of native JSON output.
- At this stage, metadata-refresh replacement readiness still blocked on
  preview/bulk-only metadata sources.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/metadata ./cmd/nntmux-worker -run 'Srrdb|MetadataRefresh.*(WriteRehearsal|Commits|RequireReplacementReady)' -count=1
native/scripts/audit-native-replacement-readiness.sh
```

Observed: local SRRDB HTTP fixtures prove native archive-CRC search paths and
provider-derived `predb` / `predb_crcs` writes.

### Task 102: Metadata-Refresh Native Provider Search Imports

**Status:** Completed.

**Goal:** Move rename-authoritative metadata search providers from PHP-owned
metadata-refresh writes into native write rehearsal and guarded commit.

**Files:**

- `native/internal/metadata/search_client.go`
- `native/internal/metadata/search_client_test.go`
- `native/internal/metadata/refresh_plan.go`
- `native/internal/metadata/write_rehearsal.go`
- `native/internal/metadata/refresh_plan_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/Distributed/NativeWorkerCommitRunner.php`
- `app/Services/Distributed/NativeWorkerLaneRunner.php`
- `tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php`
- `tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php`
- `.env.example`
- `docs/distributed-workers.md`
- `native/scripts/audit-native-replacement-readiness.sh`

**Changes:**

- Native now queries `predb-net`, `predb-ovh`, `xrel`, and `xrel-p2p` for
  selected metadata-refresh search queries when `--source` includes `all` or
  the explicit provider source.
- Provider hits create or reuse `predb` rows with the provider source. The old
  `native-search-query` placeholder write path is no longer used for provider
  search queries.
- Provider base URLs and metadata source toggles are exposed through env and
  forwarded by PHP native lane/commit runners.
- At this stage, metadata-refresh replacement readiness narrowed to
  preview/bulk-only metadata sources such as NZBIndex and Internet Archive
  dumps.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/metadata -run 'Srrdb|SearchProvider|BuildRefresh|RehearseMetadataRefresh|CommitMetadataRefresh' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'MetadataRefresh|RequireReplacementReady' -count=1
native/scripts/audit-native-replacement-readiness.sh
```

Observed: local provider HTTP fixtures prove native provider search request
shapes, parsing, and provider-derived `predb` writes without placeholder rows.

### Task 103: Metadata-Refresh PreDB Search Side-Effect Outbox

**Status:** Completed.

**Goal:** Move metadata-refresh `predb` search-index side effects into the
existing native side-effect handoff so PHP can sync newly imported PreDB rows
before skipping the command loop.

**Files:**

- `native/internal/metadata/write_rehearsal.go`
- `native/internal/metadata/refresh_plan_test.go`
- `native/internal/testdb/metadata_fixtures.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/NameFixing/NativeSearchSideEffectOutboxSync.php`
- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php`
- `tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php`
- `docs/distributed-workers.md`
- `native/scripts/audit-native-replacement-readiness.sh`

**Changes:**

- Native metadata-refresh commit now enqueues one
  `metadata-refresh` / `predb-search-sync` side-effect row for each newly
  imported `predb` row.
- Metadata write reports include `search_updates_enqueued`, and
  `writes_committed` includes those durable outbox writes.
- PHP pending-outbox sync now supports `predb-search-sync` rows by hydrating
  the current `predb` row and calling `Search::insertPredb`.
- PHP native lane commit handoff runs the outbox sync after successful
  metadata-refresh commits and fails before skipping the PHP command loop if
  sync fails.
- At this stage, metadata-refresh replacement readiness narrowed to
  preview/bulk-only metadata sources such as NZBIndex and Internet Archive
  dumps.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/metadata -run 'RehearseMetadataRefresh|CommitMetadataRefresh|SearchProvider|Srrdb' -count=1 -v
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'TestRun(PrintsMetadataRefreshJSONReportWithWriteRehearsal|CommitsMetadataRefreshWritesWithRedisLock|RequireReplacementReady)|TestMetadataRefreshIncludesSrrdbHonorsSourceArguments' -count=1 -v
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'metadata_predb|metadata_refresh_predb|regular_fixnames_status_commit|pending_native_search_outbox'
native/scripts/audit-native-replacement-readiness.sh
```

Observed: native metadata commits enqueue deterministic PreDB search outbox
rows, PHP syncs those rows through `Search::insertPredb`, and the catalog
readiness audit accepts the narrowed metadata-refresh blocker.

### Task 104: Metadata-Refresh Preview/Bulk Source Parity

**Status:** Completed.

**Goal:** Cover the remaining metadata-refresh preview/bulk-only source
behavior natively without introducing database writes or leaking source
payloads.

**Files:**

- `native/internal/metadata/preview_client.go`
- `native/internal/metadata/preview_client_test.go`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `.env.example`
- `docs/distributed-workers.md`
- `native/scripts/audit-native-replacement-readiness.sh`

**Changes:**

- Native metadata-refresh write rehearsal and guarded commit now run a
  no-write preview/bulk source summary after rename-authoritative provider
  fetches.
- NZBIndex preview search uses `NNTMUX_NZBINDEX_BASE_URL`,
  `NNTMUX_NZBINDEX_API_KEY`, `NNTMUX_METADATA_SOURCE_NZBINDEX`, the exported
  `--limit`, and `--sleep-ms` pacing, then reports only aggregate query and hit
  counts.
- Internet Archive PreDB remains an external bulk-import handoff and is counted
  as skipped without logging or reporting dump paths.
- Metadata-refresh replacement readiness no longer blocks on preview/bulk-only
  sources; the remaining metadata-refresh blocker is the embedded hashed
  fix-name commands that are deferred to PHP after native metadata commits.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/metadata -run 'Preview|SearchProvider|Srrdb|RehearseMetadataRefresh|CommitMetadataRefresh' -count=1 -v
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./cmd/nntmux-worker -run 'MetadataRefresh|RequireReplacementReady' -count=1 -v
native/scripts/audit-native-replacement-readiness.sh
git diff --check
```

Observed: local NZBIndex HTTP fixtures prove request shape and aggregate hit
counting, the metadata write reports include
`metadata_refresh_preview_source_fetch`, and readiness now fails closed only on
the remaining metadata-refresh hashed fix-name command deferral blocker.

### Task 105: IRC PreDB Search Side-Effect Outbox

**Status:** Completed.

**Goal:** Move IRC `predb` search indexing out of the remaining PHP-owned
blocker set by reusing the native side-effect outbox handoff after native IRC
commits.

**Files:**

- `native/internal/irc/write_rehearsal.go`
- `native/internal/irc/write_rehearsal_test.go`
- `native/internal/irc/plan.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/cmd/nntmux-worker/main_integration_test.go`
- `app/Services/NameFixing/NativeSearchSideEffectOutboxSync.php`
- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `docs/distributed-workers.md`
- `native/scripts/audit-native-replacement-readiness.sh`

**Changes:**

- Native IRC `predb` insert/update commits now enqueue one `irc` /
  `predb-search-sync` outbox row for each changed `predb` row.
- IRC write reports expose `search_updates_enqueued`, and
  `writes_committed` includes both `predb` writes and durable outbox rows.
- PHP pending-outbox sync now accepts `irc` predb-search rows and hydrates the
  current `predb` row through `Search::insertPredb`.
- PHP native lane execution runs the `irc` scoped outbox sync after successful
  native IRC run-lane execution and fails before skipping the PHP command loop
  if sync fails.
- IRC replacement readiness now narrows to live deployment verification.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  /usr/local/go/bin/go test ./internal/irc ./cmd/nntmux-worker -run 'Irc|IRCPredb|PredbWrites|RequireReplacementReady' -count=1 -v
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'irc|pending_irc|unscoped_pending_outbox'
native/scripts/audit-native-replacement-readiness.sh
git diff --check
```

Observed: native IRC commits enqueue deterministic PreDB search outbox rows,
PHP syncs those rows through `Search::insertPredb`, and the readiness audit now
fails closed for IRC only on live deployment verification.

### Task 106: Metadata-Refresh Embedded Hashed Fix-Name Deferral

**Status:** Completed.

**Goal:** Keep metadata-refresh native lane commits behavior-preserving when the
catalog embeds strong hashed fix-name commands by running only those deferred
PHP commands after the native metadata commit and PreDB search outbox sync.

**Files:**

- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `native/cmd/nntmux-worker/main.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/scripts/audit-native-replacement-readiness.sh`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Successful native `metadata-refresh` lane commits now continue with deferred
  non-metadata commands instead of skipping the legacy command loop entirely.
- The deferred set excludes `predb:refresh-external-metadata`, so the native
  commit remains the only metadata-refresh writer while embedded
  `releases:fix-names` hashed commands still run through PHP.
- The metadata-refresh readiness blocker now names the explicit PHP deferral
  instead of implying an unsafe skipped side-effect path.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'metadata_refresh.*(outbox|embedded_hashed|lane_commit)'
docker compose -f docker-compose.native-test.yml run --rm go-test \
  go test ./cmd/nntmux-worker -run 'MetadataRefresh|RequireReplacementReady' -count=1
native/scripts/audit-native-replacement-readiness.sh
git diff --check
```

Observed: metadata-refresh native commit syncs the PreDB outbox before the
embedded hashed fix-name PHP commands run, the metadata refresh command itself
is not re-dispatched through PHP, and replacement readiness fails closed on the
explicit deferral blocker.

### Task 107: Fixnames Native Commit Unsupported-Method Deferral

**Status:** Completed.

**Goal:** Keep full `fixnames` catalog plans behavior-preserving when native
commits the supported regular method 15/19 status subset by running the
remaining PHP-owned fix-name commands after the native status/outbox commit.

**Files:**

- `app/Services/Distributed/DistributedJobWorker.php`
- `tests/Unit/Distributed/DistributedJobWorkerTest.php`
- `native/internal/fixnames/plan.go`
- `native/internal/fixnames/plan_test.go`
- `native/cmd/nntmux-worker/main_test.go`
- `native/scripts/audit-native-replacement-readiness.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/native-worker-lanes-test-plan.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Successful native `fixnames` lane commits now continue with deferred
  unsupported fix-name commands instead of skipping the full PHP command loop.
- The deferred set excludes native-supported regular methods `15` and `19` for
  regular categories, so those status/outbox updates are not re-run through PHP.
- The fixnames readiness blocker now names the explicit PHP deferral for
  remaining regular fix-name methods.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test \
  ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'fixnames.*(status_commit|unsupported_methods|lane_commit)|metadata_refresh.*embedded_hashed'
docker compose -f docker-compose.native-test.yml run --rm go-test \
  go test ./internal/fixnames ./cmd/nntmux-worker -run 'Fixnames|RequireReplacementReady' -count=1
native/scripts/audit-native-replacement-readiness.sh
git diff --check
```

Observed: native `fixnames` commit syncs search outbox rows before PHP runs the
remaining unsupported methods, native-supported methods 15/19 are not
re-dispatched through PHP, and readiness fails closed on the explicit
remaining-method deferral blocker plus full side-effect blockers.

### Task 108: Local Compose All-Lane Native Worker Runtime Proof

**Status:** Completed.

**Goal:** Prove the compose eval stack can use k3s-synced NNTP configuration
and execute the native worker handoff for the first lanes and every catalog
lane without exposing provider credentials or leaving worker locks behind.

**Files:**

- `native/scripts/sync-native-eval-nntp-from-k3s.sh`
- `docs/distributed-workers.md`
- `docs/native-worker-lanes-test-plan.md`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- The k3s NNTP sync helper now defaults to the actual media deployment
  `nntmux-web`, while still allowing `--deployment` and `--selector`
  overrides.
- Operator docs now state that the helper defaults to `$HOME/k3s.yaml`,
  namespace `media`, deployment `nntmux-web`, and `.env.native-eval`.
- Verified the compose eval stack with smoke-guarded native worker execution
  for first lanes and all catalog lanes.

**Verification:**

```bash
KUBECONFIG=$HOME/k3s.yaml native/scripts/sync-native-eval-nntp-from-k3s.sh --mode check --namespace media
NNTMUX_NATIVE_EVAL_LANES="binaries backfill releases" native/scripts/run-native-eval-first-lanes.sh
NNTMUX_NATIVE_EVAL_LANES="binaries backfill releases" native/scripts/run-native-eval-compose-workers.sh
native/scripts/run-native-eval-all-workers.sh
native/scripts/run-native-eval-compose-workers.sh
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T redis sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort"
native/scripts/audit-native-replacement-readiness.sh
git diff --check
```

Observed: the k3s check passed for 11 redacted NNTP keys from
`nntmux-web`; the first-lane runner completed `binaries`, `backfill`, and
`releases`; the one-shot compose services completed all 13 catalog lanes; the
PHP-orchestrated all-worker runner completed all 13 catalog lanes; the Redis
distributed-worker lock scan was empty; and the replacement-readiness audit
still fails closed with the expected blocker for every catalog lane.

### Task 109: All-Lane Commit Smoke Metadata-Refresh Deferred Command Fix

**Status:** Completed.

**Goal:** Keep the packaged PHP-orchestrated native lane commit smoke aligned
with the current metadata-refresh commit behavior, where native owns the
metadata write/outbox subset and PHP intentionally runs only the embedded
hashed fix-name commands afterward.

**Files:**

- `tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- The all-lane commit smoke now expects `metadata-refresh` to run the deferred
  hashed `releases:fix-names` commands after native metadata commit, while
  still rejecting any rerun of `predb:refresh-external-metadata`.
- The metadata-refresh smoke assertion no longer requires an archive-CRC
  provider hit in the packaged fake-provider run; it still asserts that native
  commits provider-backed `predb` rows and does not leave placeholder
  `native-archive-crc` / `native-search-query` rows.

**Verification:**

```bash
native/scripts/verify-php-native-lane-commit-smoke.sh
git diff --check
```

Observed: the packaged native binary plus PHP orchestrator committed all
supported lane-write proofs for `binaries`, `backfill`, `releases`,
`per-group`, `removecrap`, `metadata-refresh`, `post-tv`, `post-movies`,
`post-amazon`, and guarded `post-additional`.

### Task 110: Fixture-Backed Native Eval All-Lane Execution Proof

**Status:** Completed.

**Goal:** Prove the packaged native worker binary can execute every catalog
lane through the compose eval stack against deterministic fixture data, using
the exported Redis worker lock and fake Artisan leaf dispatch where appropriate.

**Files:**

- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Recorded the fixture-backed native eval worker proof as a durable plan task.

**Verification:**

```bash
native/scripts/run-native-eval-fixture-workers.sh
native/scripts/audit-native-eval-all-workers.sh
native/scripts/audit-native-replacement-readiness.sh
```

Observed: fixture-backed execution completed for all 13 catalog lanes:
`binaries`, `backfill`, `releases`, `fixnames`, `hashed-fixnames`,
`removecrap`, `post-additional`, `metadata-refresh`, `post-tv`, `post-movies`,
`post-amazon`, `irc`, and `per-group`. The all-worker eval audit also passed
for all 13 lanes with `replacement_ready=false`, and the replacement-readiness
audit still fails closed for every lane.

### Task 111: Current First-Lane Compose Worker Service Proof

**Status:** Completed.

**Goal:** Re-prove the operator-facing compose worker services for the first
native lanes after the all-lane fixture proof, using the same one-shot service
shape that deploys `binaries`, `backfill`, and `releases`.

**Files:**

- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Recorded current runtime evidence for the first native worker services.

**Verification:**

```bash
NNTMUX_NATIVE_EVAL_LANES="binaries backfill releases" native/scripts/run-native-eval-compose-workers.sh
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T redis sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort"
```

Observed: `native-binaries-worker`, `native-backfill-worker`, and
`native-releases-worker` each reported `native lane completed <lane>`, the
compose runner completed `binaries backfill releases`, and the post-run Redis
distributed-worker lock scan was empty.

### Task 112: Current All-Worker Compose Service Proof

**Status:** Completed.

**Goal:** Re-prove the deployable compose `native-workers` profile can run every
catalog lane through its one-shot worker service, while keeping replacement
readiness explicitly fail-closed for production replacement claims.

**Files:**

- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Recorded current runtime evidence for every native worker service in the
  compose profile.

**Verification:**

```bash
native/scripts/run-native-eval-compose-workers.sh
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T redis sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort"
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml --profile native-workers config --services | sort
native/scripts/audit-native-replacement-readiness.sh
```

Observed: all 13 worker services reported `native lane completed <lane>`:
`binaries`, `backfill`, `releases`, `fixnames`, `hashed-fixnames`,
`removecrap`, `post-additional`, `metadata-refresh`, `post-tv`, `post-movies`,
`post-amazon`, `irc`, and `per-group`. The post-run Redis distributed-worker
lock scan was empty, the compose profile listed the expected native worker
services, and the replacement-readiness audit still reported `replacement guard
ok` for every lane.

### Task 113: Compose Worker Service Preflight Guard

**Status:** Completed.

**Goal:** Make the deployable compose worker runner fail early when a requested
native lane does not have a matching `native-${lane}-worker` service in the
`native-workers` profile, before seeding data or starting any one-shot workers.

**Files:**

- `native/scripts/run-native-eval-compose-workers.sh`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- `run-native-eval-compose-workers.sh` now reads
  `docker compose --profile native-workers config --services` and verifies
  every requested `NNTMUX_NATIVE_EVAL_LANES` entry has a matching
  `native-${lane}-worker` service.
- The catalog guard test now asserts that the compose service runner keeps this
  runtime service preflight.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobCatalogTest.php --filter 'compose_service_runner|native_eval_compose_declares'
bash -n native/scripts/run-native-eval-compose-workers.sh
NNTMUX_NATIVE_EVAL_LANES="binaries backfill releases" native/scripts/run-native-eval-compose-workers.sh
native/scripts/audit-native-replacement-readiness.sh
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T redis sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort"
git diff --check
```

Observed: the focused catalog tests passed with 39 assertions, shell syntax
validation passed, the first-lane compose runner completed `binaries`,
`backfill`, and `releases` through the new preflight, the Redis lock scan was
empty, and the replacement-readiness audit still reported `replacement guard ok`
for every lane.

### Task 114: First-Lane Compose Native Commit Runner

**Status:** Completed.

**Goal:** Prove the operator-facing compose `native-workers` services can run
guarded native committed writes for the first native lanes: `binaries`,
`backfill`, and `releases`.

**Files:**

- `native/scripts/run-native-eval-first-lane-commit-workers.sh`
- `app/Services/Distributed/NativeWorkerCommitRunner.php`
- `native/internal/safety/mysql.go`
- `native/internal/safety/mysql_test.go`
- `native/internal/releases/write_rehearsal.go`
- `native/internal/releases/write_rehearsal_test.go`
- `tests/Unit/Distributed/DistributedJobCatalogTest.php`
- `tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php`
- `docs/superpowers/plans/2026-06-15-native-worker-lanes.md`

**Changes:**

- Added the first-lane compose commit runner, which starts a fake NNTP server,
  seeds deterministic eval data per lane, and runs `native-binaries-worker`,
  `native-backfill-worker`, and `native-releases-worker` with explicit native
  committed-write guards.
- The PHP commit runner now accepts the exact `nntmux_native_eval` database for
  guarded native test commits while keeping the committed-write env gates.
- The Go safety layer now has a commit-specific allowlist for
  `nntmux_native_eval` without broadening fixture or rollback-only mutation
  guards.
- Native release committed writes no longer insert the dropped
  `releases.nzb_guid` column, matching the migrated Laravel schema.

**Verification:**

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test php -l app/Services/Distributed/NativeWorkerCommitRunner.php
docker compose -f docker-compose.native-test.yml run --rm php-test php -l tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/safety -count=1
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerCommitRunnerTest.php --filter 'first_lane_native_commit|committed_test_path|connection_settings'
bash -n native/scripts/run-native-eval-first-lane-commit-workers.sh
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobCatalogTest.php --filter 'first_lane_commit_compose_runner|first_lane_runner|compose_service_runner'
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/releases -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'Releases|Commit' -count=1
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml --profile native-workers build webapp native-binaries-worker
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml up -d --force-recreate webapp
docker compose -f docker-compose.native-test.yml --profile native-worker-smoke build native-worker
NNTMUX_NATIVE_EVAL_LANES="binaries backfill releases" native/scripts/run-native-eval-first-lane-commit-workers.sh
native/scripts/audit-native-replacement-readiness.sh
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T redis sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort"
git diff --check
```

Observed: focused PHP and Go tests passed; the rebuilt eval app image and native
worker binary ran through the compose first-lane commit helper; `binaries`,
`backfill`, and `releases` each reported `native lane commit completed <lane>`;
the final runner reported `Native eval first-lane compose worker commits
completed: binaries backfill releases`; the Redis distributed-worker lock scan
was empty; and replacement readiness still failed closed with `replacement guard
ok` for every catalog lane.

## Validation

- `docker compose -f docker-compose.native-test.yml config`
- `docker compose -f docker-compose.native-test.yml run --rm php-test composer install --no-interaction --prefer-dist --ignore-platform-reqs --no-plugins`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/binaries -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Binaries' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/backfill -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Backfill' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/removecrap -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'RemoveCrap' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/postprocess -run 'PostTV|PostprocessPlanJSON' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostTV' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/postprocess -run 'PostMovie|PostAmazon|PostprocessPlanJSON' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostMovies|PostAmazon' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/postprocess -run 'PostAdditional|UnsupportedTypes' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostAdditional' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'PostAdditionalNativeLaneWithoutDeferredGuard' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostAdditionalNativeLaneQueue' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/releases -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'UnsupportedReleasesCommand' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Releases' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/pergroup -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/pergroup -run 'PerGroup|Commit|Rehearse' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'UnsupportedPerGroupCommand' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'PerGroup.*(WriteCommit|Command)' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PerGroup' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PerGroup.*(JSONReport|Commits|MariaDB|NativeLane)' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/fixnames -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'Fixnames|FixnamesCatalog' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'FixnamesNativeLaneCommands' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/irc -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'Irc|IrcCatalog' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'IrcNativeLaneCommand' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'MetadataRefreshNativeLaneCommands|HashedFixnamesNativeLaneCommands' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php --filter 'metadata_refresh|hashed_fixnames.*skips|command_only_.*(metadata|hashed)'`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run RequireReplacementReady -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/testdb ./cmd/nntmux-test-fixture`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-test-fixture`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'HashedFixName|Resolved|Rehearsal' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run TestRunRequireReplacementReadyRejectsHashedFixnamesCatalogWithUnsupportedMethods -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run TestRunPrintsHashedFixNameJSONReportWithWriteContractDetails -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker -run 'Resolved|Rehearse' -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker`
- `docker compose -f docker-compose.native-test.yml run --rm php-test php native/scripts/generate-worker-plan-fixtures.php tests/Fixtures/native-worker/catalog`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go run ./cmd/nntmux-worker --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json --dry-run`
- `native/scripts/verify-php-go-contract.sh`
- `native/scripts/verify-resolved-write-contract.sh`
- `native/scripts/verify-native-worker-image.sh`
- `native/scripts/verify-php-native-rename-apply-smoke.sh`
- `native/scripts/verify-php-native-rename-worker-smoke.sh`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker -run 'Commit|Resolved|Rehearse' -count=1`
- `docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm php-search-test ./vendor/bin/phpunit tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php`
- `docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php`
- `docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm php-search-test ./vendor/bin/phpunit tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php --filter pending_native_search_outbox`
- `docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerPlanExporterTest.php tests/Feature/Console/NativeWorkerPlanCommandTest.php tests/Unit/Distributed/DistributedJobCatalogTest.php tests/Unit/Distributed/DistributedJobWorkerTest.php tests/Unit/Distributed/DistributedJobWorkerSignalHandlerTest.php tests/Unit/Distributed/NativeWorkerShadowRunnerTest.php`
- `docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'fail_closed|fail_open|enabled_native_hashed_fixnames|long_running_worker|outbox_exception'`
- `docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'redacts_structured_native_output|truncated_native_output_fragments'`
- `docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWriteContractResolverTest.php tests/Feature/Console/NativeWriteContractResolveCommandTest.php`
- `docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeHashedFixNameRenameApplierTest.php tests/Feature/Console/NativeHashedFixNameRenameApplyCommandTest.php`
- `docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/searchdoc -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/searchdoc -count=1`
- `docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run TestRunPrintsSearchDocumentParityForPendingNativeOutboxRows -count=1`
- `git diff --check`

## Rollback

- Delete `NativeWorkerPlanExporter.php`, `NativeWorkerPlanExporterTest.php`, and `NativeWorkerPlanCommandTest.php`.
- Revert `NntmuxDistributedWorker.php` signature and `--native-plan` branch.
- Delete `native/`, `tests/Fixtures/native-worker/`, `docker-compose.native-test.yml`, and native-worker docs.
- Existing PHP worker execution remains unchanged because the new path is opt-in.

## Review gates

- Product: first slice is explicitly shadow/dry-run only.
- Design: JSON plan is versioned and small enough to review.
- Engineering: native committed DB writes are allowed only for explicit
  Compose native-test proof mode; production native writes, native lock
  ownership, and native search side-effect execution remain blocked. Native
  search document parity is fingerprint-only until a later task proves direct
  search backend writes. PHP-owned outbox terminal updates must remain guarded
  by claim identity.
- Security/trust: no secrets in exported plan or dry-run output.
  Native failure diagnostics are structurally redacted before final log
  bounding.
- Guardrail/approval: write-mode native execution is deferred.
- QA: Docker Compose commands are the authoritative verification path.
- Launch: not deployable as a replacement lane until follow-up production
  write-mode work proves committed MariaDB writes under lane ownership plus
  Laravel events for rename updates, retries, and production idempotency.
