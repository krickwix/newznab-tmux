#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

plan_dir="storage/native-worker-plan-contract"
fixture_dir="tests/Fixtures/native-worker/catalog"

cleanup() {
    rm -rf "${plan_dir}"
}
trap cleanup EXIT

rm -rf "${plan_dir}"
mkdir -p "${plan_dir}"

docker compose -f docker-compose.native-test.yml run --rm php-test php native/scripts/generate-worker-plan-fixtures.php "${plan_dir}"

diff -ru "${fixture_dir}" "${plan_dir}"

for plan_path in "${plan_dir}"/*.json; do
    docker compose -f docker-compose.native-test.yml run --rm go-test go run ./cmd/nntmux-worker --plan ../"${plan_path}" --dry-run
done
