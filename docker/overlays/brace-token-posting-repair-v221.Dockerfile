FROM docker.io/krickwix/nntmux:microservices-pods-20260803-obfuscated-config-regression-v217@sha256:f3825e86776d60952d5db28c98b87d3e9fe0ef5ceaa1258b4bf598eaa41f1494

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260803-obfuscated-config-regression-v217"

# Supersedes v220, whose --update aborted on the first cohort it tried to merge.
# The repair parks survivor binaries on a scratch filenumber before renumbering
# them densely (the final ordinals are a permutation of numbers the cohort's
# members already hold, so a direct write collides under UNIQUE
# (collections_id, filenumber)). v220 parked on the NEGATED binary id, but
# `binaries.filenumber` is `int(10) unsigned`: MariaDB clamped it to 0 and the
# second park collided on '<survivor>-0'. The transaction rolled back cleanly and
# no production row was altered. It parks above MAX(filenumber) now, and the
# sqlite fixtures carry a CHECK (filenumber >= 0) so the unsigned domain is
# reachable in tests -- without it the tests were green while production failed.

# Supersedes v219, which was correct but incomplete: its repair could only select
# cohorts with --limit, i.e. in collection-id order, so a staged drain could not
# name the posting it had validated. v220 adds --posting=. v219 is left in the
# registry with its own digest rather than retagged; the fleet convention is that
# an image.revision label must always resolve to the source it was built from.

# Supersedes v218, which is WITHDRAWN: it shipped a repair whose target state was
# one collection per real file, and applying it destroyed 512 production
# collections and ~541 MB of articles (see the note on the repair COPY below).
# v218 is deliberately left in the registry rather than retagged -- the
# orchestrator lane still runs that digest for its unrelated
# BackfillTargetSelector guard, and its image.revision label has to stay
# resolvable. Do not run nntmux:repair-brace-token-identity from a v218 pod.

# Additive only: normalize() is byte-equivalent to v217's (the diff is a local
# variable inlined), and the two new statics -- postingKey() and
# postingIdentity() -- are called exclusively by the repair pass below. Shipping
# it therefore does not alter ingest, which is deliberate: see the ingest note
# at the bottom of this file.
COPY app/Services/Binaries/ObfuscatedSubjectNormalizer.php /app/app/Services/Binaries/ObfuscatedSubjectNormalizer.php

# The reclaim pass for the collections stranded before the normalizer went live.
# Needs no NNTP access: the real filename survives in collections.subject.
# Dry-run by default.
#
# Target state is one collection per POSTING holding one binary per real file
# with dense filenumbers, NOT one collection per file. The per-file shape is
# deleted by the release pipeline twice over -- stage 6 rewrites totalfiles to
# COUNT(binaries) so a single binary falls under minfilestoformrelease, and a
# par2 file's lone binary is 100% par2 for the $par2Only filter -- and both
# deletes cascade through FK_Collections. That is not hypothetical: an earlier
# build of this overlay shipped the per-file target and cost 512 production
# collections and ~541 MB of articles. The service now refuses any cohort whose
# merged shape would trip either predicate, and leaves it stranded instead.
COPY app/Services/Diagnostics/BraceTokenIdentityRepairService.php /app/app/Services/Diagnostics/BraceTokenIdentityRepairService.php
COPY app/Console/Commands/RepairBraceTokenIdentity.php /app/app/Console/Commands/RepairBraceTokenIdentity.php

# Unrelated to the brace-token work but shipped here because v218 had not been
# deployed yet: the selector's last-resort round-robin read a candidate's yield
# history unguarded, and Laravel turns the resulting E_WARNING into an
# ErrorException that WorkerOrchestrator's catch-all converts into
# failClosed(). Production sat in that crash-and-fail-closed loop from
# 07:22:35 UTC with backfill paused and no pod restart to signal it.
COPY app/Services/Orchestrator/BackfillTargetSelector.php /app/app/Services/Orchestrator/BackfillTargetSelector.php

# DELIBERATELY NOT SHIPPED: HeaderParser.php and CollectionHandler.php.
#
# Those two carry the branch's ingest change, which routes brace-token subjects
# onto a per-real-file collection key. That fixes the collapse in v217 -- where
# the cleaned name fuses part01..partNN into one collection AND one binary, so
# parts of several files pile onto a binary carrying another file's name -- but
# it produces exactly the one-binary-per-collection shape the pipeline deletes,
# for the same two reasons given above. Shipping it would trade silent
# corruption for reliable deletion.
#
# So ingest in this image is unchanged from v217 and is still wrong for new
# brace-token posts. That is a known, accepted gap rather than an oversight:
# the poster has produced no articles since 2026-08-02 20:07:21, and a correct
# ingest fix needs a per-file ordinal that a single header cannot supply (a
# par2 volume cannot be ranked without knowing the payload count, and the two
# gates that read MAX(filenumber) as a file count reject any sparse or
# high-band numbering). That is scoped as its own piece of work.

# No RUN step on purpose: /app/bootstrap/cache holds no config.php, so there is
# no cached config to invalidate, and a RUN here would need cross-arch emulation.
