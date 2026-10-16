<?php

declare(strict_types=1);

namespace App\Http;

final class JsonResponse
{
    public static function send(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function error(string $message, int $status): void
    {
        self::send(['error' => $message], $status);
    }
}
