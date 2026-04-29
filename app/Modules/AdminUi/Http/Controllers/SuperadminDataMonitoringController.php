<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\AdminUi\Services\SuperadminDataMonitoringQueryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuperadminDataMonitoringController extends Controller
{
    public function __construct(private readonly SuperadminDataMonitoringQueryService $queryService)
    {
    }

    public function index(Request $request): Response
    {
        $this->assertSuperadmin($request);

        return Inertia::render('Superadmin/DataMonitoringIndex', [
            'tenants' => $this->queryService->getTenantSummaries(),
        ]);
    }

    public function show(Request $request, Tenant $tenant): Response
    {
        $this->assertSuperadmin($request);

        return Inertia::render('Superadmin/DataMonitoringShow', [
            'detail' => $this->queryService->getTenantDetail($tenant),
        ]);
    }

    private function assertSuperadmin(Request $request): void
    {
        if ($request->user()?->role !== UserRole::Superadmin) {
            throw new HttpException(403, 'Forbidden role.');
        }
    }
}
