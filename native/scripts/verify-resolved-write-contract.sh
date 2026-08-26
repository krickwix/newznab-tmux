#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

artifact_dir="storage/native-resolved-write-contract"
native_report="${artifact_dir}/hashed-fixnames-report.json"
resolved_report="${artifact_dir}/hashed-fixnames-resolved.json"
rehearsal_report="${artifact_dir}/hashed-fixnames-rehearsal.json"
commit_report="${artifact_dir}/hashed-fixnames-commit.json"
search_sync_report="${artifact_dir}/hashed-fixnames-search-sync.json"
second_commit_report="${artifact_dir}/hashed-fixnames-commit-second.json"
resolver_db="${artifact_dir}/resolver.sqlite"

# This verifier reseeds the shared Compose MariaDB fixture tables. Run it
# serially with go-integration-test, not in parallel against the same project.

assert_json_path() {
    docker compose -f docker-compose.native-test.yml run --rm -T php-test \
      php native/scripts/assert-json-path.php "$@"
}

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

docker compose -f docker-compose.native-test.yml run --rm \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE="${resolver_db}" \
  php-test \
  php native/scripts/prepare-write-contract-resolver-db.php "${resolver_db}"

docker compose -f docker-compose.native-test.yml run --rm -T \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE="${resolver_db}" \
  php-test \
  php artisan nntmux:native-write-contract:resolve \
    --input="${native_report}" \
  > "${resolved_report}"

grep -Eq '"mode":[[:space:]]*"native-write-contract-resolve"' "${resolved_report}"
grep -Eq '"release_updates_resolved":[[:space:]]*2' "${resolved_report}"
grep -Eq '"release_updates_blocked":[[:space:]]*0' "${resolved_report}"

docker compose -f docker-compose.native-test.yml run --rm -T go-integration-test \
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --dry-run \
    --output=json \
    --rehearse-writes \
    --resolved-write-contract "../'"${resolved_report}"'" \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"' \
  > "${rehearsal_report}"

grep -Eq '"release_updates_attempted":[[:space:]]*2' "${rehearsal_report}"
grep -Eq '"release_updates_blocked":[[:space:]]*0' "${rehearsal_report}"
grep -Eq '"rolled_back":[[:space:]]*true' "${rehearsal_report}"
grep -Eq '"writes_committed":[[:space:]]*0' "${rehearsal_report}"

docker compose -f docker-compose.native-test.yml run --rm -T \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  go-integration-test \
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --commit-miss-status \
    --output=json \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner resolved-contract-verifier \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"' \
  > "${commit_report}"

grep -Eq '"dry_run":[[:space:]]*false' "${commit_report}"
grep -Eq '"lock_acquired":[[:space:]]*true' "${commit_report}"
grep -Eq '"single_column_updates_committed":[[:space:]]*2' "${commit_report}"
grep -Eq '"search_side_effect_rows_enqueued":[[:space:]]*2' "${commit_report}"
grep -Eq '"search_updates_enqueued":[[:space:]]*2' "${commit_report}"
grep -Eq '"writes_committed":[[:space:]]*2' "${commit_report}"

docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm -T php-search-test \
  php native/scripts/prepare-native-search-sync-smoke-db.php

docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm -T php-search-test \
  php artisan manticore:create-indexes --drop >/dev/null

docker compose -f docker-compose.native-test.yml --profile manticore-smoke run --rm -T php-search-test \
  php artisan nntmux:native-search-side-effects:sync \
    --pending-outbox \
    --limit=10 \
  > "${search_sync_report}"

assert_json_path "${search_sync_report}" mode '"native-search-side-effect-outbox-sync"'
assert_json_path "${search_sync_report}" search_updates_seen 2
assert_json_path "${search_sync_report}" search_updates_synced 2
assert_json_path "${search_sync_report}" search_updates_failed 0
assert_json_path "${search_sync_report}" release_ids '[102,301]'

docker compose -f docker-compose.native-test.yml run --rm -T \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  go-integration-test \
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-worker \
    --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
    --commit-miss-status \
    --output=json \
    --redis-addr "$NNTMUX_NATIVE_REDIS_ADDR" \
    --lock-owner resolved-contract-verifier-second \
    --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"' \
  > "${second_commit_report}"

grep -Eq '"writes_committed":[[:space:]]*0' "${second_commit_report}"

echo "resolved write-contract pipeline verified"
