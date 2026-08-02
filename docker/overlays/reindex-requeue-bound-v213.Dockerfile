FROM docker.io/krickwix/nntmux:microservices-pods-20260802-split-anchor-position-v212@sha256:416a9da97b21b67daf82c6c0d057b5ff93f1d82198c8a892ed3d1176f0e73cd9

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260802-split-anchor-position-v212"

COPY app/Jobs/ReindexReleaseJob.php /app/app/Jobs/ReindexReleaseJob.php
COPY app/Services/Search/Drivers/ManticoreSearchDriver.php /app/app/Services/Search/Drivers/ManticoreSearchDriver.php
