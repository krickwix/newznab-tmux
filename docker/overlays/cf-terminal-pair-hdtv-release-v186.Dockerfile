FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-dynamic-pair-shadow-release-v183@sha256:aec282800a761450edb313ea11287766fc05b91239f32b357d9ed4cfcb152231

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-dynamic-pair-shadow-release-v183"

COPY app/Services/Orchestrator/CurrentForwardTerminalSplitRepair.php /app/app/Services/Orchestrator/CurrentForwardTerminalSplitRepair.php
COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
COPY app/Services/ReleaseCreationService.php /app/app/Services/ReleaseCreationService.php
COPY app/Models/Release.php /app/app/Models/Release.php
COPY database/migrations/2026_07_18_094500_add_current_forward_terminal_split_repairs.php /app/database/migrations/2026_07_18_094500_add_current_forward_terminal_split_repairs.php
COPY config/nntmux.php /app/config/nntmux.php
