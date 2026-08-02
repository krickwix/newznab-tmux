FROM docker.io/krickwix/nntmux:microservices-pods-20260802-split-multi-payload-v214@sha256:5d81c3d382cb73f3f5252bea862a94b0a16f0483ef3db42b5ba78e836e45c7e2

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260802-split-multi-payload-v214"

COPY config/nntmux.php /app/config/nntmux.php
COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
