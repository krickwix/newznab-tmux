FROM docker.io/krickwix/nntmux:microservices-pods-20260716-split-fanout-v162@sha256:3456407f7d916902ad54c7c5cba4b2c69f779e7562c64924e608f12bac05209a

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260716-split-fanout-v162"

COPY app/Support/ReleaseNames/CompactTvEpisode.php /app/app/Support/ReleaseNames/CompactTvEpisode.php
COPY app/Services/Categorization/Categorizers/MiscCategorizer.php /app/app/Services/Categorization/Categorizers/MiscCategorizer.php
COPY app/Services/Categorization/Categorizers/TvCategorizer.php /app/app/Services/Categorization/Categorizers/TvCategorizer.php
COPY app/Services/TvProcessing/Providers/AbstractTvProvider.php /app/app/Services/TvProcessing/Providers/AbstractTvProvider.php
COPY app/Services/Orchestrator/BackfillSourceActivator.php /app/app/Services/Orchestrator/BackfillSourceActivator.php
