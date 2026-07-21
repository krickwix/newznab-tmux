FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-dynamic-pair-shadow-release-v183@sha256:aec282800a761450edb313ea11287766fc05b91239f32b357d9ed4cfcb152231

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-dynamic-pair-shadow-release-v183"
