FROM docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-fill-profile-config-v231@sha256:a33faa6070881f5217c7d083d708c07456c5880a820bcbd60dc25f94094f7cc6

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-fill-profile-config-v231"

# Operator free-run mode: every worker timer at zero, backfill always
# permitted, no admission gating, no fail-safe.
#
# READ THIS BEFORE ENABLING. The gates it bypasses are not theoretical -- both
# have taken this fleet down:
#
#  - a 177k-collection ingest burst blew past the collections watermark and
#    self-locked the orchestrator into fail_safe with no permits, so parts were
#    never fetched and the backlog could not drain;
#  - a MariaDB working set above the memory threshold pinned it in fail_safe for
#    hours with zero pod restarts, visible only in the profile metric.
#
# Free-run removes exactly those brakes. It is therefore:
#
#  - OFF by default, behind NNTMUX_ORCHESTRATOR_FREE_RUN with no default;
#  - unreachable from the adaptive ladder (stepUp() stops at Fill), so pressure
#    can never promote into it;
#  - checked BEFORE telemetry validity and hard safety, because a mode whose
#    point is "do not stop" cannot be gated on the signals it exists to ignore.
#
# What it does NOT remove: the connection budget. Groups, threads and quantity
# still come from the FILL_* config, because exceeding the provider's allowance
# fails fetches rather than speeding them up. Free-run means no waiting, not no
# limits.
#
# Turning it off returns the fleet to the adaptive ladder at Balanced rather
# than fail_safe, so recovery is a normal step rather than a cliff.
#
# config/nntmux.php ships because the two new keys must exist in the IMAGE, not
# just the branch -- the v225/v231 lesson. Verified superset before building.
COPY app/Services/Orchestrator/ControlProfile.php /app/app/Services/Orchestrator/ControlProfile.php
COPY app/Services/Orchestrator/WorkerControlProfile.php /app/app/Services/Orchestrator/WorkerControlProfile.php
COPY app/Services/Orchestrator/WorkerControlPolicy.php /app/app/Services/Orchestrator/WorkerControlPolicy.php
COPY app/Services/Orchestrator/WorkerProfileApplier.php /app/app/Services/Orchestrator/WorkerProfileApplier.php
COPY config/nntmux.php /app/config/nntmux.php
