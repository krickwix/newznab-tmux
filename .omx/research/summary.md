# Native Reimplementation Research Summary

Date: 2026-06-15
Branch: `microservices-pods`
Commit: `18c0d1651602c6a730ec7b2f202f9d90d6f46dd2`

## Finding

A native reimplementation is feasible only as a phased migration. A full rewrite of NNTmux/newznab-tmux is not recommended as the first path because the current branch is a broad Laravel product and operational processing system, not a narrow worker binary.

Best language: Go for worker-lane extraction.

Secondary language: Rust for narrow parsing/codec helpers after profiling.

Not recommended: C++ as a primary migration language.

## Repo Truth

- The repo is a Laravel/PHP application with web, API, RSS, scheduler, Horizon queue, tmux, and distributed worker runtimes.
- The branch includes a useful migration boundary: `nntmux:worker {job}` and `DistributedJobCatalog` already map former tmux panes to pod-friendly lanes.
- The data plane is MariaDB/MySQL plus Redis and Manticore/Elasticsearch, with 80 schema tables and hot tables around headers, collections, binaries, parts, releases, and groups.
- The heaviest behavioral contracts are NNTP header ingestion, header storage, release creation, categorization/name fixing, NZB writing, search-index synchronization, and post-processing.
- The highest native performance upside is concentrated in streaming NNTP/header ingestion, yEnc/PAR2/body processing, and streaming NZB generation; release creation, search indexing, and CBP writes remain DB/query/lock constrained.
- Existing tests cover many internal behaviors, but a native rewrite needs language-neutral golden fixtures and live MariaDB/Redis/search/NNTP smoke tests.

## External Truth

- Go provides goroutines/channels, standard `database/sql`, race detector support, and operationally simple binaries, which align with Kubernetes worker lanes and DB/network I/O.
- Rust provides strong ownership and concurrency guarantees and is a good fit for small high-correctness parsers/codecs, but whole-lane migration would be slower and more complex.
- C++ offers performance but brings avoidable packaging, memory-safety, and maintenance overhead for this repo's dominant risks.

## Recommendation

Keep Laravel as the control plane and compatibility layer. Extract one lane at a time, starting with Go, and require parity before enabling write mode.

Suggested sequence:

1. Export golden fixtures from categorization, header storage, release processing, NZB, worker catalog, NNTP/yEnc, and metrics tests.
2. Instrument lane throughput, DB latency/locks, peak RSS, search write latency, and NNTP reconnect/probe behavior before choosing a write-mode target.
3. Build a Go shadow worker for one distributed lane using the same settings snapshot, lock semantics, DB reads, and metrics.
4. For a focused performance proof, prefer yEnc/PAR2/body processing or streaming NZB generation before a full `binaries` replacement.
5. Compare output to PHP on recorded fixtures and a disposable MariaDB/Redis/search/NNTP smoke stack.
6. Enable write mode only when DB rows, search documents, files, metrics, and retry/idempotency behavior match.
7. Consider Rust for yEnc/header/NZB parsing only if profiling shows parser/codec CPU dominates.

## Risks

- Full rewrite risk is high because API/RSS/admin/auth/search/migrations/settings would all need parity.
- Direct native DB writes can bypass Laravel observer/search/file side effects.
- SQLite-heavy tests do not prove MariaDB locking, deadlock, FK, or query-plan behavior.
- Search compatibility requires Manticore/Elasticsearch integration, not just DB parity.
- NNTP behavior needs recorded or simulated sessions for compression, reconnects, malformed headers, body preamble reads, and provider edge cases.

## Open Questions

- Which lane should be first: binaries ingestion, post-processing, metadata refresh, or a read-only/search helper?
- Is the goal throughput, memory reduction, deployment simplification, or long-term maintainability?
- Is API/admin parity a hard requirement for the long-term endpoint, or only for migration phases?

## Artifacts

- `.omx/plans/brownfield-map.md`
- `.omx/research/native-reimplementation-claim-ledger.md`
- `docs/native-reimplementation-feasibility.md`
