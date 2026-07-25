FROM docker.io/krickwix/nntmux:microservices-pods-20260713-backfill-source-v97@sha256:653f3982d2a35cf05981916a167d10cc1a231e6d4aedd6eeef1e5bab6257363f

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260713-backfill-source-v97"

COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php
