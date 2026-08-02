FROM docker.io/krickwix/nntmux:microservices-pods-20260802-worker-lock-shutdown-v211@sha256:3584bdc90e2fc77ac2276a107e26c5741171205838b9673105f45da8050828a2

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260802-worker-lock-shutdown-v211"

COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
