<?php

namespace App\Modules\DataKnowledge\Services;

use App\Models\Tenant;
use App\Models\TenantAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvoiceAssetUploadService
{
    public function uploadInvoicePdf(
        int $tenantId,
        int $ownerTenantId,
        UploadedFile $file,
        ?int $uploadedByUserId = null,
        ?string $displayName = null,
    ): TenantAsset {
        $this->assertTenantId($tenantId);
        $this->assertTenantId($ownerTenantId);
        $this->assertTenantOwnership($tenantId, $ownerTenantId);

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            throw new HttpException(404, 'Tenant not found.');
        }

        if (! $tenant->is_active) {
            throw new HttpException(422, 'Tenant is inactive.');
        }

        $this->assertPdfFile($file);

        $disk = (string) config('dataknowledge.assets.disk', config('filesystems.default', 'local'));
        $baseDir = trim((string) config('dataknowledge.assets.invoice_dir', 'tenant-assets/invoice'), '/');
        $directory = $baseDir.'/'.$tenantId;
        $storedPath = Storage::disk($disk)->putFile($directory, $file);

        if (! is_string($storedPath) || $storedPath === '') {
            throw new HttpException(500, 'Failed to store asset file.');
        }

        return TenantAsset::query()->create([
            'tenant_id' => $tenantId,
            'asset_type' => 'invoice',
            'display_name' => $displayName,
            'original_filename' => $file->getClientOriginalName(),
            'storage_disk' => $disk,
            'storage_path' => $storedPath,
            'uploaded_by_user_id' => $uploadedByUserId,
            'sort_order' => 0,
            'is_active' => true,
            'active_from' => now(),
            'active_until' => null,
        ]);
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(422, 'Tenant id is required.');
        }
    }

    private function assertTenantOwnership(int $tenantId, int $ownerTenantId): void
    {
        if ($tenantId !== $ownerTenantId) {
            throw new HttpException(403, 'Cross-tenant asset write is not allowed.');
        }
    }

    private function assertPdfFile(UploadedFile $file): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getClientMimeType());

        if ($extension !== 'pdf' && $mime !== 'application/pdf') {
            throw new HttpException(422, 'Only PDF file is allowed.');
        }
    }
}
