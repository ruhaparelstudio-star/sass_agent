<?php

namespace App\Modules\AdminUi\Services;

use App\Models\Faq;
use App\Models\Package;
use App\Models\Price;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\Tenant;

class SuperadminDataMonitoringQueryService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function getTenantSummaries(): array
    {
        $tenants = Tenant::query()->orderBy('id')->get(['id', 'name', 'slug', 'is_active']);

        return $tenants->map(function (Tenant $tenant): array {
            $counts = $this->countActiveByTenant((int) $tenant->id);

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'is_active' => $tenant->is_active,
                'counts' => $counts,
                'data_gap_flags' => [
                    'package_without_price' => $counts['packages_active'] > 0 && $counts['prices_active'] === 0,
                    'product_without_package' => $counts['products_active'] > 0 && $counts['packages_active'] === 0,
                ],
            ];
        })->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function getTenantDetail(Tenant $tenant): array
    {
        $counts = $this->countActiveByTenant((int) $tenant->id);

        return [
            'tenant' => $tenant->only(['id', 'name', 'slug', 'is_active']),
            'counts' => $counts,
            'recent' => [
                'service_catalogs' => ServiceCatalog::query()->where('tenant_id', $tenant->id)->latest('id')->limit(5)->get(['id', 'name', 'is_active'])->toArray(),
                'products' => Product::query()->where('tenant_id', $tenant->id)->latest('id')->limit(5)->get(['id', 'name', 'service_catalog_id', 'is_active'])->toArray(),
                'packages' => Package::query()->where('tenant_id', $tenant->id)->latest('id')->limit(5)->get(['id', 'name', 'product_id', 'is_active'])->toArray(),
                'prices' => Price::query()->where('tenant_id', $tenant->id)->latest('id')->limit(5)->get(['id', 'label', 'amount', 'currency', 'is_active'])->toArray(),
                'faqs' => Faq::query()->where('tenant_id', $tenant->id)->latest('id')->limit(5)->get(['id', 'question', 'is_active'])->toArray(),
            ],
        ];
    }

    /**
     * @return array<string,int>
     */
    private function countActiveByTenant(int $tenantId): array
    {
        return [
            'service_catalogs_active' => ServiceCatalog::query()->where('tenant_id', $tenantId)->where('is_active', true)->count(),
            'products_active' => Product::query()->where('tenant_id', $tenantId)->where('is_active', true)->count(),
            'packages_active' => Package::query()->where('tenant_id', $tenantId)->where('is_active', true)->count(),
            'prices_active' => Price::query()->where('tenant_id', $tenantId)->where('is_active', true)->count(),
            'faqs_active' => Faq::query()->where('tenant_id', $tenantId)->where('is_active', true)->count(),
        ];
    }
}
