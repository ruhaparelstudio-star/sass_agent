<?php

namespace App\Modules\Plans\Services;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanService
{
    public function assertSuperadmin(User $user): void
    {
        if ($user->role !== UserRole::Superadmin) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    public function createPlan(array $payload): Plan
    {
        return Plan::query()->create($payload);
    }

    public function updatePlan(Plan $plan, array $payload): Plan
    {
        $plan->fill($payload);
        $plan->save();

        return $plan;
    }

    /**
     * @param  array{value_string?:mixed,value_int?:mixed,value_bool?:mixed}  $payload
     * @return array{value_string:mixed,value_int:mixed,value_bool:mixed}
     */
    public function normalizeFeatureValuePayload(array $payload): array
    {
        $valueString = $payload['value_string'] ?? null;
        $valueInt = $payload['value_int'] ?? null;
        $valueBool = $payload['value_bool'] ?? null;

        $definedValues = collect([$valueString, $valueInt, $valueBool])
            ->filter(static fn ($value) => $value !== null)
            ->count();

        if ($definedValues > 1) {
            throw new HttpException(422, 'Only one feature value type may be defined.');
        }

        return [
            'value_string' => $valueString,
            'value_int' => $valueInt,
            'value_bool' => $valueBool,
        ];
    }

    public function createFeature(Plan $plan, array $payload): PlanFeature
    {
        return $plan->features()->create(array_merge(
            [
                'code' => $payload['code'],
                'name' => $payload['name'],
            ],
            $this->normalizeFeatureValuePayload($payload),
        ));
    }

    public function updateFeature(PlanFeature $feature, array $payload): PlanFeature
    {
        $valuePayload = $this->normalizeFeatureValuePayload($payload);

        $feature->fill(array_merge(
            [
                'code' => $payload['code'],
                'name' => $payload['name'],
            ],
            $valuePayload,
        ));
        $feature->save();

        return $feature;
    }
}

