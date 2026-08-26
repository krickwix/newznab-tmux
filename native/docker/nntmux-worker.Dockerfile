# syntax=docker/dockerfile:1

FROM golang:1.23-bookworm AS build

WORKDIR /src/native

COPY native/go.mod native/go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download

COPY native/ ./

ARG TARGETOS=linux
ARG TARGETARCH
RUN --mount=type=cache,target=/root/.cache/go-build \
    export GOARCH="${TARGETARCH:-$(go env GOARCH)}" \
    && CGO_ENABLED=0 GOOS="${TARGETOS}" GOARCH="${GOARCH}" \
        go build -trimpath -ldflags="-s -w" -o /out/nntmux-worker ./cmd/nntmux-worker

FROM debian:bookworm-slim

LABEL org.opencontainers.image.title="nntmux-native-worker"
LABEL org.opencontainers.image.description="NNTmux native worker dry-run and rollback-only rehearsal binary"

RUN groupadd --gid 10001 nntmux \
    && useradd --uid 10001 --gid nntmux --home-dir /nonexistent --shell /usr/sbin/nologin nntmux

COPY --from=build /out/nntmux-worker /usr/local/bin/nntmux-worker

USER nntmux:nntmux
WORKDIR /workspace/native

ENTRYPOINT ["/usr/local/bin/nntmux-worker"]
