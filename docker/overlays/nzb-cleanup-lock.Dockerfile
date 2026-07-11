FROM docker.io/krickwix/nntmux:microservices-pods-20260710-nzb-query-v8@sha256:099d1023241be21a9c39ac20a30d4ec72a92a091cac0fbc97c951446b02aadc3

ARG SOURCE_REVISION=47fb7f33803bb1c06c4b2c003427e159b84cca3d
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260710-nzb-query-v8"

COPY app/Services/CollectionCleanupService.php /app/app/Services/CollectionCleanupService.php
