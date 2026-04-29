<?php

namespace Tests\Feature\Security;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_token_authentication_denial_is_logged(): void
    {
        Route::post('/__test/security/api-token-denial', fn () => response()->json(['ok' => true]))
            ->middleware('api.token');

        $user = User::factory()->create([
            'role' => 'tenant_admin',
        ]);

        $response = $this->actingAs($user)->postJson('/__test/security/api-token-denial');

        $response->assertStatus(401);

        $this->assertDatabaseHas('audit_logs', [
            'event_key' => 'auth.api_token.denied',
            'status_code' => 401,
            'reason' => 'missing_or_invalid_access_token',
            'actor_user_id' => $user->id,
            'tenant_id' => null,
        ]);
    }

    public function test_internal_secret_denial_is_logged_with_redacted_context(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->withHeader('X-Internal-Secret', 'raw-secret-value')
            ->postJson('/api/internal/whatsapp/accounts/upsert', [
                'tenant_id' => $tenant->id,
                'provider' => 'meta',
                'provider_ref' => 'acct-001',
                'status' => 'disconnected',
                'phone' => '+628111111111',
                'payload' => ['event' => 'created'],
            ])
            ->assertForbidden();

        $row = \App\Models\AuditLog::query()->latest('id')->firstOrFail();

        $this->assertSame('whatsapp.internal_secret.denied', $row->event_key);
        $this->assertSame(403, $row->status_code);
        $this->assertSame('[REDACTED]', $row->context['headers']['x-internal-secret'] ?? null);
        $this->assertNotSame('raw-secret-value', $row->context['headers']['x-internal-secret'] ?? null);
    }

    public function test_cross_tenant_forbidden_is_logged_once_per_request(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenantOne = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $tenantTwo = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenantOne->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->withHeader('X-Internal-Secret', 'wa-internal-secret')
            ->postJson('/api/internal/whatsapp/accounts/upsert', [
                'tenant_id' => $tenantTwo->id,
                'provider' => 'meta',
                'provider_ref' => 'acct-foreign',
                'status' => 'disconnected',
                'phone' => '+628122222222',
                'payload' => ['event' => 'created'],
            ])
            ->assertForbidden();

        $this->assertSame(
            1,
            \App\Models\AuditLog::query()->where('event_key', 'tenancy.scope.denied')->count()
        );

        $this->assertDatabaseHas('audit_logs', [
            'event_key' => 'tenancy.scope.denied',
            'actor_user_id' => $admin->id,
            'tenant_id' => $tenantTwo->id,
            'status_code' => 403,
            'reason' => 'forbidden_tenant_scope',
        ]);
    }
}
