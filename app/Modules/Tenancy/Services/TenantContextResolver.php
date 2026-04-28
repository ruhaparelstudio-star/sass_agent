<?php

namespace App\Modules\Tenancy\Services;

use App\DTOs\TenantContextData;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Plans\Services\FeatureGateService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantContextResolver
{
    public function __construct(private readonly FeatureGateService $featureGateService)
    {
    }

    public function resolve(User $user): TenantContextData
    {
        if ($user->role === UserRole::Superadmin) {
            return new TenantContextData($user->id, $user->role->value, null, null);
        }

        $tenantId = $user->tenants()->value('tenants.id');
        $featureGate = $this->featureGateService->resolveForTenant($tenantId ? (int) $tenantId : null);

        return new TenantContextData($user->id, $user->role->value, $tenantId ? (int) $tenantId : null, $featureGate);
    }

    public function assertCanAccessTenant(User $user, Tenant $tenant): void
    {
        if ($user->role === UserRole::Superadmin) {
            return;
        }

        $ownsTenant = $user->tenants()->whereKey($tenant->id)->exists();

        if (! $ownsTenant) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }
    }
}
