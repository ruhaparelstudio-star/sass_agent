<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Tenant;
use App\Modules\AdminUi\Services\SuperadminPlanManagementQueryService;
use App\Modules\Plans\Services\PlanService;
use App\Modules\Plans\Services\TenantSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuperadminPlanManagementController extends Controller
{
    public function __construct(
        private readonly SuperadminPlanManagementQueryService $queryService,
        private readonly PlanService $planService,
        private readonly TenantSubscriptionService $subscriptionService,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->assertSuperadmin($request);

        return Inertia::render('Superadmin/PlansIndex', [
            'plans' => $this->queryService->getPlans(),
            'tenants' => $this->queryService->getTenantsWithCurrentSubscription(),
            'subscriptionStatuses' => array_map(
                static fn (SubscriptionStatus $status): string => $status->value,
                SubscriptionStatus::cases(),
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->assertSuperadmin($request);

        return Inertia::render('Superadmin/PlansCreate');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertSuperadmin($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:plans,slug'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $plan = $this->planService->createPlan([
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'is_active' => (bool) ($payload['is_active'] ?? false),
        ]);

        return redirect('/superadmin/plans/'.$plan->id.'/edit');
    }

    public function edit(Request $request, Plan $plan): Response
    {
        $this->assertSuperadmin($request);

        return Inertia::render('Superadmin/PlansEdit', [
            'plan' => $plan->load(['features' => fn ($query) => $query->orderBy('id')]),
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $this->assertSuperadmin($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:plans,slug,'.$plan->id],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->planService->updatePlan($plan, [
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'is_active' => (bool) ($payload['is_active'] ?? false),
        ]);

        return redirect('/superadmin/plans/'.$plan->id.'/edit');
    }

    public function storeFeature(Request $request, Plan $plan): RedirectResponse
    {
        $this->assertSuperadmin($request);

        $payload = $request->validate([
            'code' => ['required', 'string', 'max:255', 'unique:plan_features,code,NULL,id,plan_id,'.$plan->id],
            'name' => ['required', 'string', 'max:255'],
            'value_string' => ['nullable', 'string'],
            'value_int' => ['nullable', 'integer'],
            'value_bool' => ['nullable', 'boolean'],
        ]);

        try {
            $this->planService->createFeature($plan, $payload);
        } catch (HttpException $exception) {
            return $this->redirectWithHttpException($exception);
        }

        return redirect('/superadmin/plans/'.$plan->id.'/edit');
    }

    public function updateFeature(Request $request, Plan $plan, PlanFeature $feature): RedirectResponse
    {
        $this->assertSuperadmin($request);

        if ($feature->plan_id !== $plan->id) {
            abort(404);
        }

        $payload = $request->validate([
            'code' => ['required', 'string', 'max:255', 'unique:plan_features,code,'.$feature->id.',id,plan_id,'.$plan->id],
            'name' => ['required', 'string', 'max:255'],
            'value_string' => ['nullable', 'string'],
            'value_int' => ['nullable', 'integer'],
            'value_bool' => ['nullable', 'boolean'],
        ]);

        try {
            $this->planService->updateFeature($feature, $payload);
        } catch (HttpException $exception) {
            return $this->redirectWithHttpException($exception);
        }

        return redirect('/superadmin/plans/'.$plan->id.'/edit');
    }

    public function destroyFeature(Request $request, Plan $plan, PlanFeature $feature): RedirectResponse
    {
        $this->assertSuperadmin($request);

        if ($feature->plan_id !== $plan->id) {
            abort(404);
        }

        $feature->delete();

        return redirect('/superadmin/plans/'.$plan->id.'/edit');
    }

    public function assignSubscription(Request $request): RedirectResponse
    {
        $this->assertSuperadmin($request);

        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'status' => ['required', new Enum(SubscriptionStatus::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $tenant = Tenant::query()->findOrFail($payload['tenant_id']);
        $plan = Plan::query()->findOrFail($payload['plan_id']);

        try {
            $this->subscriptionService->assign(
                $tenant,
                $plan,
                SubscriptionStatus::from($payload['status']),
                $payload['starts_at'] ?? null,
                $payload['ends_at'] ?? null,
            );
        } catch (HttpException $exception) {
            return $this->redirectWithHttpException($exception);
        }

        return redirect('/superadmin/plans')->with('success', 'Langganan berhasil ditetapkan.');
    }

    public function unassignSubscription(Request $request): RedirectResponse
    {
        $this->assertSuperadmin($request);

        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
        ]);

        $tenant = Tenant::query()->findOrFail($payload['tenant_id']);

        try {
            $this->subscriptionService->unassign($tenant);
        } catch (HttpException $exception) {
            return $this->redirectWithHttpException($exception);
        }

        return redirect('/superadmin/plans')->with('success', 'Langganan berhasil dilepas.');
    }

    private function assertSuperadmin(Request $request): void
    {
        if ($request->user()?->role !== UserRole::Superadmin) {
            throw new HttpException(403, 'Forbidden role.');
        }
    }

    private function redirectWithHttpException(HttpException $exception): RedirectResponse
    {
        $status = $exception->getStatusCode();

        if (in_array($status, [409, 422], true)) {
            return back()->withInput()->withErrors([
                'operation' => $exception->getMessage(),
            ]);
        }

        throw $exception;
    }
}
