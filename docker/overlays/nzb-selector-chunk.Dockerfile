FROM docker.io/krickwix/nntmux:microservices-pods-20260711-nzb-cleanup-lock-v9@sha256:606364e346c6e34f456c2d3f9f1326ed51524e75e486bd662b896ee16c4c9fb6

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260711-nzb-cleanup-lock-v9"

COPY app/Services/Nzb/NzbBacklogCreationService.php /app/app/Services/Nzb/NzbBacklogCreationService.php
