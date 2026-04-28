<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContextResolver;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenantService,
        private readonly TenantContextResolver $tenantContextResolver,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== UserRole::Superadmin) {
            throw new HttpException(403, 'Forbidden');
        }

        return response()->json([
            'data' => Tenant::query()->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== UserRole::Superadmin) {
            throw new HttpException(403, 'Forbidden');
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:tenants,slug'],
        ]);

        $tenant = $this->tenantService->createTenant($payload);

        return response()->json(['data' => $tenant], 201);
    }

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->tenantContextResolver->assertCanAccessTenant($request->user(), $tenant);

        return response()->json(['data' => $tenant]);
    }
}
