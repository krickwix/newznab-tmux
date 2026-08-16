FROM docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-mode-cli-v238@sha256:652997db5ed2ad3418c9fe871fd668ae65f95ea1e70789f1b5211d37d1a0cbb8

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-mode-cli-v238"

# Consult the in-cluster IMDb metadata service before any web source.
#
# fetchById went to imdb.com first - a scrape with a WAF that blocks it often
# enough that the scraper tracks wasBlockedByWaf() and negative-caches the
# failures - then api.imdbapi.dev, then OMDb. The cluster has carried the full
# IMDb dataset all along; with LOCAL_IMDB_METADATA_URL set the imdb-metadata
# service answers first, from the local dataset, in milliseconds. On a miss or
# error the existing chain proceeds unchanged, and with the variable unset the
# behaviour is byte-identical.
#
# Plot and cover are not in the IMDb dumps; updateMovieInfo fills them from
# TMDB/Trakt/OMDb per field as it always has.
COPY app/Services/ImdbScraper.php /app/app/Services/ImdbScraper.php
COPY config/nntmux_api.php /app/config/nntmux_api.php
