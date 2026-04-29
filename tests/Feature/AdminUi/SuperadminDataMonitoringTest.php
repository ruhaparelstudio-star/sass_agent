<?php

namespace Tests\Feature\AdminUi;

use App\Models\Faq;
use App\Models\Package;
use App\Models\Price;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperadminDataMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_access_data_monitoring_pages(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $service = ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'svc-wed',
            'name' => 'Wedding Service',
            'description' => null,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'service_catalog_id' => $service->id,
            'code' => 'prod-premium',
            'name' => 'Premium Product',
            'description' => null,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $package = Package::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'code' => 'pkg-silver',
            'name' => 'Silver Package',
            'description' => null,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Price::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'label' => 'Base Price',
            'currency' => 'IDR',
            'amount' => 1500000,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Faq::query()->create([
            'tenant_id' => $tenant->id,
            'question' => 'Apakah bisa survey lokasi?',
            'answer' => 'Bisa.',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)
            ->get('/superadmin/data-monitoring')
            ->assertOk()
            ->assertSee('Superadmin\\/DataMonitoringIndex', false)
            ->assertSeeText($tenant->name);

        $this->actingAs($superadmin)
            ->get('/superadmin/data-monitoring/'.$tenant->id)
            ->assertOk()
            ->assertSee('Superadmin\\/DataMonitoringShow', false)
            ->assertSeeText($tenant->name)
            ->assertSeeText('service_catalogs');
    }

    public function test_tenant_admin_is_forbidden_from_data_monitoring_pages(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $tenantAdmin = User::factory()->create([
            'role' => 'tenant_admin',
        ]);

        $this->actingAs($tenantAdmin)
            ->get('/superadmin/data-monitoring')
            ->assertForbidden();

        $this->actingAs($tenantAdmin)
            ->get('/superadmin/data-monitoring/'.$tenant->id)
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_for_data_monitoring_pages(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $this->get('/superadmin/data-monitoring')->assertRedirect('/login');
        $this->get('/superadmin/data-monitoring/'.$tenant->id)->assertRedirect('/login');
    }
}
