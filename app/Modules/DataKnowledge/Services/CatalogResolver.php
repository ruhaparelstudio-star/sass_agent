<?php

namespace App\Modules\DataKnowledge\Services;

use App\Models\ServiceCatalog;
use App\Modules\DataKnowledge\Support\KnowledgeWindowScope;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CatalogResolver
{
    public function __construct(
        private readonly KnowledgeWindowScope $windowScope,
        private readonly KnowledgeVersionResolver $knowledgeVersionResolver,
    ) {}

    public function resolveCatalog(int $tenantId, CarbonInterface $at): array
    {
        $this->assertTenantId($tenantId);
        if ($this->knowledgeVersionResolver->resolveActiveVersion($tenantId, $at) === null) {
            return [];
        }

        $rows = $this->windowScope
            ->apply(ServiceCatalog::query(), $tenantId, $at)
            ->with([
                'products' => function ($query) use ($tenantId, $at): void {
                    $this->windowScope
                        ->apply($query, $tenantId, $at)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->with([
                            'packages' => function ($query) use ($tenantId, $at): void {
                                $this->windowScope
                                    ->apply($query, $tenantId, $at)
                                    ->orderBy('sort_order')
                                    ->orderBy('id')
                            ->with([
                                'items' => function ($query) use ($tenantId, $at): void {
                                    $this->windowScope
                                        ->apply($query, $tenantId, $at)
                                        ->orderBy('sort_order')
                                        ->orderBy('id');
                                },
                                'aliases' => function ($query) use ($tenantId, $at): void {
                                    $this->windowScope
                                        ->apply($query, $tenantId, $at)
                                        ->orderBy('sort_order')
                                        ->orderBy('id');
                                },
                            ]);
                    },
                ]);
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $rows->map(static function (ServiceCatalog $catalog): array {
            return [
                'id' => $catalog->id,
                'code' => $catalog->code,
                'name' => $catalog->name,
                'description' => $catalog->description,
                'sort_order' => $catalog->sort_order,
                'products' => $catalog->products->map(static function ($product): array {
                    return [
                        'id' => $product->id,
                        'code' => $product->code,
                        'name' => $product->name,
                        'description' => $product->description,
                        'sort_order' => $product->sort_order,
                        'packages' => $product->packages->map(static function ($package): array {
                            return [
                                'id' => $package->id,
                                'code' => $package->code,
                                'name' => $package->name,
                                'description' => $package->description,
                                'sort_order' => $package->sort_order,
                                'items' => $package->items->map(static function ($item): array {
                                    return [
                                        'id' => $item->id,
                                        'name' => $item->name,
                                        'description' => $item->description,
                                        'sort_order' => $item->sort_order,
                                    ];
                                })->values()->all(),
                                'aliases' => $package->aliases->map(static function ($alias): array {
                                    return [
                                        'id' => $alias->id,
                                        'alias' => $alias->alias,
                                        'sort_order' => $alias->sort_order,
                                    ];
                                })->values()->all(),
                            ];
                        })->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(422, 'Tenant id is required.');
        }
    }
}
