#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

artifact_dir="storage/native-removecrap-production-commit-smoke"
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

docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  sh -lc "/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture removecrap"

docker compose -f docker-compose.native-test.yml run --rm -T \
  -e NNTMUX_NATIVE_REMOVECRAP_PRODUCTION_COMMIT_SMOKE=1 \
  -e NNTMUX_NATIVE_WORKER_BINARY="/var/www/html/${binary_host}" \
  -e NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=0 \
  -e NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB= \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB= \
  php-native-test \
  ./vendor/bin/phpunit tests/Feature/Console/NativeWorkerFirstLaneCommitSmokeTest.php --filter test_php_worker_commits_native_first_lane_writes_and_skips_php_command_loop

echo "php-orchestrated native removecrap production opt-in commit smoke verified"
