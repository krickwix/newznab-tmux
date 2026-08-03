FROM docker.io/krickwix/nntmux:microservices-pods-20260803-split-posting-guard-v223@sha256:90179ef0876e1e3608889755956d53cbb2f23f385cd01d44e62bf735162c4017

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260803-split-posting-guard-v223"

# Additional postprocessing has been idle since 2026-05-27 (096f9e919).
#
# AdditionalCandidateQuery::bucketChars() projects a GUID character out of
# releases.leftguid and aliased it `id` on an Eloquent builder over the Release
# model. HasAttributes::getCasts() merges [keyName => keyType] for incrementing
# models, so reading $row->id ran the character through the primary-key int
# cast: (int) 'e' === 0. PostProcessRunner::processAdditional() dispatches one
# child per returned bucket, so the fan-out kept logging the right NUMBER of
# jobs ("6 job(s) to do") while every child was pointed at bucket '0'.
#
# Measured on the live database at the time of this build:
#
#   bucketChars() returned   0,0,0,0,0,0
#   real buckets             e=643  a=630  c=628  d=607  f=596  b=596
#   bucket '0'               1 candidate, of 3,701
#
# So each cycle finished in ~2 seconds having processed at most one release,
# and 3,700 sat untouched. Nothing downstream recovers from that: additional
# postprocessing is the only source of inner-archive names, mediainfo and NFO
# evidence, so releases:fix-names reported "Nothing to fix" against 7,045
# hashed releases -- every one of them proc_pp=0, predb_id=0 -- and obfuscated
# postings stayed in Other -> Hashed instead of being renamed into Movies. Over
# the preceding 7 days that was 5,343 of 5,708 new releases, against 32 movies.
#
# The fix takes the query to the base builder (stdClass rows, no casting) and
# aliases the projection `bucket`, so neither the cast nor the name can come
# back. Verified in a throwaway pod off the v223 image: the regression test
# fails there with 'b' collapsing to '0' and passes with this file copied in,
# and bucketChars() against the production database then returns f,a,c,b,e,d
# covering all 3,701 candidates.
#
# Digit buckets are why this hid for ten weeks: (int) '0' === 0 round-trips
# cleanly, so any fixture built on numeric GUIDs passes against the broken code.
#
# Not touched, because they were never affected: the music/console/games bucket
# helpers in PostProcessRunner still use DB::select, whose rows are stdClass and
# never see a model cast.
COPY app/Services/AdditionalProcessing/AdditionalCandidateQuery.php /app/app/Services/AdditionalProcessing/AdditionalCandidateQuery.php

# No RUN step on purpose, and no config COPY: /app/bootstrap/cache holds no
# config.php so there is nothing to invalidate, a RUN would need cross-arch
# emulation, and copying the shared nntmux config in from a feature branch is
# the v214/v215 hazard that silently NULLs branch keys in-pod. The overlay test
# asserts that path never appears here, so it is deliberately not spelled out.
