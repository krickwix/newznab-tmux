# Native Worker Lanes Test Plan

Date: 2026-06-15
Branch: `feat/native-worker-lanes`

## Scope

This plan verifies the first native worker-lane slice only:

- Laravel exports a versioned native worker plan.
- Go validates and dry-runs plans for every distributed worker lane name.
- PHP workers can optionally run the native dry-run validator after acquiring
  their existing lane lock.
- Go can build a read-only MariaDB-backed dry-run plan for the
  `metadata-refresh` lane's external metadata phase.
- Go can build a read-only MariaDB-backed dry-run queue plan for the
  `binaries` lane's safe header-ingestion work.
- Go can build a read-only MariaDB-backed dry-run queue plan for the
  `backfill` lane's safe historical header-ingestion work.
- Go can build a read-only MariaDB-backed candidate-count plan for the
  `removecrap` lane's current cleanup fixture types.
- Go can build a read-only MariaDB-backed queue plan for the `releases` lane's
  group-level release-processing queue.
- Go can build read-only MariaDB-backed queue plans for the `post-tv`,
  `post-movies`, and `post-amazon` postprocess lanes.
- Go validates the exported logical distributed lock name and physical Redis
  key, then proves an explicit owner-token Redis lock round trip against that
  physical key.
- Go can build a read-only hashed fix-name mutation plan for the
  `metadata-refresh` lane's method 20/16 hashed passes.
- Go can expand hashed fix-name candidates into a read-only write contract that
  names the release update columns and required PHP-side effects for a later
  write-mode gate.
- Go can hydrate eligible `hashed-fixnames` search-outbox release documents in
  read-only parity mode and emit only release IDs plus stable document
  fingerprints.
- A profile-gated live Manticore smoke proves the existing PHP
  `ReleaseSearchIndexSync` side effect can refresh `releases_rt` after DB-side
  release mutations.
- The same Manticore smoke covers the durable PHP-owned pending outbox command
  path and verifies synced rows clear their claim lease.
- A profile-gated rename-apply smoke proves native hashed-fix JSON can be
  resolved by PHP, applied through `ReleaseUpdateService`, dispatch
  `ReleaseNameFixed`, and update the real Manticore document in Compose.
- A profile-gated native worker image smoke proves the packaged Go runtime
  artifact preserves dry-run-only execution and guarded rollback-only
  rehearsal.
- No native worker writes lane domain rows to MariaDB, search, or the
  filesystem.
- Existing PHP distributed worker execution remains unchanged unless
  `--native-plan` is explicitly used or runtime shadow validation is explicitly
  enabled.

## Local Tool Constraint

The host currently does not provide `php`, `composer`, or `go`. All verification commands use Docker Compose.

The compose services run as `${HOST_UID:-1000}:${HOST_GID:-1000}` and keep
tool caches inside each temporary container, so verification should not leave
root-owned files in the bind-mounted worktree. The Manticore smoke uses a
test-only image that copies `config/manticore.conf` into the container, avoiding
entrypoint ownership changes on the host file.

## Required Commands

Render the Compose test configuration:

```bash
docker compose -f docker-compose.native-test.yml config
```

Run Go unit tests:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./...
```

Regenerate committed PHP catalog fixtures when `DistributedJobCatalog` or
`NativeWorkerPlanExporter` changes:

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test php native/scripts/generate-worker-plan-fixtures.php tests/Fixtures/native-worker/catalog
```

Run the Go dry-run fixture:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go run ./cmd/nntmux-worker --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json --dry-run
```

Run the same fixture as a machine-readable report:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go run ./cmd/nntmux-worker --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json --dry-run --output=json
```

JSON mode is opt-in. The default text output remains the compatibility path for
operator dry-runs and `native/scripts/verify-php-go-contract.sh`.

Run MariaDB/Redis-backed native integration gates:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./...
```

This starts isolated `mariadb` and `redis` services with no host ports. The
native integration tests create their own minimal tables, seed deterministic
metadata-refresh candidates under a MySQL advisory lock, prove the Go planner
reads them without changing table contents, and prove an owner-token Redis lock
can acquire/release the Laravel-prefixed physical key for
`nntmux:distributed-worker:metadata-refresh`.

The Compose integration service sets
`NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1` and points at the allowlisted
`nntmux_native_test` schema. Tests refuse to reset tables unless both the flag
and a native-test database name are present.

For isolated Compose validation only, the metadata-refresh planner can commit
the representative `predb` and `predb_crcs` write subset in the native-test
schema:

```bash
docker compose -f docker-compose.native-test.yml run --rm \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json \
    --commit-lane-writes \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner local-metadata-refresh-commit-proof
```

This is a native-test proof only. It does not fetch external providers, update
release rows, dispatch Laravel events, update search, run hashed fix-name
subcommands, or claim production replacement readiness.

Run the focused safe-binaries planner gate while iterating on header-ingestion
planning:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/binaries -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Binaries' -count=1
```

This uses isolated MariaDB tables for `usenet_groups` and `short_groups` and
mirrors PHP `BinariesRunner::safeBinaries()` queue construction. It reports
`update_group_headers` for new or small-backlog groups and `part_repair` plus
bounded `get_range binaries` chunks for large backlogs. The native worker does
not contact NNTP providers, run `update_groups.php`, update group cursors, or
write any header/body rows in this slice.

The same fixture can be inspected through JSON output:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/binaries.json \
    --dry-run \
    --output=json \
    --binaries-max-messages=10000 \
    --binaries-max-headers=25000 \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

JSON mode reports aggregate binaries counts only under `binaries` and keeps
`writes: 0`; it does not include DSNs, Redis physical keys, command arguments,
or per-group command strings.

Add `--nntp-probe` to the same dry-run command when validating real provider
reachability. The native worker reads the configured NNTP environment,
authenticates when credentials are present, issues `GROUP` for the planned
queue groups, and reports only aggregate probe counts. It does not fetch
headers or write MariaDB rows.
Add `--nntp-overview-sample=N` when validating native overview parsing against
the planned `get_range` work. The worker issues bounded `OVER start-end`
requests, falls back to `XOVER` when needed, and reports aggregate
requested/received/parsed/malformed row plus byte/line counts only. It still
does not persist headers, parts, binaries, or cursor updates. The aggregate
report includes header/part candidate counts plus unique and duplicate
Message-ID counts so the next native persistence step has a measurable contract.

The same isolated fixture has a committed-write proof for representative
binaries cursor, header, and part writes:

```bash
docker compose -f docker-compose.native-test.yml run --rm \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/binaries.json \
    --commit-lane-writes \
    --output=json \
    --binaries-max-messages=10000 \
    --binaries-max-headers=25000 \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner local-binaries-commit-proof
```

This mode is test-only, requires both native test DB safety guards plus the
exported Redis worker lock, and supports the `binaries`, `backfill`,
`releases`, `per-group`, `removecrap`, `metadata-refresh`, and executable
postprocess catalogs. It commits representative DB writes in the Compose schema
for non-acquisition lanes; it does not make any lane production replacement-ready.
Committed `binaries` and `backfill` runs require `--nntp-overview-sample=N`.
That mode contacts the configured NNTP provider, samples planned overview rows,
commits cursor/header/part rows from those sampled candidates in the native-test
schema, and keeps JSON output aggregate-only.
For PHP-orchestrated lane commits, set
`NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE=N`; PHP passes the flag only for
`binaries`/`backfill` and forwards the `NNTP_*` provider environment plus
`USE_ALTERNATE_NNTP_SERVER` to the Go process.

Run the focused safe-backfill planner gate while iterating on historical
header-ingestion planning:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/backfill -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Backfill' -count=1
```

This uses isolated MariaDB tables for `usenet_groups` and `short_groups` and
mirrors PHP `BackfillRunner::safeBackfill()` eligibility plus
`buildSafeBackfillQueues()` chunk construction. It reports bounded
`get_range backfill` chunks, including final partial chunks down to the
provider first article, and interleaves queue entries by chunk across groups.
The native worker does not contact NNTP providers, run `update_groups.php`,
update group cursors, or write any header/body rows in this slice.

The same fixture can be inspected through JSON output:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/backfill.json \
    --dry-run \
    --output=json \
    --backfill-qty=75000 \
    --backfill-max-messages=20000 \
    --backfill-threads=4 \
    --backfill-groups=10 \
    --backfill-days=1 \
    --backfill-min-articles=100 \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

JSON mode reports aggregate backfill counts only under `backfill` and keeps
`writes: 0`; it does not include DSNs, Redis physical keys, command arguments,
or per-group command strings.

Add `--nntp-probe` to the same dry-run command when validating real provider
reachability for the planned historical groups. The probe opens the configured
NNTP server, authenticates when credentials are present, issues `GROUP`, and
reports aggregate probe counts only. It does not fetch headers or write
MariaDB rows.
Add `--nntp-overview-sample=N` when validating native overview parsing against
the planned historical ranges. The worker issues bounded `OVER start-end`
requests, falls back to `XOVER` when needed, and reports aggregate
requested/received/parsed/malformed row plus byte/line counts only. It still
does not persist headers, parts, binaries, or cursor updates. The aggregate
report includes header/part candidate counts plus unique and duplicate
Message-ID counts so the next native persistence step has a measurable contract.

The backfill fixture also has a committed-write proof for representative group
cursor, header, and part writes:

```bash
docker compose -f docker-compose.native-test.yml run --rm \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/backfill.json \
    --commit-lane-writes \
    --output=json \
    --backfill-qty=75000 \
    --backfill-max-messages=20000 \
    --backfill-threads=4 \
    --backfill-groups=10 \
    --backfill-days=1 \
    --backfill-min-articles=100 \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner local-backfill-commit-proof
```

Like the binaries commit proof, this is isolated Compose validation only. It
requires an allowlisted native-test schema, the explicit committed-test
environment guard, and the exported Redis worker lock.
Add `--nntp-overview-sample=N` to the same guarded commit command when proving
sample-derived acquisition writes. That mode contacts the configured NNTP
provider, samples planned overview rows, commits cursor/header/part rows from
those sampled candidates in the native-test schema, and keeps JSON output
aggregate-only.
For PHP-orchestrated lane commits, set
`NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE=N`; PHP passes the flag only for
`binaries`/`backfill` and forwards the `NNTP_*` provider environment plus
`USE_ALTERNATE_NNTP_SERVER` to the Go process.

When testing the PHP `backfill_days = 2` mode, pass
`--backfill-safe-date=YYYY-MM-DD`. The native worker rejects
`--backfill-days=2` without that explicit date so it cannot silently plan with
a zero-day cutoff.

Run the focused removecrap candidate-count gate while iterating on cleanup
planning:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/removecrap -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'RemoveCrap' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'RemoveCrapNativeLaneCommands' -count=1
```

This uses isolated MariaDB tables for `collections`, `releases`, and
`release_files` and mirrors the PHP predicates for the committed `removecrap`
catalog fixture's types: `gibberish`, `executable`, `hashed`, `short`,
`installbin`, `passwordurl`, `nzb`, `scr`, `passworded`, `sample`, `size`,
`codec`, `blfiles`, bounded-time subject/poster `blacklist`, and `par2only`
SQL plus hashed NZB-content detection.
The dry-run reports candidate counts, PHP row-operation counts, and
destructive PHP command counts only and keeps `writes: 0`.
For bounded-time `blacklist`, explicit plan arguments preserve
`--blacklist-id=<id>` through executable lane dispatch and filter the native
candidate query to that configured blacklist row.
For the PHP `fix_crap_opt=All` export, native expands the single no-`--type`
command into PHP's all-removal handler list for candidate planning while
preserving the single leaf command for executable lane dispatch; this excludes
`passwordurl` and `wmv_all`, matching `ReleaseRemoverService`.
The native planner also covers explicit `wmv_all` requests, but the committed
catalog fixture omits that type because PHP excludes it from all-removal runs.
Full-history blacklist search remains a follow-up gate.

Rollback-only `--rehearse-writes` executes the native subset of the delete
contract inside the native test transaction, deleting linked `collections`,
`release_files`, and `releases` for candidate releases, then rolling the
transaction back. The JSON report includes `collection_rows_affected`,
`release_file_rows_affected`, `release_rows_affected`, and
`writes_committed: 0`. The native worker still does not commit cleanup, delete
NZBs or images, update search, or run the full PHP descendant collection
cleanup path.

For isolated Compose validation only, the same native subset can commit linked
`collections`, `release_files`, and `releases` deletes in the native-test
schema:

```bash
docker compose -f docker-compose.native-test.yml run --rm \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/removecrap.json \
    --commit-lane-writes \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner local-removecrap-commit-proof
```

This remains a native-test proof only. The direct native report includes the
deleted release and collection IDs; the PHP-orchestrated lane commit validates
those IDs against `release_rows_affected` and `collection_rows_affected`,
deletes the matching search documents, and runs the existing descendant
collection cleanup path before deleting matching NZB/image files from the
internal native side-effect outbox. It does not run release deletion events or
claim production replacement readiness.
When `NNTMUX_NATIVE_WORKER_REMOVECRAP_PRODUCTION_COMMIT_ENABLED=true`, PHP can
invoke the same native removecrap commit path against the configured production
DSN with the lane-scoped `NNTMUX_NATIVE_ALLOW_PRODUCTION_COMMIT=removecrap`
guard instead of native-test commit guard variables. Replacement readiness
remains fail-closed until live rollout evidence proves that handoff.

The executable bridge dispatches the exported `releases:remove-crap` commands
under the held Laravel worker lock and defaults to serial cleanup command
dispatch. In that bridge mode PHP still owns production release events. The
native commit proof covers guarded native-test DB deletes for linked
`collections`, `release_files`, and `releases` rows plus the PHP-consumed
release file cleanup outbox handoff.

The same fixture can be inspected through JSON output:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/removecrap.json \
    --dry-run \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

JSON mode reports aggregate removecrap counts only under `removecrap` and
keeps `writes: 0`; it does not include DSNs, Redis physical keys, command
arguments, release GUIDs, release IDs, or search names. Handler families that
need blacklist/search integration or NZB content reads remain explicit
follow-up gates. `candidate_releases` is a unique release count;
`candidate_rows` mirrors the PHP row-operation count for joined handlers where
one release can appear more than once.

The executable bridge can be exercised directly with fake or guarded leaf
Artisan commands:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/removecrap.json \
    --run-lane \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

Run the focused postprocess queue planner gates while iterating on
postprocess planning:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/postprocess -run 'PostTV|PostprocessPlanJSON' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostTV' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/postprocess -run 'PostMovie|PostAmazon|PostprocessPlanJSON' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostMovies|PostAmazon' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Post(TV|Movies|Amazon)NativeLaneQueue' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/postprocess -run 'PostAdditional|UnsupportedTypes' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PostAdditional' -count=1
```

This uses isolated MariaDB tables for `settings` and `releases` and mirrors the
PHP `PostProcessRunner` predicates for:

- `multiprocessing:postprocess tv` and `ani`.
- `multiprocessing:postprocess mov`.
- `multiprocessing:postprocess ama`, expanded into books, music, console, and
  games bucket families.

Dry-run mode reports aggregate type counts, bucket-entry counts, thread
settings, renamed mode, and TV pipeline mode only. The executable bridge can
also dispatch the planned complete queues for `post-tv`, `post-movies`, and
`post-amazon` while the exported Laravel worker lock is already held. Native
owns bucket selection and command dispatch in that mode; `postprocess:guid` and
`postprocess:tv-pipeline` still own metadata providers, NZB/NFO reads, metadata
row writes, events, search updates, and release mutations.

The generated `post-additional` fixture is accepted as a mixed plan, but this
slice only summarizes the `add` and `nfo` postprocess bucket queues. Metadata
refresh and hashed fix-name commands that can be appended to that lane remain
deferred under `post-additional`; their detailed native reports stay scoped to
the `metadata-refresh` and `hashed-fixnames` lanes.

The same fixture can be inspected through JSON output:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/post-tv.json \
    --dry-run \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"

docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/post-amazon.json \
    --dry-run \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"

docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/post-additional.json \
    --dry-run \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

JSON mode reports aggregate postprocess counts only under `postprocess` and
keeps `writes: 0`; it does not include DSNs, Redis physical keys, command
arguments, release names, GUIDs, leftguid values, bucket commands, or
per-release details.

For isolated Compose validation only, executable postprocess plans can commit
the representative bucket-update subset in the native-test schema:

```bash
docker compose -f docker-compose.native-test.yml run --rm \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/post-tv.json \
    --commit-lane-writes \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner local-postprocess-commit-proof
```

This mode is native-test only. It commits representative release-row updates
for the planned postprocess buckets under the exported Redis worker lock and
reports `committed_release_ids` for the rows it changed. PHP-orchestrated
commit mode uses those IDs to run the existing release search-index sync path
before it skips the postprocess command loop, but it does not run metadata
providers, read NZBs or NFOs, dispatch Laravel events, or claim production
replacement readiness.
For `post-additional`, mixed plans with deferred metadata-refresh or hashed
fix-name commands still require `--allow-deferred-post-additional`; only the
add/NFO postprocess bucket subset is committed by this proof.

The executable bridge can be exercised directly with fake or guarded leaf
Artisan commands:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/post-tv.json \
    --run-lane \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

`post-additional` requires an explicit deferred-command guard before native
execution, because the full lane can combine `add`/`nfo` postprocess buckets
with deferred metadata-refresh and hashed fix-name commands:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/post-additional.json \
    --run-lane \
    --allow-deferred-post-additional \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

With the guard, native dispatches only add/NFO `postprocess:guid` leaf
commands and leaves embedded metadata-refresh and hashed-fixname commands
deferred to their own bridges. PHP only enables this handoff when
`NNTMUX_NATIVE_WORKER_POST_ADDITIONAL_DEFERRED_EXECUTION_ENABLED=true`.

Run the focused releases queue planner gate while iterating on release queue
planning:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/releases -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'UnsupportedReleasesCommand' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'Releases' -count=1
```

This uses isolated MariaDB tables for `settings`, `usenet_groups`, and
`collections` and mirrors PHP `ReleasesRunner::releases()` queue selection:
active/backfill groups are candidates, and only groups with at least one
collection are queued. It reports candidate groups, eligible groups,
no-collection skips, queue entries, effective `releasethreads` concurrency,
batch count, and `writes: 0`. The native worker does not run
`releases:process`, create releases, categorize, create NZBs, mutate
collections, update search, read files, or contact NNTP providers.

The same fixture can be inspected through JSON output:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/releases.json \
    --dry-run \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

JSON mode reports aggregate releases counts only under `releases` and keeps
`writes: 0`; it does not include DSNs, Redis physical keys, command arguments,
group names, group IDs, DNR command strings, collection IDs, or per-collection
details.

The releases fixture also has a committed-write proof for representative
release-row creation and collection linking:

```bash
docker compose -f docker-compose.native-test.yml run --rm \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/releases.json \
    --commit-lane-writes \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner local-releases-commit-proof
```

Like the other first-lane commit proofs, this is isolated Compose validation
only. It requires an allowlisted native-test schema, the explicit
committed-test environment guard, and the exported Redis worker lock. The
PHP-orchestrated lane commit handoff validates the reported
`committed_release_ids` against `release_rows_affected` and runs the existing
release search-index sync path before skipping the PHP releases command loop.

Run the focused per-group queue planner gate while iterating on the sequential
mode queue envelope:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/pergroup -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'UnsupportedPerGroupCommand' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'PerGroup' -count=1
```

This uses isolated MariaDB tables for `settings`, `usenet_groups`, and a
poison `collections` table and mirrors PHP `ReleasesRunner::updatePerGroup()`
queue selection: all active/backfill groups are queued, regardless of
collection rows. It reports candidate groups, queue entries, effective
`releasethreads` concurrency, batch count, and `writes: 0`. The native worker
does not run `group:update-all`, download headers, backfill, create releases,
run additional/NFO processing, contact NNTP providers, write NZBs/files,
mutate release tables, or update search.

The same fixture can be inspected through JSON output:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/per-group.json \
    --dry-run \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

JSON mode reports aggregate per-group counts only under `per_group` and keeps
`writes: 0`; it does not include DSNs, Redis physical keys, command arguments,
group names, group IDs, DNR command strings, collection IDs, or child-stage
details.

The per-group fixture also has a committed-write proof for representative
group `last_updated` updates:

```bash
docker compose -f docker-compose.native-test.yml run --rm \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/per-group.json \
    --commit-lane-writes \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner local-per-group-commit-proof
```

This is isolated Compose validation only. It requires an allowlisted
native-test schema, the explicit committed-test environment guard, and the
exported Redis worker lock. It does not run `group:update-all`, download
headers, backfill, create releases, run post-processing, contact NNTP
providers, write files, update search, or make the lane production
replacement-ready.

Run the focused regular fixnames command-envelope gate while iterating on
catalog/readiness reporting:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/fixnames -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'Fixnames|FixnamesCatalog' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'FixnamesNativeLaneCommands' -count=1
```

This is a no-DB gate: it parses the exported `fixnames` catalog plan, rejects
non-`fixnames` jobs, rejects unsupported command names or categories, reports
command count, unique methods, unique regular categories, limited commands,
update/set-status/show flags, replacement blockers, and `writes: 0`. It does
not open MariaDB, read release tables, produce rename candidates, build write
contracts, call PHP name-fixing code, contact NNTP, update search, or commit
anything. Supplying `--mysql-dsn` for this regular `fixnames` report remains
an unsupported DB planner path.

`--run-lane` can execute the exported command matrix under a held Redis worker
lock without opening MariaDB. Native validates that every command is
`releases:fix-names`, preserves method/category/limit/update/set-status/show
flags, and dispatches the existing PHP leaf command. PHP still owns release
rename/category/event/search side effects and name-fixing services. The PHP
runner treats this as command-only and does not require or pass
`NNTMUX_NATIVE_WORKER_MYSQL_DSN`.

The same fixture can be inspected through JSON output:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/fixnames.json \
    --dry-run \
    --output=json
```

JSON mode reports aggregate regular fixnames counts only under `fixnames` and
keeps `replacement_ready=false` and `writes: 0`; it does not include DSNs,
Redis physical keys, raw command arguments, regular category labels, raw
options, release IDs, names, GUIDs, or PHP command payloads.

Run the focused IRC command-envelope gate while iterating on no-network lane
reporting:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/irc -count=1
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run 'Irc|IrcCatalog' -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run 'IrcNativeLaneCommand' -count=1
```

This is a no-network gate: it parses the exported `irc` catalog plan, rejects
non-`irc:scrape` commands or command arguments, reports the command count,
`network_required=true`, replacement blockers, and `writes: 0`. It does not
open sockets, read IRC settings, log into IRC, write `predb`, update search,
or commit anything.

`--run-lane` can execute the native IRC session under a held Redis worker lock
with MariaDB. Native validates the no-argument envelope, reads the existing
`SCRAPE_IRC_*` settings plus native IRC bounds, opens the IRC socket, joins the
configured channel, parses PRE messages, commits `predb` inserts/updates, and
enqueues `irc` / `predb-search-sync` rows. PHP then syncs those outbox rows
through `Search::insertPredb` before skipping the legacy `irc:scrape` loop.

The same fixture can be inspected through JSON output:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/irc.json \
    --dry-run \
    --output=json
```

JSON mode reports aggregate IRC counts only under `irc` and keeps
`replacement_ready=false` and `writes: 0`; it does not include Redis physical
keys, raw command arguments, IRC server/channel/password settings, or parser
payloads.

Run the focused hashed fix-name planner gate while iterating on method 20/16
parity:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker
```

This uses the same isolated MariaDB service and proves the native planner can
report CRC/PAR-hash candidate renames, status-only misses, and write-contract
side effects without changing the seeded `releases`, `release_files`, `predb`,
`predb_crcs`, or `par_hashes` contents.

The same command can emit a structured JSON artifact with native summary,
hashed counts, and full write-contract detail under
`hashed_fixnames.write_contract`:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --dry-run \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

To rehearse the concrete status-update SQL from that write contract without
committing it, add `--rehearse-writes`. This is rollback-only and is guarded by
the native test database safety check:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --dry-run \
    --output=json \
    --rehearse-writes \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

The rehearsal executes only concrete `proc_crc32` and `proc_hash16k`
single-column updates inside one transaction, then rolls the transaction back.
Release rename updates remain blocked because category resolution,
`ReleaseNameFixed`, and search indexing are still PHP-owned side effects.

Resolve the native write contract through PHP without executing those side
effects:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --dry-run \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
  > storage/native-hashed-write-contract.json

docker compose -f docker-compose.native-test.yml run --rm php-test \
  php artisan nntmux:native-write-contract:resolve \
    --input=storage/native-hashed-write-contract.json \
  > storage/native-hashed-write-contract-resolved.json
```

The PHP resolver is read-only. It resolves the `categories_id` value using
`CategorizationService`, reports `ReleaseNameFixed` and search-update intent,
preserves single-column update intent, and omits poster/fromname values from
the output.

To rehearse the resolved release-update SQL without committing it, pass the PHP
oracle back to the native worker:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --dry-run \
    --output=json \
    --rehearse-writes \
    --resolved-write-contract ../storage/native-hashed-write-contract-resolved.json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This path is still rollback-only. The native worker validates the PHP oracle is
dry-run/read-only and only executes Go-planned release columns matched by
`release_id`. `ReleaseNameFixed` and search updates are not executed.

For the first committed-write proof, use the narrower miss-status commit mode:

```bash
docker compose -f docker-compose.native-test.yml run --rm \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --commit-miss-status \
    --output=json \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner local-commit-proof \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This is not production write mode. It is restricted to the allowlisted Compose
MariaDB schema, requires both `NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1` and
`NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1`, acquires the exported worker Redis
lock, and commits only true hashed-fix miss statuses (`crc-miss` and
`par-hash-miss`) for `proc_crc32` / `proc_hash16k`. Release renames,
`crc-predb-match-confirmation`, and events remain blocked. Native code still
does not execute search updates.

To prove the production-shaped lock handoff, seed or acquire the exported Redis
worker lock from Laravel and run the same commit path with `--lock-mode=held`.
Native validates that `plan.lock.redis_key` is currently owned by
`--lock-owner`, commits only after that check passes, and does not release the
key:

```bash
go run ./cmd/nntmux-worker \
  --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
  --commit-miss-status \
  --lock-mode=held \
  --output=json \
  --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
  --lock-owner "$LARAVEL_LOCK_OWNER" \
  --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

Missing or mismatched owners must fail before status columns are written. The
default `--lock-mode=acquire` remains the standalone verifier path and releases
the lock itself.

After the commit report is captured, the native commit should have inserted
pending durable outbox rows for committed miss-status releases. Run the
PHP-owned retryable search side-effect handoff:

```bash
php artisan nntmux:native-search-side-effects:sync --pending-outbox --limit=100
```

The outbox mode claims pending or expired `processing` `hashed-fixnames` /
`release-search-sync` rows, calls `ReleaseSearchIndexSync::forIds()` for
committed release IDs only, marks successful rows `synced`, and leaves failed
rows `pending` with sanitized retry metadata. The output is bounded aggregate
JSON and does not include DSNs, Redis keys, command arguments, release names,
or full native reports.

The explicit report-file handoff remains available for compatibility and unit
coverage:

```bash
php artisan nntmux:native-search-side-effects:sync \
  --input=storage/native-hashed-commit.json
```

The command accepts only committed `hashed-fixnames` reports with
`schema_version: 1`, `mode: shadow`, `dry_run: false`,
`write_commit.lock_acquired: true`, and matching native/write counts. It calls
`ReleaseSearchIndexSync::forIds()` for committed release IDs only, rejects
duplicate or malformed committed IDs, and refuses skipped/blocked-only
reports.

Run the full resolved write-contract handoff verifier:

```bash
native/scripts/verify-resolved-write-contract.sh
```

This script seeds the deterministic hashed-fix Compose MariaDB fixture through
`cmd/nntmux-test-fixture`, captures a Go dry-run JSON report, prepares a
file-backed SQLite DB for the PHP resolver's real categorization dependencies,
runs `nntmux:native-write-contract:resolve`, and feeds the PHP oracle back to
Go's `--rehearse-writes --resolved-write-contract` path. It fails unless the
PHP oracle resolves both release updates with no blocked rows and the final Go
rehearsal reports `rolled_back: true` and `writes_committed: 0`. It then runs
the guarded miss-status commit proof, prepares the disposable PHP search schema,
creates the throwaway Manticore index, runs
`nntmux:native-search-side-effects:sync --pending-outbox` against the pending
outbox rows, and then runs a second idempotency commit. The first commit must
report `writes_committed: 2`, `search_side_effect_rows_enqueued: 2`, and
`search_updates_enqueued: 2`; the outbox handoff must sync both committed
release IDs, and the second commit must report `writes_committed: 0`. Run this
serially with `go-integration-test`; the commands reseed and mutate the same
Compose MariaDB fixture tables.

`cmd/nntmux-test-fixture` also supports reusable seed fixtures for the first
native executable lanes:

```bash
go run ./cmd/nntmux-test-fixture --fixture binaries
go run ./cmd/nntmux-test-fixture --fixture backfill
go run ./cmd/nntmux-test-fixture --fixture releases
go run ./cmd/nntmux-test-fixture --fixture per-group
```

Those fixtures reuse the same `native/internal/testdb` schema and row builders
as the worker integration tests, so PHP-orchestrated smoke tests can seed the
same queue data that the Go `--run-lane` tests exercise. The fixture command
keeps `--action fingerprint` scoped to the hashed-fix fixture for now.

Run the PHP-orchestrated native lane execution smoke:

```bash
native/scripts/verify-php-native-lanes-smoke.sh
```

This builds the packaged native worker, copies the binary into the PHP smoke
container mount, then loops over `binaries`, `backfill`, `releases`,
`per-group`, `removecrap`, `post-tv`, `post-movies`, `post-amazon`,
`post-additional`, and the command-only `fixnames`, `metadata-refresh`,
`hashed-fixnames`, and `irc` lanes. For DB-backed lanes it reseeds the matching
deterministic MariaDB fixture through `nntmux-test-fixture` and runs
`DistributedJobWorker` with `NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED`.
It first uses a throwaway fake Artisan executable so the native worker must
build and execute each native lane queue, then repeats the first queue lanes
through real Laravel Artisan with an explicit startup-smoke guard that returns
before NNTP or release-processing side effects. Native records each command
line only after the real Artisan leaf exits successfully, so the smoke asserts
both the queue shape and the guarded real-Artisan startup path.
Command-only lanes run through the fake Artisan executable only, proving PHP
exports their command envelopes, native validates them without a MariaDB DSN,
and the native handoff skips the original PHP command loop after success.
The smoke runs `backfill` twice: once in the default
`backfill_days = 1` mode and once with `backfill_days = 2` plus an explicit
safe date, proving PHP forwards the safe-date branch and native dispatches the
additional eligible range from the deterministic fixture.
The older `native/scripts/verify-php-native-first-lanes-smoke.sh` path remains
as a compatibility wrapper for existing local commands.

For a live local Compose evaluation stack configured by `.env.native-eval`, run
the bounded first executable lanes directly:

```bash
native/scripts/deploy-native-eval-compose.sh
native/scripts/run-native-eval-first-lanes.sh
```

Or opt in to run `binaries`, `backfill`, and `releases` as part of the compose
deploy smoke:

```bash
NNTMUX_NATIVE_EVAL_RUN_FIRST_LANES=1 native/scripts/deploy-native-eval-compose.sh
```

To run every catalog lane as part of the compose deploy smoke, use the
all-worker opt-in instead:

```bash
NNTMUX_NATIVE_EVAL_RUN_ALL_WORKERS=1 native/scripts/deploy-native-eval-compose.sh
```

To run every catalog lane through the one-shot `native-workers` compose service
profile as part of the deploy smoke, use the compose-service opt-in:

```bash
NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS=1 native/scripts/deploy-native-eval-compose.sh
```

`NNTMUX_NATIVE_EVAL_RUN_FIRST_LANES` and
`NNTMUX_NATIVE_EVAL_RUN_ALL_WORKERS` and
`NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS` are mutually exclusive.

The deploy helper reuses the selected lane runner after the stack readiness
checks and metadata-refresh native dry-run. Custom
`NNTMUX_NATIVE_EVAL_ENV_FILE`, `NNTMUX_NATIVE_EVAL_COMPOSE_FILE`, lane list,
lock seconds, and real-leaf settings are preserved through the selected runner
handoff.

Before running real NNTP leaf commands, check or apply the current k3s media
deployment NNTP values without printing credentials:

```bash
native/scripts/sync-native-eval-nntp-from-k3s.sh --mode check
native/scripts/sync-native-eval-nntp-from-k3s.sh --mode apply
```

The helper defaults to `$HOME/k3s.yaml`, namespace `media`, deployment
`nntmux-web`, and `.env.native-eval`. It resolves direct env values plus
`envFrom`, `secretKeyRef`, and `configMapKeyRef` references, then reports only
redacted key names/counts. If the k3s API is unreachable from the local shell,
keep the existing `.env.native-eval` values and rerun the check when the
control-plane route is available.

This uses the same mounted packaged native binary as the eval webapp, prints
resolved native-plan summaries, executes `binaries`, `backfill`, and `releases`
once, and asserts that no distributed-worker Redis lock remains after the run.
It refuses non-eval app databases and seeds a minimal eval-only
group/collection/settings fixture so all three first lanes have work to plan.
For command-dispatch smoke runs that must not contact NNTP providers or execute
release-processing side effects, set `NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=1` and
`NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG=/tmp/nntmux-native-leaf-startup-smoke.log`
in `.env.native-eval` before recreating the webapp container. The runner
requires this smoke guard by default; set `NNTMUX_NATIVE_EVAL_ALLOW_REAL_LEAVES=1`
only when intentionally running the real PHP leaf commands.

When real leaves are enabled, the first-lane seed must use a group that exists
on the configured NNTP provider. The default `alt.binaries.native.eval` group
is only a deterministic smoke fixture. Set `NNTMUX_NATIVE_EVAL_GROUP_NAME` plus
the `NNTMUX_NATIVE_EVAL_GROUP_*_RECORD` and
`NNTMUX_NATIVE_EVAL_SHORT_GROUP_*_RECORD` bounds to a provider-visible range
before running real `binaries` or `backfill` leaves.

To run every currently enabled distributed worker once through the same
PHP-orchestrated native executable handoff in the eval stack, run:

```bash
native/scripts/run-native-eval-all-workers.sh
```

This seeds deterministic eval settings/data, configures each lane's routing
mode, requires every default lane to resolve as enabled, requires every lane to
report `native lane completed <lane>`, and asserts that no distributed-worker
Redis lock remains after the run. Its default lane list is covered by the
catalog sync unit test so newly added workers cannot be omitted from executable
eval coverage.

The same eval compose file declares deployable one-shot services for every
catalog lane under the `native-workers` profile. The services run
`php artisan nntmux:worker <lane> --once --stop-on-disabled`, inherit the
mounted native worker binary and eval DSN/Redis settings, and set
`NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED=true`:

```bash
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml \
  --profile native-workers config --services

docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml \
  --profile native-workers run --rm native-binaries-worker

docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml \
  --profile native-workers run --rm native-backfill-worker

docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml \
  --profile native-workers run --rm native-releases-worker
```

The declared service set is guarded against `DistributedJobCatalog` so every
lane has a matching `native-<lane>-worker` compose entry.

Run the entire compose service set through the deterministic eval seed:

```bash
native/scripts/run-native-eval-compose-workers.sh
```

This configures each lane, runs the matching `native-<lane>-worker` service,
requires `native lane completed <lane>`, and asserts that no distributed-worker
Redis lock remains after all services exit.

To exercise every catalog fixture through the mounted native binary in the eval
stack regardless of current app settings, run:

```bash
native/scripts/run-native-eval-fixture-workers.sh
```

This stages the committed catalog fixtures into the native eval mount, creates
a temporary allowlisted fixture database (`nntmux_native_test_eval_fixture` by
default), reseeds deterministic fixture tables for DB-backed lanes there under
`NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1`, holds each exported Redis worker
lock, and dispatches every lane through `--run-lane` with a fake Artisan leaf
runner. The fixture database is dropped on exit, so the app eval database is
not reset by this runner. It fails unless each fixture reports at least one
successful native lane command. This is an eval-only command-shape proof; it
intentionally does not run real NNTP, delete, postprocess, search, IRC, or
metadata leaf side effects.

To validate all distributed workers in the same eval stack without executing
leaf commands, run:

```bash
native/scripts/audit-native-eval-all-workers.sh
```

The audit resolves every catalog lane through Laravel, sends each plan to the
mounted native worker with `--dry-run --output=json`, validates the JSON report,
and only supplies the native MariaDB DSN for DB-backed lanes with commands. It
uses the same deterministic eval seed/configuration helpers as the executable
runner and fails if any catalog lane resolves disabled. The JSON validation
requires the report to remain a dry-run, match `native_worker.job` to the lane,
keep `native_worker.writes: 0`, keep `native_worker.replacement_ready: false`,
and expose at least one `native_worker.replacement_readiness.blockers` entry.

Current compose evidence from the native eval stack:

- `native/scripts/audit-native-eval-all-workers.sh` reported `enabled=true` and
  native dry-run OK with replacement-readiness blockers for all 13 catalog
  lanes.
- `native/scripts/run-native-eval-all-workers.sh` reported native completion
  for `binaries`, `backfill`, `releases`, `fixnames`, `hashed-fixnames`,
  `removecrap`, `post-additional`, `metadata-refresh`, `post-tv`,
  `post-movies`, `post-amazon`, `irc`, and `per-group`.
- The post-run Redis scan for `*nntmux:distributed-worker*` returned no keys.

Run the packaged native worker image smoke:

```bash
native/scripts/verify-native-worker-image.sh
```

This builds the multi-stage `native-worker` image, runs the packaged binary
against a committed metadata-refresh plan fixture, runs every committed catalog
plan fixture through the packaged image in JSON dry-run mode, proves the binary
still rejects execution without `--dry-run`, seeds the deterministic hashed-fix
MariaDB fixture, and then runs JSON dry-run plus rollback-only rehearsal from
the packaged image. The `native-worker` service does not set
`NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB` by default; the smoke first proves
`--rehearse-writes` is refused without that guard, then passes the guard
explicitly for the rollback-only check. It fails unless the packaged image
reports `dry_run: true`, `native_worker.writes: 0`,
`native_worker.replacement_ready: false`, and at least one
`native_worker.replacement_readiness.blockers` entry for every catalog plan,
and also proves each packaged catalog plan fails closed with
`--require-replacement-ready`. It then checks a write contract with `writes: 0`,
`rolled_back: true`, and `writes_committed: 0` for the hashed-fix rehearsal
path. It also captures an
all-column hashed-fix MariaDB fixture fingerprint before and after
packaged-image rehearsal and fails if any fixture table changes, including
release metadata columns that resolved rehearsal may update. Run this verifier
serially with `go-integration-test`; both commands reseed the same Compose
MariaDB fixture tables. The same smoke also proves packaged commit mode is
refused without `NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1`, then commits the two
miss-status rows with the guard enabled and verifies the fixture fingerprint
changes.

Run the PHP-to-Go contract check:

```bash
native/scripts/verify-php-go-contract.sh
```

This regenerates plans into `storage/native-worker-plan-contract`, diffs them
against `tests/Fixtures/native-worker/catalog`, then dry-runs every generated
plan through the Go validator.

Install PHP dependencies in the CLI test container. The container uses PHP 8.5
from `composer:2`. It ignores platform requirements because this first slice
does not exercise the media/NNTP/search extensions that the full runtime image
installs:

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test composer install --no-interaction --prefer-dist --ignore-platform-reqs --no-plugins
```

Run focused PHP tests. Use direct PHPUnit file paths for this compose harness;
`php artisan test` bootstraps the console app first and can touch unrelated
settings-backed services before PHPUnit receives the filter.

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWorkerPlanExporterTest.php tests/Feature/Console/NativeWorkerPlanCommandTest.php tests/Unit/Distributed/DistributedJobCatalogTest.php tests/Unit/Distributed/DistributedJobWorkerTest.php
```

Run PHPStan against changed native PHP surfaces with a small prepared SQLite
settings database. Larastan boots the application, and categorization services
read `settings` during bootstrap:

```bash
docker compose -f docker-compose.native-test.yml run --rm -e DB_CONNECTION=sqlite -e DB_DATABASE=/var/www/html/storage/phpstan-native-lanes.sqlite php-test php -r '$db = new PDO("sqlite:/var/www/html/storage/phpstan-native-lanes.sqlite"); $db->exec("CREATE TABLE settings (name varchar(255) primary key, value text null)"); $stmt = $db->prepare("INSERT INTO settings (name, value) VALUES (?, ?)"); foreach (["categorizeforeign" => "0", "catwebdl" => "0", "innerfileblacklist" => ""] as $name => $value) { $stmt->execute([$name, $value]); }'
docker compose -f docker-compose.native-test.yml run --rm -e DB_CONNECTION=sqlite -e DB_DATABASE=/var/www/html/storage/phpstan-native-lanes.sqlite php-test ./vendor/bin/phpstan analyse app/Services/Distributed/NativeWorkerLaneRunner.php app/Services/Distributed/NativeWorkerLaneResult.php app/Services/Distributed/NativeWorkerCommitRunner.php app/Services/Distributed/NativeHashedFixNameRenamePrepassRunner.php app/Services/Distributed/DistributedJobWorker.php app/Services/NameFixing/NativeHashedFixNameRenameApplier.php app/Services/NameFixing/NativeSearchSideEffectOutboxSync.php app/Services/NameFixing/NativeHashedFixNameWriteContractResolver.php app/Services/NameFixing/NativeHashedFixNameSearchSync.php app/Console/Commands/ApplyNativeHashedFixNameRenames.php app/Console/Commands/ResolveNativeWriteContract.php app/Console/Commands/SyncNativeSearchSideEffects.php config/nntmux.php --memory-limit=2G
docker compose -f docker-compose.native-test.yml run --rm php-test php -r 'if (is_file("/var/www/html/storage/phpstan-native-lanes.sqlite")) { unlink("/var/www/html/storage/phpstan-native-lanes.sqlite"); }'
```

Run the runtime shadow hook and process-wrapper tests:

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php tests/Unit/Distributed/DistributedJobWorkerSignalHandlerTest.php tests/Unit/Distributed/NativeWorkerShadowRunnerTest.php tests/Unit/Distributed/NativeWorkerLaneRunnerTest.php
```

Run the PHP native write-contract oracle tests:

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeWriteContractResolverTest.php tests/Feature/Console/NativeWriteContractResolveCommandTest.php
```

Run the PHP-owned resolved rename apply proof:

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeHashedFixNameRenameApplierTest.php tests/Feature/Console/NativeHashedFixNameRenameApplyCommandTest.php
```

Run the end-to-end PHP-owned rename apply smoke:

```bash
native/scripts/verify-php-native-rename-apply-smoke.sh
```

This reseeds the deterministic hashed-fix fixture, captures native JSON, runs
the PHP resolver and apply command, observes real `ReleaseNameFixed` events,
and verifies the updated `releases_rt` Manticore documents for release IDs
`100` and `300`.

Run the worker-orchestrated native prepass smokes:

```bash
native/scripts/verify-php-native-hashed-worker-smoke.sh
native/scripts/verify-php-native-rename-worker-smoke.sh
```

These reseed the Compose fixtures and should run serially with other
MariaDB/Manticore smoke scripts. They prove the packaged native binary can run
under Laravel's held `hashed-fixnames` worker lock, keep DSN/lock details out
of argv/output, and continue the existing PHP command loop.

Run the resolved write-rehearsal tests:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/namefix ./cmd/nntmux-worker -run 'Resolved|Rehearse' -count=1
```

Run the hashed fix-name replacement-readiness report guard:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run TestRunRequireReplacementReadyRejectsHashedFixnamesCatalogWithUnsupportedMethods -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run TestRunPrintsHashedFixNameJSONReportWithWriteContractDetails -count=1
```

Run the universal replacement-readiness guard for every catalog lane:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./cmd/nntmux-worker -run RequireReplacementReady -count=1
native/scripts/audit-native-replacement-readiness.sh
```

This proves `--require-replacement-ready` fails closed for the current catalog
lanes. Normal JSON dry-runs expose
`native_worker.replacement_ready=false` and
`native_worker.replacement_readiness.blockers` for every lane; the hard guard
still fails before JSON reports, MariaDB planners, or write paths can run, and
includes lane-specific PHP-owned side-effect blockers for operator triage.
The audit script runs the hard guard against every committed catalog fixture
and fails if a lane omits its expected PHP-owned production blocker. It also
fails if replacement-readiness output leaks DSNs, Redis keys, command
arguments, or physical cache-key prefixes.

Run the held-lock native commit tests:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/lock ./cmd/nntmux-worker -run 'Held|RedisLock' -count=1
```

Run the hashed-fixnames prepass fail-closed policy tests:

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'fail_closed|fail_open|enabled_native_hashed_fixnames|long_running_worker|outbox_exception'
```

This keeps the default fail-open behavior covered while proving the opt-in
fail-closed policy stops before the PHP command loop for configured native
prepass failures, including the default long-running worker loop and thrown
search outbox sync errors.

Run the native failure-output redaction checks:

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/DistributedJobWorkerTest.php --filter 'redacts_structured_native_output|truncated_native_output_fragments'
```

These tests prove worker logs redact structured native stderr/stdout before
final byte bounding across shadow validation, miss-status prepass, rename
prepass, and outbox exception diagnostics. Covered sensitive forms include CLI
flag values, JSON `redis_key`/lock-owner fields, command `arguments`,
release/search-name fields, Redis physical key prefixes, and partial DSN
fragments from already-truncated runner output.

Run the opt-in live Manticore search side-effect smoke:

```bash
docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm php-search-test ./vendor/bin/phpunit tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php
```

This starts isolated MariaDB, Redis, and Manticore services without publishing
host ports. The smoke uses a PHP runner with `pdo_mysql`,
`NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1`, and the allowlisted
`nntmux_native_test` schema. It recreates the throwaway `releases_rt` index,
mutates a release through MariaDB, runs `nntmux:native-search-side-effects:sync`,
and verifies the indexed Manticore document changed. It proves the PHP-owned
search side effect native write mode must preserve; it does not make native
code execute search updates.

Run the focused PHP-owned pending-outbox hardening checks:

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test ./vendor/bin/phpunit tests/Unit/Distributed/NativeSearchSideEffectSyncTest.php tests/Feature/Console/NativeSearchSideEffectSyncCommandTest.php
docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm php-search-test ./vendor/bin/phpunit tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php --filter pending_native_search_outbox
```

These tests prove successful outbox rows clear `available_at`, stale claimants
cannot overwrite a newer claim or completion, failed rows keep bounded retry
metadata, and the public `--pending-outbox` command updates the real
`releases_rt` document in Compose without exposing release names, DSNs, Redis
keys, or backend error strings in command output.

Run the read-only native search-document parity gate:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go test ./internal/searchdoc -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./internal/searchdoc -count=1
docker compose -f docker-compose.native-test.yml run --rm go-integration-test go test ./cmd/nntmux-worker -run TestRunPrintsSearchDocumentParityForPendingNativeOutboxRows -count=1
```

This uses eligible pending or expired `processing` `hashed-fixnames` /
`release-search-sync` outbox rows as the native input and hydrates the
PHP-shaped release search document fields from MariaDB. The CLI emits
`search_documents` with release IDs and SHA-256 fingerprints only, keeps
`writes: 0`, suppresses raw write-contract payloads, and proves table
fingerprints do not change. It does not execute Manticore or Elasticsearch
writes.

Run final whitespace verification:

```bash
git diff --check
```

## Expected Evidence

- Compose config renders with `php-test` and `go-test`.
- Go tests pass and cover valid plan parsing, every supported distributed lane name, every committed catalog fixture, unsupported version, non-shadow rejection, empty PHP argument arrays, and dry-run summary.
- Go integration tests pass against Compose MariaDB/Redis and cover the
  read-only `metadata-refresh` candidate planner plus native Redis lock
  acquire/release behavior against the exported physical Redis key.
- Go integration tests cover the safe-binaries queue planner for active groups
  with provider cursor rows and prove it does not change MariaDB tables.
- Go integration tests cover the safe-backfill queue planner for enabled groups
  with provider cursor rows and prove it does not change MariaDB tables.
- Go tests cover the optional native NNTP `GROUP` probe for binaries/backfill
  dry-runs, including provider authentication, sanitized failures, unsupported
  lane rejection, and aggregate-only JSON output without DSNs, server addresses,
  ports, credentials, or group names.
- Go tests cover the optional native NNTP overview sampler for binaries/backfill
  dry-runs, including bounded `OVER` requests, sanitized failures, unsupported
  lane rejection, fallback to `XOVER`, malformed row accounting, and
  aggregate-only header/part candidate counts without provider endpoints,
  credentials, article subjects, message IDs, or group names.
- Go integration tests cover `--nntp-overview-sample` combined with
  `--rehearse-writes` for binaries/backfill, proving sampled overview rows
  drive rollback-only cursor, binary, and part write-shape checks while the JSON
  report stays aggregate-only and MariaDB fingerprints remain unchanged.
- Go integration tests cover the removecrap candidate planner for the current
  fixture types and prove it does not change MariaDB tables.
- Go integration tests cover the releases queue planner for active/backfill
  groups with collection rows and prove it does not change MariaDB tables.
- Go integration tests cover the per-group queue-envelope planner for
  active/backfill groups without collection filtering and prove it does not
  change MariaDB tables.
- Go tests cover the regular fixnames no-DB command-envelope/readiness report
  for the full catalog matrix and prove `--mysql-dsn` remains unsupported for
  this non-DB slice.
- Go tests cover the IRC no-network command-envelope/readiness report, native
  socket/session execution, `predb` writes, and PHP-synced search outbox
  handoff; live rollout proof remains the readiness blocker.
- Go tests cover the universal `--require-replacement-ready` guard and prove
  every current catalog lane fails closed unless it has an explicit
  replacement-ready implementation with no blockers.
- Go integration tests cover the post-tv TV/anime, post-movies movie,
  post-amazon books/music/console/games, and post-additional add/NFO bucket
  planners, lookup settings, size and retry boundaries, renamed filtering,
  aggregate-only JSON output, mixed-plan deferred command handling, and prove
  they do not change MariaDB tables.
- Go integration tests cover the hashed fix-name method 20/16 mutation planner
  and prove it reports candidate renames/status updates plus read-only
  write-contract side effects without DB writes.
- Go integration tests cover resolved PHP-oracle release-update rehearsal and
  prove release updates plus status updates run only inside rolled-back
  transactions.
- Go tests cover no-DB replacement readiness for the full hashed-fixnames
  catalog, an unsupported-only hashed plan, and the `--require-replacement-ready`
  guard. Go integration tests cover the matching DB-backed metadata. Together
  they prove methods `4`, `6`, `8`, `10`, `12`, `14`, `18`, and `21` remain
  unsupported while only methods `16` and `20` are implemented.
- Go integration tests cover the guarded hashed-fix miss-status commit proof
  and prove only true miss status columns commit in the native-test schema
  while release renames and search side effects remain blocked.
- The resolved write-contract verifier proves the Go dry-run JSON -> PHP oracle
  -> Go resolved rehearsal handoff works from repeatable Docker commands.
- The PHP-owned resolved rename apply proof validates guard, blocked, stale,
  duplicate, and redacted-output behavior before handing resolved release
  renames to `ReleaseUpdateService`.
- The end-to-end PHP-owned rename apply smoke proves the native JSON -> PHP
  resolver -> real DB mutation/event/search-index path in the Compose
  MariaDB/Manticore environment.
- The worker-orchestrated PHP-owned rename prepass smoke proves the packaged
  native binary can run under the Laravel-held hashed-fixnames worker lock,
  pass MariaDB DSN via environment, resolve/apply native-supported method
  `16`/`20` renames through PHP, emit `ReleaseNameFixed`, update Manticore,
  and continue the existing PHP command loop without exposing DSNs in argv or
  worker output.
- The opt-in Manticore smoke proves `ReleaseSearchIndexSync::forIds()` updates
  `releases_rt` after DB-side release mutations in the Compose MariaDB/Manticore
  environment. Its pending-outbox case also proves the durable outbox command
  path syncs the real search document and clears the successful row's claim
  lease.
- `tests/Fixtures/native-worker/catalog/` contains one generated JSON fixture per `DistributedJobCatalog` lane.
- Go dry-run prints the `metadata-refresh` job, the existing distributed lock name, and command count.
- PHP-generated exporter JSON for every catalog lane matches the committed catalog fixtures and is accepted by the Go dry-run validator.
- PHP tests pass and prove the plan exporter preserves existing lane command structure.
- `--native-plan` exits before `DistributedJobWorker::run`, so it does not acquire locks or execute lane commands.
- Exported plans include both the logical lock name
  `nntmux:distributed-worker:{job}` and the physical Redis key built from the
  configured Redis connection prefix plus Laravel cache prefix.
- Runtime shadow validation runs only after the PHP worker acquires the existing
  lane lock, uses argv plus stdin, bounds native output, and fails open while
  preserving PHP command exit codes.
- The opt-in `hashed-fixnames` native miss-status prepass runs only after the
  PHP worker acquires the existing lane lock, passes the Laravel lock owner to
  native held-lock mode, sends DB/Redis connection settings through a minimal
  environment instead of argv, processes pending search outbox rows, and still
  runs the existing PHP command loop.
- PHP tests cover
  `NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_FAIL_CLOSED_ENABLED=true` and prove
  configured hashed-fixnames native prepass failures stop before the PHP command
  loop with redacted output, including non-`--once` worker execution and thrown
  search outbox sync errors.
- PHP worker redaction tests prove native failure diagnostics keep phase, lane,
  exit code, and fail-open/fail-closed action while removing structured DSNs,
  Redis keys, lock owners, command arguments, release names, filenames, and
  partial credential fragments.
- `native/scripts/verify-php-native-hashed-worker-smoke.sh` proves the
  PHP-orchestrated prepass end-to-end with the packaged native binary, real
  MariaDB fixture, real Laravel Redis lock, two synced outbox rows, bounded PHP
  continuation, and an idempotent second native run.
- `native/scripts/verify-php-native-rename-worker-smoke.sh` proves the
  PHP-orchestrated rename prepass end-to-end with the packaged native binary,
  real MariaDB fixture, real Laravel Redis lock, real PHP rename side effects,
  Manticore document replacement, bounded PHP continuation, and environment-only
  DSN handoff.
- Go search-document parity tests prove native can hydrate PHP-shaped release
  search document inputs for eligible pending or expired-processing outbox rows
  and report stable fingerprints without exposing DSNs, command arguments,
  Redis keys, release names, posters, or filenames.
- PHP outbox tests prove terminal state updates are guarded by the claimed
  attempt number, so stale workers cannot overwrite a newer retry, sync, or
  dead-letter decision.

## Known Gaps

- This slice does not prove native write-mode behavior.
- This slice does not run against live Elasticsearch or NNTP.
- Native execution of search side effects and categorization parity remain
  follow-up gates. The PHP-orchestrated hashed-fixnames bridge now has an
  opt-in fail-closed prepass policy, but full production replacement-mode
  policy remains unclaimed.
- No current catalog lane passes `--require-replacement-ready`; this is
  intentional until a lane has an explicit replacement-ready implementation with
  side-effect ownership proven.
- Hashed-fixnames JSON reports explicitly keep `replacement_ready=false` while
  unsupported catalog methods or PHP-owned rename/category/event/search side
  effects remain.
- The committed hashed-fix proof is Compose-test-only. It does not satisfy
  production replacement because PHP's status-update path also updates search.
- Runtime shadow validation is an observability/contract gate only; it is not a
  native replacement worker.
- The opt-in native miss-status prepass is fail-open by default and can be made
  fail-closed for configured native prepass failures. It still leaves release
  renames, category writes, `ReleaseNameFixed`, and full replacement-mode
  readiness to follow-up work.
- The postprocess planner covers bucket selection for `post-tv`,
  `post-movies`, `post-amazon`, and the `post-additional` add/NFO commands.
  The guarded `post-additional` bridge can dispatch add/NFO leaf commands under
  the held Laravel worker lock. Metadata-refresh subcommands and hashed
  fix-name subcommands embedded under `post-additional` remain deferred to their
  own native/PHP bridges instead of being dispatched by the post-additional
  add/NFO bridge.
- The releases planner covers group-level queue selection only. Release
  creation, categorization, collection cleanup, NZB/file writes, and search
  updates remain PHP-owned or deferred.
- The per-group executable bridge covers queue-envelope selection and dispatch
  under the held Laravel worker lock. `group:update-all` still owns headers,
  backfill, release creation, post-processing, NNTP, NZB/file writes, release
  mutations, and search updates.
- The regular fixnames commit bridge covers native status writes and search
  outbox sync for methods 15/19 under the held Laravel worker lock. Remaining
  regular fix-name methods are explicitly deferred to PHP after native commit;
  full rename/category/event side effects remain PHP-owned or deferred.
- The metadata-refresh commit bridge covers native metadata provider fetches,
  `predb`/`predb_crcs` writes, and PreDB search outbox sync under the held
  Laravel worker lock. Embedded strong hashed fix-name commands are explicitly
  deferred to PHP after the native metadata commit until their full side effects
  are native-owned.
- The hashed-fixnames executable bridge covers command-envelope validation and
  dispatch under the held Laravel worker lock. Rename writes, events, search
  updates, and full replacement-mode readiness remain PHP-owned or deferred.
- The IRC report covers command-envelope, native socket/session execution,
  IRC login/channel parsing, `predb` writes, and PHP-synced PreDB search
  outbox handoff; live rollout proof remains the readiness blocker.
- The PHP-owned resolved rename apply smoke is Compose-test-only. The
  worker-orchestrated rename prepass now proves the same side effects under the
  Laravel worker lock, but it is still opt-in, fail-open, and not production
  replacement behavior.
- PHP-owned native search outbox processing has a bounded retry budget and marks
  exhausted rows `failed`; the PHP-orchestrated Compose smoke now covers the
  real binary, Laravel-held Redis lock, MariaDB commit, outbox sync, PHP command
  continuation, and idempotent second run together. Search writes remain
  PHP-owned; native code still only records the durable outbox work.
