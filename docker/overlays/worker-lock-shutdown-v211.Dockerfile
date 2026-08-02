FROM docker.io/krickwix/nntmux:microservices-pods-20260802-split-xref-gap-v210@sha256:d188e4f3d93426c2440ff3fc23aae185acc3193daf460baaed81ca36646d4136

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260802-split-xref-gap-v210"

COPY app/Services/Distributed/DistributedJobWorker.php /app/app/Services/Distributed/DistributedJobWorker.php
