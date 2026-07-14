FROM docker.io/krickwix/nntmux:microservices-pods-20260714-row-lock-v157@sha256:4fc77278733238ae06c598489fb8743098b2f9cdc0429176db1bb1f4aa2435ab

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-row-lock-v157"

COPY app/Services/Orchestrator/PipelineSnapshotRepository.php /app/app/Services/Orchestrator/PipelineSnapshotRepository.php
COPY app/Services/Orchestrator/WorkerControlPolicy.php /app/app/Services/Orchestrator/WorkerControlPolicy.php
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
