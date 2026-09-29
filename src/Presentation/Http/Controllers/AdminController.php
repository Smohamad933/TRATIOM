<?php

declare(strict_types=1);

namespace Terrarium\Presentation\Http\Controllers;

use Terrarium\Application\UseCases\Admin\AdminService;
use Terrarium\Infrastructure\Http\Request;
use Terrarium\Infrastructure\Http\Response;
use Terrarium\Infrastructure\Persistence\Database;
use Terrarium\Infrastructure\Persistence\Migrator;
use Terrarium\Kernel\Application;
use Throwable;

final class AdminController
{
    public function __construct(private readonly Application $app) {}

    private function svc(): AdminService
    {
        return $this->app->get(AdminService::class);
    }

    private function ok(mixed $data, int $status = 200): Response
    {
        return Response::json(['success' => true, 'data' => $data], $status);
    }

    public function dashboard(Request $r): Response { return $this->ok($this->svc()->dashboard()); }
    public function catalog(Request $r): Response { return $this->ok($this->svc()->catalog()); }
    public function createItem(Request $r): Response { return $this->ok($this->svc()->createItem($r->params['type'], $r->body), 201); }
    public function updateItem(Request $r): Response { return $this->ok($this->svc()->updateItem($r->params['type'], $r->params['id'], $r->body)); }
    public function rules(Request $r): Response { return $this->ok($this->svc()->rules()); }
    public function createRule(Request $r): Response { return $this->ok($this->svc()->createRule($r->body), 201); }

    public function toggleRule(Request $r): Response
    {
        $this->svc()->setRuleActive($r->params['id'], filter_var($r->input('active', true), FILTER_VALIDATE_BOOLEAN));
        return $this->ok(null);
    }

    public function deleteRule(Request $r): Response
    {
        $this->svc()->deleteRule($r->params['id']);
        return $this->ok(null);
    }

    public function orders(Request $r): Response
    {
        return $this->ok($this->svc()->orders(
            is_string($r->input('status')) ? $r->input('status') : null,
            is_string($r->input('q')) ? trim($r->input('q')) : null,
            max(1, (int) $r->input('page', 1))
        ));
    }

    public function order(Request $r): Response { return $this->ok($this->svc()->order($r->params['id'])); }

    public function changeStatus(Request $r): Response
    {
        return $this->ok($this->svc()->changeOrderStatus($r->params['id'], (string) $r->input('status', '')));
    }

    public function system(Request $r): Response
    {
        $gateways = [];
        foreach (['zarinpal', 'zibal', 'idpay', 'stripe'] as $g) {
            $inst = $this->app->gatewayInstance($g);
            $gateways[] = [
                'name' => $g,
                'configured' => $inst->isConfigured(),
                'enabled' => in_array($g, (array) $this->app->config->get('payment.enabled', []), true),
                'sandbox' => (bool) $this->app->config->get("payment.gateways.{$g}.sandbox", false),
            ];
        }
        try {
            $db = $this->app->get(Database::class);
            $db->first('SELECT 1 AS ok');
            $dbInfo = ['status' => 'up', 'driver' => $db->driver(), 'pending_migrations' => $this->app->get(Migrator::class)->pending()];
        } catch (Throwable $e) {
            $dbInfo = ['status' => 'down'];
        }
        return $this->ok([
            'environment' => $this->app->config->get('app.env'),
            'debug' => $this->app->debug(),
            'app_url' => $this->app->config->get('app.url'),
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI,
            'extensions' => array_map(fn ($e) => ['name' => $e, 'loaded' => extension_loaded($e)], ['pdo_mysql', 'curl', 'mbstring', 'openssl', 'json']),
            'database' => $dbInfo,
            'storage_writable' => is_writable($this->app->basePath . '/storage/logs'),
            'sms_provider' => $this->app->config->get('sms.default'),
            'gateways' => $gateways,
            'available_gateways' => $this->app->availableGateways(),
        ]);
    }
}
