<?php

namespace Tests\Feature\Infra;

use App\Mail\ActivationLinkMail;
use App\Models\ActivationToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ActivationSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_issue_verify_and_activate_tenant_admin_account(): void
    {
        Mail::fake();

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $superadmin = User::factory()->create([
            'role' => 'superadmin',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => $superadmin->email,
            'password' => 'password',
        ])->json('token');

        $issue = $this->withToken($token)->postJson('/api/activation-tokens', [
            'tenant_id' => $tenant->id,
            'email' => 'new-admin@example.com',
        ]);

        $issue->assertCreated()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonStructure([
                'data' => ['email', 'expires_at'],
                'delivery' => ['token'],
            ]);

        Mail::assertSent(ActivationLinkMail::class);

        $plainToken = $issue->json('delivery.token');

        $this->postJson('/api/activation/verify', [
            'email' => 'new-admin@example.com',
            'token' => $plainToken,
        ])->assertOk()->assertJsonPath('data.status', 'valid');

        $activate = $this->postJson('/api/activation/set-password', [
            'email' => 'new-admin@example.com',
            'token' => $plainToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $activate->assertOk()
            ->assertJsonPath('data.role', 'tenant_admin')
            ->assertJsonPath('data.tenant_id', $tenant->id);

        $this->postJson('/api/auth/login', [
            'email' => 'new-admin@example.com',
            'password' => 'new-password',
        ])->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/activation/verify', [
            'email' => 'new-admin@example.com',
            'token' => $plainToken,
        ])->assertOk()->assertJsonPath('data.status', 'used');
    }

    public function test_non_superadmin_cannot_issue_activation_token(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $tenantAdmin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $tenantAdmin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $tenantAdmin->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)->postJson('/api/activation-tokens', [
            'tenant_id' => $tenant->id,
            'email' => 'new-admin@example.com',
        ])->assertForbidden();
    }

    public function test_invalid_and_expired_token_paths_are_blocked(): void
    {
        $this->postJson('/api/activation/verify', [
            'email' => 'x@example.com',
            'token' => 'invalid-token',
        ])->assertOk()->assertJsonPath('data.status', 'invalid');

        $this->postJson('/api/activation/set-password', [
            'email' => 'x@example.com',
            'token' => 'invalid-token',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422);

        $token = ActivationToken::query()->create([
            'tenant_id' => Tenant::query()->create([
                'name' => 'Tenant One',
                'slug' => 'tenant-one',
                'is_active' => true,
            ])->id,
            'email' => 'expired@example.com',
            'token_hash' => hash('sha256', 'expired-secret'),
            'expires_at' => now()->subMinute(),
            'issued_by' => User::factory()->create(['role' => 'superadmin'])->id,
        ]);

        $this->postJson('/api/activation/verify', [
            'email' => $token->email,
            'token' => 'expired-secret',
        ])->assertOk()->assertJsonPath('data.status', 'expired');

        $this->postJson('/api/activation/set-password', [
            'email' => $token->email,
            'token' => 'expired-secret',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422);
    }

    public function test_used_token_cannot_be_reused(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $superadmin = User::factory()->create([
            'role' => 'superadmin',
            'password' => 'password',
        ]);

        $apiToken = $this->postJson('/api/auth/login', [
            'email' => $superadmin->email,
            'password' => 'password',
        ])->json('token');

        $plainToken = $this->withToken($apiToken)->postJson('/api/activation-tokens', [
            'tenant_id' => $tenant->id,
            'email' => 'used@example.com',
        ])->json('delivery.token');

        $this->postJson('/api/activation/set-password', [
            'email' => 'used@example.com',
            'token' => $plainToken,
            'password' => 'password-1',
            'password_confirmation' => 'password-1',
        ])->assertOk();

        $this->postJson('/api/activation/set-password', [
            'email' => 'used@example.com',
            'token' => $plainToken,
            'password' => 'password-2',
            'password_confirmation' => 'password-2',
        ])->assertStatus(422);
    }

    public function test_tenant_admin_cannot_be_attached_to_another_tenant_via_activation(): void
    {
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

        $existingAdmin = User::factory()->create([
            'email' => 'existing@example.com',
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $existingAdmin->tenants()->attach($tenantOne->id);

        $superadmin = User::factory()->create([
            'role' => 'superadmin',
            'password' => 'password',
        ]);

        $apiToken = $this->postJson('/api/auth/login', [
            'email' => $superadmin->email,
            'password' => 'password',
        ])->json('token');

        $plainToken = $this->withToken($apiToken)->postJson('/api/activation-tokens', [
            'tenant_id' => $tenantTwo->id,
            'email' => 'existing@example.com',
        ])->json('delivery.token');

        $this->postJson('/api/activation/set-password', [
            'email' => 'existing@example.com',
            'token' => $plainToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('tenant_users', [
            'tenant_id' => $tenantTwo->id,
            'user_id' => $existingAdmin->id,
        ]);
    }
}

