FROM docker.io/krickwix/nntmux:microservices-pods-20260805-omdb-quota-cooldown-v237@sha256:6e47f42e757c4ced631647215d1580ec033befc1263d703c7fc5be67435e1342

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260805-omdb-quota-cooldown-v237"

# An operator CLI for the orchestrator control mode.
#
#   php artisan nntmux:orchestrator-mode list
#   php artisan nntmux:orchestrator-mode get
#   php artisan nntmux:orchestrator-mode set <mode>
#   php artisan nntmux:orchestrator-mode reset
#
# Free-run shipped as an env var, which makes every mode change a manifest
# edit, a rebuild and a rollout -- minutes of latency on a control whose whole
# purpose is to be reached when the fleet is misbehaving. The pin is now a
# settings row the next control cycle reads.
#
# Three things this deliberately does NOT do:
#
# 1. A pin does not bypass safety. It replaces the ladder's SELECTION and
#    nothing else, and it is read below the hard-safety block, so a pinned
#    `fill` still yields to a database in trouble. Free-run remains the single
#    mode that overrides the gates, which is what it is for.
# 2. `reset` does not mean "adaptive". It means "back to how this fleet was
#    deployed" -- with FREE_RUN set in the manifest, clearing the pin hands the
#    fleet straight back to free-run, and the CLI says so rather than let an
#    operator believe they just applied the brakes.
# 3. `get` does not report one number. It reports the pin, the deployed
#    default, the control loop's own state and what the workers were last
#    told, and warns when the last two disagree. The worst orchestrator failure
#    this fleet has had was not a wrong mode -- it was profile=free_run while
#    the adaptive planner floored every worker timer back up, so the fleet had
#    no brakes AND no speed. Anything showing only the decided profile would
#    have shown that as healthy.
#
# The pin lives in `settings`, not in the orchestrator's Redis state: a Redis
# flush restarting the ladder is recoverable, a Redis flush silently un-pinning
# the fleet is not.
#
# WorkerProfileApplier is in here because NNTMUX_ORCHESTRATOR_FREE_RUN is set on
# the orchestrator deployment alone -- verified in-pod, config() reads false on
# every worker while the orchestrator applies profile=free_run. Without the
# orchestrator publishing that default, `reset` run from a worker pod would
# promise the adaptive ladder at the exact moment it handed the fleet back to
# free-run. Both setting names are kept under the live varchar(25) on
# settings.name.
COPY app/Console/Commands/NntmuxOrchestratorMode.php /app/app/Console/Commands/NntmuxOrchestratorMode.php
COPY app/Services/Orchestrator/ControlProfileOverride.php /app/app/Services/Orchestrator/ControlProfileOverride.php
COPY app/Services/Orchestrator/ControlProfile.php /app/app/Services/Orchestrator/ControlProfile.php
COPY app/Services/Orchestrator/FailSafeCause.php /app/app/Services/Orchestrator/FailSafeCause.php
COPY app/Services/Orchestrator/WorkerControlPolicy.php /app/app/Services/Orchestrator/WorkerControlPolicy.php
COPY app/Services/Orchestrator/WorkerControlStateStore.php /app/app/Services/Orchestrator/WorkerControlStateStore.php
COPY app/Services/Orchestrator/PipelineSnapshot.php /app/app/Services/Orchestrator/PipelineSnapshot.php
COPY app/Services/Orchestrator/PipelineSnapshotRepository.php /app/app/Services/Orchestrator/PipelineSnapshotRepository.php
COPY app/Services/Orchestrator/WorkerProfileApplier.php /app/app/Services/Orchestrator/WorkerProfileApplier.php
