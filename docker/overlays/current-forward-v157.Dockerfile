FROM docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v156@sha256:53bc17e26ba3f9ac66665999fcff6de7fbebf017b9741eaf9e18c9ab8b32296d

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v156"

COPY app/Services/Distributed/CurrentForwardPermitGate.php /app/app/Services/Distributed/CurrentForwardPermitGate.php
COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php
COPY app/Services/Orchestrator/PipelineSnapshot.php /app/app/Services/Orchestrator/PipelineSnapshot.php
COPY app/Services/Orchestrator/PipelineSnapshotRepository.php /app/app/Services/Orchestrator/PipelineSnapshotRepository.php
COPY app/Services/Orchestrator/WorkerControlStateStore.php /app/app/Services/Orchestrator/WorkerControlStateStore.php
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
COPY config/nntmux.php /app/config/nntmux.php
