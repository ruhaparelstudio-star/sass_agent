<?php

namespace Tests\Feature\AdminUi;

use App\Models\Tenant;
use App\Models\TenantAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantAssetUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_upload_pricelist_pdf(): void
    {
        Storage::fake('local');
        [$tenant, $admin] = $this->createTenantAdmin('tenant-one');

        $response = $this->actingAs($admin)->post('/tenant/business-data/assets/pricelist', [
            'display_name' => 'April Pricelist',
            'file' => UploadedFile::fake()->create('pricelist.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();

        $asset = TenantAsset::query()->where('tenant_id', $tenant->id)->where('asset_type', 'pricelist')->first();
        $this->assertNotNull($asset);
        Storage::disk((string) $asset->storage_disk)->assertExists((string) $asset->storage_path);
    }

    public function test_tenant_admin_can_upload_invoice_pdf(): void
    {
        Storage::fake('local');
        [$tenant, $admin] = $this->createTenantAdmin('tenant-one');

        $response = $this->actingAs($admin)->post('/tenant/business-data/assets/invoice', [
            'display_name' => 'Invoice Template',
            'file' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();

        $asset = TenantAsset::query()->where('tenant_id', $tenant->id)->where('asset_type', 'invoice')->first();
        $this->assertNotNull($asset);
        Storage::disk((string) $asset->storage_disk)->assertExists((string) $asset->storage_path);
    }

    public function test_non_pdf_upload_is_rejected(): void
    {
        Storage::fake('local');
        [, $admin] = $this->createTenantAdmin('tenant-one');

        $this->actingAs($admin)->post('/tenant/business-data/assets/pricelist', [
            'display_name' => 'Bad File',
            'file' => UploadedFile::fake()->create('bad.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('tenant_assets', 0);
    }

    public function test_non_tenant_admin_is_forbidden_to_upload_assets(): void
    {
        Storage::fake('local');
        User::factory()->create(['role' => 'superadmin']);
        $superadmin = User::query()->firstOrFail();

        $this->actingAs($superadmin)->post('/tenant/business-data/assets/invoice', [
            'display_name' => 'Invoice',
            'file' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ])->assertForbidden();

        $this->assertDatabaseCount('tenant_assets', 0);
    }

    public function test_uploaded_asset_list_is_tenant_scoped(): void
    {
        Storage::fake('local');
        [$tenantOne, $adminOne] = $this->createTenantAdmin('tenant-one');
        [$tenantTwo] = $this->createTenantAdmin('tenant-two');

        TenantAsset::query()->create([
            'tenant_id' => $tenantTwo->id,
            'asset_type' => 'pricelist',
            'display_name' => 'Other Tenant',
            'original_filename' => 'other.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/pricelist/other.pdf',
            'uploaded_by_user_id' => null,
            'sort_order' => 0,
            'is_active' => true,
            'active_from' => now(),
        ]);

        $this->actingAs($adminOne)->post('/tenant/business-data/assets/pricelist', [
            'display_name' => 'Own Tenant',
            'file' => UploadedFile::fake()->create('mine.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $response = $this->actingAs($adminOne)->get('/tenant/business-data');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/BusinessData', false)
            ->has('assets', 1)
            ->where('assets.0.display_name', 'Own Tenant')
        );
        $this->assertDatabaseHas('tenant_assets', [
            'tenant_id' => $tenantOne->id,
            'display_name' => 'Own Tenant',
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
