FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-release-disposition-v179@sha256:a68a20906d9ff1481a9784464c60669eee247b36cf2b07150cf11fa18b245ef6

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-release-disposition-v179"

COPY app/Services/Distributed/CurrentForwardPermitGate.php /app/app/Services/Distributed/CurrentForwardPermitGate.php
COPY app/Services/Orchestrator/CurrentForwardProviderCoverage.php /app/app/Services/Orchestrator/CurrentForwardProviderCoverage.php
COPY app/Services/Orchestrator/CurrentForwardRefreshAuditor.php /app/app/Services/Orchestrator/CurrentForwardRefreshAuditor.php
COPY app/Services/Orchestrator/CurrentForwardRefreshLedger.php /app/app/Services/Orchestrator/CurrentForwardRefreshLedger.php
COPY app/Services/Orchestrator/CurrentForwardRefreshPlanner.php /app/app/Services/Orchestrator/CurrentForwardRefreshPlanner.php
COPY config/nntmux.php /app/config/nntmux.php
COPY database/migrations/2026_07_18_074500_relax_current_forward_provider_reserve_floor.php /app/database/migrations/2026_07_18_074500_relax_current_forward_provider_reserve_floor.php
