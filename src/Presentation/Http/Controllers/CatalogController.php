<?php

declare(strict_types=1);

namespace Terrarium\Presentation\Http\Controllers;

use Terrarium\Application\DTO\ValidateConfigurationInput;
use Terrarium\Application\UseCases\Configurator\ValidateConfigurationUseCase;
use Terrarium\Infrastructure\Http\Request;
use Terrarium\Infrastructure\Http\Response;
use Terrarium\Infrastructure\Persistence\Repositories\CatalogRepository;
use Terrarium\Kernel\Application;

final class CatalogController
{
    private const PUBLIC_FIELDS = [
        'glass_size' => ['id', 'name', 'code', 'total_volume_ml', 'usable_volume_ml', 'max_plant_capacity', 'is_closed_ecosystem', 'price_cents', 'stock_quantity'],
        'plant' => ['id', 'name', 'scientific_name', 'volume_occupancy_ml', 'light_level', 'moisture_level', 'tolerates_closed_glass', 'price_cents', 'stock_quantity'],
        'stone' => ['id', 'name', 'type', 'volume_per_unit_ml', 'price_cents', 'stock_quantity'],
        'figure' => ['id', 'name', 'volume_occupancy_ml', 'price_cents', 'stock_quantity'],
    ];

    public function __construct(private readonly Application $app) {}

    public function index(Request $r): Response
    {
        $repo = $this->app->get(CatalogRepository::class);
        $out = [];
        foreach (self::PUBLIC_FIELDS as $type => $fields) {
            $out[$type . 's'] = array_map(fn ($row) => array_intersect_key($row, array_flip($fields)), $repo->listRows($type, true));
        }
        $out['currency'] = $this->app->config->get('app.currency', 'IRR');
        $out['shipping_cents'] = (int) $this->app->config->get('app.shipping_flat_rate', 0);
        $out['payment_gateways'] = $this->app->availableGateways();
        return Response::json(['success' => true, 'data' => $out]);
    }

    public function validate(Request $r): Response
    {
        $useCase = $this->app->get(ValidateConfigurationUseCase::class);
        $out = $useCase->execute(ValidateConfigurationInput::fromArray($r->body));
        return Response::json(['success' => true, 'data' => ValidateConfigurationUseCase::present($out)]);
    }
}
