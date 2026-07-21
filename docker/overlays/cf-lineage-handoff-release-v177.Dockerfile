FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-continuation-release-v176@sha256:7a93c63d7225f96c50cee659298def8864432a0b121f40ce5da762bd87ec62bd

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-continuation-release-v176"

COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
