FROM docker.io/krickwix/nntmux:microservices-pods-20260804-ingest-partcount-classifier-v229@sha256:a13944a98097cac5d3e796bd9eaf62cb767a3a43878a997c0fe8f69423d95354

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260804-ingest-partcount-classifier-v229"

# Teaches the part-count report the `all` sentinel, so a measurement window does
# not depend on a hand-maintained group list that goes stale.
#
# Still reporting only: there is no code path from the flag to a write. The
# allowlist decides whether the count is said out loud, never whether it is
# computed.
#
# No config COPY -- v229 already carried `ingest_partcount_key_groups` into the
# image, and re-copying the config per overlay is how the v214/v215 drift
# started.
COPY app/Services/Binaries/HeaderStorageService.php /app/app/Services/Binaries/HeaderStorageService.php
