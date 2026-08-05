FROM docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-free-run-regrant-v234@sha256:faf8932a60232f3f93a6c44b21a80ca90b3cbd5eea3b4bd9e0ba07ddf9735a8f

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-free-run-regrant-v234"

# v234 let free-run re-grant every cycle and immediately wedged backfill.
#
# The target selector legitimately returns no group sometimes -- a rotation
# gap, or every candidate momentarily ineligible. v234 granted anyway, so
# orchestrator_bf_group was written as ''. Such a permit is unclaimable
# (claimGeneration() refuses `$group === ''`) AND un-reissuable ($autoGrant
# needs orchestrator_bf_permit back at 0, which only happens after a claim
# completes). Backfill then sat on "adaptive permit was absent or stale"
# forever.
#
# Under the adaptive ladder the observation window made this race vanishingly
# rare. Granting every cycle turned it into a certainty within minutes -- the
# second time today that removing a wait exposed a latent wedge, after the
# stop-cursor mismatch.
#
# $autoGrant now requires a non-empty target. This is not free-run specific:
# issuing a permit against no group was never useful in any profile.
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
