#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

artifact_dir="storage/native-rename-worker-smoke"
binary_host="${artifact_dir}/nntmux-worker"

# This verifier reseeds the shared Compose MariaDB fixture and Manticore index.
# Run it serially with the other native smoke scripts.

cleanup() {
  rm -rf "${artifact_dir}"
}
trap cleanup EXIT

rm -rf "${artifact_dir}"
mkdir -p "${artifact_dir}"

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke --profile manticore-smoke build native-worker php-native-test
docker compose -f docker-compose.native-test.yml --profile manticore-smoke up -d --wait mariadb redis manticore

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm -T \
  --entrypoint cat native-worker /usr/local/bin/nntmux-worker \
  > "${binary_host}"
chmod +x "${binary_host}"

docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture hashed-fixnames'

docker compose -f docker-compose.native-test.yml run --rm -T php-native-test \
  php native/scripts/prepare-native-search-sync-smoke-db.php

docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm -T \
  -e NNTMUX_NATIVE_RENAME_PREPASS_SMOKE=1 \
  -e NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_COMMIT_ENABLED=0 \
  -e NNTMUX_NATIVE_WORKER_HASHED_FIXNAMES_RENAME_PREPASS_ENABLED=1 \
  -e NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=1 \
  -e NNTMUX_NATIVE_WORKER_BINARY="/var/www/html/${binary_host}" \
  -e SEARCH_DRIVER=manticore \
  -e MANTICORESEARCH_HOST=manticore \
  -e MANTICORESEARCH_PORT=9308 \
  php-native-test \
  ./vendor/bin/phpunit tests/Feature/Console/NativeWorkerRenamePrepassSmokeTest.php

echo "php-orchestrated native hashed-fixnames rename prepass smoke verified"
