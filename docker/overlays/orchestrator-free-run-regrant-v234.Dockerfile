FROM docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-free-run-timers-v233@sha256:dfef9f2a7320454a16908ed31423d65c425c4203305ebb8272672f467c640948

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-free-run-timers-v233"

# Third and last thing free-run had to reach: the gap BETWEEN permits.
#
# v233 got the timers to zero, so the backfill lane polls every second -- and
# then sat on "adaptive orchestrator has not granted a fresh permit" after each
# completed permit. WorkerOrchestrator gates a re-grant on there being no open
# permit observation (permit_observation_seconds, 1200 by default) and no
# pending delayed attribution (600). Under the adaptive ladder those two waits
# are why backfill ran at roughly 10 permits/hour with 94% of orchestrator
# cycles spent waiting them out.
#
# They are MEASUREMENT, not safety: they exist so yield attribution is honest.
# Free-run skips both, and the cost is explicit -- with no observation there is
# no yield attribution, so fair-share target ranking and quantityForYield stop
# learning while the mode is on. That is the mode's contract, not a bug.
#
# What is still enforced: orchestrator_bf_permit === 0. A permit that has been
# granted and not yet claimed is never overwritten, so the worker cannot lose a
# permit out from under itself mid-claim.
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
