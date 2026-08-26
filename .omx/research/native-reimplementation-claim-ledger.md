# Research Claim Ledger

Schema fields: `question`, `claims`, `sources`, `freshness`, `contradictions`, `confidence`, `recheck_trigger`, `next_action`.

## Question

Is it feasible and advisable to reimplement NNTmux/newznab-tmux on branch `microservices-pods` in a native language such as C++, Rust, or Go, and which language is the best fit?

## Claims

| Claim | Source provenance | Evidence checked | Confidence | Recheck trigger |
| --- | --- | --- | --- | --- |
| A full native rewrite is technically possible but operationally high risk and not recommended as the first path. | Current repo at `18c0d1651602c6a730ec7b2f202f9d90d6f46dd2`; `README.md`; `composer.json`; `routes/*`; `database/schema/mariadb-schema.sql`; `app/Services/*`; subagent architecture/test/performance review. | Large Laravel app surface; 80 DB tables; 969 PHP files across app/config/routes/database/tests; 156 tests; broad API/RSS/admin/worker/runtime surface. | High | Major product decision to drop Laravel UI/API/admin compatibility. |
| Phased extraction around distributed worker lanes is the lowest-risk migration strategy. | `docs/distributed-workers.md`; `app/Console/Commands/NntmuxDistributedWorker.php`; `app/Services/Distributed/DistributedJobCatalog.php`; `app/Services/Distributed/DistributedJobWorker.php`. | One pod per former tmux lane already exists; catalog maps lane names to commands; worker handles settings refresh, locks, sleep, and signal release. | High | Distributed worker contract changes or tmux path removal. |
| Go is the best default language for native worker lanes. | Repo evidence plus Go docs: https://go.dev/doc/, https://go.dev/doc/effective_go, https://pkg.go.dev/database/sql, https://go.dev/doc/articles/race_detector. | Workload is long-running service/worker orchestration, network I/O, SQL, Redis/search HTTP clients, Kubernetes deployment, and concurrency. Go has goroutines/channels, `database/sql`, single-binary deployment, diagnostics, and race detector support. | High | First target is proven CPU/memory-safety-bound rather than worker orchestration/I/O-bound. |
| Rust is the best secondary choice for narrow native accelerators, not the first whole-lane migration language. | Repo evidence plus Rust docs: https://doc.rust-lang.org/book/ch04-00-understanding-ownership.html, https://doc.rust-lang.org/book/ch16-00-concurrency.html, https://doc.rust-lang.org/book/ch17-00-async-await.html, https://doc.rust-lang.org/std/process/index.html. | Rust ownership/concurrency is attractive for parsers/codecs, but migration velocity and ecosystem glue are less favorable than Go for DB-heavy operational worker lanes. | Medium-high | A profiler shows hot CPU parsing/codec work dominates DB/network I/O. |
| C++ is not the right primary rewrite language for this codebase. | Repo evidence plus C++ Core Guidelines: https://isocpp.org/guidelines. | C++ can be fast, but the repo's main risk is workflow compatibility, DB/search side effects, and operational maintenance. C++ increases packaging and safety burden without solving the dominant migration risks. | High | Required dependency exists only as a C/C++ library and cannot be wrapped from Go/Rust. |
| Current tests are useful but insufficient as-is for a native rewrite gate. | `phpunit.xml`; `tests/Feature/*`; `tests/Unit/*`; verification subagent output. | Existing tests are mostly Laravel-internal and SQLite-based; high-value behavior must be exported as language-neutral golden fixtures plus MariaDB/Redis/search/NNTP smoke tests. | High | New black-box contract fixture suite is added. |
| Native performance upside is concentrated in streaming ingestion/codecs/NZB output, while release creation, search sync, and CBP writes remain DB/query/lock constrained. | Performance subagent review of `BinariesService.php`, `NNTPService.php`, `HeaderStorageService.php`, `BinaryHandler.php`, `ReleaseProcessingService.php`, `NzbService.php`, `YencService.php`, `ManticoreSearchDriver.php`, and distributed worker code. | XOVER and body flows allocate large arrays/strings; yEnc/PAR2/NZB paths are CPU/memory-sensitive; CBP/release/search paths are dominated by SQL joins, bulk writes, retries, locks, and external search I/O. | High | Profiling or production metrics show PHP CPU dominates DB/network/search latency for a specific lane. |

## Sources

Repo sources inspected:

- `README.md`
- `AGENTS.md`
- `composer.json`
- `Dockerfile`
- `docker-compose.yml.prod-dist`
- `docs/distributed-workers.md`
- `routes/api.php`
- `routes/rss.php`
- `routes/web.php`
- `config/database.php`
- `config/search.php`
- `config/nntmux.php`
- `config/nntmux_nntp.php`
- `database/schema/mariadb-schema.sql`
- `database/migrations/2026_05_05_123242_add_cbp_query_indexes.php`
- `database/migrations/2026_05_05_124540_phase2_shrink_binaries_binaryhash.php`
- `database/migrations/2026_06_09_000000_add_nzb_backlog_selection_indexes.php`
- `database/migrations/2026_06_13_000000_add_releases_groups_group_index.php`
- `database/migrations/2026_06_13_010000_add_release_stage6_selection_index.php`
- `database/migrations/2026_06_14_010000_add_release_stage1_selection_index.php`
- `app/Console/Commands/NntmuxDistributedWorker.php`
- `app/Services/Distributed/DistributedJobCatalog.php`
- `app/Services/Distributed/DistributedJobWorker.php`
- `app/Services/Binaries/*`
- `app/Services/NNTP/NNTPService.php`
- `app/Services/ReleaseCreationService.php`
- `app/Services/ReleaseProcessingService.php`
- `app/Services/Nzb/*`
- `app/Services/Categorization/*`
- `app/Services/NameFixing/*`
- `app/Services/Search/*`
- `app/Support/ReleaseSearchIndexSync.php`
- `tests/Unit/*`, `tests/Feature/*`, `tests/Install/*`, `tests/Integration/*`

External primary sources inspected:

- Go documentation: https://go.dev/doc/
- Effective Go: https://go.dev/doc/effective_go
- Go `database/sql`: https://pkg.go.dev/database/sql
- Go race detector: https://go.dev/doc/articles/race_detector
- Rust ownership: https://doc.rust-lang.org/book/ch04-00-understanding-ownership.html
- Rust concurrency: https://doc.rust-lang.org/book/ch16-00-concurrency.html
- Rust async: https://doc.rust-lang.org/book/ch17-00-async-await.html
- Rust process module: https://doc.rust-lang.org/std/process/index.html
- C++ Core Guidelines: https://isocpp.org/guidelines

## Contradictions

- README language describes high performance and multi-threaded processing, but current evidence shows many recent performance fixes are database/index/query-shape fixes, not pure PHP CPU fixes.
- Native languages can improve throughput in selected parsing/codec/network code, but the dominant rewrite risk is semantic compatibility with a mature PHP/Laravel data plane.
- Rust is stronger than Go for compile-time memory-safety guarantees, but the first migration boundary is likely worker orchestration and DB/network I/O, where Go is simpler operationally.

## Freshness

Evidence date: 2026-06-15.

Repo commit: `18c0d1651602c6a730ec7b2f202f9d90d6f46dd2`.

External source freshness: checked live on 2026-06-15. Recheck language/runtime claims before implementation if Go, Rust, C++, MariaDB, Manticore, or Kubernetes target versions change materially.

## Decision

Evidence supports a phased native migration, not a full rewrite. Go is the recommended primary language for native worker lanes. Rust is recommended only for narrow accelerators when profiling proves parser/codec hotspots. C++ is not recommended except for forced library integration.

## Next Action

Route to planning only after choosing the first lane or accelerator. Recommended first proof: shadow-mode Go implementation for one distributed lane with golden fixtures and MariaDB/Redis/search smoke checks.
