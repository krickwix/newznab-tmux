FROM docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-free-run-v232@sha256:2314dd03e4235ab96793969097d082862e36a6793031788967e8e69f8cc65e50

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-free-run-v232"

# v232's free-run reported profile=free_run while the workers kept sleeping.
#
# WorkerControlPolicy handed back a profile with every timer at zero, and
# AdaptiveWorkerControlPlanner -- which only skipped FailSafe -- then re-derived
# them. Every branch there is a `max($base->..., floor)`, so the operator's
# zeros were floored straight back up:
#
#   binaries 0   (survived, its floor is conditional on pressure)
#   backfill 10
#   releases 60
#   nzb      60
#
# That is the worst of both states: the brakes are off but the fleet still
# waits. Caught on the first live decision by reading worker_controls instead of
# trusting the profile name.
#
# FreeRun now returns early from the planner exactly as FailSafe does, and for
# the mirror-image reason. FailSafe is authoritative because nothing may
# accelerate past it; FreeRun is authoritative because adaptive modulation is
# precisely what the mode exists to switch off.
#
# The regression test drives policy -> planner and asserts all four timers stay
# zero. Against the v232 planner it fails on `10 is not 0`.
COPY app/Services/Orchestrator/AdaptiveWorkerControlPlanner.php /app/app/Services/Orchestrator/AdaptiveWorkerControlPlanner.php
