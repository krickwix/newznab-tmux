FROM docker.io/krickwix/nntmux:microservices-pods-20260714-row-lock-metrics-v158@sha256:f27ee85998da04a98393c95c82d4f79fb47423e1a6a12005ca2a41670a712ff5

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-row-lock-metrics-v158"

COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php
