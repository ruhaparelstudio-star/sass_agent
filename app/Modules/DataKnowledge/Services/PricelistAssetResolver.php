<?php

namespace App\Modules\DataKnowledge\Services;

use App\Models\TenantAsset;
use App\Modules\DataKnowledge\Support\KnowledgeWindowScope;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PricelistAssetResolver
{
    public function __construct(
        private readonly KnowledgeWindowScope $windowScope,
        private readonly KnowledgeVersionResolver $knowledgeVersionResolver,
    ) {}

    public function resolvePricelistAsset(int $tenantId, CarbonInterface $at): ?array
    {
        $this->assertTenantId($tenantId);
        if ($this->knowledgeVersionResolver->resolveActiveVersion($tenantId, $at) === null) {
            return null;
        }

        $row = $this->windowScope
            ->apply(TenantAsset::query()->where('asset_type', 'pricelist'), $tenantId, $at)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => $row->id,
            'asset_type' => $row->asset_type,
            'display_name' => $row->display_name,
            'original_filename' => $row->original_filename,
            'storage_disk' => $row->storage_disk,
            'storage_path' => $row->storage_path,
            'active_from' => $row->active_from?->toIso8601String(),
            'active_until' => $row->active_until?->toIso8601String(),
        ];
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(422, 'Tenant id is required.');
        }
    }
}
