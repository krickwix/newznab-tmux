# NNTmux Native Reimplementation Feasibility

Date: 2026-06-15
Branch: `microservices-pods`
Commit: `18c0d1651602c6a730ec7b2f202f9d90d6f46dd2`

## Executive Summary

Reimplementing NNTmux/newznab-tmux in a native language is feasible, but only as a phased migration. A full rewrite in C++, Rust, or Go is not recommended as the first move.

Best language: **Go**.

Recommended path: keep Laravel as the control plane, UI/API/admin/settings/migration owner, and extract individual processing lanes into Go sidecars behind explicit compatibility contracts. Use Rust only for small parsing/codec accelerators when profiling proves value. Avoid C++ unless a required native library forces it.

Performance readout: the best native upside is not a whole-product rewrite. It is concentrated in long-running NNTP/header ingestion, yEnc/PAR2/body processing, and streaming NZB generation. Release creation, search indexing, and header table writes are more constrained by SQL shape, locks, indexes, and side effects than by PHP itself.

## Current System Shape

The current branch is a Laravel 13/PHP application, not just an indexing daemon. The repo has web routes, API v1/v2 routes, RSS routes, scheduler jobs, queue/Horizon behavior, tmux orchestration, and Kubernetes-friendly distributed workers.

Evidence:

- `README.md` describes a Laravel Usenet indexer with web frontend, API access, multi-threaded processing, and Manticore/Elasticsearch search.
- `composer.json` requires PHP 8.4, Laravel 13, Horizon, Manticore/Elasticsearch clients, NNTP, media, image, archive, and metadata libraries.
- `docker-compose.yml.prod-dist` runs `webapp`, `worker`, `scheduler`, MariaDB, Redis, and optional Manticore/Elasticsearch services.
- `database/schema/mariadb-schema.sql` defines 80 tables; hot tables include `collections`, `binaries`, `parts`, `missed_parts`, `releases`, `releases_groups`, `release_files`, `release_nfos`, `usenet_groups`, and `settings`.
- `phpunit.xml` defines Unit, Feature, and Install suites; the repo currently has 156 `*Test.php` files.

The largest behavior surfaces are:

| Area | Key Files | Rewrite Risk |
| --- | --- | --- |
| Distributed workers | `app/Console/Commands/NntmuxDistributedWorker.php`, `app/Services/Distributed/DistributedJobCatalog.php`, `app/Services/Distributed/DistributedJobWorker.php`, `docs/distributed-workers.md` | Medium-high, best extraction boundary |
| NNTP/header ingestion | `app/Services/NNTP/NNTPService.php`, `app/Services/Binaries/BinariesService.php`, `app/Services/Binaries/HeaderParser.php`, `app/Services/Binaries/HeaderStorageService.php` | Very high |
| Header DB writes | `app/Services/Binaries/BinaryHandler.php`, `CollectionHandler.php`, `PartHandler.php`, `HeaderStorageTransaction.php` | Very high |
| Release lifecycle | `app/Services/ReleaseProcessingService.php`, `app/Services/ReleaseCreationService.php`, `app/Services/CollectionCleanupService.php` | Very high |
| Categorization/name fixing | `app/Services/Categorization/*`, `app/Services/NameFixing/*` | Very high |
| NZB creation | `app/Services/Nzb/NzbService.php`, `app/Services/Nzb/NzbBacklogCreationService.php` | High |
| Search | `app/Services/Search/*`, `app/Services/Releases/ReleaseSearchService.php`, `app/Support/ReleaseSearchIndexSync.php` | Very high |
| UI/API/RSS/admin | `routes/web.php`, `routes/api.php`, `routes/rss.php`, `app/Http/Controllers/*` | High |

## Feasibility Verdict

### Full Rewrite

Technically possible, but not advisable.

A big-bang rewrite would need to preserve:

- API v1 XML/newznab behavior, API v2 JSON behavior, RSS feeds, NZB download paths, admin UI, auth, user roles, and settings.
- MariaDB schema compatibility across 80 tables.
- Redis locks, queues, cache behavior, and Horizon/scheduler semantics.
- Manticore/Elasticsearch document updates, fuzzy/autocomplete/search fallback behavior, and DB hydration.
- NNTP provider behavior: group selection, XOVER, compressed overview responses, BODY reads, reconnects, alternate provider fallback, and yEnc decode.
- Filesystem behavior for NZB paths, covers, archives, media samples, temp extraction, and cleanup.
- Hundreds of regex and heuristic decisions in categorization and name fixing.

This would turn an indexing performance project into a multi-year product rewrite.

### Phased Extraction

Recommended.

The branch already has the right boundary: `nntmux:worker {job}`. `DistributedJobCatalog` maps lanes like `binaries`, `backfill`, `releases`, `fixnames`, `hashed-fixnames`, `removecrap`, post-processing, metadata refresh, IRC, and `per-group` to command plans. `DistributedJobWorker` refreshes settings/counts, takes Redis cache locks, dispatches work, sleeps, and releases locks on termination.

That means a native worker can first run in shadow mode beside one lane, compare results, and only later replace the PHP command for that lane.

### Targeted Native Accelerators

Also feasible, but they should be narrow.

Good candidates:

- yEnc decode/encode and body preamble parsing.
- XOVER/header parsing.
- NZB XML parsing/writing normalization.
- Archive/media probing wrappers.
- Bulk search-index population helpers.

These should be libraries or CLIs with golden fixtures, not broad rewrites of the Laravel domain model.

## Language Recommendation

### 1. Go: Best Primary Choice

Go is the best fit for native worker lanes.

Why it fits this repo:

- The first migration target is long-running worker orchestration with DB/network I/O, not low-level memory manipulation.
- Go's goroutines and channels map well to bounded NNTP/header/post-processing concurrency.
- Go's standard `database/sql` package is a mature fit for MariaDB/MySQL access through drivers.
- Static binaries and small container images simplify Kubernetes lane deployment.
- Go has practical runtime diagnostics and race detector support for worker services.
- The language is easier to maintain for operators who need to debug lane behavior, logs, metrics, SQL, and retries.

Best targets:

- Shadow-mode distributed worker runner.
- A Go `binaries` or `metadata-refresh` lane after fixtures exist.
- DB/search/Redis-compatible worker services with Prometheus metrics.

Main caveat: Go does not eliminate semantic complexity. It still needs strict contracts for DB writes, search updates, locks, and idempotency.

### 2. Rust: Best for Narrow Accelerators

Rust is the best secondary language when memory safety and parsing correctness dominate.

Why it fits selected components:

- Ownership and borrowing are strong protection for parsers, codecs, and binary buffers.
- Rust concurrency is useful when shared mutable state is dangerous.
- Rust is appropriate for yEnc, NZB/header parsing, archive metadata helpers, or other CPU-bound paths.

Why it is not the first whole-lane choice:

- The first migration boundary is operational: SQL, Redis, search HTTP, settings, locks, logs, metrics, and Kubernetes. Go gets there faster.
- Rust would slow iteration unless the team already wants Rust as a long-term platform commitment.

### 3. C++: Not Recommended

C++ should not be the primary rewrite language.

It can be fast, but it does not solve the hardest problems in this repo: compatibility with a large relational schema, Laravel side effects, search-index synchronization, operational locks, and heuristic business logic. It also adds the highest packaging and memory-safety burden. Use it only when a required native dependency cannot be reasonably accessed from Go or Rust.

## Why Database and Search Matter More Than Language

Recent code and migrations show the hot path is often DB/query-shape-bound:

- `HeaderStorageService` explicitly bounds header chunks to avoid PHP and MySQL memory pressure.
- `BinaryHandler` batches binary resolution and caps SQL rows per statement.
- `ReleaseProcessingService` uses staged promotion queries, chunking, retryable transactions, and targeted sleeps.
- Recent migrations add indexes for parts lookup, binary hash lookup, release-stage collection selection, release group joins, and NZB backlog partitioning.
- `NzbBacklogCreationService` uses `whereExists`/`whereNotExists` completion gates before writing NZBs.

A native rewrite will not improve a bad query plan, missing index, wrong lock contract, or expensive search reindex pattern. The first performance work should include profiling and query-plan evidence.

## Performance Hot Paths

The code review points to three different kinds of hotspot, each with a different migration answer:

| Hot Path | Dominant Limit | Native Upside | Best Treatment |
| --- | --- | --- | --- |
| NNTP XOVER/header ingestion | Network, DB writes, PHP memory, process churn | High if streaming and batching are redesigned | Go or Rust shadow ingester with bounded buffers and DB parity checks |
| yEnc/PAR2/body processing | CPU, buffers, CRC, article-body memory | High | Rust helper or worker first; Go is acceptable if operational simplicity wins |
| NZB creation | DB read shape, XML/gzip memory, filesystem I/O | Medium-high | Streaming Go/Rust writer after DB selection is profiled |
| Header storage into `collections`/`binaries`/`parts` | DB transactions, tuple lookup, lock pressure | Low-medium | Improve staging, partitioning, and SQL ownership before expecting native gains |
| Release creation/filecheck/name fixing | SQL joins, grouping, updates, regex heuristics | Low-medium | Keep in PHP/SQL initially; add fixtures before any extraction |
| Search index sync | DB hydration plus Manticore/Elasticsearch I/O | Low as a language rewrite | Add outbox/bulk indexing before rewriting in a native language |

Recommended instrumentation before implementation:

- Per-lane rows/sec, bytes/sec, article/sec, and elapsed phase timings.
- DB commit latency, deadlocks, lock waits, rows examined, and slow-query samples for CBP and release-stage queries.
- Peak RSS and allocation hotspots for XOVER batches, `range()` article spans, NZB XML/gzip output, and yEnc decode buffers.
- Search write latency and retry/failure counts.
- NNTP reconnects, compressed overview time, BODY preamble probes, and provider fallback counts.

## Migration Plan

### Phase 0: Contract Fixtures

Before rewriting a lane, export language-neutral fixtures from existing tests.

Required fixture sets:

- Categorization/name fixing: hashed names, classic movies, software false positives, music/books/games/anime/XXX, name-fix recategorization.
- Header ingestion: raw XOVER lines, blacklisted headers, duplicate replay, missing part repair, transaction rollback.
- yEnc/NNTP: yEnc decode, body preamble extraction, group selection, reconnect/quit behavior, compressed overview replay.
- Release processing: collection promotion stages, completion percentage, dense/sparse file numbers, cleanup.
- NZB: backlog selection, completion threshold, XML output, gzipped paths, failure marking.
- Worker lanes: settings snapshots, counts, disabled reasons, commands, sleeps, lock TTLs.
- Search: document shape, update/delete/reindex behavior, fuzzy/autocomplete/query parity.

### Phase 1: Shadow Go Worker

Build a Go worker for one lane in read-only or shadow mode.

Recommended first candidates:

1. Operational strangler proof: `metadata-refresh`, because it has smaller blast radius and mostly exercises settings, DB, HTTP, locks, metrics, and deployment.
2. Performance proof: yEnc/PAR2/body processing or streaming NZB generation, because those can show native CPU/memory value without replacing the whole release lifecycle.
3. Highest-upside lane: `binaries`, but only after strong NNTP/header fixtures exist and the DB write path has explicit ownership and contention tests.

The Go process should consume the same settings/count inputs, use the same Redis lock naming, emit comparable logs/metrics, and produce a dry-run mutation plan.

### Phase 2: Write-Gated Lane

Enable writes only after parity checks prove:

- Same DB rows are selected and changed.
- Search documents are updated or queued identically.
- NZB/filesystem side effects match.
- Metrics and logs expose equivalent operator signals.
- Crash/retry/duplicate invocation behavior is idempotent.

### Phase 3: Broaden or Stop

If the first lane improves throughput, memory, or operability enough, repeat lane-by-lane. If not, stop at targeted accelerators and keep Laravel workers.

## Verification Gates

Minimum gates before any native lane can replace PHP:

```bash
composer install
php artisan test --compact --testsuite=Unit --filter='HashedReleaseCategorizationTest|FileNameExtractorTest|NzbParserServiceTest|YencServiceTest|DistributedJobCatalogTest|NntmuxPrometheusMetricsTest'
php artisan test --compact --testsuite=Feature --filter='BinariesStorageInternalsTest|BinariesStoreHeadersTest|ReleaseProcessingDeobfuscatedCollectionTest|NzbCreateBacklogCommandTest|ReleaseNameFixedRecategorizationTest|YencBodyDeobfuscationStorageTest'
php artisan test --compact --testsuite=Install
```

Native-specific gates:

- Golden fixture parity for the selected lane.
- Real MariaDB integration, not only SQLite.
- Redis lock and crash-recovery tests.
- Manticore or Elasticsearch indexing parity for any release mutation.
- Recorded NNTP replay or a small local NNTP test server for ingestion lanes.
- Docker/Kubernetes smoke with one pod per lane and no duplicate processing.

Current limitation: this worktree did not have `vendor/bin/phpunit`, so the existing PHP tests were enumerated and mapped but not executed.

## Decision Matrix

| Criterion | Go | Rust | C++ |
| --- | --- | --- | --- |
| Worker-lane migration speed | Strong | Medium | Weak |
| Kubernetes deployment simplicity | Strong | Strong | Medium |
| DB/Redis/search service glue | Strong | Medium | Medium |
| Parser/codec safety and performance | Medium | Strong | Strong |
| Team/operator maintainability | Strong | Medium | Weak-medium |
| Memory safety by default | Medium | Strong | Weak |
| Packaging/build risk | Low | Medium | High |
| Fit as primary rewrite language | Best | Secondary | Poor |

## Final Recommendation

Use **Go** as the primary native migration language and apply it through a strangler pattern around distributed worker lanes. Keep Laravel as the system of record for UI, API, admin, settings, migrations, and compatibility.

Use **Rust** selectively for small parsing or codec components after profiling proves CPU or memory-safety pressure in PHP.

Do **not** pursue a C++ rewrite unless a specific native dependency makes it unavoidable.

The safest first proof is a shadow-mode Go worker for one distributed lane with golden fixtures, MariaDB/Redis/search smoke tests, and explicit parity checks before any write-mode replacement.

## Source Links

External primary sources:

- Go documentation: https://go.dev/doc/
- Effective Go: https://go.dev/doc/effective_go
- Go `database/sql`: https://pkg.go.dev/database/sql
- Go race detector: https://go.dev/doc/articles/race_detector
- Rust ownership: https://doc.rust-lang.org/book/ch04-00-understanding-ownership.html
- Rust concurrency: https://doc.rust-lang.org/book/ch16-00-concurrency.html
- Rust async: https://doc.rust-lang.org/book/ch17-00-async-await.html
- Rust process module: https://doc.rust-lang.org/std/process/index.html
- C++ Core Guidelines: https://isocpp.org/guidelines
