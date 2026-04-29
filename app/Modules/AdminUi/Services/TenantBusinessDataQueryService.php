<?php

namespace App\Modules\AdminUi\Services;

use App\Models\Discount;
use App\Models\Faq;
use App\Models\Package;
use App\Models\Price;
use App\Models\Product;
use App\Models\ServiceCatalog;

class TenantBusinessDataQueryService
{
    /**
     * @return array<string,mixed>
     */
    public function getPageData(int $tenantId): array
    {
        return [
            'serviceCatalogs' => ServiceCatalog::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'products' => Product::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'service_catalog_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'packages' => Package::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'product_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'prices' => Price::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'package_id', 'label', 'currency', 'amount', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'discounts' => Discount::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'package_id', 'name', 'discount_type', 'value', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'faqs' => Faq::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'question', 'answer', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
        ];
    }
}
