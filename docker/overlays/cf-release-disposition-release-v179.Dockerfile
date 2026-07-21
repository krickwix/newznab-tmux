FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-lineage-handoff-release-v177@sha256:d683a5b54b787c730b00b40499b1cecd000941f6b52dc1f8eb2f04141edee643

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-lineage-handoff-release-v177"

COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
COPY app/Services/Releases/ReleaseManagementService.php /app/app/Services/Releases/ReleaseManagementService.php
COPY app/Services/ReleaseRemoverService.php /app/app/Services/ReleaseRemoverService.php
COPY database/migrations/2026_07_18_051500_add_current_forward_release_dispositions.php /app/database/migrations/2026_07_18_051500_add_current_forward_release_dispositions.php
