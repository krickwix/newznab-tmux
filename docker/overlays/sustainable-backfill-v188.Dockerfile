FROM docker.io/krickwix/nntmux:microservices-pods-20260719-qualified-supply-v187@sha256:1a5985a4bfeb492a4ded67ab087f49ad0aafb13ab92e28f650bcced2e305264e

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260719-qualified-supply-v187"

COPY app/Console/Commands/BackfillGroup.php /app/app/Console/Commands/BackfillGroup.php
COPY app/Console/Commands/GetArticleRange.php /app/app/Console/Commands/GetArticleRange.php
COPY app/Console/Commands/ProcessBackfill.php /app/app/Console/Commands/ProcessBackfill.php
COPY app/Console/Commands/ProcessSafe.php /app/app/Console/Commands/ProcessSafe.php
COPY app/Console/Commands/UpdateBackfill.php /app/app/Console/Commands/UpdateBackfill.php
COPY app/Services/Backfill/BackfillService.php /app/app/Services/Backfill/BackfillService.php
COPY app/Services/Binaries/BinariesService.php /app/app/Services/Binaries/BinariesService.php
COPY app/Services/Distributed/BackfillExecutionGuard.php /app/app/Services/Distributed/BackfillExecutionGuard.php
COPY app/Services/Distributed/BackfillPermitGate.php /app/app/Services/Distributed/BackfillPermitGate.php
COPY app/Services/Distributed/CurrentForwardPermitGate.php /app/app/Services/Distributed/CurrentForwardPermitGate.php
COPY app/Services/Distributed/DistributedJobWorker.php /app/app/Services/Distributed/DistributedJobWorker.php
COPY app/Services/ForkingService.php /app/app/Services/ForkingService.php
COPY app/Services/Orchestrator/WorkerControlPolicy.php /app/app/Services/Orchestrator/WorkerControlPolicy.php
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
COPY app/Services/Orchestrator/WorkerProfileApplier.php /app/app/Services/Orchestrator/WorkerProfileApplier.php
COPY app/Services/Runners/BackfillRunner.php /app/app/Services/Runners/BackfillRunner.php
COPY app/Services/Runners/BaseRunner.php /app/app/Services/Runners/BaseRunner.php
COPY app/Services/Tmux/TmuxMonitorService.php /app/app/Services/Tmux/TmuxMonitorService.php
COPY config/nntmux.php /app/config/nntmux.php
COPY database/migrations/2026_07_20_120000_create_backfill_execution_ranges.php /app/database/migrations/2026_07_20_120000_create_backfill_execution_ranges.php
