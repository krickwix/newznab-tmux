FROM docker.io/krickwix/nntmux:microservices-pods-20260717-cf-ledger-issuance-v174@sha256:7eeb3a39cb3c7ee24069451c97b994e48c40b7daaaf1c525febc0f95569d2594

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260717-cf-ledger-issuance-v174"

COPY app/Console/Commands/GetArticleRange.php /app/app/Console/Commands/GetArticleRange.php
COPY app/Console/Commands/RecoverCurrentForwardContinuation.php /app/app/Console/Commands/RecoverCurrentForwardContinuation.php
COPY app/Services/Binaries/BinariesService.php /app/app/Services/Binaries/BinariesService.php
COPY app/Services/Binaries/HeaderStorageService.php /app/app/Services/Binaries/HeaderStorageService.php
COPY app/Services/Binaries/PartHandler.php /app/app/Services/Binaries/PartHandler.php
COPY app/Services/Distributed/BackfillPermitGate.php /app/app/Services/Distributed/BackfillPermitGate.php
COPY app/Services/Distributed/CurrentForwardPermitGate.php /app/app/Services/Distributed/CurrentForwardPermitGate.php
COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php
COPY app/Services/Orchestrator/CurrentForwardRefreshLedger.php /app/app/Services/Orchestrator/CurrentForwardRefreshLedger.php
COPY app/Services/Orchestrator/CurrentForwardRefreshAuditor.php /app/app/Services/Orchestrator/CurrentForwardRefreshAuditor.php
COPY app/Services/Orchestrator/CurrentForwardRefreshSettlement.php /app/app/Services/Orchestrator/CurrentForwardRefreshSettlement.php
COPY app/Services/Orchestrator/CurrentForwardWindowAudit.php /app/app/Services/Orchestrator/CurrentForwardWindowAudit.php
COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
COPY app/Services/Orchestrator/PipelineSnapshotRepository.php /app/app/Services/Orchestrator/PipelineSnapshotRepository.php
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
COPY app/Services/Orchestrator/WorkerProfileApplier.php /app/app/Services/Orchestrator/WorkerProfileApplier.php
COPY app/Services/ReleaseCreationService.php /app/app/Services/ReleaseCreationService.php
COPY config/nntmux.php /app/config/nntmux.php
COPY database/migrations/2026_07_17_150000_add_current_forward_verification_and_settlement_fences.php /app/database/migrations/2026_07_17_150000_add_current_forward_verification_and_settlement_fences.php
COPY database/migrations/2026_07_17_160000_add_current_forward_continuation_chains.php /app/database/migrations/2026_07_17_160000_add_current_forward_continuation_chains.php
COPY database/migrations/2026_07_18_010000_allow_partial_current_forward_continuation_audits.php /app/database/migrations/2026_07_18_010000_allow_partial_current_forward_continuation_audits.php
