FROM docker.io/krickwix/nntmux:microservices-pods-20260720-sustainable-release-pump-v188@sha256:d2ae4805c71901533264c78fba254c3c95bd9f3ed9a77f2797e75b1ff18c73ca

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260720-sustainable-release-pump-v188"

COPY app/Console/Commands/ProcessReleasesCommand.php /app/app/Console/Commands/ProcessReleasesCommand.php
COPY app/Services/Distributed/DistributedJobWorker.php /app/app/Services/Distributed/DistributedJobWorker.php
COPY app/Services/Metrics/DistributedWorkerTelemetry.php /app/app/Services/Metrics/DistributedWorkerTelemetry.php
