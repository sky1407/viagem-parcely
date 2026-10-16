<?php

declare(strict_types=1);

namespace App\Repository;

use App\LazyConnection;

final class CadastralAreaRepository
{
    public function __construct(private readonly LazyConnection $connection)
    {
    }

    public function all(): array
    {
        return $this->connection->get()->query(
            'SELECT c.code, c.name, m.name AS municipality
            FROM cadastral_area c
            JOIN municipality m ON m.code = c.municipality_code
            ORDER BY c.name COLLATE "cs-CZ-x-icu", c.code'
        )->fetchAll();
    }
}
