FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-lineage-handoff-v177@sha256:db93009af2137d35a29b11a243bfb6df1f16d144b9aa3d091347e3a50378efdd

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-lineage-handoff-v177"

COPY app/Services/Distributed/CurrentForwardPermitGate.php /app/app/Services/Distributed/CurrentForwardPermitGate.php
COPY app/Services/Orchestrator/CurrentForwardRefreshLedger.php /app/app/Services/Orchestrator/CurrentForwardRefreshLedger.php
COPY app/Services/Orchestrator/CurrentForwardRefreshPlanner.php /app/app/Services/Orchestrator/CurrentForwardRefreshPlanner.php
COPY app/Services/Orchestrator/CurrentForwardWindowRetryPolicy.php /app/app/Services/Orchestrator/CurrentForwardWindowRetryPolicy.php
COPY config/nntmux.php /app/config/nntmux.php
COPY database/migrations/2026_07_18_024500_add_current_forward_retry_attempts.php /app/database/migrations/2026_07_18_024500_add_current_forward_retry_attempts.php
