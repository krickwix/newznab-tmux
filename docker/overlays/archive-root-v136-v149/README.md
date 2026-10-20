# Root overlay Dockerfiles, v136–v149 — archive

These twelve files used to sit at the repository root as `Dockerfile.v136` …
`Dockerfile.v149`. They were moved here on 2026-08-27 under initiative F2 of
`docs/plans/2026-08-26-ux-enhancement-plan.md`. Nothing builds from them.

## What they are

The earliest segment of the same FROM-chain the rest of `docker/overlays/`
holds. Each one is `FROM <previously published digest>` plus a handful of
`COPY app/…` lines and a `LABEL`. `../` picks the chain up at
`current-forward-v151.Dockerfile`; these cover the gap below it.

## Why they were moved and not deleted

`../../../build-and-push.sh` is the single build path now, and it builds from
the root `Dockerfile` — so none of these is reachable from any build. But:

- **Five of them existed only on disk.** `.gitignore` carries a root-anchored
  `/Dockerfile.v*`, so `Dockerfile.v136`, `.v137`, `.v138`, `.v139` and `.v140`
  were never tracked. `git rm` would not have been an option and a plain delete
  would have destroyed the only copy — including the published digests they
  chain from, which are not recorded anywhere else in this repo. That alone
  rules out deletion. The remaining seven (`.v141`, `.v142`, `.v143-nfo`,
  `.v143-nzb`, `.v144`, `.v145`, `.v149`) were tracked and are moved with
  `git mv`.
- Their siblings for v151+ are kept in `../`. Deleting one half of a continuous
  historical chain and keeping the other is not a defensible state.
- The digests and `COPY` lists are the only record of which PHP files went into
  which published tag during that period, since the tags came from ad-hoc
  `docker buildx build` invocations and are not reproducible from source.

The move also lifts the `.gitignore` exclusion — this path is not matched by
`/Dockerfile.v*`, so the five previously-untracked files can now be committed.

## Confirmed unreferenced before moving

Searched the whole workspace (excluding `.git/` and `node_modules/`) for
`Dockerfile.v1[34][0-9]` and `Dockerfile.v*`. Four hits, none of them a build:

| Hit | What it is |
| --- | --- |
| `build-and-push.sh:6` and `:16` | Header prose explaining that the old build *was* this chain, plus a verification one-liner `grep -hE '^[A-Z]+ ' docker/overlays/*.Dockerfile Dockerfile.v*`. A comment; never executed. **Its second path is now stale — it should read `docker/overlays/*.Dockerfile docker/overlays/archive-root-v136-v149/Dockerfile.v*`.** Left unedited only because that file was outside this change's scope. |
| `.github/dependabot.yml:17` | A comment explaining why Docker ecosystem updates are scoped the way they are: "…holds ~90 single-layer overlay Dockerfiles (Dockerfile.v136-.v149 here and …)". Prose, not a path Dependabot resolves. Worth a matching touch-up. |
| `.gitignore:77` | `/Dockerfile.v*`. Now matches nothing; harmless either way. |
| `docs/plans/2026-08-26-ux-enhancement-plan.md:349` | The initiative that asked for this cleanup. |

No CI workflow references them: `.github/workflows/` contains only
`add_label_to_issue.yml`, `add_label_to_reopened_issue.yml`,
`close_inactive_issues.yml` and `laravel.yml`, none of which mentions Docker.
No manifest in `mediahome/manifests/` references a `Dockerfile` at all — every
NNTmux workload pins `tag@sha256`.

## The image actually running

`docker.io/krickwix/nntmux:microservices-pods-20260827-collection-keying-v243`,
built by `build-and-push.sh` from the root `Dockerfile`. Verified live on
2026-08-27 on `nntmux-web` and `nntmux-worker-binaries`. That is 94 versions
past the newest file in this directory.
