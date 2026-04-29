<?php

namespace App\Modules\Tenancy\Services;

use App\DTOs\TenantContextData;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Services\AuditLogger;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantContextResolver
{
    public function __construct(
        private readonly FeatureGateService $featureGateService,
        private readonly AuditLogger $auditLogger
    )
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
            $request = request();
            $endpoint = method_exists($request, 'path') ? (string) $request->path() : 'unknown';

            $this->auditLogger->logDenied(
                eventKey: 'tenancy.scope.denied',
                statusCode: 403,
                reason: 'forbidden_tenant_scope',
                tenantId: $tenant->id,
                actorUserId: $user->id,
                endpoint: $endpoint,
                context: method_exists($request, 'path')
                    ? $this->auditLogger->buildMinimalRequestContext($request)
                    : ['route' => 'unknown']
            );

            throw new HttpException(403, 'Forbidden tenant scope.');
        }
    }
}
