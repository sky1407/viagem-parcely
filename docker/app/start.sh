#!/bin/sh
set -eu

if [ "${WARM_TILES:-0}" = "1" ]; then
    php bin/warm-tiles.php --workers="${WARM_WORKERS:-2}" &
fi

exec frankenphp run --config /etc/frankenphp/Caddyfile
