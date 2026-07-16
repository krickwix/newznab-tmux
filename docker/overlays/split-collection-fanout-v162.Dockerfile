FROM docker.io/krickwix/nntmux:microservices-pods-20260714-admission-settle-v159@sha256:bf5a60955fe65d7a692619ae75094d3ab33aba0e107becff69a8917796724414

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-admission-settle-v159"

COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
