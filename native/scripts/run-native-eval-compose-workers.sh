#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

env_file="${NNTMUX_NATIVE_EVAL_ENV_FILE:-.env.native-eval}"
compose_file="${NNTMUX_NATIVE_EVAL_COMPOSE_FILE:-docker-compose.native-eval.yml}"
lanes="${NNTMUX_NATIVE_EVAL_LANES:-binaries backfill releases fixnames hashed-fixnames removecrap post-additional metadata-refresh post-tv post-movies post-amazon irc per-group}"
allow_real_leaves="${NNTMUX_NATIVE_EVAL_ALLOW_REAL_LEAVES:-0}"

if [[ ! -f "${env_file}" ]]; then
    echo "Missing ${env_file}; create it from .env.example or deploy-native-eval-compose.sh prerequisites first." >&2
    exit 2
fi

source native/scripts/native-eval-common.sh

compose=(docker compose --env-file "${env_file}" -f "${compose_file}" --profile native-workers)
db_name="$(env_value DB_DATABASE)"
db_root_password="$(env_value DB_ROOTPASSWORD)"
mapfile -t real_leaf_env_args < <(real_leaf_exec_environment_args)

require_native_eval_database "compose-service native worker eval run"

"${compose[@]}" config >/dev/null
mapfile -t compose_services < <("${compose[@]}" config --services)
for lane in ${lanes}; do
    service="native-${lane}-worker"
    if ! printf '%s\n' "${compose_services[@]}" | grep -Fxq "${service}"; then
        echo "Missing compose native worker service ${service} for lane ${lane}" >&2
        exit 2
    fi
done
"${compose[@]}" ps
"${compose[@]}" exec -T webapp test -x /opt/nntmux-native/nntmux-worker

seed_eval_worker_data

for lane in ${lanes}; do
    configure_eval_lane "${lane}"

    service="native-${lane}-worker"
    echo "Running ${service}"

    if ! output="$("${compose[@]}" run --rm "${real_leaf_env_args[@]}" "${service}" 2>&1)"; then
        printf "%s\n" "${output}" >&2
        exit 1
    fi

    printf "%s\n" "${output}"
    if ! grep -Fq "native lane completed ${lane}" <<<"${output}"; then
        echo "${service} did not report native lane completion for ${lane}" >&2
        exit 1
    fi
done

leftover_locks="$("${compose[@]}" exec -T redis sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort")"
if [[ -n "${leftover_locks}" ]]; then
    echo "Leftover distributed worker locks after compose-service native eval run:" >&2
    printf "%s\n" "${leftover_locks}" >&2
    exit 1
fi

echo "Native eval compose worker services completed: ${lanes}"
