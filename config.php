<?php

declare(strict_types=1);

$db = ['host' => '127.0.0.1', 'port' => 5433, 'name' => 'parcels', 'user' => 'parcels', 'password' => 'parcels'];

$databaseUrl = getenv('DATABASE_URL');
if (is_string($databaseUrl) && $databaseUrl !== '') {
    $url = parse_url($databaseUrl);
    if ($url === false || !isset($url['host'], $url['path'], $url['user'])) {
        throw new RuntimeException('DATABASE_URL must look like postgresql://user:password@host:port/database');
    }
    $db = [
        'host' => $url['host'],
        'port' => $url['port'] ?? 5432,
        'name' => ltrim($url['path'], '/'),
        'user' => rawurldecode($url['user']),
        'password' => rawurldecode($url['pass'] ?? ''),
    ];
}

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: $db['host'],
        'port' => (int) (getenv('DB_PORT') ?: $db['port']),
        'name' => getenv('DB_NAME') ?: $db['name'],
        'user' => getenv('DB_USER') ?: $db['user'],
        'password' => getenv('DB_PASSWORD') ?: $db['password'],
    ],
    'tile_cache_dir' => __DIR__ . '/storage/tiles',
];
