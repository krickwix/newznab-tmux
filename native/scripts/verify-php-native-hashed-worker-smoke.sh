#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

artifact_dir="storage/native-php-worker-smoke"
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
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture hashed-fixnames'

docker compose -f docker-compose.native-test.yml run --rm -T php-native-test \
  ./vendor/bin/phpunit tests/Feature/Console/NativeWorkerMissStatusPrepassSmokeTest.php

echo "php-orchestrated native hashed-fixnames worker smoke verified"
