FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-continuation-v176@sha256:790ca21a99419ba5146e26bf56236cdb9f7083d53c18fcdc4c01e7bb51130386

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-continuation-v176"

COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
