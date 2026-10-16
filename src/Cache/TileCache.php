<?php

declare(strict_types=1);

namespace App\Cache;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class TileCache
{
    public function __construct(private readonly string $dir)
    {
    }

    public function get(int $z, int $x, int $y): ?string
    {
        $path = $this->path($z, $x, $y);
        $tile = is_file($path) ? file_get_contents($path) : false;

        return $tile === false ? null : $tile;
    }

    public function put(int $z, int $x, int $y, string $tile): void
    {
        $path = $this->path($z, $x, $y);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }

        $temporary = tempnam($dir, 'tile');
        if ($temporary === false) {
            throw new RuntimeException("Cannot create temporary file in {$dir}");
        }

        if (file_put_contents($temporary, $tile) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Cannot write tile {$path}");
        }
    }

    public function clear(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $trash = $this->dir . '.old-' . bin2hex(random_bytes(4));
        if (!rename($this->dir, $trash)) {
            throw new RuntimeException("Cannot move tile cache {$this->dir}");
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($trash, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $removed = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            if (!$removed) {
                throw new RuntimeException("Cannot remove {$item->getPathname()}");
            }
        }

        if (!rmdir($trash)) {
            throw new RuntimeException("Cannot remove {$trash}");
        }
    }

    private function path(int $z, int $x, int $y): string
    {
        return sprintf('%s/%d/%d/%d.pbf.gz', $this->dir, $z, $x, $y);
    }
}
