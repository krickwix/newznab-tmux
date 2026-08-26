#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

artifact_dir="storage/native-rename-apply-smoke"
native_report="${artifact_dir}/hashed-fixnames-report.json"

# This verifier reseeds the shared Compose MariaDB fixture tables. Run it
# serially with go-integration-test, not in parallel against the same project.

cleanup() {
  rm -rf "${artifact_dir}"
}
trap cleanup EXIT

rm -rf "${artifact_dir}"
mkdir -p "${artifact_dir}"

docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture hashed-fixnames --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"'

docker compose -f docker-compose.native-test.yml run --rm -T go-integration-test \
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --dry-run \
    --output=json \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"' \
  > "${native_report}"

docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm -T php-search-test \
  php native/scripts/prepare-native-search-sync-smoke-db.php

docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm -T \
  -e NNTMUX_NATIVE_RENAME_APPLY_SMOKE=1 \
  -e NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=1 \
  -e NNTMUX_NATIVE_RENAME_APPLY_SMOKE_INPUT="/var/www/html/${native_report}" \
  php-search-test \
  ./vendor/bin/phpunit tests/Feature/Console/NativeHashedFixNameResolvedApplySmokeTest.php

echo "php native rename-apply smoke verified"
