# Ingest collection keying: stop one posting becoming N collections

Status: **implemented behind a per-group flag, not yet enabled anywhere.**
Written for review before any code ships; the design below is unchanged, and
the implementation notes are at the bottom under "As built".

## Why this exists

Three repair passes now merge fragmented collections, and an hourly sweep runs
them. None of that stops fragmentation — it only clears the residue. On
2026-08-04, **1,964 of the 2,726 collections created in 24h held a single
binary**, and a fresh fragment appeared while the manual sweep was still
running.

## The defect, exactly

`HeaderStorageService::extractFileNumberAndTotal()`:

```php
$fileCount = $this->getFileCount($header['matches'][1]);   // subject WITHOUT the part counter
if ($fileCount[1] === 0 && $fileCount[3] === 0) {
    $fileCount = $this->getFileCount($header['matches'][0]);  // ← the RAW subject
}
return [(int) $fileCount[1], (int) $fileCount[3]];
```

`matches[1]` is the subject with its `(x/y)` **part** counter stripped;
`matches[0]` still has it. So when a subject carries no file counter, the
fallback matches the part counter and returns it as a file count.

That value flows straight into the collection key:

```php
// CollectionHandler::collectionIdentity()
return $collMatch['name'].$totalFiles;
```

Files of one posting have different part counts, so they mint different keys.
Live example from `alt.binaries.cinemageddon`, one posting:

| subject | cleaned name | totalfiles | key |
|---|---|---|---|
| `..._repost_(Submission.vol001+01.PAR2) yEnc (1/2)` | `60s_Sleaze_-_Submission_(1969)_repost_(Submission ) yEnc` | **2** | name+2 |
| `..._repost_(Submission.vol009+06.PAR2) yEnc (1/7)` | *identical* | **7** | name+7 |

The cleaner is doing its job — both clean to the same string, verified against
the live service. The part count is what splits them.

Downstream, stage 1 needs `COUNT(DISTINCT filenumber) >= totalfiles`, which a
one-binary fragment of a 30-file posting never reaches, so the rows sit at
`filecheck=0` until retention purges articles that were fully downloaded.

## Scope

**In scope — the part-count leak.** ~1,017 fragmented collections/day in
cinemageddon alone, and it is a *bug*: the value in `totalfiles` is simply
wrong.

**Out of scope — random per-file names.** `alt.binaries.moovee` and
`alt.binaries.hdtv` post files like `"gjX3QVMbjFGuo7IXAfp"` with no shared stem
and often no counter at all (~505/day). There is no ingest-time signal that
groups them: the only candidate key is (poster, declared count, time window),
which merges two same-sized postings by one poster into a chimera. The
`repair-fragmented-posting-identity` pass reaches them *after the fact* because
it can verify a filenumber bijection over the whole cohort — a check that is
impossible when you are looking at one article. **Do not extend this design to
cover them.**

**Out of scope — brace-token.** Already has a normalizer and a repair, and the
class has stopped arriving: 0 of 645 new collections in the allowlisted groups
over 24h.

## Design

### 1. Tell a real file counter from a part counter

`getFileCount()` gains a third element: whether the match came from the
de-part-countered subject (trustworthy) or from the raw-subject fallback (a part
counter wearing a file counter's clothes).

```php
[$fileNumber, $totalFiles, $countIsReal] = $this->extractFileNumberAndTotal($header);
```

Nothing else about the patterns changes.

### 2. When the count is not real, keep it out of the key and out of the column

| | today | proposed |
|---|---|---|
| collection key | `name . partCount` | `name . 0` |
| `collections.totalfiles` | part count | `0` |
| `binaries.filenumber` | part number of whichever article arrived first | dense ordinal, allocated |

`totalfiles = 0` is not a special case — it is the existing path for
counter-less postings. `runCollectionFileCheckStage0()` already promotes such
collections by setting `totalfiles = MAX(NULLIF(filenumber, 0))` once
`COUNT(DISTINCT filenumber) >= CEIL(MAX(filenumber) * completion / 100)`.

### 3. Dense ordinal allocation — the hard part

With `completion = 100` (production: `settings.completion` is NULL → 100), that
gate reduces to `COUNT(DISTINCT filenumber) == MAX(filenumber)`. **Only a dense
1..N satisfies it.** So the ordinal cannot be left at 1, and it cannot be
sparse.

Per ingest batch, per collection:

1. `SELECT COALESCE(MAX(filenumber), 0) FROM binaries WHERE collections_id = ?`
   — once per collection, not per header.
2. Assign `max + 1, max + 2, …` to the *new* files in the batch, in a stable
   order (subject sort) so a replay is deterministic.
3. Files already present resolve by binary hash and keep the ordinal they have.

**Concurrency is the open risk.** `binaries` is `UNIQUE (collections_id,
filenumber)`, so a second writer allocating from a stale `MAX` collides. The
worker lanes (`binaries`, `current-forward`, `backfill`) each hold their own
lane lock, not a per-group exclusive one, so two lanes writing one collection
is plausible. The allocator must therefore:

- catch `UniqueConstraintViolationException` on the binary insert,
- re-read `MAX(filenumber)` and retry the batch **once**,
- on a second failure, fail the batch to part-repair rather than guessing.

This is the piece I would want reviewed hardest. It is also the reason this was
not shipped earlier.

### 4. Coexistence with collections already keyed the old way

A posting mid-flight has collections under `name . partCount`. New articles
would compute `name . 0`, miss, and create yet another collection.

Mitigation: on a key miss, look up the **legacy** key before inserting — i.e.
try `name . $partCount` and adopt that collection if it exists. This keeps
in-flight postings landing where their siblings already are, and the hourly
sweep merges whatever still split. The legacy lookup can be dropped once the
backlog has aged out (~7 days).

## Why this is safe

- **It only ever removes a wrong value.** A subject with a real file counter is
  untouched — same key, same `totalfiles`, same ordinal. Measured: the
  `declares_a_real_file_count` guard in the split repair refuses 590 cohorts
  across movies/hdtv/cinemageddon, of which 579 genuinely are short. Those
  postings keep exactly today's behaviour.
- **`totalfiles = 0` cannot publish a partial archive as complete.** Stage 0
  only sets a count once the filenumbers are dense, and stage 6 recomputes it
  from `COUNT(binaries)` regardless.
- **The failure mode is a stall, not corruption.** If allocation goes wrong the
  collection stays at `filecheck=0` — the state it is in today.

## Test plan

Fixtures transcribed from live subjects, not invented.

**Unit — counter classification**

| subject | real counter? |
|---|---|
| `Foo.Bar [03/11] - "x.rar" yEnc (1/500)` | yes → 3 of 11 |
| `..._repost_(Submission.vol001+01.PAR2) yEnc (1/2)` | **no** |
| `"gjX3QVMbjFGuo7IXAfp" yEnc (1/158)` | **no** |
| `Foo File 3 of 11 yEnc (1/500)` | yes |

**Integration — the whole point.** Feed the articles of one counter-less
multi-file posting through `HeaderStorageService` and assert **one** collection,
one binary per file, filenumbers dense `1..N`, `totalfiles = 0`; then run
`runCollectionFileCheckStage0()` and assert it promotes to `filecheck=1` with
`totalfiles = N`.

**Regression — real counters unchanged.** The same test for a
`[03/11]` posting asserts byte-identical behaviour to today. This is the test
that must fail loudly if the classification ever widens.

**Allocator under contention.** Two interleaved batches into one collection;
assert no duplicate ordinals, no lost binaries, and that the retry path is
exercised (not merely present).

**Characterisation.** With the flag off, ingest must produce today's fragmented
shape exactly — so the flag is provably a switch and not a rewrite.

## Rollout

1. Config flag + per-group allowlist, defaulting **off**
   (`nntmux.ingest_partcount_key_groups`), mirroring
   `obfuscated_brace_token_groups`. Declared in the manifest so
   `test_every_declared_nntmux_variable_is_read_by_a_config_file` covers it.
   Note the v225 lesson: the key must exist in the *image's* `config/nntmux.php`,
   not just on the branch.
2. Enable for `alt.binaries.cinemageddon` alone — the dominant class, 1,017/day,
   and a group whose subjects carry real names, so a wrong merge is visible.
3. Watch for 24h: one-binary collections created per hour in that group should
   fall toward zero; `filecheck=0` count should stop growing; releases from the
   group should appear.
4. Widen group by group.

**Rollback** is flipping the flag. Collections already keyed the new way are
valid — they are the shape everything downstream wants — and the hourly sweep
reconciles anything mid-flight.

## Open questions, answered

**1. Can two lanes write binaries into one collection concurrently? — Assume
yes.** There is no per-group lock anywhere in the ingest path: no `GET_LOCK`,
no `lockForUpdate`, no cache lock in `HeaderStorageService` or
`CollectionHandler`. The lane locks (`nntmux:release-worker-lock`) scope a
*lane*, not a group, and `binaries`, `current-forward` and `backfill` all reach
the same code. **The retry path is load-bearing and the contention test is not
optional.**

**2. Is `settings.completion` ever below 100? — No.** It is NULL in production
(→ 100 via `requiredCompletionPercent()`), so the stage 0 gate really does
reduce to `COUNT(DISTINCT filenumber) == MAX(filenumber)`. Dense means dense.
If anyone ever sets it lower, sparse ordinals start passing and this design's
safety argument weakens — worth an explicit test pinning the 100 assumption.

**3. Does anything mis-read `totalfiles = 0`? — One interaction, and it is
benign.**

- `PipelineSnapshotRepository` is already 0-aware: it computes
  `COALESCE(NULLIF(c.totalfiles, 0), MAX(NULLIF(b.filenumber, 0)), 0)`, the same
  fallback stage 0 uses. Orchestrator telemetry needs no change.
- `SplitCollectionReconciler` selects `whereBetween('totalfiles', [2, MAX])`, so
  new-shape collections are **invisible** to it. That is fine and arguably
  correct: the reconciler exists to pair a payload anchor with its par2
  companions across collections, and this change puts them in one collection to
  begin with. Worth asserting in a test so the exclusion is deliberate rather
  than discovered.

## Effort and sequencing

Roughly: classification + key change is small and well understood; the
allocator with its retry and contention test is the bulk of the work and the
bulk of the risk. The legacy-key adoption is optional — without it the hourly
sweep simply has more to do for a week.

I would not implement this in one pass. Classification first, behind the flag,
asserting today's behaviour is unchanged everywhere — then the allocator, with
the contention test written before it.

---

## As built

Written after the fact. The design above is what shipped; this records the two
places the implementation had to differ, and where the code is.

### Where it lives

| Piece | File |
|---|---|
| Classification (already shipped, v229) | `app/Services/Binaries/HeaderStorageService::extractFileNumberAndTotal()` |
| The switch | `app/Services/Binaries/IngestCollectionKeying.php` |
| Demotion + wiring | `app/Services/Binaries/HeaderStorageService::processHeaderChunk()` |
| Dense ordinals + contention check | `app/Services/Binaries/CollectionFileNumberAllocator.php` |
| The collision | `app/Services/Binaries/CollectionFileNumberCollision.php` |
| Legacy-key adoption | `app/Services/Binaries/CollectionHandler::adoptLegacyCollections()` |
| Config | `config/nntmux.php` |

### Difference 1 — the flag is not `ingest_partcount_key_groups`

The design names that key, but between the design and this implementation the
name was taken by the *reporting* flag from the v229/v230 measurement window,
and that flag is deployed fleet-wide as `all`
(`mediahome/manifests/media/nntmux/distributed-workers.yaml`). Reusing it would
have turned the next image build into a fleet-wide behaviour change nobody asked
for.

So the write flag is a new key, `nntmux.ingest_collection_keying_groups`
(`NNTMUX_INGEST_COLLECTION_KEYING_GROUPS`), empty by default, and it
deliberately has **no `all` sentinel** — the Rollout section above is group by
group.
The reporting flag keeps its name, its meaning and its `all`.

### Difference 2 — legacy adoption defaults off

Section 4 calls adoption optional. It defaults **off**
(`NNTMUX_INGEST_COLLECTION_KEYING_LEGACY_ADOPTION`) because of a hazard the
design does not discuss: an adopted collection keeps its old, wrong
`totalfiles`. Feed it the rest of a large posting and
`COUNT(DISTINCT filenumber) >= CEIL(totalfiles * completion / 100)` is satisfied
by a handful of files, so it promotes and releases short. Without adoption the
in-flight fragment stalls exactly where it stalls today and the hourly sweep
merges it, which is the safer half of the trade.

The hazard is not created by adoption — two files of one posting that happen to
declare the same part count already share a too-small `totalfiles` today — but
adoption feeds it more.

Note also what adoption can reach. Every demoted header of one posting collapses
onto one new key, so the pending row carries the legacy keys of all of them and
the lowest-id match wins, taking the whole posting with it. That only works
because one of those legacy keys is already in the database: adoption catches a
posting whose ingest had *started*, which is the in-flight case it exists for.

### Retry: the machinery was already there

The design's contract — catch the collision, re-read `MAX`, retry the batch, and
on repeated failure fail to part repair — is served by the chunk retry loop in
`HeaderStorageService::storeChunk()`. `TransientHeaderStorageFailure::is()`
recognises `CollectionFileNumberCollision`, the transaction rolls back, the next
attempt re-reads `MAX(filenumber)` from scratch, and after
`MAX_CHUNK_ATTEMPTS` (3) the chunk's article numbers go to part repair. No new
retry loop was written.

The insert itself cannot raise for us: `BinaryHandler`'s bulk statement carries
`ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)`, so a stolen ordinal resolves
*silently* to the other writer's binary. Detection is therefore
`CollectionFileNumberAllocator::assertOrdinalsHeld()`, which runs after binary
resolution and before any part is attributed, and asks only "is this binary the
file we asked for?" — not "is the filenumber the one we picked", because a
pre-existing file legitimately keeps a legacy ordinal.

Locking was considered and rejected: header storage runs at READ COMMITTED
specifically to avoid gap locks (`HeaderStorageTransaction::begin()`), so a
locking read could not protect the gap above `MAX` anyway.

### Tests

- `tests/Unit/Binaries/CollectionFileNumberAllocatorTest.php` — density,
  determinism, high-water continuation, legacy-zero handling, collision
  detection.
- `tests/Feature/IngestCollectionKeyingTest.php` — the integration case from the
  test plan, stage 0 promotion, the completion-100 pin, the characterisation
  (flag off still fragments), the differential regression for real counters, and
  the `SplitCollectionReconciler` exclusion.
- `tests/Feature/IngestCollectionKeyingContentionTest.php` — the contention path
  actually exercised: one collision retried into a clean 1..N, persistent
  contention failed to part repair with nothing half-written, and a
  non-transient allocator bug still propagating.

### Still to do before this is live

1. Declare `NNTMUX_INGEST_COLLECTION_KEYING_GROUPS` on the three lanes that
   reach `HeaderStorageService` (`binaries`, `current-forward`, `backfill`) in
   `mediahome/manifests/media/nntmux/distributed-workers.yaml`, set to
   `alt.binaries.cinemageddon`.
2. The v225 lesson: the key must exist in the **image's** `config/nntmux.php`,
   not just on the branch. An overlay that copies `HeaderStorageService.php`
   without `config/nntmux.php` gives a pod where `config()` returns `[]` and the
   manifest variable is silently inert.
3. Then step 3 of the rollout above: watch one-binary collections created per
   hour in that group fall toward zero, `filecheck=0` stop growing, and releases
   from the group appear. The per-batch count is logged as
   `ingest re-keyed collections off a leaked counter`.

   That wording is load-bearing: the measurement CronJob in
   `mediahome/manifests/media/nntmux/distributed-workers.yaml` sums every log
   line containing the substring `part count` followed by a `{group, headers}`
   object, so a message reusing that phrase would silently double-count the
   reporting flag's window.
