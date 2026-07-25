FROM docker.io/krickwix/nntmux:microservices-pods-20260714-admission-settle-v159@sha256:bf5a60955fe65d7a692619ae75094d3ab33aba0e107becff69a8917796724414

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-admission-settle-v159"

COPY app/Services/Orchestrator/CurrentForwardStopCursorPolicy.php /app/app/Services/Orchestrator/CurrentForwardStopCursorPolicy.php
COPY app/Services/Distributed/CurrentForwardPermitGate.php /app/app/Services/Distributed/CurrentForwardPermitGate.php
COPY app/Services/Distributed/DistributedJobWorker.php /app/app/Services/Distributed/DistributedJobWorker.php
COPY app/Services/Orchestrator/WorkerOrchestrator.php /app/app/Services/Orchestrator/WorkerOrchestrator.php
COPY app/Console/Commands/GetArticleRange.php /app/app/Console/Commands/GetArticleRange.php
COPY config/nntmux.php /app/config/nntmux.php
