<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Activation\Services\ActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ActivationWebFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_open_activation_link_page_and_see_valid_status(): void
    {
        Mail::fake();

        [$tenant, $superadmin] = $this->prepareTenantAndIssuer();
        $issued = app(ActivationService::class)->issueToken($superadmin, $tenant, 'web-admin@example.com');

        $this->get('/activation?token='.$issued['token'].'&email=web-admin@example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Activation', false)
                ->where('email', 'web-admin@example.com')
                ->where('status', 'valid')
            );
    }

    public function test_guest_can_set_password_from_activation_link(): void
    {
        Mail::fake();

        [$tenant, $superadmin] = $this->prepareTenantAndIssuer();
        $issued = app(ActivationService::class)->issueToken($superadmin, $tenant, 'activate-me@example.com');

        $this->post('/activation/set-password', [
            'token' => $issued['token'],
            'email' => 'activate-me@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect('/login');

        $this->assertDatabaseHas('users', [
            'email' => 'activate-me@example.com',
            'role' => 'tenant_admin',
        ]);

        $user = User::query()->where('email', 'activate-me@example.com')->firstOrFail();

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);

        $this->get('/activation?token='.$issued['token'].'&email=activate-me@example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Activation', false)
                ->where('status', 'used')
            );
    }

    public function test_invalid_activation_token_is_blocked_with_validation_error(): void
    {
        $this->from('/activation?token=bad&email=x@example.com')->post('/activation/set-password', [
            'token' => 'bad',
            'email' => 'x@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertRedirect('/activation?token=bad&email=x@example.com')
            ->assertSessionHasErrors('activation');
    }

    private function prepareTenantAndIssuer(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $superadmin = User::factory()->create([
            'role' => 'superadmin',
        ]);

        return [$tenant, $superadmin];
    }
}
