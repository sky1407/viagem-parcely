<?php

declare(strict_types=1);

namespace App;

use PDO;

final class LazyConnection
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly array $config,
        private readonly bool $persistent = false,
    ) {
    }

    public function get(): PDO
    {
        return $this->pdo ??= Database::connect(...$this->config, persistent: $this->persistent);
    }
}
