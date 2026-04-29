<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuperadminTenantController extends Controller
{
    public function __construct(private readonly TenantService $tenantService)
    {
    }

    public function dashboard(Request $request): Response
    {
        $this->assertSuperadmin($request);

        return Inertia::render('Superadmin/Dashboard', [
            'summary' => [
                'tenants_total' => Tenant::query()->count(),
                'tenants_active' => Tenant::query()->where('is_active', true)->count(),
                'tenants_inactive' => Tenant::query()->where('is_active', false)->count(),
            ],
            'recentTenants' => Tenant::query()
                ->latest('id')
                ->limit(5)
                ->get(['id', 'name', 'slug', 'is_active', 'created_at'])
                ->toArray(),
        ]);
    }

    public function index(Request $request): Response
    {
        $this->assertSuperadmin($request);

        return Inertia::render('Superadmin/TenantsIndex', [
            'tenants' => Tenant::query()->orderBy('id')->get(['id', 'name', 'slug', 'is_active', 'created_at'])->toArray(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->assertSuperadmin($request);

        return Inertia::render('Superadmin/TenantsCreate');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertSuperadmin($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:tenants,slug'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tenant = $this->tenantService->createTenant([
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'is_active' => (bool) ($payload['is_active'] ?? false),
        ]);

        return redirect('/superadmin/tenants/'.$tenant->id);
    }

    public function show(Request $request, Tenant $tenant): Response
    {
        $this->assertSuperadmin($request);

        return Inertia::render('Superadmin/TenantsShow', [
            'tenant' => $tenant->only(['id', 'name', 'slug', 'is_active', 'created_at', 'updated_at']),
        ]);
    }

    public function edit(Request $request, Tenant $tenant): Response
    {
        $this->assertSuperadmin($request);

        return Inertia::render('Superadmin/TenantsEdit', [
            'tenant' => $tenant->only(['id', 'name', 'slug', 'is_active']),
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->assertSuperadmin($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:tenants,slug,'.$tenant->id],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tenant->update([
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'is_active' => (bool) ($payload['is_active'] ?? false),
        ]);

        return redirect('/superadmin/tenants/'.$tenant->id);
    }

    public function deactivate(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->assertSuperadmin($request);

        $tenant->update([
            'is_active' => false,
        ]);

        return redirect('/superadmin/tenants/'.$tenant->id);
    }

    private function assertSuperadmin(Request $request): void
    {
        if ($request->user()?->role !== UserRole::Superadmin) {
            throw new HttpException(403, 'Forbidden role.');
        }
    }
}
