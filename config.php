<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 5433),
        'name' => getenv('DB_NAME') ?: 'parcels',
        'user' => getenv('DB_USER') ?: 'parcels',
        'password' => getenv('DB_PASSWORD') ?: 'parcels',
    ],
    'tile_cache_dir' => __DIR__ . '/storage/tiles',
];
