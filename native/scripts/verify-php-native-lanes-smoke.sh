#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

artifact_dir="storage/native-lane-execution-smoke"
binary_host="${artifact_dir}/nntmux-worker"

# This verifier reseeds the shared Compose MariaDB fixture once per lane. Run it
# serially with the other native smoke scripts.

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

run_lane_smoke() {
  local job="$1"
  local case_name="$2"
  local backfill_days="$3"
  local backfill_safe_date="$4"
  local artisan_mode="${5:-fake}"
  local fixture="${6:-${job}}"

  if [[ "${fixture}" != "none" ]]; then
    docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
      sh -lc "/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture ${fixture}"
  fi

  docker compose -f docker-compose.native-test.yml run --rm -T \
    -e NNTMUX_NATIVE_LANE_EXECUTION_SMOKE=1 \
    -e NNTMUX_NATIVE_LANE_EXECUTION_SMOKE_JOB="${job}" \
    -e NNTMUX_NATIVE_LANE_EXECUTION_SMOKE_CASE="${case_name}" \
    -e NNTMUX_NATIVE_LANE_EXECUTION_ARTISAN_MODE="${artisan_mode}" \
    -e NNTMUX_NATIVE_WORKER_BINARY="/var/www/html/${binary_host}" \
    -e NNTMUX_NATIVE_WORKER_LANE_MAX_PROCESSES=2 \
    -e NNTMUX_NATIVE_WORKER_BINARIES_MAX_MESSAGES=10000 \
    -e NNTMUX_NATIVE_WORKER_BINARIES_MAX_HEADERS=25000 \
    -e NNTMUX_NATIVE_WORKER_BACKFILL_QTY=75000 \
    -e NNTMUX_NATIVE_WORKER_BACKFILL_MAX_MESSAGES=20000 \
    -e NNTMUX_NATIVE_WORKER_BACKFILL_THREADS=4 \
    -e NNTMUX_NATIVE_WORKER_BACKFILL_GROUPS=10 \
    -e NNTMUX_NATIVE_WORKER_BACKFILL_DAYS="${backfill_days}" \
    -e NNTMUX_NATIVE_WORKER_BACKFILL_SAFE_DATE="${backfill_safe_date}" \
    -e NNTMUX_NATIVE_WORKER_BACKFILL_MIN_ARTICLES=100 \
    php-native-test \
    ./vendor/bin/phpunit tests/Feature/Console/NativeWorkerLaneExecutionSmokeTest.php
}

run_lane_smoke binaries default 1 ""
run_lane_smoke backfill default 1 ""
run_lane_smoke backfill day2-safe-date 2 "1999-01-01"
run_lane_smoke releases default 1 ""
run_lane_smoke per-group default 1 ""
run_lane_smoke removecrap default 1 ""
run_lane_smoke post-tv default 1 ""
run_lane_smoke post-movies default 1 ""
run_lane_smoke post-amazon default 1 ""
run_lane_smoke post-additional default 1 ""
run_lane_smoke fixnames command-only 1 "" fake none
run_lane_smoke metadata-refresh command-only 1 "" fake none
run_lane_smoke hashed-fixnames command-only 1 "" fake none
run_lane_smoke irc command-only 1 "" fake none

run_lane_smoke binaries real-artisan-startup 1 "" real
run_lane_smoke backfill real-artisan-startup 1 "" real
run_lane_smoke releases real-artisan-startup 1 "" real
run_lane_smoke per-group real-artisan-startup 1 "" real
run_lane_smoke removecrap real-artisan-startup 1 "" real
run_lane_smoke post-tv real-artisan-startup 1 "" real
run_lane_smoke post-movies real-artisan-startup 1 "" real
run_lane_smoke post-amazon real-artisan-startup 1 "" real
run_lane_smoke post-additional real-artisan-startup 1 "" real
run_lane_smoke fixnames real-artisan-startup 1 "" real none
run_lane_smoke metadata-refresh real-artisan-startup 1 "" real none
run_lane_smoke hashed-fixnames real-artisan-startup 1 "" real none
run_lane_smoke irc real-artisan-startup 1 "" real none

echo "php-orchestrated native lane worker smoke verified"
