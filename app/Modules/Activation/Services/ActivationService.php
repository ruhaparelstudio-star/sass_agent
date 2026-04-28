<?php

namespace App\Modules\Activation\Services;

use App\Enums\UserRole;
use App\Models\ActivationToken;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActivationService
{
    public function __construct(
        private readonly ActivationMailService $mailService,
        private readonly TenantService $tenantService,
    ) {
    }

    /**
     * @return array{token:string,email:string,expires_at:string,status:string}
     */
    public function issueToken(User $issuer, Tenant $tenant, string $email): array
    {
        if ($issuer->role !== UserRole::Superadmin) {
            throw new HttpException(403, 'Forbidden');
        }

        $plainToken = Str::random(64);
        $normalizedEmail = mb_strtolower($email);

        $token = ActivationToken::query()->create([
            'tenant_id' => $tenant->id,
            'email' => $normalizedEmail,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
            'issued_by' => $issuer->id,
        ]);

        $this->mailService->sendActivationLink($normalizedEmail, $plainToken);

        return [
            'token' => $plainToken,
            'email' => $normalizedEmail,
            'expires_at' => $token->expires_at->toISOString(),
            'status' => 'issued',
        ];
    }

    /**
     * @return array{status:string}
     */
    public function verifyToken(string $plainToken, string $email): array
    {
        $token = $this->findToken($plainToken, $email);

        if (! $token) {
            return ['status' => 'invalid'];
        }

        if ($token->used_at !== null) {
            return ['status' => 'used'];
        }

        if ($token->expires_at->isPast()) {
            return ['status' => 'expired'];
        }

        return ['status' => 'valid'];
    }

    /**
     * @return array{user_id:int,role:string,tenant_id:int}
     */
    public function setPassword(string $plainToken, string $email, string $password): array
    {
        return DB::transaction(function () use ($plainToken, $email, $password): array {
            $normalizedEmail = mb_strtolower($email);
            $tokenHash = hash('sha256', $plainToken);

            $token = ActivationToken::query()
                ->where('token_hash', $tokenHash)
                ->where('email', $normalizedEmail)
                ->lockForUpdate()
                ->first();

            if (! $token) {
                throw new HttpException(422, 'Invalid activation token.');
            }

            if ($token->used_at !== null) {
                throw new HttpException(422, 'Activation token already used.');
            }

            if ($token->expires_at->isPast()) {
                throw new HttpException(422, 'Activation token expired.');
            }

            $user = User::query()->firstOrNew(['email' => $normalizedEmail]);

            if ($user->exists && $user->role === UserRole::Superadmin) {
                throw new HttpException(422, 'Superadmin account cannot be activated with tenant token.');
            }

            if (! $user->exists) {
                $user->name = Str::before($normalizedEmail, '@');
            }

            $user->role = UserRole::TenantAdmin;
            $user->password = $password;
            $user->save();

            $tenant = Tenant::query()->findOrFail($token->tenant_id);
            $this->tenantService->assignTenantToUser($user, $tenant);

            $token->used_at = now();
            $token->save();

            return [
                'user_id' => $user->id,
                'role' => $user->role->value,
                'tenant_id' => $tenant->id,
            ];
        });
    }

    private function findToken(string $plainToken, string $email): ?ActivationToken
    {
        return ActivationToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('email', mb_strtolower($email))
            ->first();
    }
}

