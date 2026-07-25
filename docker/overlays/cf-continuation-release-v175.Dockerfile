FROM docker.io/krickwix/nntmux:microservices-pods-20260717-compact-tv-release-recovery-v163@sha256:8a9b54bb40bef31bf52a6681625f357e8fd10c710d8d48969b9692a2fab348bb

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260717-compact-tv-release-recovery-v163"

COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
COPY app/Services/ReleaseCreationService.php /app/app/Services/ReleaseCreationService.php
COPY config/nntmux.php /app/config/nntmux.php
