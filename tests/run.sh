#!/usr/bin/env bash

set -euo pipefail

tmpdir=$(mktemp -d)

./tests/duckdb -dark-mode -c "INSTALL quack; INSTALL httpfs;"

sql="CALL quack_serve('quack:127.0.0.1:9494', token='secret'); CALL quack_serve('quack:127.0.0.1:9495', token='secret', disable_ssl=false);"
bash -c 'echo $$ > "$2"; printf "%s\n" "$1"; exec sleep 3600' _ "${sql}" "${tmpdir}/http.feed" </dev/null | ./tests/duckdb -dark-mode &
pid_http=$!

cleanup() {
    kill "${pid_http}" || true
    if [ -s "${tmpdir}/http.feed" ]; then
        kill "$(cat "${tmpdir}/http.feed")" || true
    fi
    rm -rf "${tmpdir}"
}
trap cleanup EXIT INT TERM

wait_for_server() {
    local url=$1
    local deadline=$((SECONDS + 60))
    until curl -ks --max-time 2 "${url}" >/dev/null 2>&1; do
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
