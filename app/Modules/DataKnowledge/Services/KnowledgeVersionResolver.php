<?php

namespace App\Modules\DataKnowledge\Services;

use App\Models\KnowledgeVersion;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KnowledgeVersionResolver
{
    public function resolveActiveVersion(int $tenantId, CarbonInterface $at): ?KnowledgeVersion
    {
        $this->assertTenantId($tenantId);

        return KnowledgeVersion::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($query) use ($at): void {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at);
            })
            ->where(function ($query) use ($at): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $at);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(422, 'Tenant id is required.');
        }
    }
}
