FROM docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v154@sha256:81ebf199e79c6c064e6663695939cde48313f0fa04e1c8be38fb963c7053a84d

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v154"

COPY app/Services/Distributed/CurrentForwardPermitGate.php /app/app/Services/Distributed/CurrentForwardPermitGate.php
