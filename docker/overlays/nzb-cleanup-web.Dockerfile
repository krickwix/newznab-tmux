FROM docker.io/krickwix/nntmux:microservices-pods-20260628-audio-posters-web-amd64-v1@sha256:5d656b718fd57e9a4ac026badd9df31b802dc47e8ca6f3c78dc59f52a832a1a8

ARG SOURCE_REVISION=47fb7f33803bb1c06c4b2c003427e159b84cca3d
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260628-audio-posters-web-amd64-v1"

COPY app/Services/CollectionCleanupService.php /app/app/Services/CollectionCleanupService.php
