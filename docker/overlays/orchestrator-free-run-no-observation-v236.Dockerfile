FROM docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-free-run-target-guard-v235@sha256:5bdc55380866c934dec53093f50421234e10893d1f20ef0fad090c080fb1535a

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-free-run-target-guard-v235"

# The last thing standing between free-run and actually-continuous backfill.
#
# v234 taught $autoGrant to ignore the permit observation and the delayed
# attribution settle. It did not stop free-run OPENING an observation, and that
# is the head of a chain:
#
#   beginPermitObservation()
#     -> observation completes on a later cycle
#     -> the completion path defers attribution
#     -> queueBackfillDelayedAttribution() records a pending group
#     -> selectBackfillTarget() returns NULL outright while any group is
#        pending without a continuation
#
# So free-run granted a permit, ran it, and then had NO TARGET for the next
# ~600s. Backfill reported "adaptive orchestrator has not granted a fresh
# permit" and the fleet idled -- the "permitted but nothing happens" state
# free-run exists to remove, reintroduced one layer down. Observed live: the
# lane ran, went quiet, and only recovered once the pending group aged out.
#
# Cutting the chain at its source beats teaching the selector to ignore pending
# groups, which would leave real attribution state accumulating unread by
# anything.
#
# Nothing is lost that free-run had not already given up: $autoGrant ignores the
# observation window regardless, so yield attribution -- fair-share ranking and
# quantityForYield -- is forfeit in this mode by design. The adaptive ladder is
# untouched and still observes every permit.
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
