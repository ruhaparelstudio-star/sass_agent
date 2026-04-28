<?php

namespace App\Modules\Plans\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantSubscriptionService
{
    public function assertSuperadmin(User $user): void
    {
        if ($user->role !== UserRole::Superadmin) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    public function canTransition(SubscriptionStatus $from, SubscriptionStatus $to): bool
    {
        return match ($from) {
            SubscriptionStatus::Trial => in_array($to, [SubscriptionStatus::Active, SubscriptionStatus::Cancelled, SubscriptionStatus::Expired], true),
            SubscriptionStatus::Active => in_array($to, [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired], true),
            SubscriptionStatus::Cancelled => false,
            SubscriptionStatus::Expired => false,
        };
    }

    public function assign(Tenant $tenant, Plan $plan, SubscriptionStatus $status, ?string $startsAt, ?string $endsAt): TenantSubscription
    {
        if ($startsAt && $endsAt && $endsAt < $startsAt) {
            throw new HttpException(422, 'Subscription ends_at must be after starts_at.');
        }

        return DB::transaction(function () use ($tenant, $plan, $status, $startsAt, $endsAt): TenantSubscription {
            $current = TenantSubscription::query()
                ->where('tenant_id', $tenant->id)
                ->where('current_marker', 1)
                ->lockForUpdate()
                ->first();

            if ($current) {
                if (! $this->canTransition($current->status, SubscriptionStatus::Cancelled)) {
                    throw new HttpException(422, 'Current subscription cannot be replaced.');
                }

                $current->status = SubscriptionStatus::Cancelled;
                $current->ends_at = now();
                $current->current_marker = null;
                $current->save();
            }

            return TenantSubscription::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => $status,
                'starts_at' => $startsAt ? Carbon::parse($startsAt) : now(),
                'ends_at' => $endsAt ? Carbon::parse($endsAt) : null,
                'current_marker' => 1,
            ]);
        });
    }

    public function unassign(Tenant $tenant): ?TenantSubscription
    {
        return DB::transaction(function () use ($tenant): ?TenantSubscription {
            $current = TenantSubscription::query()
                ->where('tenant_id', $tenant->id)
                ->where('current_marker', 1)
                ->lockForUpdate()
                ->first();

            if (! $current) {
                return null;
            }

            if (! $this->canTransition($current->status, SubscriptionStatus::Cancelled)) {
                throw new HttpException(422, 'Subscription cannot be cancelled from current status.');
            }

            $current->status = SubscriptionStatus::Cancelled;
            $current->ends_at = now();
            $current->current_marker = null;
            $current->save();

            return $current;
        });
    }
}
