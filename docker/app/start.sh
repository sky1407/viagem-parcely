#!/bin/sh
set -eu

if [ "${WARM_TILES:-0}" = "1" ]; then
    php bin/warm-tiles.php --workers="${WARM_WORKERS:-2}" --max-zoom="${WARM_MAX_ZOOM:-16}" &
fi

exec frankenphp run --config /etc/frankenphp/Caddyfile
