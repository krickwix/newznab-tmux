FROM docker.io/krickwix/nntmux:microservices-pods-20260805-local-imdb-metadata-v239@sha256:bb2b111ac2c7c75d1c224b896e8290d33a443cbb24806d86a8ed65117c946986

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260805-local-imdb-metadata-v239"

# 'video' is not a movie, and the type mapper stops substituting its own judgement.
#
# v239 shipped mapLocalTitleType collapsing IMDb's 'video' to 'movie' on the
# assumption it meant direct-to-video features. The dataset says otherwise: of
# 324,200 video titles, 135,511 are tagged Short and 114,651 Adult, and only
# 1,564 have 1,000+ votes -- overwhelmingly shorts, adult content and music
# videos. MovieService::isExplicitNonMovieMediaType() had listed 'video' as
# non-movie all along, so v239 made the local provider the one source able to
# walk them past the media-type gate.
#
# Only movie and tvMovie now collapse to 'movie'; every other type passes
# through under the name the gate already knows, so short/tvShort/tvSpecial/
# videoGame go from 'unknown' to explicitly rejected -- what they always were
# from every other source.
COPY app/Services/ImdbScraper.php /app/app/Services/ImdbScraper.php
