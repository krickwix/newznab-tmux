#!/bin/bash
#
# Build and push the nntmux image from THIS Dockerfile.
#
# Why this script exists: the deployed image was not built from Dockerfile. It
# was ~90 overlay Dockerfiles (Dockerfile.v* and docker/overlays/*.Dockerfile),
# each FROM-ing the previous published digest and COPY-ing a few PHP files on
# top. Tags came from ad-hoc `docker buildx build` invocations, so no published
# tag was reproducible from the repo.
#
# A clean build from here collapses that chain. That is safe because every
# overlay uses only COPY/LABEL/FROM/ARG - there is not a single RUN across all
# 90 of them - and every COPY source is an app/ path that `COPY . /app` already
# includes. Verify both before assuming it still holds:
#
#   grep -hE '^[A-Z]+ ' docker/overlays/*.Dockerfile Dockerfile.v* | awk '{print $1}' | sort -u
#
# Usage: ./build-and-push.sh <tag>
#
# Multi-arch is mandatory: the nntmux fleet runs ~27 pods across a mixed
# amd64/arm64 cluster, and an amd64-only tag was already shipped once
# (…-imdb-identity-web-amd64-v13), which silently constrained scheduling.

set -euo pipefail

REGISTRY="krickwix"
IMAGE_NAME="nntmux"
TAG="${1:?usage: $0 <tag>   e.g. microservices-pods-20260824-base-refresh-v241}"
PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
BUILDER="${BUILDER:-multiarch}"

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'; NC='\033[0m'
log_info(){ echo -e "${BLUE}[INFO]${NC} $1"; }
log_ok(){   echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_err(){  echo -e "${RED}[ERROR]${NC} $1"; }

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

docker buildx version >/dev/null 2>&1 || { log_err "docker buildx required"; exit 1; }
docker buildx inspect "${BUILDER}" >/dev/null 2>&1 \
  || docker buildx create --name "${BUILDER}" --driver docker-container --use >/dev/null
docker buildx use "${BUILDER}"

REV="$(git rev-parse HEAD)"
log_info "Building ${REGISTRY}/${IMAGE_NAME}:${TAG} for ${PLATFORMS} from ${REV:0:12}"

# No :latest tag. Every manifest pins tag@digest, and a floating latest would
# make the deployed version unknowable from git.
docker buildx build \
  --platform "${PLATFORMS}" \
  --build-arg SOURCE_REVISION="${REV}" \
  --label "org.opencontainers.image.revision=${REV}" \
  -t "${REGISTRY}/${IMAGE_NAME}:${TAG}" \
  --push .

log_ok "Pushed docker.io/${REGISTRY}/${IMAGE_NAME}:${TAG}"
log_info "Before rolling the fleet, compare against the image it replaces:"
echo "  docker run --rm --entrypoint php <image> -m      # extension parity"
echo "  docker run --rm --entrypoint php <image> -v      # PHP version"
log_info "Then deploy ONE low-risk workload first (nntmux-worker-removecrap or nntmux-metrics)."
