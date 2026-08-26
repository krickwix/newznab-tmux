#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

artifact_dir="storage/native-first-lane-commit-smoke"
binary_host="${artifact_dir}/nntmux-worker"

cleanup() {
  rm -rf "${artifact_dir}"
}
trap cleanup EXIT

rm -rf "${artifact_dir}"
mkdir -p "${artifact_dir}"

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke build native-worker php-native-test
docker compose -f docker-compose.native-test.yml up -d --wait mariadb redis

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm -T \
  --entrypoint cat native-worker /usr/local/bin/nntmux-worker \
  > "${binary_host}"
chmod +x "${binary_host}"

run_commit_smoke() {
  local lane="$1"

  docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
    sh -lc "/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture ${lane}"

  docker compose -f docker-compose.native-test.yml run --rm -T \
    -e NNTMUX_NATIVE_FIRST_LANE_COMMIT_SMOKE=1 \
    -e NNTMUX_NATIVE_FIRST_LANE_COMMIT_SMOKE_JOB="${lane}" \
    -e NNTMUX_NATIVE_WORKER_BINARY="/var/www/html/${binary_host}" \
    php-native-test \
    ./vendor/bin/phpunit tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php
}

run_commit_smoke binaries
run_commit_smoke backfill
run_commit_smoke releases

echo "php-orchestrated native first-lane commit smoke verified: binaries backfill releases"
