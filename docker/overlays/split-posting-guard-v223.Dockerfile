FROM docker.io/krickwix/nntmux:microservices-pods-20260803-split-posting-repair-v222@sha256:aff3dae4d1160830a19d244b59da43628d0ccd139c471e36bdcddd421bb16623

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260803-split-posting-repair-v222"

# v222 SHIPPED A REPAIR THAT COULD CORRUPT DATA. This layer is the guard, and
# v222 must not be run with --update.
#
# The split-posting repair rewrites collections.totalfiles to COUNT(binaries),
# because for its target class totalfiles holds a PART count and is therefore
# meaningless. v222 never checked whether that premise held for the cohort in
# front of it. Where the subject carries a real `n/m` FILE counter, totalfiles is
# correct and a shortfall means articles were never downloaded -- so rewriting it
# publishes a partial archive as complete. Unextractable, and unlike a stall it
# cannot be undone by waiting.
#
# Caught by production dry-runs on named targets, not by tests -- twice:
#
#  1. `(Nativ) [58/93] - "Nativ.part57.rar" yEnc`, the cohort picked as the first
#     apply target. 36 binaries against 93 declared files. Fixed by refusing any
#     `[n/m]`.
#  2. `The Borrowers (1997) ==(37/62) - yEnc "...part32.rar"`, the cohort that
#     ranked first AFTER that fix. Parenthesised, so a bracket-anchored pattern
#     missed it: 58 binaries against 62 declared files.
#
# So the pattern is deliberately undelimited (`/\d+\s*\/\s*\d+/`). Measured over
# the live non-obfuscated filecheck=0 residue the counter appears bracketed on
# 3,850 collections, parenthesised on 94, and on 239 with its opening delimiter
# eaten by an ingest regex (`star trek ... s3 d425/96] - "...part24.rar"`). A
# bracket-only pattern passes 333 collections through to a merge. Dropping the
# delimiter costs the target class nothing: none of the 5,413 no-counter
# collections contains an `n/m` substring anywhere in its subject, and all 4,182
# rows the loose form refuses have totalfiles > COUNT(binaries).
#
# Also lowers the min-files default from 0 to 1. 0 does not mean "no floor" --
# both delete predicates are `> 0` guarded, so it DISABLES the check. 1 is the
# weakest floor that leaves it on and deletes nothing (0 collections have
# totalfiles < 1; the site's configured 2 is taking 9 collections and 48 of 8,277
# releases). The admin edit form was manufacturing explicit 0s by rendering a
# NULL override as 0 and posting it back; that is fixed here too, which is why
# the blade template ships.
COPY app/Services/Diagnostics/SplitPostingIdentityRepairService.php /app/app/Services/Diagnostics/SplitPostingIdentityRepairService.php
COPY app/Support/Data/ProcessReleasesSettings.php /app/app/Support/Data/ProcessReleasesSettings.php
COPY app/Http/Controllers/Admin/AdminGroupController.php /app/app/Http/Controllers/Admin/AdminGroupController.php
COPY resources/views/admin/groups/edit.blade.php /app/resources/views/admin/groups/edit.blade.php

# STILL NOT SHIPPED, unchanged from v222: the ingest-side fix for this class.
# Keying these collections on the filename stem instead of the part count is the
# real cure and changes the identity of every live collection in every group, not
# just the stalled ones. Until it lands this repair is for historical residue
# only -- which is still accruing, newest row minutes old.
#
# HONEST YIELD, re-measured after the guard because every figure v222 shipped was
# contaminated by counter-bearing cohorts. The real class is 1,638 cohorts /
# 3,736 collections / 13,314 files. At the site's current floor of 2, 264 cohorts
# survive both delete predicates and 13 hold every file complete; at the floor of
# 1 this image sets, 1,452 survive and 1,080 are complete. Expect the drain to be
# gated by the min-files floor far more than by the merge.

# No RUN step on purpose: /app/bootstrap/cache holds no config.php, so there is
# no cached config to invalidate, and a RUN here would need cross-arch emulation.
# The blade template is compiled on demand, and its cache key is the file mtime,
# which COPY changes.
