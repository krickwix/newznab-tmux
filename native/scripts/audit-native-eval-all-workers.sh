#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

env_file="${NNTMUX_NATIVE_EVAL_ENV_FILE:-.env.native-eval}"
compose_file="${NNTMUX_NATIVE_EVAL_COMPOSE_FILE:-docker-compose.native-eval.yml}"
lock_seconds="${NNTMUX_NATIVE_EVAL_LOCK_SECONDS:-42}"
lanes="${NNTMUX_NATIVE_EVAL_LANES:-binaries backfill releases fixnames hashed-fixnames removecrap post-additional metadata-refresh post-tv post-movies post-amazon irc per-group}"

if [[ ! -f "${env_file}" ]]; then
    echo "Missing ${env_file}; create it from .env.example or deploy-native-eval-compose.sh prerequisites first." >&2
    exit 2
fi

source native/scripts/native-eval-common.sh

compose=(docker compose --env-file "${env_file}" -f "${compose_file}")
webapp_exec=("${compose[@]}" exec -T -e NNTMUX_NATIVE_WORKER_POST_ADDITIONAL_DEFERRED_EXECUTION_ENABLED=true webapp)
db_name="$(env_value DB_DATABASE)"
db_root_password="$(env_value DB_ROOTPASSWORD)"

require_native_eval_database "all-worker audit"

"${compose[@]}" config >/dev/null
"${webapp_exec[@]}" test -x /opt/nntmux-native/nntmux-worker

db_mode_for_lane() {
    case "$1" in
        binaries|backfill|releases|hashed-fixnames|metadata-refresh|removecrap|post-additional|post-tv|post-movies|post-amazon|per-group)
            printf 'db'
            ;;
        *)
            printf 'no-db'
            ;;
    esac
}

seed_eval_worker_data

for lane in ${lanes}; do
    db_mode="$(db_mode_for_lane "${lane}")"
    configure_eval_lane "${lane}"

    "${webapp_exec[@]}" sh -lc '
        set -eu

        lane="$1"
        lock_seconds="$2"
        db_mode="$3"
        plan_file="/tmp/nntmux-native-eval-plan-${lane}.json"
        report_file="/tmp/nntmux-native-eval-report-${lane}.json"
        extra_flags=""

        php artisan nntmux:worker "$lane" --native-plan --lock-seconds="$lock_seconds" > "$plan_file"
        php -r '"'"'
            $plan = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
            $job = $plan["job"] ?? [];
            printf(
                "%s plan: enabled=%s commands=%d sleep=%s reason=%s\n",
                (string) ($job["name"] ?? "unknown"),
                ($job["enabled"] ?? false) ? "true" : "false",
                count($plan["commands"] ?? []),
                (string) ($job["sleep"] ?? ""),
                (string) ($job["disabled_reason"] ?? "")
            );
            $enabled = (bool) ($job["enabled"] ?? false);
            if (! $enabled) {
                fwrite(STDERR, sprintf(
                    "%s disabled: %s\n",
                    (string) ($job["name"] ?? "unknown"),
                    (string) ($job["disabled_reason"] ?? "unknown")
                ));
                exit(2);
            }
        '"'"' "$plan_file"

        command_count="$(php -r '"'"'
            $plan = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
            echo count($plan["commands"] ?? []);
        '"'"' "$plan_file")"

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
        esac

        if [ "$command_count" -gt 0 ] && [ "$db_mode" = "db" ]; then
            if [ -z "${NNTMUX_NATIVE_WORKER_MYSQL_DSN:-}" ]; then
                echo "${lane} requires NNTMUX_NATIVE_WORKER_MYSQL_DSN for native dry-run audit" >&2
                exit 2
            fi

            NNTMUX_NATIVE_MYSQL_DSN="${NNTMUX_NATIVE_WORKER_MYSQL_DSN}" \
                /opt/nntmux-native/nntmux-worker \
                    --plan "$plan_file" \
                    --dry-run \
                    --output=json \
                    --mysql-dsn-env \
                    ${extra_flags} \
                    > "$report_file"
        else
            /opt/nntmux-native/nntmux-worker \
                --plan "$plan_file" \
                --dry-run \
                --output=json \
                ${extra_flags} \
                > "$report_file"
        fi

        php -r '"'"'
            $report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
            $lane = (string) $argv[2];
            $nativeWorker = $report["native_worker"] ?? null;
            if (! is_array($nativeWorker)) {
                fwrite(STDERR, "{$lane} native dry-run report is missing native_worker\n");
                exit(1);
            }
            if (($report["dry_run"] ?? null) !== true) {
                fwrite(STDERR, "{$lane} native dry-run report did not set dry_run=true\n");
                exit(1);
            }
            if (($nativeWorker["job"] ?? null) !== $lane) {
                fwrite(STDERR, "{$lane} native dry-run report job mismatch\n");
                exit(1);
            }
            if (($nativeWorker["writes"] ?? null) !== 0) {
                fwrite(STDERR, "{$lane} native dry-run report wrote unexpectedly\n");
                exit(1);
            }
            if (! array_key_exists("replacement_ready", $nativeWorker) || $nativeWorker["replacement_ready"] !== false) {
                fwrite(STDERR, "{$lane} native dry-run report is missing replacement_ready=false\n");
                exit(1);
            }
            $readiness = $nativeWorker["replacement_readiness"] ?? null;
            $blockers = is_array($readiness) ? ($readiness["blockers"] ?? null) : null;
            if (! is_array($blockers) || count($blockers) === 0) {
                fwrite(STDERR, "{$lane} native dry-run report is missing replacement readiness blockers\n");
                exit(1);
            }
            printf("%s native dry-run ok replacement_ready=false blockers=%d\n", $lane, count($blockers));
        '"'"' "$report_file" "$lane"
    ' sh "${lane}" "${lock_seconds}" "${db_mode}"
done

echo "Native eval all-worker plan audit completed: ${lanes}"
