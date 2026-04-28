<?php

namespace App\Modules\Plans\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Modules\Plans\Services\TenantSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class TenantSubscriptionController extends Controller
{
    public function __construct(private readonly TenantSubscriptionService $subscriptionService)
    {
    }

    public function assign(Request $request): JsonResponse
    {
        $this->subscriptionService->assertSuperadmin($request->user());

        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'status' => ['required', new Enum(SubscriptionStatus::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $tenant = Tenant::query()->findOrFail($payload['tenant_id']);
        $plan = Plan::query()->findOrFail($payload['plan_id']);
        $status = SubscriptionStatus::from($payload['status']);

        $subscription = $this->subscriptionService->assign(
            $tenant,
            $plan,
            $status,
            $payload['starts_at'] ?? null,
            $payload['ends_at'] ?? null,
        );

        return response()->json(['data' => $subscription]);
    }

    public function unassign(Request $request): JsonResponse
    {
        $this->subscriptionService->assertSuperadmin($request->user());

        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
        ]);

        $tenant = Tenant::query()->findOrFail($payload['tenant_id']);
        $subscription = $this->subscriptionService->unassign($tenant);

        return response()->json([
            'data' => $subscription,
            'message' => 'Subscription unassigned',
        ]);
    }
}

