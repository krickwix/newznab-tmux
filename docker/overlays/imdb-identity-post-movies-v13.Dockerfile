FROM docker.io/krickwix/nntmux:microservices-pods-20260710-nzb-query-v8@sha256:099d1023241be21a9c39ac20a30d4ec72a92a091cac0fbc97c951446b02aadc3

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260710-nzb-query-v8"

COPY app/Services/ImdbScraper.php /app/app/Services/ImdbScraper.php
COPY app/Services/MovieService.php /app/app/Services/MovieService.php
COPY app/Services/Movies/MovieLookupState.php /app/app/Services/Movies/MovieLookupState.php
COPY app/Services/Runners/PostProcessRunner.php /app/app/Services/Runners/PostProcessRunner.php
COPY app/Services/Tmux/Tmux.php /app/app/Services/Tmux/Tmux.php
COPY config/nntmux_api.php /app/config/nntmux_api.php
COPY database/migrations/2026_07_21_120000_create_movie_lookup_states_table.php /app/database/migrations/2026_07_21_120000_create_movie_lookup_states_table.php
