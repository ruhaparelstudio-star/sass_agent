<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\AdminUi\Services\TenantDashboardQueryService;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantDashboardController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
        private readonly TenantDashboardQueryService $dashboardQueryService,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $request->user();
        if ($user->role !== UserRole::TenantAdmin) {
            throw new HttpException(403, 'Forbidden role.');
        }

        $context = $this->tenantContextResolver->resolve($user);
        if (! is_int($context->tenantId)) {
            throw new HttpException(403, 'Tenant context unavailable.');
        }

        $requestedTenantId = $request->query('tenant_id');
        if ($requestedTenantId !== null) {
            $tenant = Tenant::query()->findOrFail((int) $requestedTenantId);
            $this->tenantContextResolver->assertCanAccessTenant($user, $tenant);

            if ((int) $requestedTenantId !== $context->tenantId) {
                throw new HttpException(403, 'Forbidden tenant scope.');
            }
        }

        $dashboardData = $this->dashboardQueryService->getDashboardData($context->tenantId);

        return Inertia::render('Tenant/Dashboard', [
            'summary' => $dashboardData['summary'],
            'recentConversations' => $dashboardData['recentConversations'],
            'recentHandoffs' => $dashboardData['recentHandoffs'],
            'recentNotifications' => $dashboardData['recentNotifications'],
            'tenantId' => $context->tenantId,
        ]);
    }
}
