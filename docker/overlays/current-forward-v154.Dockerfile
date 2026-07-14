FROM docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v153@sha256:5b1970082c6702bb96f146adf238e224efdb101dda3fbffdcc22a9e03d970767

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v153"

COPY app/Services/Binaries/HeaderParser.php /app/app/Services/Binaries/HeaderParser.php
