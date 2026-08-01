FROM docker.io/krickwix/nntmux:microservices-pods-20260802-split-merge-budget-v209@sha256:984c6e76d8ce20a3f371d25b4b4f06d5959266d60c682feb0a1a3afc5d083176

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260802-split-merge-budget-v209"

COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
COPY config/nntmux.php /app/config/nntmux.php
