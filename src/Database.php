<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(string $host, int $port, string $name, string $user, string $password): PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $name);

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
