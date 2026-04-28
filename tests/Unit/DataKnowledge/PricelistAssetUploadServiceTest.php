<?php

namespace Tests\Unit\DataKnowledge;

use App\Models\Tenant;
use App\Models\TenantAsset;
use App\Modules\DataKnowledge\Services\PricelistAssetUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PricelistAssetUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_persists_metadata_with_expected_tenant_scope(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $file = UploadedFile::fake()->create('price-list.pdf', 50, 'application/pdf');

        $asset = app(PricelistAssetUploadService::class)->uploadPricelistPdf(
            tenantId: $tenant->id,
            ownerTenantId: $tenant->id,
            file: $file,
            uploadedByUserId: 7,
            displayName: 'Wedding Pricelist',
        );

        $this->assertSame($tenant->id, $asset->tenant_id);
        $this->assertSame('pricelist', $asset->asset_type);
        $this->assertSame('Wedding Pricelist', $asset->display_name);
        Storage::disk($asset->storage_disk)->assertExists($asset->storage_path);
        $this->assertDatabaseHas('tenant_assets', [
            'id' => $asset->id,
            'tenant_id' => $tenant->id,
            'asset_type' => 'pricelist',
        ]);
    }

    public function test_non_pdf_file_is_rejected(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $file = UploadedFile::fake()->create('price-list.txt', 50, 'text/plain');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Only PDF file is allowed.');

        app(PricelistAssetUploadService::class)->uploadPricelistPdf(
            tenantId: $tenant->id,
            ownerTenantId: $tenant->id,
            file: $file,
            uploadedByUserId: 7,
        );
    }

    public function test_cross_tenant_ownership_mismatch_is_rejected_before_write(): void
    {
        Storage::fake('local');

        $tenantA = Tenant::query()->create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'is_active' => true,
        ]);

        $tenantB = Tenant::query()->create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'is_active' => true,
        ]);

        $file = UploadedFile::fake()->create('price-list.pdf', 50, 'application/pdf');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Cross-tenant asset write is not allowed.');

        try {
            app(PricelistAssetUploadService::class)->uploadPricelistPdf(
                tenantId: $tenantA->id,
                ownerTenantId: $tenantB->id,
                file: $file,
                uploadedByUserId: 7,
            );
        } finally {
            $this->assertDatabaseCount('tenant_assets', 0);
            Storage::disk('local')->assertDirectoryEmpty('tenant-assets');
        }
    }

    public function test_inactive_tenant_is_rejected(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => false,
        ]);

        $file = UploadedFile::fake()->create('price-list.pdf', 50, 'application/pdf');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Tenant is inactive.');

        app(PricelistAssetUploadService::class)->uploadPricelistPdf(
            tenantId: $tenant->id,
            ownerTenantId: $tenant->id,
            file: $file,
            uploadedByUserId: 7,
        );
    }

    public function test_invalid_tenant_id_is_rejected(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('price-list.pdf', 50, 'application/pdf');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Tenant id is required.');

        app(PricelistAssetUploadService::class)->uploadPricelistPdf(
            tenantId: 0,
            ownerTenantId: 0,
            file: $file,
            uploadedByUserId: 7,
        );
    }
}
