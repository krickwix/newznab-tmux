# Distributed Workers

NNTmux can run processing lanes without tmux by starting one long-running
Artisan worker per former tmux pane:

```bash
php artisan nntmux:worker --list
php artisan nntmux:worker binaries
php artisan nntmux:worker backfill
```

Each worker resolves the current site settings on every cycle, runs the same
Artisan commands used by the tmux monitor, then sleeps for the configured pane
timer. This makes settings changed in the Web UI effective on the next cycle
without restarting the pod.

## Jobs

| Job | Purpose |
| --- | --- |
| `binaries` | Download new headers for active groups. |
| `backfill` | Safely backfill enabled groups. This always uses `multiprocessing:safe backfill`. |
| `releases` | Create and categorize releases from complete collections. |
| `fixnames` | Run release name-fixing passes. |
| `hashed-fixnames` | Run full-history name-fixing passes for `Other > Hashed` when the count exceeds 100. |
| `removecrap` | Remove releases matched by configured cleanup rules. |
| `post-additional` | Download/process additional files and NFOs. |
| `metadata-refresh` | Refresh external release-name evidence and run strong hashed fix-name passes. |
| `post-tv` | Run TV and anime post-processing. |
| `post-movies` | Run movie post-processing. |
| `post-amazon` | Run books, music, console, and games post-processing. |
| `irc` | Run the IRC scraper. |
| `per-group` | Run the sequential per-group worker when sequential mode 2 is enabled. |

## Locking

Workers use Laravel cache locks as a final guard against two pods running the
same lane at the same time. In orchestrated deployments, keep one replica per
lane and use a `Recreate` rollout strategy as the primary duplicate-processing
control. Configure the lock store and TTLs with:

```env
NNTMUX_DISTRIBUTED_LOCK_STORE=redis
NNTMUX_DISTRIBUTED_LOCK_SECONDS=900
NNTMUX_DISTRIBUTED_LONG_LOCK_SECONDS=3600
```

`irc` can use a different TTL, but keep it bounded. A pod killed during a
rollout cannot release its cache lock, so very long TTLs can leave the lane idle
until the key expires or an operator clears it. Locks do not heartbeat while a
long Artisan command is running, so do not scale one lane above one replica.

The `hashed-fixnames` lane uses the normal `fix_timer` sleep. It is disabled
unless `fix_names` is enabled and the exact `Other > Hashed` count is greater
than 100. It runs the hashed-only selector, while the regular `fixnames`
worker excludes `Other > Hashed` to avoid duplicate rename attempts.

## Kubernetes Pattern

A Kubernetes deployment should run one pod per job:

```yaml
args: [php, artisan, nntmux:worker, backfill]
```

Use ReadWriteMany storage for `storage`, temporary resources, covers, and any
shared install state. Use preferred pod anti-affinity and `ScheduleAnyway`
topology spread constraints to distribute load without pinning the image to a
single CPU architecture.

Do not run the legacy tmux worker and distributed workers at the same time
unless you intentionally want duplicate processing. During cutover, scale the
legacy tmux worker to zero before applying the distributed worker deployments.

## Native Shadow Plans

Laravel remains the control plane for native worker modes and can export the
resolved distributed-lane plan without acquiring a worker lock or running the
lane:

```bash
php artisan nntmux:worker metadata-refresh --native-plan --lock-seconds=42
```

The JSON plan includes a version, mode, job metadata, existing distributed lock
name, Laravel-prefixed physical Redis lock key, lock TTL, and command
count/source data. It does not include raw settings or service credentials. The
Go worker validates version 1 `shadow` plans for known distributed worker lane
names. The simplest native mode is a dry-run validation:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test go run ./cmd/nntmux-worker --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json --dry-run
```

It also accepts plans on stdin for PHP-to-native shadow checks:

```bash
php artisan nntmux:worker metadata-refresh --native-plan --lock-seconds=42 \
  | native/bin/nntmux-worker --plan - --dry-run
```

Runtime shadow validation is disabled by default. When enabled, the PHP worker
first acquires the normal Laravel cache lock for its lane, then exports the
resolved plan and invokes the configured native binary with argv form:
`[binary, --plan, -, --dry-run]`. The plan JSON is passed on stdin. Native
dry-run failures, timeouts, missing binaries, and validation errors are logged
with bounded output and the PHP worker continues to run the existing Artisan
commands. The PHP command exit code remains authoritative.

```env
NNTMUX_NATIVE_WORKER_SHADOW_ENABLED=false
NNTMUX_NATIVE_WORKER_LANE_COMMIT_ENABLED=false
NNTMUX_NATIVE_WORKER_FIRST_LANE_COMMIT_ENABLED=false
NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED=false
NNTMUX_NATIVE_WORKER_POST_ADDITIONAL_DEFERRED_EXECUTION_ENABLED=false
NNTMUX_NATIVE_WORKER_BINARY=/app/native/bin/nntmux-worker
NNTMUX_NATIVE_WORKER_TIMEOUT_SECONDS=5
NNTMUX_NATIVE_WORKER_LANE_TIMEOUT_SECONDS=3600
NNTMUX_NATIVE_WORKER_ARTISAN_BINARY=
NNTMUX_NATIVE_WORKER_ARTISAN_SCRIPT=
NNTMUX_NATIVE_WORKER_LANE_MAX_PROCESSES=0
NNTMUX_NATIVE_WORKER_BINARIES_MAX_MESSAGES=0
NNTMUX_NATIVE_WORKER_BINARIES_MAX_HEADERS=0
NNTMUX_NATIVE_WORKER_BACKFILL_QTY=0
NNTMUX_NATIVE_WORKER_BACKFILL_MAX_MESSAGES=0
NNTMUX_NATIVE_WORKER_BACKFILL_THREADS=0
NNTMUX_NATIVE_WORKER_BACKFILL_GROUPS=0
NNTMUX_NATIVE_WORKER_BACKFILL_DAYS=0
NNTMUX_NATIVE_WORKER_BACKFILL_SAFE_DATE=
NNTMUX_NATIVE_WORKER_BACKFILL_MIN_ARTICLES=0
NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE=0
NNTMUX_NATIVE_WORKER_LOG_STDERR_BYTES=2048
NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_COMMIT_ENABLED=false
NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_RENAME_PREPASS_ENABLED=false
NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_FAIL_CLOSED_ENABLED=false
NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=false
NNTMUX_NATIVE_WORKER_REMOVECRAP_PRODUCTION_COMMIT_ENABLED=false
NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=false
NNTMUX_NATIVE_WORKER_MYSQL_DSN=
NNTMUX_NATIVE_WORKER_REDIS_ADDR=
NNTMUX_NATIVE_WORKER_COMMIT_TIMEOUT_SECONDS=30
NNTMUX_NATIVE_WORKER_RENAME_PREPASS_TIMEOUT_SECONDS=30
NNTMUX_NATIVE_WORKER_RENAME_PREPASS_REPORT_BYTES=1048576
NNTMUX_NATIVE_WORKER_SEARCH_OUTBOX_LIMIT=100
```

The PHP-to-Go contract script generates plans for every catalog lane, diffs
them against the committed catalog fixtures, and feeds each generated plan to
the Go dry-run validator:

```bash
native/scripts/verify-php-go-contract.sh
```

The current native worker can also be built as a separate runtime image for
artifact-level smoke testing:

```bash
native/scripts/verify-native-worker-image.sh
```

That verifier builds the `native-worker` image, runs the packaged binary
against every committed catalog plan fixture, proves non-dry-run execution is
still refused, and exercises the Compose MariaDB-backed dry-run plus
rollback-only rehearsal path. Every packaged catalog dry-run must report
`dry_run: true`, `native_worker.writes: 0`,
`native_worker.replacement_ready: false`, and non-empty
`native_worker.replacement_readiness.blockers`, then fail closed with
`--require-replacement-ready`. The image service does not set
`NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB`; the verifier passes that guard only
for the explicit rollback-only rehearsal command. It does not enable committed
DB writes, Laravel events, or search index updates.

Committed catalog fixtures live under `tests/Fixtures/native-worker/catalog/`.
Regenerate them after changing lane selection or native plan export logic:

```bash
docker compose -f docker-compose.native-test.yml run --rm php-test php native/scripts/generate-worker-plan-fixtures.php tests/Fixtures/native-worker/catalog
```

The native binary can also build a MariaDB dry-run plan for the
`metadata-refresh` external-metadata phase. Plain `--dry-run` only selects the
local rows and filename-derived queries PHP would use as candidates. When the
same plan runs with `--rehearse-writes` or guarded `--commit-lane-writes`,
native fetches SRRDB title/archive evidence and rename-authoritative provider
search hits before writing representative `predb` / `predb_crcs` rows.
When the plan also includes the hashed fix-name method 20/16 commands, the same
dry-run reports the CRC/PAR-hash release renames and status-only misses that the
native planner would attempt next. It also prints a read-only write contract
for the PHP-equivalent release update columns, `ReleaseNameFixed` event
requirements, search update requirements, and unresolved categorization calls
that native write mode must reproduce later:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json \
    --dry-run \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

The same dry-run path can plan the `binaries` lane's safe queue from local
MariaDB cursor tables:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/binaries.json \
    --dry-run \
    --binaries-max-messages=10000 \
    --binaries-max-headers=25000 \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This mirrors PHP `BinariesRunner::safeBinaries()` queue construction for
`update_group_headers`, `part_repair`, and bounded `get_range binaries` work.
It is read-only: no NNTP connections, no `update_groups.php`, no group cursor
updates, and no header/body writes.

To prove the planned groups are visible to the configured provider without
fetching headers, add `--nntp-probe` to the same dry-run command. The probe
uses `NNTP_SERVER`, `NNTP_PORT`, `NNTP_USERNAME`, `NNTP_PASSWORD`, and
`NNTP_SSLENABLED` from the process environment, opens the provider connection,
authenticates when credentials are configured, issues `GROUP` for the planned
queue groups, and reports aggregate success/failure counts plus numeric
provider watermarks (`total-count`, `lowest-low`, `highest-high`, and unnamed
per-group `count`/`low`/`high` stats). It is still dry-run only: no article
ranges are fetched and no MariaDB rows are written.
To fetch a bounded native overview sample for each planned `get_range`, add
`--nntp-overview-sample=N`. The worker issues `OVER start-end` after selecting
the planned group, falls back to `XOVER` when the server rejects `OVER` as
unsupported, treats sparse `423`/`430` responses as empty windows, parses
standard overview article/bytes/lines fields, and reports aggregate
requested/received/empty/parsed/malformed row plus byte/line counts only.
It also reports aggregate `header_candidates`, `part_candidates`,
`unique_message_ids`, and `duplicate_message_ids` as the native write-contract
shape the later persistence step must consume. In dry-run mode it does not
persist headers, parts, binaries, or group cursors.
When `--nntp-overview-sample=N` is combined with `--rehearse-writes`, the
rollback-only write rehearsal uses those sampled overview rows internally for
cursor, binary, and part insert shape checks. The report remains aggregate-only
and still does not expose raw subjects, Message-IDs, group names, provider
endpoints, or credentials.

Rollback-only write rehearsal proves the native binary can execute
representative cursor, header, and part writes for the planned safe queue while
leaving MariaDB unchanged:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/binaries.json \
    --dry-run \
    --rehearse-writes \
    --output=json \
    --binaries-max-messages=10000 \
    --binaries-max-headers=25000 \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This path requires the native test DB safety guard and always reports
`writes_committed=0`.

For isolated Compose validation only, the binaries planner can commit the same
representative cursor, header, and part writes:

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

This mode is test-only and currently supports the `binaries`, `backfill`,
`releases`, `per-group`, `removecrap`, `metadata-refresh`, and executable
postprocess catalogs. It requires the native test DB guard,
`NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1`, an allowlisted native-test schema,
and the exported Redis worker lock. It still does not contact NNTP providers or
claim production replacement readiness.
For the acquisition write-shape proof, the same guarded command can include
`--nntp-overview-sample=N`; native then samples overview rows from the planned
ranges and commits cursor, binary, and part rows derived from those sampled
overview candidates in the native-test schema. The JSON report remains
aggregate-only and does not expose raw subjects, Message-IDs, group names, or
provider connection details.

The PHP worker can execute this guarded lane commit path instead of the PHP
command loop by setting:

```env
NNTMUX_NATIVE_WORKER_LANE_COMMIT_ENABLED=true
NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=true
NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1
NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1
NNTMUX_NATIVE_WORKER_MYSQL_DSN='nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true'
NNTMUX_NATIVE_WORKER_REDIS_ADDR='redis:6379'
NNTMUX_NATIVE_WORKER_BINARY=/app/native/bin/nntmux-worker
NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE=2
```

Committed native `binaries` and `backfill` writes require
`NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE` to be greater than zero. PHP then
passes `--nntp-overview-sample` to the native commit runner and forwards the
configured `NNTP_*` provider environment plus `USE_ALTERNATE_NNTP_SERVER` to the
Go process. Other commit-capable lanes ignore that sample setting so they do not
receive an unsupported native flag. Dry-run and rollback-only rehearsal can still
use representative acquisition rows without contacting NNTP.

This is intentionally limited to disposable native-test schemas. The older
`NNTMUX_NATIVE_WORKER_FIRST_LANE_COMMIT_ENABLED` switch is still accepted as a
compatibility alias for the first-lane proof. A successful native commit report
skips the PHP command loop for that worker cycle after PHP validates
`schema_version=1`, `mode=shadow`, `dry_run=false`, the matching
`native_worker.job`, the lane-specific commit section
(`binaries_write_commit`, `per_group_write_commit`,
`metadata_refresh_write_commit`, `postprocess_write_commit`, and so on), and
matching committed write counts. For executable postprocess commit reports,
PHP also requires `postprocess_write_commit.committed_release_ids` to contain
one positive integer release ID per committed write. After validating the
native report, PHP calls the existing release search-index sync path for those
IDs before it is allowed to skip the postprocess command loop; search-sync
failures return a nonzero worker exit and do not fall back to the full PHP
postprocess lane after native writes have committed.

For `post-additional`, PHP enables this commit handoff only when
`NNTMUX_NATIVE_WORKER_POST_ADDITIONAL_DEFERRED_EXECUTION_ENABLED=true`. Native
commits only the add/NFO postprocess subset and the PHP worker then continues
with the deferred `metadata-refresh` and hashed fix-name commands under the
same held worker lock.

To verify the PHP-orchestrated committed handoff for the first native lanes
against the disposable native-test schema, run:

```bash
native/scripts/verify-php-native-first-lane-commit-smoke.sh
```

The smoke reseeds each fixture and proves `binaries`, `backfill`, and
`releases` skip their PHP command loop only after PHP validates the native
commit report and the Redis worker lock is released.

To verify the same PHP-orchestrated committed handoff for every current
commit-capable catalog lane, run:

```bash
native/scripts/verify-php-native-lane-commit-smoke.sh
```

This broader smoke covers `binaries`, `backfill`, `releases`, `per-group`,
`removecrap`, `metadata-refresh`, `post-tv`, `post-movies`, `post-amazon`, and
guarded `post-additional`. Override the lane list with
`NNTMUX_NATIVE_LANE_COMMIT_SMOKE_LANES` when iterating on one lane.

For the first executable native-worker bridge, the same planner can own queue
construction and dispatch the selected leaf work through explicit Artisan
commands:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/binaries.json \
    --run-lane \
    --binaries-max-messages=10000 \
    --binaries-max-headers=25000 \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

This is not a full header-ingestion rewrite yet. Native owns the safe queue
selection, batching, and command dispatch; `group:update-headers`,
`binaries:part-repair`, and `articles:get-range binaries` still own NNTP and
database side effects.

The PHP worker can hand this lane to native while it holds the existing Laravel
distributed-worker lock by setting:

```env
NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED=true
NNTMUX_NATIVE_WORKER_MYSQL_DSN='nntmux:nntmux@tcp(mariadb:3306)/nntmux?parseTime=true'
NNTMUX_NATIVE_WORKER_REDIS_ADDR='redis:6379'
NNTMUX_NATIVE_WORKER_BINARY=/app/native/bin/nntmux-worker
```

When enabled for `binaries`, native receives the exported plan on stdin, reads
the DSN from `NNTMUX_NATIVE_MYSQL_DSN`, and passes an absolute Artisan script
path to the leaf commands. DB-backed executable lanes (`binaries`, `backfill`,
`releases`, `per-group`, `removecrap`, postprocess lanes, and `irc`) require
`NNTMUX_NATIVE_WORKER_MYSQL_DSN`. Command-only executable lanes
(`metadata-refresh`, `fixnames`, and `hashed-fixnames`) do not require the
native MySQL DSN; the PHP runner omits `--mysql-dsn-env` and
`NNTMUX_NATIVE_MYSQL_DSN` for those lanes.
Because this mode still dispatches PHP leaf commands, the PHP
runner forwards a constrained Laravel runtime environment for those Artisan
children, including app, DB, Redis, queue/cache/session/search, filesystem/NZB,
body-processing, and NNTP keys.
A successful native lane run skips the PHP command loop for that cycle only
after PHP validates the native JSON report and sees
`schema_version=1`, `mode=shadow`, `dry_run=false`,
`native_worker.job` matching the requested lane, `native_lane.succeeded` equal
to `native_lane.commands`, and `native_lane.failed=0` with
`native_lane.exit_code=0`. A failed native lane run, malformed JSON report,
wrong-lane report, incomplete success report, or failed native-lane report
returns a native failure and does not fall back to the full PHP lane, because
some leaf commands may already have advanced cursors or created rows. Native
stops scheduling additional leaf commands after the first leaf failure; when
concurrency is greater than one, already-running leaf commands are allowed to
finish before the native failure is reported.

Direct `--run-lane` usage is rejected unless `--lock-mode=held` proves that
the exported Redis lock key is currently owned by the supplied lock owner. The
PHP worker supplies this owner from Laravel's held lock; standalone tests must
seed the same lock before dispatching native lane execution.
Before opening MariaDB or validating Redis lock ownership, `--run-lane` also
validates the exported command envelope for the requested native executable
lane:
`binaries` and `backfill` must contain only their matching
`multiprocessing:safe` command, `releases` must contain only
`multiprocessing:releases`, `per-group` must contain only
`multiprocessing:update-per-group`, `removecrap` must contain only
`releases:remove-crap` cleanup commands, and the executable postprocess lanes
(`post-tv`, `post-movies`, `post-amazon`) must contain only their
`multiprocessing:postprocess` envelopes. The command-only executable lanes
validate their direct leaf envelopes: `metadata-refresh` must contain only
`predb:refresh-external-metadata` and hashed `releases:fix-names` commands,
`fixnames` and `hashed-fixnames` must contain only `releases:fix-names`
commands, and `irc` must contain only `irc:scrape`.

The PHP runner also forwards optional first-lane tuning values to native. Leave
most numeric values at `0` to use the native defaults or the lane's planned
worker count; committed `binaries` and `backfill` are the exception and require
a positive `NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE`.

```env
NNTMUX_NATIVE_WORKER_LANE_MAX_PROCESSES=0
NNTMUX_NATIVE_WORKER_BINARIES_MAX_MESSAGES=0
NNTMUX_NATIVE_WORKER_BINARIES_MAX_HEADERS=0
NNTMUX_NATIVE_WORKER_BACKFILL_QTY=0
NNTMUX_NATIVE_WORKER_BACKFILL_MAX_MESSAGES=0
NNTMUX_NATIVE_WORKER_BACKFILL_THREADS=0
NNTMUX_NATIVE_WORKER_BACKFILL_GROUPS=0
NNTMUX_NATIVE_WORKER_BACKFILL_DAYS=0
NNTMUX_NATIVE_WORKER_BACKFILL_SAFE_DATE=
NNTMUX_NATIVE_WORKER_BACKFILL_MIN_ARTICLES=0
NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE=0
NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=
NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG=
```

For the local Compose evaluation stack, deploy the app/native binary and then
run the first executable lanes with the bounded values in `.env.native-eval`:

```bash
native/scripts/deploy-native-eval-compose.sh
native/scripts/run-native-eval-first-lanes.sh
```

To make a compose deploy run the first executable lanes immediately after the
readiness and native metadata-refresh smoke, opt in explicitly:

```bash
NNTMUX_NATIVE_EVAL_RUN_FIRST_LANES=1 native/scripts/deploy-native-eval-compose.sh
```

To make a compose deploy run every catalog lane through the PHP-orchestrated
native handoff after the same readiness checks, use the all-worker opt-in
instead:

```bash
NNTMUX_NATIVE_EVAL_RUN_ALL_WORKERS=1 native/scripts/deploy-native-eval-compose.sh
```

To make a compose deploy validate every one-shot `native-workers` profile
service instead, use the compose-service opt-in:

```bash
NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS=1 native/scripts/deploy-native-eval-compose.sh
```

`NNTMUX_NATIVE_EVAL_RUN_FIRST_LANES` and
`NNTMUX_NATIVE_EVAL_RUN_ALL_WORKERS` and
`NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS` are mutually exclusive.

The deploy helper forwards `NNTMUX_NATIVE_EVAL_ENV_FILE` and
`NNTMUX_NATIVE_EVAL_COMPOSE_FILE` to the selected runner, so custom eval files
and compose paths are preserved. The lane runner still owns the NNTP real-leaf
guard, seeded eval data, per-lane enablement checks, and post-run Redis lock
scan.

To align the local eval NNTP settings with the k3s `media` deployment without
printing credentials, use the k3s sync helper. It reads deployment env,
`envFrom`, `secretKeyRef`, and `configMapKeyRef` values through
`$HOME/k3s.yaml` by default, targets the `nntmux-web` deployment in the
`media` namespace, reports only key names/counts, and updates only the
NNTP-related keys in `.env.native-eval` when run in apply mode:

```bash
native/scripts/sync-native-eval-nntp-from-k3s.sh --mode check
native/scripts/sync-native-eval-nntp-from-k3s.sh --mode apply
```

If the deployment name differs from the standard media deployment, scope
discovery explicitly:

```bash
native/scripts/sync-native-eval-nntp-from-k3s.sh \
  --mode check \
  --namespace media \
  --deployment nntmux-web

native/scripts/sync-native-eval-nntp-from-k3s.sh \
  --mode check \
  --namespace media \
  --selector app.kubernetes.io/name=nntmux
```

The runner refuses to run unless the compose app database is
`nntmux_native_eval`, seeds a small eval-only group/settings fixture, includes
one deterministic completed collection for `releases:process`, and gives
`binaries`, `backfill`, and `releases` work to plan. It prints a native plan
summary for those lanes, executes each lane once through
`php artisan nntmux:worker`, and fails if any distributed-worker lock remains
in Redis afterward. Override the lane list or lock TTL without editing secrets:

For command-dispatch smoke runs that must not contact NNTP providers or execute
release-processing side effects, set `NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=1` and
`NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG=/tmp/nntmux-native-leaf-startup-smoke.log`
in `.env.native-eval` before recreating the webapp container. The runner
requires this smoke guard by default; set `NNTMUX_NATIVE_EVAL_ALLOW_REAL_LEAVES=1`
only when intentionally running the real PHP leaf commands.

Real-leaf `binaries` and `backfill` runs must target a group that the configured
NNTP provider serves. The default eval fixture group
`alt.binaries.native.eval` is intentionally fake for smoke tests and will fail
against a real provider with a missing-group response. Override the seeded group
and provider-visible article bounds before enabling real leaves:

```bash
NNTMUX_NATIVE_EVAL_ALLOW_REAL_LEAVES=1 \
NNTMUX_NATIVE_EVAL_GROUP_NAME="alt.binaries.example" \
NNTMUX_NATIVE_EVAL_GROUP_FIRST_RECORD=100000 \
NNTMUX_NATIVE_EVAL_GROUP_LAST_RECORD=101000 \
NNTMUX_NATIVE_EVAL_SHORT_GROUP_FIRST_RECORD=1 \
NNTMUX_NATIVE_EVAL_SHORT_GROUP_LAST_RECORD=101000 \
NNTMUX_NATIVE_EVAL_LANES="binaries backfill releases" \
NNTMUX_NATIVE_EVAL_LOCK_SECONDS=900 \
native/scripts/run-native-eval-first-lanes.sh
```

```bash
NNTMUX_NATIVE_EVAL_LANES="binaries backfill releases" \
NNTMUX_NATIVE_EVAL_LOCK_SECONDS=900 \
native/scripts/run-native-eval-first-lanes.sh
```

The eval compose file also exposes one-shot native worker services under the
`native-workers` profile. These services use the same app image and mounted
`/opt/nntmux-native/nntmux-worker` binary as the webapp, set
`NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED=true`, and run
`php artisan nntmux:worker <lane> --once --stop-on-disabled` with the
`NNTMUX_NATIVE_EVAL_LOCK_SECONDS` TTL. Use them when validating deployable
service shape rather than the wrapper scripts:

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

The profile includes matching services for every catalog lane:
`native-binaries-worker`, `native-backfill-worker`, `native-releases-worker`,
`native-fixnames-worker`, `native-hashed-fixnames-worker`,
`native-removecrap-worker`, `native-post-additional-worker`,
`native-metadata-refresh-worker`, `native-post-tv-worker`,
`native-post-movies-worker`, `native-post-amazon-worker`, `native-irc-worker`,
and `native-per-group-worker`. The catalog sync unit test fails if a new
distributed job is added without a compose worker service.

To run every compose worker service once with the deterministic eval seed and
per-lane settings, use:

```bash
native/scripts/run-native-eval-compose-workers.sh
```

The script runs the matching `native-<lane>-worker` service for each catalog
lane, requires `native lane completed <lane>` in each service log, and fails if
any distributed-worker Redis lock remains afterward.

To execute every currently enabled catalog lane through the PHP-orchestrated
native handoff in the same eval stack, run:

```bash
native/scripts/run-native-eval-all-workers.sh
```

This seeds deterministic eval settings/data, configures each lane's routing
mode, requires every catalog lane to resolve as enabled, requires every lane to
report `native lane completed <lane>`, and fails if any distributed-worker lock
remains in Redis afterward. Its default lane list is checked against
`DistributedJobCatalog` by the unit test suite.

To exercise every catalog fixture through native `--run-lane` in the eval stack
without invoking real leaf side effects, run:

```bash
native/scripts/run-native-eval-fixture-workers.sh
```

This stages the committed plan fixtures into the native eval mount, creates a
temporary allowlisted fixture database (`nntmux_native_test_eval_fixture` by
default), reseeds deterministic fixture tables for DB-backed lanes there under
`NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1`, holds each exported Redis worker
lock, and uses a fake Artisan executable to prove native command dispatch for
all catalog workers. The fixture database is dropped on exit, so the app eval
database is not reset by this runner. It is an eval-only command-shape proof
and does not run real NNTP, delete, postprocess, search, IRC, or metadata side
effects.

To audit every distributed worker without executing leaf commands, run:

```bash
native/scripts/audit-native-eval-all-workers.sh
```

The audit seeds the same deterministic eval data, resolves the Laravel native
plan for all catalog lanes, requires every lane to resolve as enabled, and feeds
each plan into the mounted native binary with `--dry-run --output=json`. It uses
the native MariaDB DSN only for DB-backed plans that have commands;
command-only reports are still validated without opening MariaDB. The report
check requires `dry_run: true`, `native_worker.job` to match the lane,
`native_worker.writes: 0`, `native_worker.replacement_ready: false`, and a
non-empty `native_worker.replacement_readiness.blockers` list for every current
catalog lane.

The same dry-run path can plan the `backfill` lane's safe queue from local
MariaDB cursor tables:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/backfill.json \
    --dry-run \
    --backfill-qty=75000 \
    --backfill-max-messages=20000 \
    --backfill-threads=4 \
    --backfill-groups=10 \
    --backfill-days=1 \
    --backfill-min-articles=100 \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This mirrors PHP `BackfillRunner::safeBackfill()` eligibility and
`buildSafeBackfillQueues()` chunk construction for bounded
`get_range backfill` work. It is read-only: no NNTP connections, no
`update_groups.php`, no group cursor updates, and no header/body writes.

To verify provider visibility for the planned historical groups without
fetching headers, add `--nntp-probe` to the same dry-run command. The probe
uses the configured NNTP environment, authenticates when credentials are set,
issues `GROUP` for the planned backfill queue groups, and reports aggregate
counts plus numeric provider watermarks only. It remains a dry-run
provider-connectivity check and does not write cursors, headers, parts, or
bodies.
To fetch a bounded native overview sample for each planned historical range,
add `--nntp-overview-sample=N`. The worker issues `OVER start-end` for the
first `N` articles in each planned range, falls back to `XOVER` when the server
rejects `OVER` as unsupported, treats sparse `423`/`430` responses as empty
windows, parses standard overview article/bytes/lines fields, and reports
aggregate requested/received/empty/parsed/malformed row plus byte/line counts
only. It also reports aggregate `header_candidates`, `part_candidates`,
`unique_message_ids`, and `duplicate_message_ids` as the native write-contract
shape the later persistence step must consume. It does not update backfill
cursors or persist article metadata in dry-run mode.
When `--nntp-overview-sample=N` is combined with `--rehearse-writes`, the
rollback-only write rehearsal uses those sampled overview rows internally for
backfill cursor, binary, and part insert shape checks. The report remains
aggregate-only and still does not expose raw subjects, Message-IDs, group
names, provider endpoints, or credentials.

For isolated Compose validation only, the backfill planner can commit
representative group cursor, header, and part writes:

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

This commit proof is test-only, guarded by the native test DB allowlist and the
explicit committed-test environment flag, and still does not contact NNTP
providers or claim production replacement readiness. PHP-orchestrated lane
commit mode validates `releases_write_commit.committed_release_ids` against
`release_rows_affected` and runs the existing release search-index sync path
for those IDs before it skips the PHP releases command loop.
For the acquisition write-shape proof, the same guarded command can include
`--nntp-overview-sample=N`; native then samples overview rows from the planned
historical ranges and commits cursor, binary, and part rows derived from those
sampled overview candidates in the native-test schema. The JSON report remains
aggregate-only and does not expose raw subjects, Message-IDs, group names, or
provider connection details.

If you test PHP `backfill_days = 2` parity, include
`--backfill-safe-date=YYYY-MM-DD`; the native worker rejects mode 2 without an
explicit safe date instead of falling back to a zero-day cutoff.
The PHP-orchestrated first-lane smoke covers both the default
`backfill_days = 1` queue and an explicit safe-date mode 2 queue. It verifies
native command dispatch once with a fake Artisan executable and again through
real Laravel Artisan under an explicit startup-smoke guard that returns before
NNTP or release-processing side effects. Native records each command line after
the guarded Artisan leaf exits successfully.

Rollback-only write rehearsal proves representative backfill cursor, header,
and part writes for the planned safe queue while leaving MariaDB unchanged:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/backfill.json \
    --dry-run \
    --rehearse-writes \
    --output=json \
    --backfill-qty=75000 \
    --backfill-max-messages=20000 \
    --backfill-threads=4 \
    --backfill-groups=10 \
    --backfill-days=1 \
    --backfill-min-articles=100 \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

This path requires the native test DB safety guard and always reports
`writes_committed=0`.

The executable bridge can also dispatch the planned safe backfill ranges:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/backfill.json \
    --run-lane \
    --backfill-qty=75000 \
    --backfill-max-messages=20000 \
    --backfill-threads=4 \
    --backfill-groups=10 \
    --backfill-days=1 \
    --backfill-min-articles=100 \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

Native owns backfill queue selection and dispatch in this mode;
`articles:get-range backfill` still owns NNTP fetches and group cursor writes.
The PHP worker uses the same disabled-by-default
`NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED` handoff for `backfill`, with the
same authoritative failure behavior.

The same dry-run path can count `removecrap` candidates for the current
catalog fixture types:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/removecrap.json \
    --dry-run \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This mirrors the PHP predicates for the committed cleanup commands:
`gibberish`, `executable`, `hashed`, `short`, `installbin`, `passwordurl`,
`nzb`, `scr`, `passworded`, `sample`, `size`, `codec`, `blfiles`, and
bounded-time subject/poster `blacklist`, plus `par2only` SQL and hashed
NZB-content detection. It reports aggregate unique candidate counts plus PHP
row-operation counts. It is read-only: no `ReleaseManagementService` delete
path, no release file cleanup side-effect rows, no search updates, no
collection unlinking, and no release-row deletes.
For bounded-time `blacklist`, explicit plan arguments preserve
`--blacklist-id=<id>` and filter the native candidate query to that configured
blacklist row.
For the PHP `fix_crap_opt=All` export, native expands the single no-`--type`
command into PHP's all-removal handler list for candidate planning while
preserving the single leaf command for executable lane dispatch; this excludes
`passwordurl` and `wmv_all`, matching `ReleaseRemoverService`.
The native planner also supports explicit `wmv_all` cleanup requests; the
exported default catalog omits that type to match PHP's all-removal behavior.
Full-history blacklist search remains a follow-up gate.

Rollback-only write rehearsal can execute the native subset of the delete
contract against the candidate releases while leaving MariaDB unchanged:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/removecrap.json \
    --dry-run \
    --rehearse-writes \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This rehearsal deletes linked `collections`, `release_files`, and `releases`
inside the native test transaction and reports `collection_rows_affected`,
`release_file_rows_affected`, and `release_rows_affected`; it always rolls
back and reports `writes_committed=0`. It still does not commit cleanup,
enqueue release file cleanup rows, update search, or run the full PHP
descendant collection cleanup path.

The executable bridge can dispatch the exported cleanup commands under the held
Laravel worker lock:

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

Native validates and dispatches the cleanup command list serially by default;
`releases:remove-crap` still owns release deletion, file cleanup, release
events, and other production destructive side effects in this bridge mode. The
guarded native commit handoff reports deleted release and collection IDs plus a
release-file cleanup side-effect count; PHP validates those IDs against
`release_rows_affected` and `collection_rows_affected`, calls the existing
search delete path, runs the existing descendant collection cleanup path, and
deletes matching NZB/image files from the internal side-effect outbox before
skipping the PHP command loop. The PHP worker uses the same
disabled-by-default
`NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED` handoff for `removecrap`.
The native `removecrap` commit path can target the production schema only
when `NNTMUX_NATIVE_WORKER_REMOVECRAP_PRODUCTION_COMMIT_ENABLED=true`; PHP then
passes the lane-scoped `NNTMUX_NATIVE_ALLOW_PRODUCTION_COMMIT=removecrap`
guard to the Go binary instead of the native-test commit guards. This does not
make the catalog replacement-ready by itself; live rollout proof is still
required before replacing the PHP lane.

The native worker can also dispatch the planned complete postprocess bucket
queues for TV/anime, movie, and Amazon-family metadata lanes:

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

In this mode native owns postprocess bucket selection and command dispatch;
`postprocess:tv-pipeline` and `postprocess:guid` still own metadata-provider
lookups, NZB/NFO reads, release metadata writes, events, and search side
effects. The same disabled-by-default
`NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED` PHP handoff applies to
`post-tv`, `post-movies`, and `post-amazon`.

`post-additional` can use the same executable bridge for its add/NFO
postprocess buckets, but only when the deferred-command guard is explicitly
enabled:

```env
NNTMUX_NATIVE_WORKER_POST_ADDITIONAL_DEFERRED_EXECUTION_ENABLED=true
```

Direct native runs must pass the matching flag:

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

With that guard, native plans and dispatches only the `add` and `nfo`
postprocess buckets. The PHP worker then continues under the same held lane lock
and runs embedded `metadata-refresh` and hashed-fixname commands through the
normal Artisan command loop, so those deferred commands are not silently
dispatched by native or skipped by the global lane-execution flag.

The same dry-run path can plan the `releases` lane's group queue from local
MariaDB tables:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/releases.json \
    --dry-run \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This mirrors only PHP `ReleasesRunner::releases()` queue selection: active or
backfill groups are candidates, and groups with at least one collection become
queued release-processing work. It is read-only: no `releases:process`,
release creation, categorization, collection cleanup, NZB/file writes, search
updates, or NNTP access.

Rollback-only write rehearsal proves representative release-row creation and
collection linking for the planned group queue while leaving MariaDB unchanged:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/releases.json \
    --dry-run \
    --rehearse-writes \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This path requires the native test DB safety guard and always reports
`writes_committed=0`.

For isolated Compose validation only, the releases planner can commit
representative release rows and collection links:

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

This commit proof is test-only, guarded by the native test DB allowlist and the
explicit committed-test environment flag, and still does not contact NNTP
providers or claim production replacement readiness.

The executable bridge can dispatch the selected release groups:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/releases.json \
    --run-lane \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

Native owns release group queue selection and dispatch in this mode;
`releases:process {groupId}` still owns release creation, categorization,
collection cleanup, NZB/file writes, and search side effects.
The PHP worker uses the same disabled-by-default
`NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED` handoff for `releases`, with the
same authoritative failure behavior.

The same queue-planning shape is available for the `per-group` lane's
sequential-mode envelope:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/per-group.json \
    --dry-run \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This mirrors only PHP `ReleasesRunner::updatePerGroup()` queue selection:
active or backfill groups become queued `update_per_group` work, batched by
`releasethreads`. It is read-only and does not run `group:update-all`, download
headers, backfill, create releases, run additional/NFO processing, contact
NNTP providers, write NZBs/files, mutate release tables, or update search.

The per-group fixture also has a committed-write proof for representative
`usenet_groups.last_updated` updates:

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

This is isolated Compose validation only. It proves the native worker can
mutate the native-test `usenet_groups` queue rows under the exported Redis
worker lock; it does not replace `group:update-all` or any of its header,
backfill, release, post-processing, NNTP, file, event, or search side effects.

The executable bridge can also dispatch that per-group queue under the held
Laravel worker lock:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/per-group.json \
    --run-lane \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

Native owns active/backfill group queue selection and command dispatch in this
mode; `group:update-all {groupId}` still owns headers, backfill, release
creation, post-processing, NNTP, NZB/file writes, release mutations, and search
updates. The PHP worker uses the same disabled-by-default
`NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED` handoff for `per-group`.

The regular `fixnames` lane has a no-DB command-envelope report:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/fixnames.json \
    --dry-run
```

This parses the exported `releases:fix-names` command matrix, counts methods,
categories, limited commands, update/set-status/show flags, and reports
`replacement_ready=false`. It intentionally does not open MariaDB and does not
plan or run regular rename candidates, category writes, `ReleaseNameFixed`,
search updates, NNTP lookups, PHP name-fixing services, or any write contract.
`--mysql-dsn` remains unsupported for the regular `fixnames` report.

The metadata-refresh lane can dispatch its exported metadata refresh command
and strong hashed fix-name leaf commands under the held Laravel worker lock:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/metadata-refresh.json \
    --run-lane \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

Native can build a read-only metadata candidate plan from MariaDB and can
exercise representative `predb` / `predb_crcs` inserts with
`--rehearse-writes` inside a rolled-back native test transaction. During
write rehearsal and guarded commit, native now fetches SRRDB
`/details/{title}` rows for selected `predb.source = srrdb` candidates and
SRRDB `search/archive-crc:{crc}/archive-size:{size}` rows for local archive CRC
candidates using `NNTMUX_SRRDB_BASE_URL`,
`NNTMUX_METADATA_REFRESH_TIMEOUT`, and the exported `--sleep-ms` pacing. It
also queries the rename-authoritative search providers selected by `--source`
(`predb-net`, `predb-ovh`, `xrel`, and `xrel-p2p`) and imports returned release
titles as `predb` rows with the provider source. It inserts only
provider-returned valid CRC/size pairs, SRRDB release titles, and provider
search hits into `predb` / `predb_crcs`; reports expose aggregate
lookup/file/hit counts only, not titles, CRCs, provider URLs, DSNs, or Redis
keys. Native enqueues `predb` search-index side effects in the existing native
side-effect outbox, and PHP syncs those rows through `Search::insertPredb`
before skipping the metadata-refresh command loop. Native also covers the
preview/bulk-only source behavior without database writes: NZBIndex is queried
only when `NNTMUX_METADATA_SOURCE_NZBINDEX=true` and
`NNTMUX_NZBINDEX_API_KEY` is configured, Internet Archive PreDB is reported as
the same postprocess-external bulk handoff, and reports expose aggregate
preview hit / bulk skipped counts only. The metadata-refresh replacement
readiness guard now blocks on the embedded hashed fix-name commands that are
explicitly deferred to PHP after native metadata commits. The disabled-by-default
`NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED` handoff includes
`metadata-refresh`.

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/metadata-refresh.json \
    --dry-run \
    --output=json \
    --rehearse-writes \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

The executable bridge can dispatch the same regular fix-name command matrix
under the held Laravel worker lock:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/fixnames.json \
    --run-lane \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

Native validates the envelope, preserves method/category/limit/update/status/
show flags, and dispatches `releases:fix-names` leaf commands. PHP still owns
the regular rename logic, category writes, events, search updates, NNTP lookups,
and name-fixing services. The disabled-by-default
`NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED` handoff includes `fixnames`.

The full hashed-fixnames lane can dispatch its exported hashed fix-name command
matrix without opening MariaDB:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --run-lane \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

Native validates that the envelope contains only hashed
`releases:fix-names` commands and dispatches those PHP leaf commands. The
separate native hashed-fixnames prepass options can still run before the lane
handoff when enabled; full rename/category/event/search side effects remain
PHP-owned unless a later replacement-ready lane proves them natively.

The `irc` lane also has a no-network command-envelope report:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/irc.json \
    --dry-run
```

This validates that the exported lane is the single `irc:scrape` command with
empty arguments, reports `network_required=true`, and keeps
`replacement_ready=false` until live rollout proof exists. It does not open
sockets, log into IRC, read IRC settings, write `predb`, or update search.

The executable bridge runs the native IRC session under the held Laravel worker
lock:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/irc.json \
    --run-lane \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner "$NNTMUX_NATIVE_LOCK_OWNER" \
    --lock-mode=held
```

Native validates the envelope, reads the existing `SCRAPE_IRC_*` settings plus
native IRC bounds, opens the IRC socket, joins the configured channel, parses
PRE messages, commits `predb` inserts/updates, and enqueues `irc` /
`predb-search-sync` rows in the native side-effect outbox. PHP then syncs those
outbox rows through `Search::insertPredb` before skipping the legacy
`irc:scrape` command loop. Reports expose aggregate session, candidate, write,
and outbox counts only; DSNs, Redis keys, server/port, channels, credentials,
titles, and command arguments stay out of JSON/text output. The
disabled-by-default native lane handoff includes `irc`.

Use `--output=json` when a machine-readable dry-run artifact is needed for
parity tooling:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --dry-run \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

JSON reports include the native job/lock summary, DB-backed planner counts,
and `native_worker.replacement_ready` plus
`native_worker.replacement_readiness.blockers` for every catalog lane. Generic
lanes use the same lane-specific PHP-owned blocker text as the hard
replacement guard; specialized reports can add more detail under their own
sections. Hashed fix-name plans also include
`hashed_fixnames.write_contract`, `hashed_fixnames.replacement_ready`, and
`hashed_fixnames.replacement_readiness`, listing the implemented methods in the
current plan, unsupported hashed fix-name methods still present in the catalog,
and blocker strings that must be cleared before replacement mode can be claimed.
The full `hashed-fixnames` catalog currently reports methods `16` and `20` as
implemented and methods `4`, `6`, `8`, `10`, `12`, `14`, `18`, and `21` as
unsupported. Reports intentionally omit command arguments, Redis physical keys,
and DSNs.

Plan-derived replacement-readiness metadata is available even without a
MariaDB DSN for the `hashed-fixnames` catalog. Use
`--require-replacement-ready` as a hard guard before experimenting with any
native replacement claim. For the current full `hashed-fixnames` catalog it
fails before opening MariaDB or entering write paths:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --dry-run \
    --output=json \
    --require-replacement-ready
```

Expected: non-zero exit with method and side-effect blockers; no command
arguments, Redis physical keys, DSNs, or release names.

`--require-replacement-ready` is a default-deny guard for every exported
catalog lane. Normal JSON dry-runs expose each lane's
`native_worker.replacement_readiness` summary for operator triage, but the hard
guard still fails before JSON reports, MariaDB planners, or write paths with a
lane-specific production blocker. Unknown future lanes retain the generic
`no explicit replacement-ready implementation` blocker until they receive an
explicit readiness contract. No current native catalog lane should be treated
as production replacement-ready.

Run the repository-wide readiness audit before changing rollout policy:

```bash
native/scripts/audit-native-replacement-readiness.sh
```

The audit requires every current catalog fixture to fail closed under
`--require-replacement-ready`, verifies each lane reports its expected
PHP-owned production blocker, and checks the blocker output for common
secret/internal-detail leaks.

For local Compose validation only, hashed-fixnames `--rehearse-writes` can
execute the contract's concrete status-column updates inside a rolled-back
transaction:

```bash
docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --dry-run \
    --output=json \
    --rehearse-writes \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"
```

This requires `NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1` and the allowlisted
`nntmux_native_test` schema provided by the Compose harness. It always rolls
back, reports `writes_committed: 0`, and leaves release renames blocked until
native code can reproduce PHP categorization, `ReleaseNameFixed`, and search
side effects.

The PHP oracle can consume the native JSON report and resolve those PHP-owned
side-effect values without executing them:

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

The resolver calls PHP `CategorizationService` for the documented
`categories_id` value source, reports `ReleaseNameFixed` and search-update
intent, preserves single-column status-update intent, and still reports
`writes: 0`. It intentionally omits poster/fromname values, command arguments,
DSNs, Redis physical keys, and credentials.

A separate disabled-by-default PHP proof can consume the resolved report and
apply the release-renaming side effects through the existing
`ReleaseUpdateService` boundary:

```bash
NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=true \
  php artisan nntmux:native-hashed-fixnames:apply-renames \
    --input=storage/native-hashed-write-contract-resolved.json
```

The command accepts only schema version 1 resolved native write contracts with
`dry_run: true`, `writes: 0`, no blocked release updates, unique positive
release IDs, required search-update intent, and current `releases` rows that
still match the old search name and category from the required event. It emits
aggregate counts and release IDs only. It does not make native code write
renames, and it is not wired into the distributed worker prepass.

The full local proof for that PHP-owned boundary is:

```bash
native/scripts/verify-php-native-rename-apply-smoke.sh
```

That verifier reseeds the deterministic hashed-fix Compose MariaDB fixture,
captures a native JSON dry-run report, prepares the PHP support schema, resolves
the write contract through `nntmux:native-write-contract:resolve`, applies the
resolved renames through `nntmux:native-hashed-fixnames:apply-renames`, captures
real `ReleaseNameFixed` events, and asserts the `releases_rt` Manticore
documents are updated by `ReleaseUpdateService` itself.

For the second rehearsal gate, feed that PHP oracle back to the native worker:

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

`--resolved-write-contract` is accepted only with `--rehearse-writes`. The
native worker validates that the PHP oracle is dry-run/read-only, matches
Go-planned release IDs and columns, and then executes the resolved release
updates plus concrete status-column updates in one transaction that is always
rolled back. Events and search updates remain reporting-only side effects.

For a committed-write proof in the local Compose test database only, the
native worker can commit the narrower hashed-fix miss-status updates:

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

This mode is test-only and requires the existing destructive-test DB guard, the
additional `NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1` guard, an allowlisted
native-test schema, the `hashed-fixnames` catalog plan, and the exported Redis
worker lock. The native CLI rejects `--commit-miss-status` for other catalog
lanes, including `metadata-refresh`. It commits only
`proc_crc32 = 1` / `proc_hash16k = 1` for true `crc-miss` and `par-hash-miss`
status rows that are not linked to planned release renames. Release renames,
`crc-predb-match-confirmation`, and `ReleaseNameFixed` stay blocked. Native
code still does not update search indexes.

After a successful native miss-status commit, native records one pending
side-effect row per committed miss-status release in
`native_worker_side_effects` inside the same MariaDB transaction. PHP owns the
retryable search handoff:

```bash
php artisan nntmux:native-search-side-effects:sync --pending-outbox --limit=100
```

The outbox mode claims pending or expired `processing` `hashed-fixnames` /
`release-search-sync` rows, calls `ReleaseSearchIndexSync::forIds()` for each
committed release ID, marks successful rows `synced`, and leaves failed rows
`pending` with a sanitized `last_error_code` for retry. It emits aggregate JSON
counts and IDs only.

Claims are guarded by the row's attempt number. Successful rows clear
`available_at`, failed rows keep bounded retry metadata, and a stale worker that
finishes after another worker reclaims or syncs the row cannot overwrite the
newer terminal state.

The older report-file handoff remains available for explicit commit reports:

```bash
php artisan nntmux:native-search-side-effects:sync \
  --input=storage/native-hashed-commit.json
```

The command validates `schema_version: 1`, `mode: shadow`, `dry_run: false`,
`native_worker.job: hashed-fixnames`, `write_commit.lock_acquired: true`, and
matching native/write counts before calling
`ReleaseSearchIndexSync::forIds()` for `committed_release_ids`. It rejects
duplicate or malformed committed IDs and skipped/blocked-only reports. The
command does not dispatch `ReleaseNameFixed`, echo DSNs/Redis keys/command
arguments, or execute native search writes. PHP `updateSingleColumn()` calls
`Search::updateRelease()` after the DB update, so the combined proof is still
not a full production replacement lane.

When Laravel already owns the distributed worker lock, native commit mode can
validate that held lock instead of trying to acquire the same Redis key:

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

`--lock-mode=held` checks that `plan.lock.redis_key` is currently owned by the
provided `--lock-owner`, rejects missing or mismatched owners before DB writes,
and leaves the Redis key in place for Laravel's existing `finally` release
path. The default `--lock-mode=acquire` behavior is unchanged for standalone
Compose proof scripts.

Laravel can orchestrate this held-lock proof as a disabled-by-default
`hashed-fixnames` prepass. When
`NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_COMMIT_ENABLED=true`, the PHP worker first
acquires its normal Laravel lock, passes that lock owner to native through the
private `NNTMUX_NATIVE_LOCK_OWNER` process environment with `--lock-mode=held`,
and sends MariaDB/Redis connection settings through the minimal native process
environment:

```bash
NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_COMMIT_ENABLED=true
NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_FAIL_CLOSED_ENABLED=false
NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=true
NNTMUX_NATIVE_WORKER_MYSQL_DSN='nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true'
NNTMUX_NATIVE_WORKER_REDIS_ADDR='redis:6379'
NNTMUX_NATIVE_WORKER_SEARCH_OUTBOX_MAX_ATTEMPTS=5
```

The committed prepass has an additional PHP-side fail-closed guard:
`NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=true`, both native test DB guard
environment variables, and an allowlisted `nntmux_native_test*` DSN are required
before PHP spawns the native binary. This keeps committed writes scoped to the
Compose smoke harness until a separate production replacement design exists.

The prepass remains a compatibility bridge, not replacement mode. Native
commits only the guarded miss-status subset, PHP processes the durable search
outbox, and the existing PHP `releases:fix-names` command loop still runs for
renames, categorization, `ReleaseNameFixed`, and the rest of the lane. Native
prepass or outbox failures are logged with redacted/bounded output and the PHP
worker continues through the existing command loop. Search outbox rows retry up
to `NNTMUX_NATIVE_WORKER_SEARCH_OUTBOX_MAX_ATTEMPTS`; rows that still fail are
marked `failed`, excluded from future pending scans, and reported through
`search_updates_dead_lettered` plus `dead_lettered_release_ids`.

Native failure diagnostics are sanitized before the final byte limit is
applied. The worker redacts configured DSNs and Redis addresses, CLI flag
values for `--mysql-dsn`, `--redis-addr`, and `--lock-owner`, JSON
`redis_key`/lock-owner fields, command `arguments`, release/search-name fields,
Redis physical key prefixes, and partial DSN fragments from already-truncated
native output. Logs should keep only the phase, lane, exit code, and
fail-open/fail-closed action.

Set `NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_FAIL_CLOSED_ENABLED=true` only when
you want configured hashed-fixnames native prepass failures to stop before the
PHP command loop. In that mode, native commit runner failures, invalid native
commit reports, thrown search outbox sync errors, failed/dead-lettered search
outbox rows, and rename prepass failures return a nonzero worker exit after a
redacted `stopping PHP worker` message. This is a failure-policy proof for the
PHP-orchestrated bridge; it still does not make the lane production
replacement-ready.

PHP also validates a zero-exit native commit report before it logs the prepass
as committed or processes the outbox. The report must be schema version 1,
`dry_run=false`, for `hashed-fixnames`, and include a held-lock
`hashed_fixnames.write_commit` section whose committed write counts match the
native worker summary. Invalid or malformed successful output is treated as a
native prepass failure and the PHP command loop continues.

The integration harness also starts Redis and verifies native acquire/release
behavior for the exported physical Redis key that corresponds to the logical
`nntmux:distributed-worker:{job}` lock. Native release-renaming write mode,
native search writes, and event dispatch remain out of scope until later
migration slices prove those boundaries explicitly. The hashed fix-name write
contract names categorization, `ReleaseNameFixed`, and `Search::updateRelease()`
as required side effects, but the Go worker does not execute them.

The native worker can also run a read-only search-document parity gate against
eligible pending or expired `processing` `hashed-fixnames` /
`release-search-sync` outbox rows:

```bash
go run ./cmd/nntmux-worker \
  --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
  --dry-run \
  --output=json \
  --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN" \
  --search-document-parity \
  --search-document-limit=100
```

This mode hydrates the release search document fields native search ownership
would need, normalizes them to the existing PHP document shape, and emits only
release IDs plus stable SHA-256 fingerprints under `search_documents`.
`writes` remains `0`, raw write-contract details are suppressed, and
`--rehearse-writes` cannot be combined with the parity mode. It is not a
replacement-ready signal and does not write Manticore or Elasticsearch.

The PHP-orchestrated Compose proof is:

```bash
native/scripts/verify-php-native-hashed-worker-smoke.sh
```

It builds the packaged native-worker image, copies its binary into the PHP test
container, seeds the MariaDB native fixture, uses a real Laravel Redis lock,
commits CRC and PAR miss-status updates, syncs the PHP-owned native search
outbox, proves the PHP continuation runs while the lock is held, and verifies an
idempotent second native prepass.

Laravel can also orchestrate a disabled-by-default PHP-owned rename prepass for
the native-supported hashed fix-name methods `16` and `20`. When
`NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_RENAME_PREPASS_ENABLED=true`, the PHP
worker runs after the miss-status prepass and before the existing
`releases:fix-names` command loop:

1. Export the held-lane native plan.
2. Run the native binary as a read-only JSON dry-run with `--mysql-dsn-env`.
3. Pass the JSON to `NativeHashedFixNameWriteContractResolver`.
4. Apply resolved renames through `NativeHashedFixNameRenameApplier`, which
   delegates to `ReleaseUpdateService`.
5. Continue the existing PHP command loop.

The MariaDB DSN is sent only through the minimal native process environment.
The worker rejects non-`nntmux_native_test*` DSNs and also requires
`NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=true` before spawning the
native binary, so this remains a Compose proof path rather than production
replacement mode. Native still does not write rename rows, dispatch
`ReleaseNameFixed`, categorize releases, or update search indexes directly.

The PHP-orchestrated rename proof is:

```bash
native/scripts/verify-php-native-rename-worker-smoke.sh
```

It builds the packaged native-worker image, copies its binary into the PHP test
container, seeds the MariaDB native fixture, uses a real Laravel Redis lock,
applies releases `100` and `300` through the PHP-owned rename path, captures
`ReleaseNameFixed`, verifies Manticore `releases_rt` replacement, proves the
PHP continuation runs while the lock is held, and keeps DSN/lock details out of
argv and worker output.

The Compose harness has an opt-in Manticore smoke for the existing PHP-owned
search side effect. It uses isolated MariaDB and Manticore services, mutates a
release row through MariaDB, runs `nntmux:native-search-side-effects:sync` via
both the explicit commit-report handoff and the durable `--pending-outbox`
handoff, and asserts that `releases_rt` is replaced with the updated release
document:

```bash
docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm php-search-test ./vendor/bin/phpunit tests/Feature/Search/NativeReleaseSearchSideEffectSmokeTest.php
```

This proves the PHP-owned side effect the native write mode must preserve. It
does not make the native worker dispatch Laravel events or update search
indexes directly.

## Monitoring

Watch a lane directly through pod logs:

```bash
kubectl -n media logs -f deploy/nntmux-worker-backfill
```

For backfill, `first_record` should move backward over time:

```bash
php artisan tinker --execute='use Illuminate\Support\Facades\DB; $g=DB::table("usenet_groups")->where("name","alt.binaries.movies")->first(); echo now()."\t".$g->name."\tfirst=".$g->first_record."\tfirst_post=".$g->first_record_postdate."\n";'
```

The Web UI group count can remain unchanged while article cursors are moving;
that count reflects eligible groups, not per-article progress.
