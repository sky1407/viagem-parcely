<?php

declare(strict_types=1);

namespace Tests;

use App\Router;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class RouterTest extends TestCase
{
    public function testPassesNamedGroupsAsArguments(): void
    {
        $received = null;
        $router = new Router();
        $router->get('/tiles/(?<z>\d+)/(?<x>\d+)/(?<y>\d+)\.pbf', function (string $z, string $x, string $y) use (&$received): void {
            $received = [$z, $x, $y];
        });

        $router->dispatch('GET', '/tiles/14/8890/5525.pbf');

        self::assertSame(['14', '8890', '5525'], $received);
    }

    public function testNamedArgumentsDoNotDependOnGroupOrder(): void
    {
        $received = null;
        $router = new Router();
        $router->get('/(?<y>\d+)/(?<x>\d+)', function (string $x, string $y) use (&$received): void {
            $received = ['x' => $x, 'y' => $y];
        });

        $router->dispatch('GET', '/2/1');

        self::assertSame(['x' => '1', 'y' => '2'], $received);
    }

    public function testFirstMatchingRouteWins(): void
    {
        $called = [];
        $router = new Router();
        $router->get('/api/parcels/search', function () use (&$called): void {
            $called[] = 'search';
        });
        $router->get('/api/parcels/(?<id>\d+)', function (string $id) use (&$called): void {
            $called[] = 'show';
        });

        $router->dispatch('GET', '/api/parcels/search');

        self::assertSame(['search'], $called);
    }

    public function testHeadRequestUsesGetRoute(): void
    {
        $called = false;
        $router = new Router();
        $router->get('/api/health', function () use (&$called): void {
            $called = true;
        });

        $router->dispatch('HEAD', '/api/health');

        self::assertTrue($called);
    }

    public function testPatternIsAnchored(): void
    {
        $router = new Router();
        $router->get('/api/health', function (): void {
            self::fail('Route must not match a longer path');
        });

        $this->expectOutputString('{"error":"Not found"}');
        $router->dispatch('GET', '/api/health/extra');

        self::assertSame(404, http_response_code());
    }

    public function testPostRouteRunsOnlyForPost(): void
    {
        $called = false;
        $router = new Router();
        $router->post('/api/parcels/intersect', function () use (&$called): void {
            $called = true;
        });

        $router->dispatch('POST', '/api/parcels/intersect');
        self::assertTrue($called);

        $called = false;
        $this->expectOutputString('{"error":"Method not allowed"}');
        $router->dispatch('GET', '/api/parcels/intersect');
        self::assertFalse($called);
        self::assertSame(405, http_response_code());
    }

    public function testReturnsNotFoundForUnknownPath(): void
    {
        $router = new Router();

        $this->expectOutputString('{"error":"Not found"}');
        $router->dispatch('GET', '/unknown');

        self::assertSame(404, http_response_code());
    }

    public function testReturnsMethodNotAllowedForKnownPathWithOtherMethod(): void
    {
        $router = new Router();
        $router->get('/api/health', function (): void {
            self::fail('GET handler must not run for POST');
        });

        $this->expectOutputString('{"error":"Method not allowed"}');
        $router->dispatch('POST', '/api/health');

        self::assertSame(405, http_response_code());
    }
}
