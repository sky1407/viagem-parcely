<?php

declare(strict_types=1);

namespace App\Repository;

use Closure;
use PDO;
use RuntimeException;

final class CadastralAreaRepository
{
    public function __construct(private readonly Closure $connect)
    {
    }

    public function all(): array
    {
        $pdo = ($this->connect)();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection factory must return PDO');
        }

        return $pdo->query(
            'SELECT c.code, c.name, m.name AS municipality
            FROM cadastral_area c
            JOIN municipality m ON m.code = c.municipality_code
            ORDER BY c.name COLLATE "cs-CZ-x-icu", c.code'
        )->fetchAll();
    }
}
