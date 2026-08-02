FROM docker.io/krickwix/nntmux:microservices-pods-20260802-reindex-requeue-bound-v213@sha256:24f204a54ef7bc67dd1b82f8e5d9bc5e4c9637238e8d415babbfb92572367315

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260802-reindex-requeue-bound-v213"

COPY config/nntmux.php /app/config/nntmux.php
COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
