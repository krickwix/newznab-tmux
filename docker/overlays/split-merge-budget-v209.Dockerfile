FROM docker.io/krickwix/nntmux:microservices-pods-20260801-xover-cache-fix-v208@sha256:bb377b5637090420ace38a3a17fd104744e99466b968bad92e0ec94e84c9984d

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260801-xover-cache-fix-v208"

COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
COPY config/nntmux.php /app/config/nntmux.php
