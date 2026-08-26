# Brownfield Map: Native Reimplementation Feasibility

Date: 2026-06-15
Branch: `microservices-pods`
Commit: `18c0d1651602c6a730ec7b2f202f9d90d6f46dd2`

## Task Contract

Goal: analyze the current NNTmux/newznab-tmux code and evaluate whether a native-language reimplementation in C++, Rust, or Go is feasible.

Context: current branch is a Laravel 13/PHP application with web, API, RSS, queue, scheduler, tmux, and distributed worker runtimes. The highest-risk code is in NNTP ingestion, header storage, release processing, categorization, name fixing, NZB creation, search indexing, and operational worker orchestration.

Constraints: preserve existing MariaDB/Manticore/Redis/file contracts, existing API/RSS/admin behavior unless explicitly broken, and live operational lane semantics. A rewrite recommendation must be grounded in the current branch, not generic language preference.

Done when: the feasibility report, brownfield map, and research claim ledger exist; recommendation names the best language and migration strategy; risk and verification gates are explicit.

## Boundaries

### Control Plane

Keep Laravel as the control plane for any first migration phase.

Evidence:

- `README.md` describes NNTmux as a Laravel Usenet indexer with web frontend, API access, full-text search, distributed workers, and tmux processing.
- `composer.json` requires PHP 8.4, Laravel 13, Horizon, Manticore/Elasticsearch clients, media tooling integrations, and many PHP extensions.
- `routes/web.php`, `routes/api.php`, and `routes/rss.php` expose broad user-facing behavior that is not naturally isolated from Laravel middleware, auth, and models.
- `config/database.php`, `config/search.php`, `config/nntmux.php`, and `config/nntmux_nntp.php` are the runtime contract surface.

Risk: replacing this surface first converts a processing rewrite into a full product rewrite.

### Worker Lanes

The best extraction boundary is the distributed worker lane model.

Evidence:

- `app/Console/Commands/NntmuxDistributedWorker.php` exposes `nntmux:worker {job}` with `--once`, `--sleep`, `--lock-seconds`, and `--list`.
- `app/Services/Distributed/DistributedJobCatalog.php` maps each lane to existing Artisan commands: `binaries`, `backfill`, `releases`, `fixnames`, `hashed-fixnames`, `removecrap`, post-processing lanes, IRC, metadata refresh, and per-group processing.
- `app/Services/Distributed/DistributedJobWorker.php` refreshes settings/counts every cycle, uses cache locks, runs commands, sleeps, and handles signal-driven lock release.
- `docs/distributed-workers.md` says Kubernetes should run one pod per job and should not run legacy tmux and distributed workers concurrently.

Risk: native lanes must exactly preserve lock ownership, disabled-lane behavior, settings refresh, sleep/exit semantics, and idempotency.

### Data Plane

The data plane is relational and write-heavy.

Evidence:

- `database/schema/mariadb-schema.sql` contains 80 tables.
- Core hot tables include `collections`, `binaries`, `parts`, `missed_parts`, `releases`, `releases_groups`, `release_files`, `release_nfos`, `usenet_groups`, `settings`, and metadata tables.
- Recent migrations add targeted indexes for hot paths: NZB backlog selection, release group lookup, collection release-stage queries, parts-number lookup, and binary hash lookup.
- `ReleaseProcessingService` uses staged SQL updates and retryable transactions for collection promotion.
- `HeaderStorageService`, `BinaryHandler`, `PartHandler`, and `CollectionHandler` coordinate chunked inserts, retries, dedupe, and rollback behavior.

Risk: a native service that writes directly to these tables can silently diverge from Laravel observer/search/file side effects.

### Search Plane

Search is an external backend plus DB hydration, not a simple SQL query.

Evidence:

- `SearchService` abstracts Manticore and Elasticsearch.
- `SearchServiceInterface` includes release/predb/movie/tv/music/book/game/console/anime indexing, fuzzy search, autocomplete, suggest, bulk insert, truncate, and optimize operations.
- `ManticoreSearchDriver` performs retries, fallback job dispatch, and document normalization.
- `ReleaseSearchIndexSync` exists because raw SQL updates bypass Eloquent observers and still need search reindexing.

Risk: rewriting only DB mutations without search parity will produce broken browse/API results.

### Performance Plane

Native code is most attractive where the PHP process currently holds large network/body buffers or does CPU-heavy parsing.

Evidence:

- NNTP header ingestion downloads and parses XOVER ranges before writing CBP rows.
- Body preamble and yEnc/PAR2 paths involve article-body reads, decode buffers, CRC checks, and metadata extraction.
- NZB creation loads release rows, writes XML, gzips output, and cleans related rows.
- Header storage, release processing, and search sync are tightly coupled to SQL joins, bulk writes, retries, locks, and external search I/O.

Risk: a faster native writer can increase DB deadlocks or search lag unless ownership, batching, lock waits, and idempotency are measured first.

## Invariants

- MariaDB/MySQL remains the source of truth for release lifecycle state.
- Redis-backed locks remain the duplicate-work guard for distributed lanes.
- Search index updates must happen after any native mutation equivalent to Laravel release changes.
- Existing API v1/v2, RSS, and NZB download compatibility are preserved unless a later plan explicitly declares a breaking migration.
- Native components must be idempotent under restart and duplicate invocation.
- Large table changes must remain opt-in/online where the current migrations require it.

## Strategy Recommendation

Use a strangler migration, not a big-bang rewrite.

1. Keep Laravel for UI/API/admin/settings/migrations/search compatibility.
2. Export language-neutral contract fixtures from existing tests.
3. Prototype one Go worker lane in read-only or shadow mode.
4. Move to write mode only after DB/search/filesystem parity checks pass.
5. Use Rust only for small, hot parsing/codec helpers when profiling proves PHP is the bottleneck.
6. Avoid C++ unless a required native library forces it.

## Next Step

Use `docs/native-reimplementation-feasibility.md` as the decision report and convert the recommended first Go worker proof into an implementation plan only after profiling or live operator goals identify the first lane.
