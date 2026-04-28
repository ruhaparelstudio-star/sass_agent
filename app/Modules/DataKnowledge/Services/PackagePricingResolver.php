<?php

namespace App\Modules\DataKnowledge\Services;

use App\Models\Package;
use App\Modules\DataKnowledge\Support\KnowledgeWindowScope;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PackagePricingResolver
{
    public function __construct(
        private readonly KnowledgeWindowScope $windowScope,
        private readonly KnowledgeVersionResolver $knowledgeVersionResolver,
    ) {}

    public function resolvePackagePricing(int $tenantId, int $packageId, CarbonInterface $at): ?array
    {
        $this->assertTenantId($tenantId);
        if ($this->knowledgeVersionResolver->resolveActiveVersion($tenantId, $at) === null) {
            return null;
        }

        $package = $this->windowScope
            ->apply(Package::query(), $tenantId, $at)
            ->whereKey($packageId)
            ->with([
                'prices' => function ($query) use ($tenantId, $at): void {
                    $this->windowScope
                        ->apply($query, $tenantId, $at)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
                'discounts' => function ($query) use ($tenantId, $at): void {
                    $this->windowScope
                        ->apply($query, $tenantId, $at)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
            ])
            ->first();

        if ($package === null) {
            return null;
        }

        return [
            'package_id' => $package->id,
            'package_code' => $package->code,
            'prices' => $package->prices->map(static function ($price): array {
                return [
                    'id' => $price->id,
                    'label' => $price->label,
                    'currency' => $price->currency,
                    'amount' => $price->amount,
                    'sort_order' => $price->sort_order,
                ];
            })->values()->all(),
            'discounts' => $package->discounts->map(static function ($discount): array {
                return [
                    'id' => $discount->id,
                    'name' => $discount->name,
                    'discount_type' => $discount->discount_type,
                    'value' => $discount->value,
                    'sort_order' => $discount->sort_order,
                ];
            })->values()->all(),
        ];
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(422, 'Tenant id is required.');
        }
    }
}
