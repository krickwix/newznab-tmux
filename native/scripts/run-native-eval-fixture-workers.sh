#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

env_file="${NNTMUX_NATIVE_EVAL_ENV_FILE:-.env.native-eval}"
compose_file="${NNTMUX_NATIVE_EVAL_COMPOSE_FILE:-docker-compose.native-eval.yml}"
lanes="${NNTMUX_NATIVE_EVAL_LANES:-binaries backfill releases fixnames hashed-fixnames removecrap post-additional metadata-refresh post-tv post-movies post-amazon irc per-group}"
go_image="${NNTMUX_NATIVE_EVAL_GO_IMAGE:-golang:1.23-bookworm}"
fixture_host_dir="storage/native-eval/catalog-fixtures"
fixture_container_dir="/opt/nntmux-native/catalog-fixtures"

if [[ ! -f "${env_file}" ]]; then
    echo "Missing ${env_file}; create it from .env.example or deploy-native-eval-compose.sh prerequisites first." >&2
    exit 2
fi

env_value() {
    local key="$1"
    awk -F= -v key="${key}" '$1 == key { sub(/^[^=]*=/, ""); print; exit }' "${env_file}"
}

compose=(docker compose --env-file "${env_file}" -f "${compose_file}")
network_name="nntmux-native-eval_nntmux-native-eval"
db_name="$(env_value DB_DATABASE)"
db_root_password="$(env_value DB_ROOTPASSWORD)"
fixture_db="${NNTMUX_NATIVE_EVAL_FIXTURE_DB:-nntmux_native_test_eval_fixture}"
fixture_dsn="root:${db_root_password:-nntmux-root}@tcp(mariadb:3306)/${fixture_db}?parseTime=true"

if [[ "${db_name}" != "nntmux_native_eval" ]]; then
    echo "Refusing fixture-backed eval run against DB_DATABASE=${db_name}; expected nntmux_native_eval." >&2
    exit 2
fi
if [[ "${fixture_db}" != nntmux_native_test_* ]]; then
    echo "Refusing fixture-backed eval run against fixture DB ${fixture_db}; expected nntmux_native_test_*." >&2
    exit 2
fi

"${compose[@]}" config >/dev/null
"${compose[@]}" ps
"${compose[@]}" exec -T webapp test -x /opt/nntmux-native/nntmux-worker
docker network inspect "${network_name}" >/dev/null

cleanup_fixture_db() {
    "${compose[@]}" exec -T mariadb \
        mariadb -uroot -p"${db_root_password:-nntmux-root}" \
        -e "DROP DATABASE IF EXISTS \`${fixture_db}\`" >/dev/null 2>&1 || true
}
trap cleanup_fixture_db EXIT

cleanup_fixture_db
"${compose[@]}" exec -T mariadb \
    mariadb -uroot -p"${db_root_password:-nntmux-root}" \
    -e "CREATE DATABASE \`${fixture_db}\`"

rm -rf "${fixture_host_dir}"
mkdir -p "${fixture_host_dir}"
cp tests/Fixtures/native-worker/catalog/*.json "${fixture_host_dir}/"

seed_fixture() {
    local fixture="$1"

    docker run --rm \
        --network "${network_name}" \
        -e NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1 \
        -e NNTMUX_NATIVE_MYSQL_DSN="${fixture_dsn}" \
        -e FIXTURE="${fixture}" \
        -v "${PWD}:/workspace" \
        -w /workspace/native \
        "${go_image}" \
        sh -lc '/usr/local/go/bin/go run ./cmd/nntmux-test-fixture --fixture "$FIXTURE" >/dev/null'
}

fixture_for_lane() {
    case "$1" in
        binaries|backfill|releases|per-group|removecrap|post-tv|post-movies|post-amazon|post-additional)
            printf '%s' "$1"
            ;;
        *)
            printf 'none'
            ;;
    esac
}

db_mode_for_lane() {
    case "$1" in
        binaries|backfill|releases|per-group|removecrap|post-tv|post-movies|post-amazon|post-additional)
            printf 'db'
            ;;
        *)
            printf 'no-db'
            ;;
    esac
}

plan_lock_metadata() {
    python3 - "$1" <<'PY'
import json
import sys

with open(sys.argv[1], "r", encoding="utf-8") as handle:
    plan = json.load(handle)

lock = plan.get("lock") or {}
key = str(lock.get("redis_key") or "")
seconds = max(1, int(lock.get("seconds") or 42))
if not key:
    raise SystemExit("fixture plan is missing lock.redis_key")

print(f"{key}\t{seconds}")
PY
}

release_fixture_lock() {
    local lock_key="$1"
    local owner="$2"
    local current

    current="$("${compose[@]}" exec -T redis redis-cli GET "${lock_key}" | tr -d '\r' || true)"
    if [[ "${current}" == "${owner}" ]]; then
        "${compose[@]}" exec -T redis redis-cli DEL "${lock_key}" >/dev/null
    fi
}

"${compose[@]}" exec -T webapp sh -lc 'cat > /tmp/nntmux-native-eval-fake-artisan.sh <<'"'"'SH'"'"'
#!/usr/bin/env sh
set -eu
log="${NNTMUX_NATIVE_EVAL_FAKE_ARTISAN_LOG:?missing fake artisan log}"
if [ "${1:-}" = "artisan" ]; then
    shift
fi
printf "%s\n" "$*" >> "$log"
SH
chmod 755 /tmp/nntmux-native-eval-fake-artisan.sh
: > /tmp/nntmux-native-eval-fake-artisan.log'

for lane in ${lanes}; do
    fixture="$(fixture_for_lane "${lane}")"
    db_mode="$(db_mode_for_lane "${lane}")"
    plan_file_host="${fixture_host_dir}/${lane}.json"
    owner="native-eval-fixture-${lane}"
    lock_key=""
    lock_seconds=""

    if [[ "${fixture}" != "none" ]]; then
        seed_fixture "${fixture}"
    fi

    IFS=$'\t' read -r lock_key lock_seconds < <(plan_lock_metadata "${plan_file_host}")
    "${compose[@]}" exec -T redis redis-cli SETEX "${lock_key}" "${lock_seconds}" "${owner}" >/dev/null

    set +e
    "${compose[@]}" exec -T -e NNTMUX_NATIVE_EVAL_FIXTURE_DSN="${fixture_dsn}" webapp sh -lc '
        set -eu

        lane="$1"
        db_mode="$2"
        owner="$3"
        plan_file="'"${fixture_container_dir}"'/${lane}.json"
        report_file="/tmp/nntmux-native-eval-fixture-report-${lane}.json"
        extra_flags=""

        case "$lane" in
            binaries)
                [ "${NNTMUX_NATIVE_WORKER_BINARIES_MAX_MESSAGES:-0}" -gt 0 ] && extra_flags="${extra_flags} --binaries-max-messages=${NNTMUX_NATIVE_WORKER_BINARIES_MAX_MESSAGES}"
                [ "${NNTMUX_NATIVE_WORKER_BINARIES_MAX_HEADERS:-0}" -gt 0 ] && extra_flags="${extra_flags} --binaries-max-headers=${NNTMUX_NATIVE_WORKER_BINARIES_MAX_HEADERS}"
                ;;
            backfill)
                [ "${NNTMUX_NATIVE_WORKER_BACKFILL_QTY:-0}" -gt 0 ] && extra_flags="${extra_flags} --backfill-qty=${NNTMUX_NATIVE_WORKER_BACKFILL_QTY}"
                [ "${NNTMUX_NATIVE_WORKER_BACKFILL_MAX_MESSAGES:-0}" -gt 0 ] && extra_flags="${extra_flags} --backfill-max-messages=${NNTMUX_NATIVE_WORKER_BACKFILL_MAX_MESSAGES}"
                [ "${NNTMUX_NATIVE_WORKER_BACKFILL_THREADS:-0}" -gt 0 ] && extra_flags="${extra_flags} --backfill-threads=${NNTMUX_NATIVE_WORKER_BACKFILL_THREADS}"
                [ "${NNTMUX_NATIVE_WORKER_BACKFILL_GROUPS:-0}" -gt 0 ] && extra_flags="${extra_flags} --backfill-groups=${NNTMUX_NATIVE_WORKER_BACKFILL_GROUPS}"
                [ "${NNTMUX_NATIVE_WORKER_BACKFILL_DAYS:-0}" -gt 0 ] && extra_flags="${extra_flags} --backfill-days=${NNTMUX_NATIVE_WORKER_BACKFILL_DAYS}"
                [ "${NNTMUX_NATIVE_WORKER_BACKFILL_MIN_ARTICLES:-0}" -gt 0 ] && extra_flags="${extra_flags} --backfill-min-articles=${NNTMUX_NATIVE_WORKER_BACKFILL_MIN_ARTICLES}"
                [ -n "${NNTMUX_NATIVE_WORKER_BACKFILL_SAFE_DATE:-}" ] && extra_flags="${extra_flags} --backfill-safe-date=${NNTMUX_NATIVE_WORKER_BACKFILL_SAFE_DATE}"
                ;;
            post-additional)
                extra_flags="${extra_flags} --allow-deferred-post-additional"
                ;;
        esac

        set +e
        if [ "$db_mode" = "db" ]; then
            NNTMUX_NATIVE_MYSQL_DSN="${NNTMUX_NATIVE_EVAL_FIXTURE_DSN}" \
            NNTMUX_NATIVE_EVAL_FAKE_ARTISAN_LOG="/tmp/nntmux-native-eval-fake-artisan.log" \
                /opt/nntmux-native/nntmux-worker \
                    --plan "$plan_file" \
                    --run-lane \
                    --mysql-dsn-env \
                    --redis-addr redis:6379 \
                    --lock-owner "$owner" \
                    --lock-mode held \
                    --output json \
                    --artisan-binary /tmp/nntmux-native-eval-fake-artisan.sh \
                    --artisan-script artisan \
                    --lane-max-processes 1 \
                    ${extra_flags} \
                    > "$report_file"
        else
            NNTMUX_NATIVE_EVAL_FAKE_ARTISAN_LOG="/tmp/nntmux-native-eval-fake-artisan.log" \
                /opt/nntmux-native/nntmux-worker \
                    --plan "$plan_file" \
                    --run-lane \
                    --redis-addr redis:6379 \
                    --lock-owner "$owner" \
                    --lock-mode held \
                    --output json \
                    --artisan-binary /tmp/nntmux-native-eval-fake-artisan.sh \
                    --artisan-script artisan \
                    --lane-max-processes 1 \
                    ${extra_flags} \
                    > "$report_file"
        fi
        status="$?"
        set -e

        if [ "$status" -ne 0 ]; then
            echo "${lane}: native fixture lane exited ${status}" >&2
            exit "$status"
        fi

        php -r '"'"'
            $report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
            $lane = $argv[2];
            $nativeLane = $report["native_lane"] ?? null;
            if (! is_array($nativeLane) || ($nativeLane["commands"] ?? 0) < 1 || ($nativeLane["failed"] ?? 1) !== 0 || ($nativeLane["exit_code"] ?? 1) !== 0) {
                fwrite(STDERR, $lane.": invalid native lane report\n");
                exit(1);
            }
            printf("%s fixture native lane ok: commands=%d\n", $lane, (int) $nativeLane["commands"]);
        '"'"' "$report_file" "$lane"
    ' sh "${lane}" "${db_mode}" "${owner}"
    status="$?"
    set -e

    release_fixture_lock "${lock_key}" "${owner}"
    if [[ "${status}" -ne 0 ]]; then
        exit "${status}"
    fi
done

echo "Native eval fixture-backed worker execution completed: ${lanes}"
