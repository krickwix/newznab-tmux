FROM docker.io/krickwix/nntmux:microservices-pods-20260720-sustainable-backfill-web-amd64-v11@sha256:aa198b9ea0c4b6a23dce33bdc5a42673ba21004e3097edfbe49f4091cfbda11e

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260720-sustainable-backfill-web-amd64-v11"

COPY app/Services/ImdbScraper.php /app/app/Services/ImdbScraper.php
COPY app/Services/MovieService.php /app/app/Services/MovieService.php
COPY app/Services/Movies/MovieLookupState.php /app/app/Services/Movies/MovieLookupState.php
COPY app/Services/Runners/PostProcessRunner.php /app/app/Services/Runners/PostProcessRunner.php
COPY app/Services/Tmux/Tmux.php /app/app/Services/Tmux/Tmux.php
COPY config/nntmux_api.php /app/config/nntmux_api.php
COPY database/migrations/2026_07_21_120000_create_movie_lookup_states_table.php /app/database/migrations/2026_07_21_120000_create_movie_lookup_states_table.php
