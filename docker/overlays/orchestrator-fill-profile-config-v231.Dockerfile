FROM docker.io/krickwix/nntmux:microservices-pods-20260804-ingest-partcount-all-groups-v230@sha256:1984fa2aefacb80f975675ca8bbdf7df313bb7edacf53b09e316221a23e07089

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260804-ingest-partcount-all-groups-v230"

# The Fill profile could not be tuned, and v225 only looked like it fixed that.
#
# v225 restored four backfill_fill_* keys to config/nntmux.php, and they do
# resolve: the orchestrator reports fill_groups=4, fill_threads=8,
# fill_quantity=100000. But the CODE that reads them was never in any image.
# The deployed WorkerControlProfile::for() has:
#
#   ControlProfile::Fill => new self($profile, 20,20,20,20,20, true, 1, 1, 10000);
#
# hardcoded. So a fleet sitting in `fill` ran backfill at ONE group, ONE process
# and a 10k quantity no matter what the manifest declared -- and
# WorkerProfileApplier duly wrote settings.backfill_groups=1,
# settings.backfillthreads=1 every cycle, which is what BackfillRunner obeys.
#
# Same drift as v225 one layer deeper: the keys were restored, the reader was
# not. Caught by building the profile in-pod and getting 1/1/10000 back while
# config() in the same process returned 4/8/100000.
#
# The diff against the image is exactly the config block and nothing else --
# verified by diffing the deployed file against the branch, 18 added lines, with
# the Fill arm switching from literals to the resolved values. Every other
# profile is untouched; Balanced still hardcodes 1/1/10000 by design.
#
# NOT shipped here: config/nntmux.php. v229 already carried it and the keys are
# present; this overlay only needs the reader.
COPY app/Services/Orchestrator/WorkerControlProfile.php /app/app/Services/Orchestrator/WorkerControlProfile.php
