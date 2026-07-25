FROM docker.io/krickwix/nntmux:microservices-pods-20260720-sustainable-backfill-v188@sha256:9162bcefa4e5e0f7b6c0690d1e05c26ede86e938900dd2f8efda36dd20468a0a

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260720-sustainable-backfill-v188"

COPY app/Services/Metrics/DistributedWorkerTelemetry.php /app/app/Services/Metrics/DistributedWorkerTelemetry.php
COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php
COPY app/Services/Orchestrator/PipelineSnapshot.php /app/app/Services/Orchestrator/PipelineSnapshot.php
COPY app/Services/Orchestrator/PipelineSnapshotRepository.php /app/app/Services/Orchestrator/PipelineSnapshotRepository.php
COPY app/Services/Orchestrator/WorkerControlStateStore.php /app/app/Services/Orchestrator/WorkerControlStateStore.php
COPY database/migrations/2026_07_21_130000_add_metrics_query_indexes.php /app/database/migrations/2026_07_21_130000_add_metrics_query_indexes.php
