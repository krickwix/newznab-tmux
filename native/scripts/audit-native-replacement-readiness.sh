#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

docker compose -f docker-compose.native-test.yml run --rm go-test sh -c '
set -eu

go_bin="${NNTMUX_NATIVE_GO_BIN:-/usr/local/go/bin/go}"
worker_bin="${NNTMUX_NATIVE_READINESS_AUDIT_BIN:-/tmp/nntmux-worker-readiness-audit}"
"$go_bin" build -o "$worker_bin" ./cmd/nntmux-worker

has_forbidden_detail() {
    case "$1" in
        *"--mysql-dsn"*|*"mysql_dsn"*|*"NNTMUX_NATIVE_MYSQL_DSN"*|\
        *"--redis-addr"*|*"redis_addr"*|*"NNTMUX_NATIVE_REDIS_ADDR"*|\
        *"lock-owner"*|*"lock_owner"*|*"NNTMUX_NATIVE_LOCK_OWNER"*|\
        *"redis_key"*|*"nntmux_database"*|*"nntmux-cache"*|\
        *"nntmux:distributed-worker"*|*"native_worker"*|*"command_names"*|\
        *"\"lock\""*|*"\"commands\""*|*"\"arguments\""*|*"\"native_args\""*)
            return 0
            ;;
    esac

    return 1
}

expected_blocker() {
    case "$1" in
        backfill)
            printf "%s\n" "production backfill acquisition, full header persistence, and cursor ownership remain PHP-owned"
            ;;
        binaries)
            printf "%s\n" "production binary header acquisition, full header persistence, and cursor ownership remain PHP-owned"
            ;;
        fixnames)
            printf "%s\n" "remaining regular fix-name methods are deferred to PHP"
            ;;
        hashed-fixnames)
            printf "%s\n" "release rename, category, event, and search side effects remain PHP-owned"
            ;;
        irc)
            printf "%s\n" "native IRC replacement still requires live deployment verification"
            ;;
        metadata-refresh)
            printf "%s\n" "metadata-refresh embedded hashed fix-name commands are deferred to PHP"
            ;;
        per-group)
            printf "%s\n" "group update, backfill, release creation, and post-processing side effects remain PHP-owned"
            ;;
        post-additional)
            printf "%s\n" "additional/NFO provider processing, NNTP/NZB/NFO reads, release events, and deferred metadata-refresh/hashed-fixnames side effects remain PHP-owned"
            ;;
        post-amazon|post-movies|post-tv)
            printf "%s\n" "metadata-provider lookups, NZB/NFO reads, release events, and full postprocess side effects remain PHP-owned"
            ;;
        releases)
            printf "%s\n" "full release creation, categorization, and release-processing side effects remain PHP-owned"
            ;;
        removecrap)
            printf "%s\n" "removecrap production commit requires live rollout proof"
            ;;
        *)
            printf "%s\n" "native replacement behavior has not been proven"
            ;;
    esac
}

failed=0
for plan in ../tests/Fixtures/native-worker/catalog/*.json; do
    lane="$(basename "$plan" .json)"
    stdout_path="$(mktemp)"
    stderr_path="$(mktemp)"

    set +e
    "$worker_bin" \
        --plan "$plan" \
        --dry-run \
        --output=json \
        --require-replacement-ready >"$stdout_path" 2>"$stderr_path"
    status="$?"
    set -e
    stdout="$(cat "$stdout_path")"
    stderr="$(cat "$stderr_path")"
    rm -f "$stdout_path" "$stderr_path"

    if [ "$status" -eq 0 ]; then
        echo "${lane}: unexpectedly passed replacement readiness" >&2
        failed=1
        continue
    fi

    if [ "$status" -ne 2 ]; then
        echo "${lane}: replacement readiness guard exited ${status}, want 2" >&2
        failed=1
        continue
    fi

    if [ -n "$stdout" ]; then
        echo "${lane}: replacement readiness wrote stdout before failing closed" >&2
        failed=1
        continue
    fi

    case "$stderr" in
        "${lane} catalog is not replacement-ready"*) ;;
        *)
            echo "${lane}: missing exact replacement readiness blocker prefix" >&2
            failed=1
            continue
            ;;
    esac

    blocker="$(expected_blocker "$lane")"
    case "$stderr" in
        *"$blocker"*) ;;
        *)
            echo "${lane}: missing expected replacement readiness blocker: ${blocker}" >&2
            failed=1
            continue
            ;;
    esac

    if has_forbidden_detail "$stderr"; then
        echo "${lane}: replacement readiness stderr leaked sensitive/internal execution detail" >&2
        failed=1
        continue
    fi

    echo "${lane}: replacement guard ok"
done

exit "$failed"
'
