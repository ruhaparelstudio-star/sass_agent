<?php

namespace Tests\Feature\AdminUi;

use App\Models\ServiceCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantBusinessDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_tenant_admin_can_access_business_data_page(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-one');

        ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'wedd',
            'name' => 'Wedding',
            'description' => 'Catalog',
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->get('/tenant/business-data')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/BusinessData', false)
                ->where('tenantId', $tenant->id)
                ->has('data.serviceCatalogs', 1)
                ->has('data.products')
                ->has('data.packages')
                ->has('data.prices')
                ->has('data.discounts')
                ->has('data.faqs')
                ->has('assets')
            );
    }

    public function test_non_tenant_admin_cannot_access_business_data_page(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($superadmin)
            ->get('/tenant/business-data')
            ->assertForbidden();
    }

    public function test_tenant_admin_can_create_service_catalog_in_own_tenant(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-one');

        $this->actingAs($admin)
            ->post('/tenant/business-data/service-catalogs', [
                'code' => 'engagement',
                'name' => 'Engagement Package',
                'description' => 'For engagement event',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('service_catalogs', [
            'tenant_id' => $tenant->id,
            'code' => 'engagement',
            'name' => 'Engagement Package',
        ]);
    }

    public function test_cross_tenant_update_is_forbidden(): void
    {
        [$tenantOne, $adminOne] = $this->createTenantAdmin('tenant-one');
        [$tenantTwo] = $this->createTenantAdmin('tenant-two');

        $catalog = ServiceCatalog::query()->create([
            'tenant_id' => $tenantTwo->id,
            'code' => 'external',
            'name' => 'External Catalog',
            'description' => null,
            'sort_order' => 0,
        ]);

        $this->actingAs($adminOne)
            ->put('/tenant/business-data/service-catalogs/'.$catalog->id, [
                'code' => 'external-updated',
                'name' => 'Updated',
                'description' => null,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('service_catalogs', [
            'id' => $catalog->id,
            'tenant_id' => $tenantTwo->id,
            'code' => 'external',
        ]);

        $this->assertNotSame($tenantOne->id, $tenantTwo->id);
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
