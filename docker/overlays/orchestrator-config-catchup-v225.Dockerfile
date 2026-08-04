FROM docker.io/krickwix/nntmux:microservices-pods-20260804-additional-pp-bucket-v224@sha256:48f46342da889194d11b279c9594fcd2f02b10b8efad0a896618cc6644eff188

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260804-additional-pp-bucket-v224"

# The config drift, running the OTHER way from v214/v215.
#
# Every overlay since v215 refuses to COPY config/nntmux.php, because copying
# it from microservices-pods while running feature-branch code deleted keys the
# shipped code needed and turned it into a no-op. That rule is correct and it
# has a cost: the image's copy of the file has been frozen at the last FULL
# build while the branch kept gaining keys. Nothing warns about it. The
# manifest guard that checks "every declared NNTMUX_* var has a reader" reads
# the BRANCH config, so it passes while production resolves the same key to its
# default.
#
# Measured in-pod against the v223 fleet on 2026-08-04:
#
#   key                                 declared      effective
#   backfill_fair_share_newest_cursor   true          false
#   backfill_fill_quantity              100000        10000
#   backfill_fill_groups                --            1
#   backfill_fill_threads               --            1
#
# All four are read through config() with no env() fallback (see
# BackfillTargetSelector:80 and WorkerControlProfile:31,34,39), so the declared
# values never applied. Fair-share backfill target selection has been off
# fleet-wide and each round ran at a tenth of the declared quantity -- a
# plausible mechanism for one high-volume group crowding the movie groups out
# of the backfill budget.
#
# WHY COPYING THE CONFIG IS SAFE HERE, when the v214/v215 lesson says it is not:
# the hazard is copying a config that is MISSING keys the running code reads.
# This image's payload is microservices-pods plus overlay files from the same
# branch, so the direction is reversed. Verified by key-set comparison rather
# than assumed -- flattened, in-pod against the branch file:
#
#   orchestrator keys   pod 94   branch 98
#   branch-only         4 (the table above)
#   POD-ONLY            0
#   whole nntmux config 176 pod leaves, 0 absent from the branch file
#
# Strict superset, so the copy is purely additive: 4 keys gained, none lost. If
# that ever stops being true this overlay becomes the v214/v215 bug again, which
# is why the manifest test asserts the four keys explicitly rather than trusting
# the file.
#
# The lasting cure is a full build of microservices-pods; this is the cheap one,
# because a full build cross-compiles imagick from source under emulation for
# arm64.
COPY config/nntmux.php /app/config/nntmux.php

# No RUN step: /app/bootstrap/cache holds no config.php, so there is no cached
# config to invalidate, and a RUN here would need cross-arch emulation.
