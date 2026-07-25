FROM docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v155@sha256:ac170cbb8eeb1e6c5eb2e3166c38c1fb1888a4cfa373842bd40e543b98edda0a

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v155"

COPY app/Services/Binaries/MissedPartHandler.php /app/app/Services/Binaries/MissedPartHandler.php
