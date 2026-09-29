<?php

declare(strict_types=1);

namespace Terrarium\Presentation\Http\Controllers;

use Terrarium\Infrastructure\Http\Request;
use Terrarium\Infrastructure\Http\Response;
use Terrarium\Infrastructure\Persistence\Database;
use Terrarium\Infrastructure\Persistence\Migrator;
use Terrarium\Kernel\Application;
use Throwable;

final class SystemController
{
    public function __construct(private readonly Application $app) {}

    /** Real health check: DB connectivity, pending migrations, writable storage. */
    public function health(Request $r): Response
    {
        $checks = [];
        $ok = true;

        try {
            $start = microtime(true);
            $this->app->get(Database::class)->first('SELECT 1 AS ok');
            $checks['database'] = ['status' => 'up', 'driver' => $this->app->get(Database::class)->driver(), 'latency_ms' => round((microtime(true) - $start) * 1000, 1)];
            $pending = $this->app->get(Migrator::class)->pending();
            $checks['migrations'] = ['status' => $pending ? 'pending' : 'up', 'pending' => $pending];
            $ok = $ok && !$pending;
        } catch (Throwable $e) {
            $ok = false;
            $checks['database'] = ['status' => 'down'] + ($this->app->debug() ? ['error' => $e->getMessage()] : []);
        }

        $logs = $this->app->basePath . '/storage/logs';
        $writable = is_dir($logs) && is_writable($logs);
        $checks['storage'] = ['status' => $writable ? 'up' : 'not_writable'];
        $ok = $ok && $writable;

        return Response::json([
            'status' => $ok ? 'UP' : 'DEGRADED',
            'timestamp' => date('c'),
            'checks' => $checks,
            'php_version' => PHP_VERSION,
        ], $ok ? 200 : 503);
    }

    public function info(Request $r): Response
    {
        return Response::json([
            'project' => 'Terrarium Configurator API',
            'version' => '2.0.0',
            'endpoints' => [
                'health' => '/health',
                'catalog' => '/api/v1/catalog',
                'storefront' => '/',
                'admin' => '/admin/',
            ],
        ]);
    }
}
