#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

artifact_dir="storage/native-worker-image-smoke"
dry_run_report="${artifact_dir}/hashed-fixnames-image-report.json"
rehearsal_report="${artifact_dir}/hashed-fixnames-image-rehearsal.json"
commit_report="${artifact_dir}/hashed-fixnames-image-commit.json"
fingerprint_before="${artifact_dir}/hashed-fixnames-before.json"
fingerprint_after="${artifact_dir}/hashed-fixnames-after.json"
fingerprint_committed="${artifact_dir}/hashed-fixnames-committed.json"
mysql_dsn='nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true'

# This verifier reseeds the shared Compose MariaDB fixture tables. Run it
# serially with go-integration-test, not in parallel against the same project.

cleanup() {
    rm -rf "${artifact_dir}"
}
trap cleanup EXIT

rm -rf "${artifact_dir}"
mkdir -p "${artifact_dir}"

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke build native-worker

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm native-worker \
  --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json \
  --dry-run

for plan_path in tests/Fixtures/native-worker/catalog/*.json; do
    plan_name="$(basename "${plan_path}" .json)"
    catalog_report="${artifact_dir}/catalog-${plan_name}.json"

    docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm -T native-worker \
      --plan "../${plan_path}" \
      --dry-run \
      --output=json \
      > "${catalog_report}"

    docker compose -f docker-compose.native-test.yml run --rm php-test \
      php native/scripts/assert-json-path.php "${catalog_report}" dry_run true
    docker compose -f docker-compose.native-test.yml run --rm php-test \
      php native/scripts/assert-json-path.php "${catalog_report}" native_worker.writes 0
    docker compose -f docker-compose.native-test.yml run --rm php-test \
      php -r '
        $report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        $planName = (string) $argv[2];
        $nativeWorker = $report["native_worker"] ?? null;
        if (! is_array($nativeWorker)) {
            fwrite(STDERR, "{$planName} image report is missing native_worker\n");
            exit(1);
        }
        if (($nativeWorker["replacement_ready"] ?? null) !== false) {
            fwrite(STDERR, "{$planName} image report is missing replacement_ready=false\n");
            exit(1);
        }
        $readiness = $nativeWorker["replacement_readiness"] ?? null;
        $blockers = is_array($readiness) ? ($readiness["blockers"] ?? null) : null;
        if (! is_array($blockers) || count($blockers) === 0) {
            fwrite(STDERR, "{$planName} image report is missing replacement readiness blockers\n");
            exit(1);
        }
      ' "${catalog_report}" "${plan_name}"

    if docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm -T native-worker \
      --plan "../${plan_path}" \
      --dry-run \
      --output=json \
      --require-replacement-ready \
      > "${artifact_dir}/catalog-${plan_name}-replacement-ready.stdout" \
      2> "${artifact_dir}/catalog-${plan_name}-replacement-ready.stderr"; then
        echo "native-worker image accepted replacement readiness for ${plan_name}" >&2
        exit 1
    fi

    grep -F "${plan_name} catalog is not replacement-ready" "${artifact_dir}/catalog-${plan_name}-replacement-ready.stderr"
done

if docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm native-worker \
  --plan ../tests/Fixtures/native-worker/metadata-refresh-plan.json \
  > "${artifact_dir}/missing-dry-run.stdout" \
  2> "${artifact_dir}/missing-dry-run.stderr"; then
    echo "native-worker image accepted execution without --dry-run" >&2
    exit 1
fi

grep -Eq 'only --dry-run, --run-lane, --commit-lane-writes, or --commit-miss-status is supported' "${artifact_dir}/missing-dry-run.stderr"

docker compose -f docker-compose.native-test.yml up -d --wait mariadb redis

docker compose -f docker-compose.native-test.yml run --rm go-integration-test \
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture hashed-fixnames --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"'

docker compose -f docker-compose.native-test.yml run --rm -T go-integration-test \
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture hashed-fixnames --action fingerprint --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"' \
  > "${fingerprint_before}"

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm -T native-worker \
  --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
  --dry-run \
  --output=json \
  --mysql-dsn "${mysql_dsn}" \
  > "${dry_run_report}"

docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${dry_run_report}" dry_run true
docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${dry_run_report}" native_worker.writes 0
docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${dry_run_report}" hashed_fixnames.writes 0
docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${dry_run_report}" hashed_fixnames.write_contract.writes 0

if docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm -T native-worker \
  --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
  --dry-run \
  --output=json \
  --rehearse-writes \
  --mysql-dsn "${mysql_dsn}" \
  > "${artifact_dir}/rehearsal-without-guard.stdout" \
  2> "${artifact_dir}/rehearsal-without-guard.stderr"; then
    echo "native-worker image accepted write rehearsal without safety guard" >&2
    exit 1
fi

grep -F 'NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1 is required' "${artifact_dir}/rehearsal-without-guard.stderr"

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm -T \
  -e NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1 \
  native-worker \
  --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
  --dry-run \
  --output=json \
  --rehearse-writes \
  --mysql-dsn "${mysql_dsn}" \
  > "${rehearsal_report}"

docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${rehearsal_report}" hashed_fixnames.write_rehearsal.rolled_back true
docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${rehearsal_report}" hashed_fixnames.write_rehearsal.writes_committed 0

docker compose -f docker-compose.native-test.yml run --rm -T go-integration-test \
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture hashed-fixnames --action fingerprint --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"' \
  > "${fingerprint_after}"

diff -u "${fingerprint_before}" "${fingerprint_after}"

if docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm -T \
  -e NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1 \
  native-worker \
  --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
  --commit-miss-status \
  --output=json \
  --redis-addr redis:6379 \
  --lock-owner image-commit-without-guard \
  --mysql-dsn "${mysql_dsn}" \
  > "${artifact_dir}/commit-without-guard.stdout" \
  2> "${artifact_dir}/commit-without-guard.stderr"; then
    echo "native-worker image accepted miss-status commit without commit guard" >&2
    exit 1
fi

grep -F 'NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 is required' "${artifact_dir}/commit-without-guard.stderr"

docker compose -f docker-compose.native-test.yml --profile native-worker-smoke run --rm -T \
  -e NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1 \
  -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
  native-worker \
  --plan ../tests/Fixtures/native-worker/catalog/hashed-fixnames.json \
  --commit-miss-status \
  --output=json \
  --redis-addr redis:6379 \
  --lock-owner image-commit \
  --mysql-dsn "${mysql_dsn}" \
  > "${commit_report}"

docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${commit_report}" dry_run false
docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${commit_report}" native_worker.writes 2
docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${commit_report}" hashed_fixnames.write_commit.lock_acquired true
docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${commit_report}" hashed_fixnames.write_commit.writes_committed 2
docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${commit_report}" hashed_fixnames.write_commit.search_side_effect_rows_enqueued 2
docker compose -f docker-compose.native-test.yml run --rm php-test \
  php native/scripts/assert-json-path.php "${commit_report}" hashed_fixnames.write_commit.search_updates_enqueued 2

docker compose -f docker-compose.native-test.yml run --rm -T go-integration-test \
  sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture hashed-fixnames --action fingerprint --mysql-dsn "$NNTMUX_NATIVE_MYSQL_DSN"' \
  > "${fingerprint_committed}"

if diff -q "${fingerprint_before}" "${fingerprint_committed}" >/dev/null; then
    echo "native-worker image miss-status commit did not change the hashed-fixnames fixture" >&2
    exit 1
fi

echo "native worker image smoke verified"
