<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Modules\AdminUi\Services\TenantAnalyticsQueryService;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantAnalyticsController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
        private readonly TenantAnalyticsQueryService $analyticsQueryService,
    ) {}

    public function show(Request $request): Response
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $data = $this->analyticsQueryService->getAnalyticsData($tenantId);

        return Inertia::render('Tenant/Analytics', [
            'analytics' => $data,
        ]);
    }

    private function resolveAuthorizedTenantId(Request $request): int
    {
        $user = $request->user();
        if ($user->role !== UserRole::TenantAdmin) {
            throw new HttpException(403, 'Forbidden role.');
        }
        $context = $this->tenantContextResolver->resolve($user);
        if (! is_int($context->tenantId)) {
            throw new HttpException(403, 'Tenant context unavailable.');
        }

        return $context->tenantId;
    }
}
