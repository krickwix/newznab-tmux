FROM docker.io/krickwix/nntmux:microservices-pods-20260717-cf-refresh-shadow-v172@sha256:ca120e3c7b25d13adae8789196d946c46aed8240b27d5fdce36debf06567711a

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260717-cf-refresh-shadow-v172"

COPY app/Console/Commands/GetArticleRange.php /app/app/Console/Commands/GetArticleRange.php
COPY app/Console/Commands/PartRepair.php /app/app/Console/Commands/PartRepair.php
COPY app/Console/Commands/UpdateBinaries.php /app/app/Console/Commands/UpdateBinaries.php
COPY app/Services/Binaries/BinariesService.php /app/app/Services/Binaries/BinariesService.php
COPY app/Services/Distributed/BackfillPermitGate.php /app/app/Services/Distributed/BackfillPermitGate.php
COPY app/Services/Distributed/CurrentForwardPermitGate.php /app/app/Services/Distributed/CurrentForwardPermitGate.php
COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php
COPY app/Services/Orchestrator/CurrentForwardRefreshLedger.php /app/app/Services/Orchestrator/CurrentForwardRefreshLedger.php
COPY app/Services/Orchestrator/CurrentForwardRefreshPlanner.php /app/app/Services/Orchestrator/CurrentForwardRefreshPlanner.php
COPY app/Services/Orchestrator/CurrentForwardRefreshSettlement.php /app/app/Services/Orchestrator/CurrentForwardRefreshSettlement.php
COPY app/Services/Orchestrator/CurrentForwardRefreshTrustPolicy.php /app/app/Services/Orchestrator/CurrentForwardRefreshTrustPolicy.php
COPY app/Services/Orchestrator/PipelineSnapshotRepository.php /app/app/Services/Orchestrator/PipelineSnapshotRepository.php
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
COPY app/Services/Orchestrator/WorkerProfileApplier.php /app/app/Services/Orchestrator/WorkerProfileApplier.php
COPY config/nntmux.php /app/config/nntmux.php
COPY database/migrations/2026_07_17_140000_add_current_forward_refresh_issuance_guards.php /app/database/migrations/2026_07_17_140000_add_current_forward_refresh_issuance_guards.php
COPY database/migrations/2026_07_17_150000_add_current_forward_verification_and_settlement_fences.php /app/database/migrations/2026_07_17_150000_add_current_forward_verification_and_settlement_fences.php
