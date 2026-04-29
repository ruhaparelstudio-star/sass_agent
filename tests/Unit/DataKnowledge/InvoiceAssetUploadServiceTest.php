<?php

namespace Tests\Unit\DataKnowledge;

use App\Models\Tenant;
use App\Modules\DataKnowledge\Services\InvoiceAssetUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InvoiceAssetUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_invoice_persists_metadata_in_tenant_scope(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $file = UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf');

        $asset = app(InvoiceAssetUploadService::class)->uploadInvoicePdf(
            tenantId: $tenant->id,
            ownerTenantId: $tenant->id,
            file: $file,
            uploadedByUserId: 7,
            displayName: 'Invoice PDF',
        );

        $this->assertSame($tenant->id, $asset->tenant_id);
        $this->assertSame('invoice', $asset->asset_type);
        Storage::disk($asset->storage_disk)->assertExists($asset->storage_path);
    }

    public function test_non_pdf_invoice_upload_is_rejected(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $file = UploadedFile::fake()->create('invoice.txt', 50, 'text/plain');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Only PDF file is allowed.');

        app(InvoiceAssetUploadService::class)->uploadInvoicePdf(
            tenantId: $tenant->id,
            ownerTenantId: $tenant->id,
            file: $file,
        );
    }
}
