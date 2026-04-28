<?php

namespace App\Modules\Tenancy\Services;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantService
{
    public function createTenant(array $payload): Tenant
    {
        return Tenant::query()->create($payload);
    }

    public function assignTenantToUser(User $user, Tenant $tenant): void
    {
        if ($user->role === UserRole::TenantAdmin) {
            $existingTenantId = $user->tenants()->value('tenants.id');

            if ($existingTenantId !== null && (int) $existingTenantId !== $tenant->id) {
                throw new HttpException(422, 'Tenant admin can only belong to one tenant.');
            }
        }

        $user->tenants()->syncWithoutDetaching([$tenant->id]);
    }
}
