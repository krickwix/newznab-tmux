FROM docker.io/krickwix/nntmux:microservices-pods-20260803-obfuscated-config-regression-v217@sha256:f3825e86776d60952d5db28c98b87d3e9fe0ef5ceaa1258b4bf598eaa41f1494

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260803-obfuscated-config-regression-v217"

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
