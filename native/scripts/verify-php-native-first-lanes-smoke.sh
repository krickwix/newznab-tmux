#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

exec native/scripts/verify-php-native-lanes-smoke.sh "$@"
