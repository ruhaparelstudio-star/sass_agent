<?php

namespace Tests\Feature\AdminUi;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantWhatsappQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_tenant_admin_can_access_whatsapp_qr_page(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');

        Config::set('whatsapp.gateway_qr_url', 'http://wa-gateway:3001/qr');
        Http::fake([
            'http://wa-gateway:3001/qr*' => Http::response([
                'tenant_id' => $tenant->id,
                'provider' => 'meta',
                'qr_code' => 'dummy-wa-qr-v1',
                'expires_in_seconds' => 60,
                'generated_at' => '2026-04-29T12:00:00Z',
            ], 200),
        ]);

        $response = $this->actingAs($tenantAdmin)->get('/tenant/whatsapp/qr');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/WhatsappQr', false)
                ->where('tenantId', $tenant->id)
                ->where('qr.status', 'available')
                ->where('qr.provider', 'meta')
                ->where('qr.code', 'dummy-wa-qr-v1')
                ->where('qr.expiresInSeconds', 60)
            );
    }

    public function test_unauthenticated_user_is_redirected_from_whatsapp_qr_page(): void
    {
        $this->get('/tenant/whatsapp/qr')->assertRedirect('/login');
    }

    public function test_non_tenant_admin_cannot_access_whatsapp_qr_page(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($superadmin)->get('/tenant/whatsapp/qr')->assertForbidden();
    }

    public function test_qr_page_shows_unavailable_when_gateway_response_fails(): void
    {
        [, $tenantAdmin] = $this->createTenantAdmin('tenant-one');

        Config::set('whatsapp.gateway_qr_url', 'http://wa-gateway:3001/qr');
        Http::fake([
            'http://wa-gateway:3001/qr*' => Http::response(['status' => 'error'], 500),
        ]);

        $response = $this->actingAs($tenantAdmin)->get('/tenant/whatsapp/qr');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/WhatsappQr', false)
                ->where('qr.status', 'unavailable')
            );
    }

    private function createTenantAdmin(string $slug): array
    {
        $tenant = Tenant::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'is_active' => true,
        ]);

        $tenantAdmin = User::factory()->create([
            'role' => 'tenant_admin',
        ]);

        $tenantAdmin->tenants()->attach($tenant->id);

        return [$tenant, $tenantAdmin];
    }
}

