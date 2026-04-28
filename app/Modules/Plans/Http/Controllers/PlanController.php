<?php

namespace App\Modules\Plans\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Modules\Plans\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function __construct(private readonly PlanService $planService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->planService->assertSuperadmin($request->user());

        return response()->json([
            'data' => Plan::query()->with('features')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->planService->assertSuperadmin($request->user());

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:plans,slug'],
            'is_active' => ['required', 'boolean'],
        ]);

        $plan = $this->planService->createPlan($payload);

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $this->planService->assertSuperadmin($request->user());

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:plans,slug,'.$plan->id],
            'is_active' => ['required', 'boolean'],
        ]);

        $updated = $this->planService->updatePlan($plan, $payload);

        return response()->json(['data' => $updated]);
    }

    public function storeFeature(Request $request, Plan $plan): JsonResponse
    {
        $this->planService->assertSuperadmin($request->user());

        $payload = $request->validate([
            'code' => ['required', 'string', 'max:255', 'unique:plan_features,code,NULL,id,plan_id,'.$plan->id],
            'name' => ['required', 'string', 'max:255'],
            'value_string' => ['nullable', 'string'],
            'value_int' => ['nullable', 'integer'],
            'value_bool' => ['nullable', 'boolean'],
        ]);

        $feature = $this->planService->createFeature($plan, $payload);

        return response()->json(['data' => $feature], 201);
    }

    public function updateFeature(Request $request, Plan $plan, PlanFeature $feature): JsonResponse
    {
        $this->planService->assertSuperadmin($request->user());

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

        $updated = $this->planService->updateFeature($feature, $payload);

        return response()->json(['data' => $updated]);
    }

    public function destroyFeature(Request $request, Plan $plan, PlanFeature $feature): JsonResponse
    {
        $this->planService->assertSuperadmin($request->user());

        if ($feature->plan_id !== $plan->id) {
            abort(404);
        }

        $feature->delete();

        return response()->json(['message' => 'Deleted']);
    }
}

