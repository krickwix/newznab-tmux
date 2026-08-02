FROM docker.io/krickwix/nntmux:microservices-pods-20260728-consolidated-v207@sha256:cc8f957d3b70f8f6856c24e019f0e3cdc539ee48bdd6a369ed1d145f27c38f35

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260728-consolidated-v207"

COPY app/Services/NNTP/NNTPService.php /app/app/Services/NNTP/NNTPService.php
COPY app/Services/Binaries/BinariesService.php /app/app/Services/Binaries/BinariesService.php
