<?php

namespace Tests\Feature\DataKnowledge;

use App\Models\KnowledgeVersion;
use App\Models\Tenant;
use App\Models\TenantAsset;
use App\Modules\DataKnowledge\Services\PricelistAssetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricelistAssetResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_asset_is_resolved_inside_window(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $this->createKnowledgeVersion($tenant->id, now()->subDay(), now()->addDay());

        TenantAsset::query()->create([
            'tenant_id' => $tenant->id,
            'asset_type' => 'pricelist',
            'display_name' => 'Price List April',
            'original_filename' => 'price-list-april.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/pricelist/1/price-list-april.pdf',
            'uploaded_by_user_id' => 10,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $asset = app(PricelistAssetResolver::class)->resolvePricelistAsset($tenant->id, now());

        $this->assertNotNull($asset);
        $this->assertSame('pricelist', $asset['asset_type']);
        $this->assertSame('Price List April', $asset['display_name']);
    }

    public function test_expired_and_future_assets_are_ignored(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $this->createKnowledgeVersion($tenant->id, now()->subDay(), now()->addDay());

        TenantAsset::query()->create([
            'tenant_id' => $tenant->id,
            'asset_type' => 'pricelist',
            'display_name' => 'Expired',
            'original_filename' => 'expired.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/pricelist/1/expired.pdf',
            'uploaded_by_user_id' => 10,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDays(3),
            'active_until' => now()->subDay(),
        ]);

        TenantAsset::query()->create([
            'tenant_id' => $tenant->id,
            'asset_type' => 'pricelist',
            'display_name' => 'Future',
            'original_filename' => 'future.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/pricelist/1/future.pdf',
            'uploaded_by_user_id' => 10,
            'sort_order' => 2,
            'is_active' => true,
            'active_from' => now()->addDay(),
            'active_until' => now()->addDays(3),
        ]);

        $asset = app(PricelistAssetResolver::class)->resolvePricelistAsset($tenant->id, now());

        $this->assertNull($asset);
    }

    public function test_cross_tenant_data_access_is_blocked_by_scope(): void
    {
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

        TenantAsset::query()->create([
            'tenant_id' => $tenantA->id,
            'asset_type' => 'pricelist',
            'display_name' => 'Tenant A Price List',
            'original_filename' => 'tenant-a.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/pricelist/tenant-a.pdf',
            'uploaded_by_user_id' => 10,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $this->createKnowledgeVersion($tenantA->id, now()->subDay(), now()->addDay());
        $this->createKnowledgeVersion($tenantB->id, now()->subDay(), now()->addDay());

        $asset = app(PricelistAssetResolver::class)->resolvePricelistAsset($tenantB->id, now());

        $this->assertNull($asset);
    }

    public function test_missing_effective_version_returns_safe_empty_result(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        TenantAsset::query()->create([
            'tenant_id' => $tenant->id,
            'asset_type' => 'pricelist',
            'display_name' => 'Blocked by version gate',
            'original_filename' => 'blocked.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/pricelist/blocked.pdf',
            'uploaded_by_user_id' => 10,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $asset = app(PricelistAssetResolver::class)->resolvePricelistAsset($tenant->id, now());

        $this->assertNull($asset);
    }

    private function createKnowledgeVersion(int $tenantId, $from, $until): KnowledgeVersion
    {
        return KnowledgeVersion::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'v1',
            'is_active' => true,
            'effective_from' => $from,
            'effective_until' => $until,
        ]);
    }
}
