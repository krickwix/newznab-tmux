FROM docker.io/krickwix/nntmux:microservices-pods-20260804-orchestrator-config-catchup-v225@sha256:883030980663d595bbadfd47b5aeb484c5932082411d08388c934b1a2b7d1e7b

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260804-orchestrator-config-catchup-v225"

# Ships the third fragmentation repair. Diagnostics only: nothing in this layer
# runs on its own, and the command is dry-run unless given --update.
#
# The class it reaches, which the other two passes cannot:
#
#   [005/243] - "1X1bCO5fFm82B1XnNgR" yEnc
#   [006/243] - "2P9bCrQhUHNbZfsJpL9" yEnc
#
# A real file counter, and a random name per file sharing no stem.
# collectionIdentity() keys on cleanedName . totalFiles and the cleaner cannot
# strip pure entropy, so each file mints its own key and one posting becomes up
# to N collections of one binary each. Stage 1 wants
# COUNT(DISTINCT filenumber) >= totalfiles, so they never advance and retention
# purges articles that were fully downloaded. Measured on this fleet: 21,846
# stalled collections, 19,815 holding one binary, and 85 cohorts / 1,835
# collections provably complete once merged.
#
# The cohort key (groups_id, fromname, totalfiles) is weak on purpose and only
# proposes; the bijection accepts -- binaries == totalfiles, distinct
# filenumbers == totalfiles, spanning 1..totalfiles. A hole means a missing
# file, a duplicate means a chimera, so a partial archive cannot be published
# as complete. That is the failure this whole family of repairs has to respect:
# it is the one that cannot be undone by waiting, and it cost 512 collections
# on 2026-08-03.
#
# NOT shipped and not weakened: v223's `declares_a_real_file_count` guard on
# SplitPostingIdentityRepairService. It was tested against the live residue --
# 590 refusals across movies/hdtv/cinemageddon, 579 genuinely short, 11
# complete and all trivial 3-file cohorts. It is nearly exact. The new pass
# reaches its targets by a different cohort key, not by relaxing that one.
#
# Ships nothing on the ingest path. Fragmentation keeps accruing until the
# withheld collection-keying change lands; this is a sweep, not the cure.
COPY app/Services/Diagnostics/FragmentedPostingIdentityRepairService.php /app/app/Services/Diagnostics/FragmentedPostingIdentityRepairService.php
COPY app/Console/Commands/RepairFragmentedPostingIdentity.php /app/app/Console/Commands/RepairFragmentedPostingIdentity.php

# No RUN step: /app/bootstrap/cache holds no config.php so there is nothing to
# invalidate, and a RUN would need cross-arch emulation. Artisan discovers
# commands from the filesystem, so the COPY is enough. The shared nntmux config
# is deliberately not copied here -- v225 already carried it, and re-copying it
# per overlay is how the v214/v215 drift started.
