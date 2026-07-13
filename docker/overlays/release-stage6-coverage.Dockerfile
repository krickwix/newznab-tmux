FROM docker.io/krickwix/nntmux:microservices-pods-20260713-raw-context-v112@sha256:1b6b8f67ccf8069de9352a49e794f5c3f7acddcda90418b69ebf7ebc98338c84

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260713-raw-context-v112@sha256:1b6b8f67ccf8069de9352a49e794f5c3f7acddcda90418b69ebf7ebc98338c84"

COPY app/Services/ReleaseProcessingService.php /app/app/Services/ReleaseProcessingService.php
