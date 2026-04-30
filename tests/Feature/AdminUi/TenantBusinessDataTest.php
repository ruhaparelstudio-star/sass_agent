<?php

namespace Tests\Feature\AdminUi;

use App\Models\ServiceCatalog;
use App\Models\Product;
use App\Models\Package;
use App\Models\Price;
use App\Models\Discount;
use App\Models\Faq;
use App\Models\BookingSetting;
use App\Models\CalendarSetting;
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
                ->has('data.bookingSettings')
                ->has('data.businessHoursPolicy')
                ->has('assets')
            );
    }

    public function test_business_data_page_includes_all_dependency_payload_for_stepper_flow(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-one');

        $catalog = ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'main',
            'name' => 'Main Catalog',
            'description' => 'Catalog',
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'service_catalog_id' => $catalog->id,
            'code' => 'photo',
            'name' => 'Photo',
            'description' => 'Product',
            'sort_order' => 1,
        ]);

        $package = Package::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'code' => 'gold',
            'name' => 'Gold',
            'description' => 'Package',
            'sort_order' => 1,
        ]);

        Price::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'label' => 'Base',
            'currency' => 'IDR',
            'amount' => 1000000,
            'sort_order' => 1,
        ]);

        Discount::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'name' => 'Promo',
            'discount_type' => 'percentage',
            'value' => 10,
            'sort_order' => 1,
        ]);

        Faq::query()->create([
            'tenant_id' => $tenant->id,
            'question' => 'Q?',
            'answer' => 'A',
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->get('/tenant/business-data?step=pricing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/BusinessData', false)
                ->has('data.serviceCatalogs', 1)
                ->has('data.products', 1)
                ->has('data.packages', 1)
                ->has('data.prices', 1)
                ->has('data.discounts', 1)
                ->has('data.faqs', 1)
                ->has('data.bookingSettings')
                ->has('data.businessHoursPolicy')
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
                'name' => 'Engagement Package',
                'description' => 'For engagement event',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('service_catalogs', [
            'tenant_id' => $tenant->id,
            'code' => 'CAT-0001',
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

    public function test_tenant_admin_can_create_product_with_auto_generated_code(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-one');

        $catalog = ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'CAT-0001',
            'name' => 'Main Catalog',
            'description' => null,
            'sort_order' => 0,
        ]);

        $this->actingAs($admin)
            ->post('/tenant/business-data/products', [
                'service_catalog_id' => $catalog->id,
                'name' => 'Photo Product',
                'description' => 'Photo package',
                'sort_order' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'service_catalog_id' => $catalog->id,
            'code' => 'PRD-0001',
            'name' => 'Photo Product',
        ]);
    }

    public function test_tenant_admin_can_create_package_with_auto_generated_code(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-one');

        $catalog = ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'CAT-0001',
            'name' => 'Main Catalog',
            'description' => null,
            'sort_order' => 0,
        ]);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'service_catalog_id' => $catalog->id,
            'code' => 'PRD-0001',
            'name' => 'Photo Product',
            'description' => null,
            'sort_order' => 0,
        ]);

        $this->actingAs($admin)
            ->post('/tenant/business-data/packages', [
                'product_id' => $product->id,
                'name' => 'Gold Package',
                'description' => 'Best seller',
                'sort_order' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('packages', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'code' => 'PKG-0001',
            'name' => 'Gold Package',
        ]);
    }

    public function test_tenant_admin_can_upsert_booking_setting(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-one');

        $this->actingAs($admin)
            ->post('/tenant/business-data/booking-setting', [
                'booking_url' => 'https://booking.example.com/wedding',
                'is_active' => true,
                'active_from' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('booking_settings', [
            'tenant_id' => $tenant->id,
            'booking_url' => 'https://booking.example.com/wedding',
            'is_active' => true,
        ]);
    }

    public function test_tenant_admin_can_upsert_business_hours_policy(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('tenant-one');

        $this->actingAs($admin)
            ->post('/tenant/business-data/business-hours', [
                'enabled' => true,
                'timezone' => 'Asia/Jakarta',
                'start_time' => '09:00',
                'end_time' => '17:00',
                'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            ])
            ->assertRedirect();

        $calendarSetting = CalendarSetting::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $rules = is_array($calendarSetting->rules) ? $calendarSetting->rules : [];
        $businessHours = is_array($rules['business_hours'] ?? null) ? $rules['business_hours'] : [];

        $this->assertSame('Asia/Jakarta', $calendarSetting->timezone);
        $this->assertSame(true, $businessHours['enabled'] ?? false);
        $this->assertSame('09:00', $businessHours['start_time'] ?? null);
        $this->assertSame('17:00', $businessHours['end_time'] ?? null);
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri'], $businessHours['days'] ?? []);
    }

    public function test_cross_tenant_booking_setting_update_is_scoped_by_context(): void
    {
        [$tenantOne, $adminOne] = $this->createTenantAdmin('tenant-one');
        [$tenantTwo] = $this->createTenantAdmin('tenant-two');

        BookingSetting::query()->create([
            'tenant_id' => $tenantTwo->id,
            'booking_url' => 'https://booking.example.com/tenant-two',
            'sort_order' => 0,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $this->actingAs($adminOne)
            ->post('/tenant/business-data/booking-setting', [
                'booking_url' => 'https://booking.example.com/tenant-one',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('booking_settings', [
            'tenant_id' => $tenantTwo->id,
            'booking_url' => 'https://booking.example.com/tenant-two',
        ]);
        $this->assertDatabaseHas('booking_settings', [
            'tenant_id' => $tenantOne->id,
            'booking_url' => 'https://booking.example.com/tenant-one',
        ]);
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
