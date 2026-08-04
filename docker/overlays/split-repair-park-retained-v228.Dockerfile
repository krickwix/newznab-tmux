FROM docker.io/krickwix/nntmux:microservices-pods-20260804-fragmented-posting-par2-guard-v227@sha256:64f3d356162b5f44a242bf35ae5f41a78470c85f82bdc3f41778c412e6128cd9

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260804-fragmented-posting-par2-guard-v227"

# candidateCohorts() skips a row whose binary name yields no filename or no
# posting stem, but the COLLECTION still joins the cohort through its other
# binaries. Those skipped binaries were never parked, so pass 2 wrote the dense
# ordinals onto numbers they already held.
#
# Surfaced by the hourly sweep the day it was scheduled, on
# alt.binaries.movie.hd:
#
#   SQLSTATE[23000]: Duplicate entry '474946-1'
#   for key 'ux_collection_id_filenumber'
#
# The transaction rolls back, so nothing was corrupted -- verified live, the
# collection was intact with zero orphaned binaries or parts globally. But the
# cohort could never merge and the job reported red every run.
#
# They are now parked into the same band as the planned survivors, continue the
# dense run at N+1, and are counted in totalfiles. The count matters as much as
# the numbering: stage 0 and stage 6 both read MAX(filenumber) as a file count,
# so numbering without counting leaves the collection permanently below its own
# completion bar. Counting them cannot flip a shape unsurvivableShape() already
# cleared -- retained binaries only ever raise the file count.
COPY app/Services/Diagnostics/SplitPostingIdentityRepairService.php /app/app/Services/Diagnostics/SplitPostingIdentityRepairService.php
