<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_can_be_accessed_by_guest(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSeeText('Admin Login')
            ->assertSeeText('SaaS Agent Console');
    }

    public function test_tenant_admin_can_login_via_web_session(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $user->tenants()->attach($tenant->id);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertRedirect('/tenant/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_superadmin_can_login_via_web_session(): void
    {
        $user = User::factory()->create([
            'role' => 'superadmin',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertRedirect('/superadmin/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertRedirect('/login')
            ->assertSessionHas('login_error');

        $this->assertGuest();
    }

    public function test_login_page_shows_validation_feedback_state_message_on_failure(): void
    {
        $user = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'invalid',
        ]);

        $response->assertRedirect('/login');
        $this->followRedirects($response)->assertSeeText('Email atau password salah.');
    }
}
