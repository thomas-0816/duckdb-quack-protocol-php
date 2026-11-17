#!/usr/bin/env bash

set -euo pipefail

tmpdir=$(mktemp -d)
mkfifo "${tmpdir}/http.in" "${tmpdir}/https.in"

# Hold the write ends open so each server keeps running after its startup
# command completes. This avoids the busy-waiting sleep_ms keepalive and lets
# both servers run in separate DuckDB processes.
exec 3<>"${tmpdir}/http.in"
exec 4<>"${tmpdir}/https.in"

printf "CALL quack_serve('quack:127.0.0.1:9494', token='secret');\n" >&3
printf "CALL quack_serve('quack:127.0.0.1:9495', token='secret', disable_ssl=false);\n" >&4

./tests/duckdb -c "install quack"
./tests/duckdb < "${tmpdir}/http.in" &
pid_http=$!
./tests/duckdb < "${tmpdir}/https.in" &
pid_https=$!

cleanup() {
    kill "${pid_http}" "${pid_https}" 2>/dev/null || true
    exec 3>&- 4>&-
    rm -rf "${tmpdir}"
}
trap cleanup EXIT INT TERM

# The CLI starts listening asynchronously; PHPUnit would otherwise race it and
# fail the first test with "couldn't connect to server".
wait_for_server() {
    local url=$1
    local deadline=$((SECONDS + 30))
    until curl -ks --max-time 1 -o /dev/null "${url}" 2>/dev/null; do
        if (( SECONDS >= deadline )); then
            echo "timed out waiting for ${url}" >&2
            return 1
        fi
        sleep 0.1
    done
}

wait_for_server "http://127.0.0.1:9494/quack"
wait_for_server "https://127.0.0.1:9495/quack"

status=0
vendor/bin/phpunit "$@" || status=$?
exit "${status}"
