FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-split-backlog-release-v182@sha256:cc31f60143352c75eede4cc9225fa720c1a77a7bf447ec41bc89232cc142f220

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-split-backlog-release-v182"

COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
COPY app/Services/Metrics/SplitCollectionTelemetry.php /app/app/Services/Metrics/SplitCollectionTelemetry.php
COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
COPY config/nntmux.php /app/config/nntmux.php
