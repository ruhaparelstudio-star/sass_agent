<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ActivationToken;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\WaAccount;
use App\Modules\Analytics\Services\SuperadminMetricsQueryService;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuperadminTenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenantService,
        private readonly SuperadminMetricsQueryService $superadminMetricsQueryService,
    )
    {
    }

    public function dashboard(Request $request): Response
    {
        $this->assertSuperadmin($request);

        $summary = [
                'tenants_total' => Tenant::query()->count(),
                'tenants_active' => Tenant::query()->where('is_active', true)->count(),
                'tenants_inactive' => Tenant::query()->where('is_active', false)->count(),
            ];
        $summary = array_merge($summary, $this->superadminMetricsQueryService->getSummary());

        return Inertia::render('Superadmin/Dashboard', [
            'summary' => $summary,
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

        $tenants = Tenant::query()
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'is_active', 'created_at'])
            ->map(function (Tenant $tenant): array {
                $sub = TenantSubscription::query()
                    ->where('tenant_id', $tenant->id)
                    ->with('plan:id,name,slug')
                    ->latest('id')
                    ->first();

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'is_active' => $tenant->is_active,
                    'created_at' => $tenant->created_at,
                    'subscription' => $sub ? [
                        'status' => $sub->status,
                        'plan' => $sub->plan ? ['name' => $sub->plan->name, 'slug' => $sub->plan->slug] : null,
                    ] : null,
                ];
            })
            ->toArray();

        return Inertia::render('Superadmin/TenantsIndex', [
            'tenants' => $tenants,
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

        $subscription = TenantSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->with('plan:id,name,slug')
            ->latest('id')
            ->first();

        $activationToken = ActivationToken::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first(['id', 'email', 'expires_at', 'created_at']);

        $waAccounts = WaAccount::query()
            ->where('tenant_id', $tenant->id)
            ->get(['id', 'label', 'phone_number', 'status', 'last_connected_at'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->label,
                'phone_number' => $a->phone_number,
                'status' => $a->status,
                'last_connected_at' => $a->last_connected_at,
            ])
            ->toArray();

        $tenantUsers = \App\Models\User::query()
            ->where('tenant_id', $tenant->id)
            ->get(['id', 'name', 'email', 'role', 'created_at'])
            ->toArray();

        return Inertia::render('Superadmin/TenantsShow', [
            'tenant' => $tenant->only(['id', 'name', 'slug', 'is_active', 'created_at', 'updated_at']),
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'plan' => $subscription->plan ? [
                    'id' => $subscription->plan->id,
                    'name' => $subscription->plan->name,
                    'slug' => $subscription->plan->slug,
                ] : null,
            ] : null,
            'activationToken' => $activationToken ? [
                'id' => $activationToken->id,
                'email' => $activationToken->email,
                'expires_at' => $activationToken->expires_at,
            ] : null,
            'waAccounts' => $waAccounts,
            'tenantUsers' => $tenantUsers,
        ]);
    }

    public function activate(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->assertSuperadmin($request);

        $tenant->update(['is_active' => true]);

        return redirect('/superadmin/tenants/'.$tenant->id);
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
