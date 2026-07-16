FROM docker.io/krickwix/nntmux:microservices-pods-20260710-nzb-query-v8@sha256:099d1023241be21a9c39ac20a30d4ec72a92a091cac0fbc97c951446b02aadc3

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260710-nzb-query-v8"

COPY app/Support/ReleaseNames/CompactTvEpisode.php /app/app/Support/ReleaseNames/CompactTvEpisode.php
COPY app/Services/TvProcessing/Providers/AbstractTvProvider.php /app/app/Services/TvProcessing/Providers/AbstractTvProvider.php
