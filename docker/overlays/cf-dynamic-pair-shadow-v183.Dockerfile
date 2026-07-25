FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-collection-handoff-v181@sha256:30740c6a253c778ce5796b448e4721279738a07a6e7b1642f79df26240c8d965

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-collection-handoff-v181"

COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php
COPY app/Services/Metrics/SplitCollectionTelemetry.php /app/app/Services/Metrics/SplitCollectionTelemetry.php
COPY config/nntmux.php /app/config/nntmux.php
