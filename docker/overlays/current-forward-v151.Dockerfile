FROM docker.io/krickwix/nntmux:microservices-pods-20260714-group-xref-v149@sha256:c3ac01b8d25170e76d73c26176f5e4b75678d709eee989014bf9f0684e41bbee

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-group-xref-v149"

COPY app/Console/Commands/GetArticleRange.php /app/app/Console/Commands/GetArticleRange.php
COPY app/Console/Commands/NntmuxWorkerOrchestrator.php /app/app/Console/Commands/NntmuxWorkerOrchestrator.php
COPY app/Console/Commands/PartRepair.php /app/app/Console/Commands/PartRepair.php
COPY app/Console/Commands/UpdateBinaries.php /app/app/Console/Commands/UpdateBinaries.php
COPY app/Services/Binaries/BinariesService.php /app/app/Services/Binaries/BinariesService.php
COPY app/Services/Distributed/CurrentForwardPermitGate.php /app/app/Services/Distributed/CurrentForwardPermitGate.php
COPY app/Services/Distributed/DistributedJobCatalog.php /app/app/Services/Distributed/DistributedJobCatalog.php
COPY app/Services/Orchestrator/CurrentForwardStopCursorPolicy.php /app/app/Services/Orchestrator/CurrentForwardStopCursorPolicy.php
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
COPY app/Services/Orchestrator/WorkerProfileApplier.php /app/app/Services/Orchestrator/WorkerProfileApplier.php
COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
COPY config/nntmux.php /app/config/nntmux.php
