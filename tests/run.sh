#!/usr/bin/env bash

set -euo pipefail

./tests/duckdb -no-stdin --cmd "CALL quack_serve('quack:127.0.0.1:9494', token='secret'); CALL quack_serve('quack:127.0.0.1:9495', token='secret', disable_ssl=false); SELECT sleep_ms(30000);" &
pid=$!

status=0
vendor/bin/phpunit "$@" || status=$?
kill -9 "$pid" 2>/dev/null || true
exit "$status"
