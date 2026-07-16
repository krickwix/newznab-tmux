FROM docker.io/krickwix/nntmux:microservices-pods-20260713-fresh-hashed-retry-v114@sha256:8f00ba69c4a576ba4ece3fb8ea9f45ed4f694b0c016e160fc2c90c554e716b6a

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260713-fresh-hashed-retry-v114"

COPY app/Support/ReleaseNames/CompactTvEpisode.php /app/app/Support/ReleaseNames/CompactTvEpisode.php
COPY app/Services/Categorization/Categorizers/MiscCategorizer.php /app/app/Services/Categorization/Categorizers/MiscCategorizer.php
COPY app/Services/Categorization/Categorizers/TvCategorizer.php /app/app/Services/Categorization/Categorizers/TvCategorizer.php
