FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-retry-ranking-v178@sha256:3851455216a568a0c4b616b04a2efa7e8e0932b07632a4863218539c3413d938

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-retry-ranking-v178"

COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
COPY app/Services/Orchestrator/CurrentForwardRefreshSettlement.php /app/app/Services/Orchestrator/CurrentForwardRefreshSettlement.php
COPY app/Services/Releases/ReleaseManagementService.php /app/app/Services/Releases/ReleaseManagementService.php
COPY app/Services/ReleaseRemoverService.php /app/app/Services/ReleaseRemoverService.php
COPY database/migrations/2026_07_18_051500_add_current_forward_release_dispositions.php /app/database/migrations/2026_07_18_051500_add_current_forward_release_dispositions.php
