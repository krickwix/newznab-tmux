#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

env_file="${NNTMUX_NATIVE_EVAL_ENV_FILE:-.env.native-eval}"
compose_file="${NNTMUX_NATIVE_EVAL_COMPOSE_FILE:-docker-compose.native-eval.yml}"
lanes="${NNTMUX_NATIVE_EVAL_LANES:-binaries backfill releases}"
go_image="${NNTMUX_NATIVE_EVAL_GO_IMAGE:-golang:1.23-bookworm}"
fake_nntp_container="${NNTMUX_NATIVE_EVAL_FAKE_NNTP_CONTAINER:-nntmux-native-eval-first-lane-commit-fake-nntp}"
network_name="nntmux-native-eval_nntmux-native-eval"

if [[ ! -f "${env_file}" ]]; then
    echo "Missing ${env_file}; create it from .env.example or deploy-native-eval-compose.sh prerequisites first." >&2
    exit 2
fi

source native/scripts/native-eval-common.sh

compose=(docker compose --env-file "${env_file}" -f "${compose_file}" --profile native-workers)
db_name="$(env_value DB_DATABASE)"
db_root_password="$(env_value DB_ROOTPASSWORD)"

require_native_eval_database "first-lane native commit compose-service eval run"

cleanup() {
    docker rm -f "${fake_nntp_container}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

"${compose[@]}" config >/dev/null
mapfile -t compose_services < <("${compose[@]}" config --services)
for lane in ${lanes}; do
    case "${lane}" in
        binaries|backfill|releases) ;;
        *)
            echo "First-lane native commit eval supports only binaries, backfill, and releases; got ${lane}" >&2
            exit 2
            ;;
    esac

    service="native-${lane}-worker"
    if ! printf '%s\n' "${compose_services[@]}" | grep -Fxq "${service}"; then
        echo "Missing compose native worker service ${service} for lane ${lane}" >&2
        exit 2
    fi
done

"${compose[@]}" ps
"${compose[@]}" exec -T webapp test -x /opt/nntmux-native/nntmux-worker
docker network inspect "${network_name}" >/dev/null

docker rm -f "${fake_nntp_container}" >/dev/null 2>&1 || true
docker run -d --rm \
    --name "${fake_nntp_container}" \
    --network "${network_name}" \
    -v "${PWD}:/workspace" \
    -w /workspace/native \
    "${go_image}" \
    /usr/local/go/bin/go run ./cmd/nntmux-fake-nntp --listen :1119 >/dev/null
for _ in $(seq 1 60); do
    if docker logs "${fake_nntp_container}" 2>&1 | grep -Fq "fake NNTP listening"; then
        break
    fi
    sleep 1
done
if ! docker logs "${fake_nntp_container}" 2>&1 | grep -Fq "fake NNTP listening"; then
    docker logs "${fake_nntp_container}" >&2 || true
    echo "fake NNTP server did not start" >&2
    exit 1
fi

seed_for_lane() {
    local lane="$1"

    case "${lane}" in
        binaries)
            NNTMUX_NATIVE_EVAL_GROUP_NAME="alt.binaries.movies" \
            NNTMUX_NATIVE_EVAL_GROUP_FIRST_RECORD=0 \
            NNTMUX_NATIVE_EVAL_GROUP_LAST_RECORD=1000 \
            NNTMUX_NATIVE_EVAL_SHORT_GROUP_FIRST_RECORD=1 \
            NNTMUX_NATIVE_EVAL_SHORT_GROUP_LAST_RECORD=100000 \
                seed_eval_worker_data
            ;;
        backfill)
            NNTMUX_NATIVE_EVAL_GROUP_NAME="a.b.multimedia.movies" \
            NNTMUX_NATIVE_EVAL_GROUP_FIRST_RECORD=50000 \
            NNTMUX_NATIVE_EVAL_GROUP_LAST_RECORD=200000 \
            NNTMUX_NATIVE_EVAL_SHORT_GROUP_FIRST_RECORD=1 \
            NNTMUX_NATIVE_EVAL_SHORT_GROUP_LAST_RECORD=200000 \
                seed_eval_worker_data
            ;;
        releases)
            NNTMUX_NATIVE_EVAL_GROUP_NAME="alt.binaries.movies" \
            NNTMUX_NATIVE_EVAL_GROUP_FIRST_RECORD=0 \
            NNTMUX_NATIVE_EVAL_GROUP_LAST_RECORD=1000 \
            NNTMUX_NATIVE_EVAL_SHORT_GROUP_FIRST_RECORD=1 \
            NNTMUX_NATIVE_EVAL_SHORT_GROUP_LAST_RECORD=100000 \
                seed_eval_worker_data
            ;;
    esac
}

for lane in ${lanes}; do
    seed_for_lane "${lane}"
    configure_eval_lane "${lane}"

    service="native-${lane}-worker"
    echo "Running native commit ${service}"

    if ! output="$("${compose[@]}" run --rm \
        -e NNTMUX_NATIVE_WORKER_FIRST_LANE_COMMIT_ENABLED=true \
        -e NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=true \
        -e NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1 \
        -e NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1 \
        -e NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE=2 \
        -e NNTMUX_NATIVE_WORKER_BINARIES_MAX_MESSAGES=10000 \
        -e NNTMUX_NATIVE_WORKER_BINARIES_MAX_HEADERS=25000 \
        -e NNTMUX_NATIVE_WORKER_BACKFILL_QTY=75000 \
        -e NNTMUX_NATIVE_WORKER_BACKFILL_MAX_MESSAGES=20000 \
        -e NNTMUX_NATIVE_WORKER_BACKFILL_THREADS=1 \
        -e NNTMUX_NATIVE_WORKER_BACKFILL_GROUPS=1 \
        -e NNTMUX_NATIVE_WORKER_BACKFILL_DAYS=1 \
        -e NNTMUX_NATIVE_WORKER_BACKFILL_MIN_ARTICLES=100 \
        -e NNTP_SERVER="${fake_nntp_container}" \
        -e NNTP_PORT=1119 \
        -e NNTP_SSLENABLED=false \
        -e NNTP_USERNAME= \
        -e NNTP_PASSWORD= \
        "${service}" 2>&1)"; then
        printf "%s\n" "${output}" >&2
        exit 1
    fi

    printf "%s\n" "${output}"
    if ! grep -Fq "native lane commit completed ${lane}" <<<"${output}"; then
        echo "${service} did not report native lane commit completion for ${lane}" >&2
        exit 1
    fi
done

leftover_locks="$("${compose[@]}" exec -T redis sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort")"
if [[ -n "${leftover_locks}" ]]; then
    echo "Leftover distributed worker locks after first-lane native commit eval run:" >&2
    printf "%s\n" "${leftover_locks}" >&2
    exit 1
fi

echo "Native eval first-lane compose worker commits completed: ${lanes}"
