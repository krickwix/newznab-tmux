#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

env_file="${NNTMUX_NATIVE_EVAL_ENV_FILE:-.env.native-eval}"
compose_file="${NNTMUX_NATIVE_EVAL_COMPOSE_FILE:-docker-compose.native-eval.yml}"
run_first_lanes="${NNTMUX_NATIVE_EVAL_RUN_FIRST_LANES:-0}"
run_all_workers="${NNTMUX_NATIVE_EVAL_RUN_ALL_WORKERS:-0}"
run_compose_workers="${NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS:-0}"

if [[ ! -f "${env_file}" ]]; then
    echo "Missing ${env_file}; create it from .env.example or sync NNTP settings first." >&2
    exit 2
fi

if [[ "${run_first_lanes}" != "0" && "${run_first_lanes}" != "1" ]]; then
    echo "NNTMUX_NATIVE_EVAL_RUN_FIRST_LANES must be 0 or 1; got ${run_first_lanes}" >&2
    exit 2
fi

if [[ "${run_all_workers}" != "0" && "${run_all_workers}" != "1" ]]; then
    echo "NNTMUX_NATIVE_EVAL_RUN_ALL_WORKERS must be 0 or 1; got ${run_all_workers}" >&2
    exit 2
fi

if [[ "${run_compose_workers}" != "0" && "${run_compose_workers}" != "1" ]]; then
    echo "NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS must be 0 or 1; got ${run_compose_workers}" >&2
    exit 2
fi

selected_runners=0
for runner_enabled in "${run_first_lanes}" "${run_all_workers}" "${run_compose_workers}"; do
    if [[ "${runner_enabled}" == "1" ]]; then
        selected_runners=$((selected_runners + 1))
    fi
done

if (( selected_runners > 1 )); then
    echo "NNTMUX_NATIVE_EVAL_RUN_FIRST_LANES, NNTMUX_NATIVE_EVAL_RUN_ALL_WORKERS, and NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS are mutually exclusive." >&2
    exit 2
fi

source native/scripts/native-eval-common.sh

compose=(docker compose --env-file "${env_file}" -f "${compose_file}")
native_compose=(docker compose -f docker-compose.native-test.yml --profile native-worker-smoke)
binary_dir="storage/native-eval"
binary_path="${binary_dir}/nntmux-worker"

app_port="$(env_value APP_PORT)"
db_username="$(env_value DB_USERNAME)"
db_password="$(env_value DB_PASSWORD)"
db_name="$(env_value DB_DATABASE)"
db_root_password="$(env_value DB_ROOTPASSWORD)"
manticore_http_port="$(env_value FORWARD_MANTICORE_HTTP_PORT)"

mkdir -p "${binary_dir}"

"${native_compose[@]}" build native-worker
container="$(docker create nntmux-native-worker:dev)"
cleanup() {
    docker rm -f "${container}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker cp "${container}:/usr/local/bin/nntmux-worker" "${binary_path}"
chmod 755 "${binary_path}"

"${compose[@]}" config >/dev/null
"${compose[@]}" up -d --build --wait webapp mariadb redis manticore mailpit
"${compose[@]}" ps

curl -fsS "http://127.0.0.1:${app_port:-18080}/" >/dev/null
curl -fsS "http://127.0.0.1:${app_port:-18080}/status" >/dev/null
"${compose[@]}" exec -T redis redis-cli ping
"${compose[@]}" exec -T mariadb mariadb-admin ping -h 127.0.0.1 -u"${db_username}" -p"${db_password}" --silent
curl -fsS "http://127.0.0.1:${manticore_http_port:-19308}/" >/dev/null

require_native_eval_database "native eval compose deploy"
seed_eval_worker_data
configure_eval_lane metadata-refresh

"${compose[@]}" exec -T webapp php artisan nntmux:worker --list
"${compose[@]}" exec -T webapp sh -lc '
    php artisan nntmux:worker metadata-refresh --native-plan --lock-seconds=42 > /tmp/nntmux-native-deploy-plan.json
    php -r '"'"'
        $plan = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        $job = $plan["job"] ?? [];
        if (($job["enabled"] ?? false) !== true || count($plan["commands"] ?? []) < 1) {
            fwrite(STDERR, sprintf(
                "metadata-refresh deploy smoke resolved disabled/no-op plan: enabled=%s commands=%d reason=%s\n",
                ($job["enabled"] ?? false) ? "true" : "false",
                count($plan["commands"] ?? []),
                (string) ($job["disabled_reason"] ?? "")
            ));
            exit(2);
        }
    '"'"' /tmp/nntmux-native-deploy-plan.json
    /opt/nntmux-native/nntmux-worker --plan /tmp/nntmux-native-deploy-plan.json --dry-run
'

if [[ "${run_first_lanes}" == "1" ]]; then
    env \
        NNTMUX_NATIVE_EVAL_ENV_FILE="${env_file}" \
        NNTMUX_NATIVE_EVAL_COMPOSE_FILE="${compose_file}" \
        native/scripts/run-native-eval-first-lanes.sh
fi

if [[ "${run_all_workers}" == "1" ]]; then
    env \
        NNTMUX_NATIVE_EVAL_ENV_FILE="${env_file}" \
        NNTMUX_NATIVE_EVAL_COMPOSE_FILE="${compose_file}" \
        native/scripts/run-native-eval-all-workers.sh
fi

if [[ "${run_compose_workers}" == "1" ]]; then
    env \
        NNTMUX_NATIVE_EVAL_ENV_FILE="${env_file}" \
        NNTMUX_NATIVE_EVAL_COMPOSE_FILE="${compose_file}" \
        native/scripts/run-native-eval-compose-workers.sh
fi

echo "NNTmux native eval stack is ready at http://127.0.0.1:${app_port:-18080}"
