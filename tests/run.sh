#!/usr/bin/env bash

set -euo pipefail

tmpdir=$(mktemp -d)
mkfifo "${tmpdir}/http.in" "${tmpdir}/https.in"

# Hold the write ends open so each server keeps running after its startup
# command completes. This avoids the busy-waiting sleep_ms keepalive and lets
# both servers run in separate DuckDB processes.
exec 3<>"${tmpdir}/http.in"
exec 4<>"${tmpdir}/https.in"

printf "CALL quack_serve('quack:127.0.0.1:9494', token='secret'); quack_serve('quack:127.0.0.1:9495', token='secret', disable_ssl=false);\n" >&3
printf "CALL quack_serve('quack:127.0.0.1:9495', token='secret', disable_ssl=false);\n" >&4

./tests/duckdb < "${tmpdir}/http.in" >/dev/null 2>&1 &
pid_http=$!
./tests/duckdb < "${tmpdir}/https.in" >/dev/null 2>&1 &
pid_https=$!

cleanup() {
    kill "${pid_http}" "${pid_https}" 2>/dev/null || true
    exec 3>&- 4>&-
    rm -rf "${tmpdir}"
}
trap cleanup EXIT INT TERM

status=0
vendor/bin/phpunit "$@" || status=$?
exit "${status}"
