FROM docker.io/krickwix/nntmux:microservices-pods-20260711-nzb-cleanup-lock-v9@sha256:606364e346c6e34f456c2d3f9f1326ed51524e75e486bd662b896ee16c4c9fb6

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260711-nzb-cleanup-lock-v9"

COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
COPY app/Services/Releases/ReleaseManagementService.php /app/app/Services/Releases/ReleaseManagementService.php
COPY app/Services/ReleaseRemoverService.php /app/app/Services/ReleaseRemoverService.php
COPY database/migrations/2026_07_18_051500_add_current_forward_release_dispositions.php /app/database/migrations/2026_07_18_051500_add_current_forward_release_dispositions.php
