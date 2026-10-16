<?php

declare(strict_types=1);

namespace App;

use App\Http\JsonResponse;
use Closure;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, Closure $handler): void
    {
        $this->routes[] = ['GET', '#^' . $pattern . '$#', $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        $allowedMethods = [];

        foreach ($this->routes as [$routeMethod, $regex, $handler]) {
            if (preg_match($regex, $path, $matches) !== 1) {
                continue;
            }
            if ($routeMethod !== $method) {
                $allowedMethods[] = $routeMethod;
                continue;
            }

            $handler(...array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
            return;
        }

        if ($allowedMethods !== []) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
            JsonResponse::error('Method not allowed', 405);
            return;
        }

        JsonResponse::error('Not found', 404);
    }
}
