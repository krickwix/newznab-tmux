# NNTmux Release Audit Remediation Plan - 2026-06-02

Source report: `/home/fandrieu/projets/media-workspace/docs/nntmux-release-audit-2026-06-02.md`

## Goal

Turn the audit findings into concrete code and operational changes that improve release quality, make the current hash backlog visible, and reduce noisy post-processing failures while keeping the live indexer running.

## Implemented Slices

### 1. Category-Based Hashed Backlog Metric

Problem: the report found `ishashed=1` and `dehashstatus=1` do not represent the live hashed backlog. The reliable signal is `categories_id=20` (`Other > Hashed`).

Implementation:

- Add `nntmux_releases_state_total{state="hashed_category"}` for `categories_id=20`.
- Add `nntmux_releases_state_total{state="hashed_effective"}` for `ishashed=1 OR categories_id=20`.
- Keep the existing `state="hashed"` metric for backward compatibility.

Verification:

- Unit test the metrics output with separate flag-based, category-based, and effective hashed counts.
- Check live `/metrics` after deployment for the new states.

### 2. NFO Save Race Guard

Problem: NFO post-processing can race with cleanup/removal workers. When a release is deleted between queue selection and NFO insert, `release_nfos` can throw an FK error.

Implementation:

- Route NFO writes through a single guarded helper.
- Skip NFO persistence when the parent release is already gone.
- Treat late-delete races as warning-level skipped writes, not error-level SQL failures.
- Preserve normal persistence and `nfostatus = NFO_FOUND` updates for existing releases.

Verification:

- Unit test missing-release NFO writes return `false` and create no `release_nfos` row.
- Unit test existing-release NFO writes persist content and mark the release found.
- Watch `post-additional` logs after deployment for absence of `release_nfos` FK noise.

### 3. Classic-Film Movie-vs-TV Classification

Problem: classic movie posts from vintage/classic film groups were frequently routed into TV categories, which then caused repeated TV parse failures.

Implementation:

- In the TV categorizer, decline TV matching for classic-film groups when the subject has movie-like year and file/source evidence and lacks strong episode/season evidence.
- Leave sports, anime, and explicit episode patterns eligible for TV routing.

Verification:

- Add regression cases for observed audit examples:
  - `Laura (1944)` in `alt.binaries.dvd.classic.movies`
  - `Kiss the Girls and Make Them Die 1966 TVrip...`
  - `The.Horror.At.37,000.Feet.1973.TVrip...`
  - `Ill.Wait.For.You.1941.TVRip...`
- Re-check live TV parse-failure volume after deployment.

### 4. Binary Insert Failure Diagnostics

Problem: the audit saw 372,590 reported failed article inserts in 12 hours, but the log lacked enough context to separate duplicate/noise from real insert failures.

Implementation:

- Keep the existing warning text for continuity.
- Add group name/id, requested range, received count, filtered counts, and a small failed-article sample.

Verification:

- Syntax-check the changed service.
- Inspect future `articles failed to insert!` log lines for group/range/sample context.

### 5. Multipart Classic-Film Name Extraction

Problem: multipart classic-film posts with support-file subjects or year-bearing subject titles were left with hashed or low-information names.

Implementation:

- Extract classic movie names from quoted support files such as `.nfo`, `.sfv`, and `.par2`.
- Extract classic movie collection parts such as `Laurel and Hardy cd 12 of 21.part063.rar`.
- Recover bare multipart subject titles with a year when the quoted archive filename is lower-information.
- Preserve support-file and archive-fallback precedence to avoid broad subject captures.

Verification:

- Unit test observed classic support-file examples and multipart archive/title examples.
- Include the extractor tests in the audit regression suite.

### 6. NNTP Connectivity Monitor and Connect Timeout

Problem: post-processing workers could stall on short hardcoded NNTP connection attempts, and connectivity failures were only visible in task logs.

Implementation:

- Add configurable `NNTP_CONNECT_TIMEOUT` settings for primary and alternate NNTP connections.
- Set the live connect timeout to 20 seconds while preserving the 120-second socket read timeout.
- Add an `nntmux-nntp-connectivity` CronJob that probes `news.eweka.nl:563` over TLS and verifies the NNTP `200` banner.
- Keep worker connection counts conservative to reduce provider-side connection pressure.

Verification:

- Syntax-check the NNTP service/config changes.
- Verify a live TLS probe from a worker pod.
- Server-side dry-run the connectivity CronJob and run a manual job after deployment.

### 7. IMDb Lookup Outcome Metrics

Problem: movie post-processing failures did not distinguish WAF blocks, fallback throttling, fallback HTTP failures, or successful fallback lookups.

Implementation:

- Record Redis counters for IMDb title lookup outcomes with bounded labels for outcome, primary reason, fallback reason, and source.
- Export those counters as `nntmux_imdb_lookup_total` from the Prometheus metrics command.
- Keep labels coarse to avoid release-id/title/cardinality growth.

Verification:

- Unit test WAF/fallback failure, fallback success, and fallback-min-interval cases.
- Unit test metrics exporter rendering for the new counter labels.

## Deferred Follow-Ups

These remain planned because they require longer live observation:

- Watch binary insert diagnostic logs over live backfill volume to separate duplicate/noise from true provider or database issues.
- Watch classic/movie extraction results against the live `Movies > Other` backlog and tune only with concrete false-positive samples.
- Watch IMDb lookup counters once post-movie processing generates enough fresh lookup volume.

## Done Criteria

- The implemented slices above are present in code.
- Focused tests or equivalent production-image verification pass.
- Live deployment picks up the changes or a clear handoff identifies that deployment remains pending.
- Residual deferred items are documented separately from completed fixes.
