FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-dynamic-pair-shadow-v183@sha256:363c34f9926301df03ec728a0f918f1460af87f4893baf68b2652eb0de6b81c4

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-dynamic-pair-shadow-v183"

COPY app/Console/Commands/AuditCurrentForwardRefresh.php /app/app/Console/Commands/AuditCurrentForwardRefresh.php
COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php
COPY app/Services/Orchestrator/AdaptiveWorkerControlPlanner.php /app/app/Services/Orchestrator/AdaptiveWorkerControlPlanner.php
COPY app/Services/Orchestrator/ControlState.php /app/app/Services/Orchestrator/ControlState.php
COPY app/Services/Orchestrator/CurrentForwardRefreshLedger.php /app/app/Services/Orchestrator/CurrentForwardRefreshLedger.php
COPY app/Services/Orchestrator/PipelineSnapshot.php /app/app/Services/Orchestrator/PipelineSnapshot.php
COPY app/Services/Orchestrator/PipelineSnapshotRepository.php /app/app/Services/Orchestrator/PipelineSnapshotRepository.php
COPY app/Services/Orchestrator/WorkerControlPolicy.php /app/app/Services/Orchestrator/WorkerControlPolicy.php
COPY app/Services/Orchestrator/WorkerControlStateStore.php /app/app/Services/Orchestrator/WorkerControlStateStore.php
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
COPY config/nntmux.php /app/config/nntmux.php
