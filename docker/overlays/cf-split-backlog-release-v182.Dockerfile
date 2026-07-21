FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-collection-handoff-release-v181@sha256:8b6bae391cd799b6a188be9b31df906ebbc9b7f531b3c60c8335095963ee8c6c

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-collection-handoff-release-v181"

COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
COPY config/nntmux.php /app/config/nntmux.php
