#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

env_file="${NNTMUX_NATIVE_EVAL_ENV_FILE:-.env.native-eval}"
compose_file="${NNTMUX_NATIVE_EVAL_COMPOSE_FILE:-docker-compose.native-eval.yml}"
lock_seconds="${NNTMUX_NATIVE_EVAL_LOCK_SECONDS:-900}"
lanes="${NNTMUX_NATIVE_EVAL_LANES:-binaries backfill releases}"
allow_real_leaves="${NNTMUX_NATIVE_EVAL_ALLOW_REAL_LEAVES:-0}"

if [[ ! -f "${env_file}" ]]; then
    echo "Missing ${env_file}; create it from .env.example or deploy-native-eval-compose.sh prerequisites first." >&2
    exit 2
fi

source native/scripts/native-eval-common.sh

compose=(docker compose --env-file "${env_file}" -f "${compose_file}")
db_name="$(env_value DB_DATABASE)"
db_root_password="$(env_value DB_ROOTPASSWORD)"
mapfile -t real_leaf_env_args < <(real_leaf_exec_environment_args)
webapp_exec=("${compose[@]}" exec -T "${real_leaf_env_args[@]}" webapp)

require_native_eval_database "first-lane eval run"

"${compose[@]}" config >/dev/null
"${compose[@]}" ps
"${webapp_exec[@]}" test -x /opt/nntmux-native/nntmux-worker

if [[ "${allow_real_leaves}" != "1" ]]; then
    "${webapp_exec[@]}" sh -lc '
        if [ "${NNTMUX_NATIVE_LEAF_STARTUP_SMOKE:-}" != "1" ]; then
            echo "NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=1 is required for eval first-lane smoke; set NNTMUX_NATIVE_EVAL_ALLOW_REAL_LEAVES=1 to run real leaves." >&2
            exit 2
        fi
    '
fi

seed_eval_worker_data

for lane in ${lanes}; do
    configure_eval_lane "${lane}"

    "${webapp_exec[@]}" sh -lc '
        lane="$1"
        lock_seconds="$2"
        plan="$(php artisan nntmux:worker "$lane" --native-plan --lock-seconds="$lock_seconds")"
        printf "%s\n" "$plan" | php -r '"'"'
            $plan = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            $job = $plan["job"] ?? [];
            $enabled = (bool) ($job["enabled"] ?? false);
            printf(
                "%s plan: enabled=%s commands=%d sleep=%s\n",
                (string) ($job["name"] ?? "unknown"),
                $enabled ? "true" : "false",
                count($plan["commands"] ?? []),
                (string) ($job["sleep"] ?? "")
            );
            if (! $enabled) {
                fwrite(STDERR, sprintf(
                    "%s disabled: %s\n",
                    (string) ($job["name"] ?? "unknown"),
                    (string) ($job["disabled_reason"] ?? "unknown")
                ));
                exit(2);
            }
        '"'"'
    ' sh "${lane}" "${lock_seconds}"

    "${webapp_exec[@]}" \
        php artisan nntmux:worker "${lane}" --once --stop-on-disabled --lock-seconds="${lock_seconds}"
done

leftover_locks="$("${compose[@]}" exec -T redis sh -lc "redis-cli --scan --pattern '*nntmux:distributed-worker*' | sort")"
if [[ -n "${leftover_locks}" ]]; then
    echo "Leftover distributed worker locks after native eval run:" >&2
    printf "%s\n" "${leftover_locks}" >&2
    exit 1
fi

echo "Native eval first lanes completed: ${lanes}"
