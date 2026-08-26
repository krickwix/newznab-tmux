#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

artifact_dir="storage/native-lane-commit-smoke"
binary_host="${artifact_dir}/nntmux-worker"
lanes="${NNTMUX_NATIVE_LANE_COMMIT_SMOKE_LANES:-binaries backfill releases per-group removecrap metadata-refresh post-tv post-movies post-amazon post-additional}"
fake_nntp_container="nntmux-native-lane-commit-fake-nntp"

cleanup() {
  docker rm -f "${fake_nntp_container}" >/dev/null 2>&1 || true
  rm -rf "${artifact_dir}"
}
trap cleanup EXIT

rm -rf "${artifact_dir}"
mkdir -p "${artifact_dir}"

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke build native-worker php-native-test
docker compose -f docker-compose.native-test.yml up -d --wait mariadb redis

docker rm -f "${fake_nntp_container}" >/dev/null 2>&1 || true
docker compose -f docker-compose.native-test.yml run -d --name "${fake_nntp_container}" \
  go-test /usr/local/go/bin/go run ./cmd/nntmux-fake-nntp --listen :1119 >/dev/null
for _ in $(seq 1 60); do
  if docker logs "${fake_nntp_container}" 2>&1 | grep -Fq "fake NNTP listening"; then
    break
  fi
  sleep 1
done
if ! docker logs "${fake_nntp_container}" 2>&1 | grep -Fq "fake NNTP listening"; then
  docker logs "${fake_nntp_container}" >&2 || true
  echo "fake NNTP server did not start" >&2
  exit 1
fi

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm -T \
  --entrypoint cat native-worker /usr/local/bin/nntmux-worker \
  > "${binary_host}"
chmod +x "${binary_host}"

run_commit_smoke() {
  local lane="$1"

  docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
    sh -lc "/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture ${lane}"

  docker compose -f docker-compose.native-test.yml run --rm -T \
    -e NNTMUX_NATIVE_LANE_COMMIT_SMOKE=1 \
    -e NNTMUX_NATIVE_LANE_COMMIT_SMOKE_JOB="${lane}" \
    -e NNTMUX_NATIVE_WORKER_BINARY="/var/www/html/${binary_host}" \
    -e NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE=2 \
    -e NNTP_SERVER="${fake_nntp_container}" \
    -e NNTP_PORT=1119 \
    -e NNTP_SSLENABLED=false \
    -e NNTP_USERNAME= \
    -e NNTP_PASSWORD= \
    php-native-test \
    ./vendor/bin/phpunit tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php
}

for lane in ${lanes}; do
  run_commit_smoke "${lane}"
done

echo "php-orchestrated native lane commit smoke verified: ${lanes}"
