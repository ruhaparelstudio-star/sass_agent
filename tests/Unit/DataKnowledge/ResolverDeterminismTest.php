<?php

namespace Tests\Unit\DataKnowledge;

use App\Models\Discount;
use App\Models\KnowledgeVersion;
use App\Models\Package;
use App\Models\PackageAlias;
use App\Models\Price;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\Tenant;
use App\Modules\DataKnowledge\Services\CatalogResolver;
use App\Modules\DataKnowledge\Services\PackagePricingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolverDeterminismTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_tree_order_is_stable_when_sort_order_is_same(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        KnowledgeVersion::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'v1',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_until' => now()->addDay(),
        ]);

        $catalogA = ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'a',
            'name' => 'A',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $catalogB = ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'b',
            'name' => 'B',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        Product::query()->create([
            'tenant_id' => $tenant->id,
            'service_catalog_id' => $catalogA->id,
            'code' => 'a1',
            'name' => 'A1',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        Product::query()->create([
            'tenant_id' => $tenant->id,
            'service_catalog_id' => $catalogB->id,
            'code' => 'b1',
            'name' => 'B1',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $rows = app(CatalogResolver::class)->resolveCatalog($tenant->id, now());

        $this->assertSame([$catalogA->id, $catalogB->id], array_column($rows, 'id'));
    }

    public function test_price_and_discount_include_only_valid_rows(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        KnowledgeVersion::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'v1',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_until' => now()->addDay(),
        ]);

        $catalog = ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'wedding',
            'name' => 'Wedding',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'service_catalog_id' => $catalog->id,
            'code' => 'photo',
            'name' => 'Photo',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $package = Package::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'code' => 'gold',
            'name' => 'Gold',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        Price::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'label' => 'Active Price',
            'currency' => 'IDR',
            'amount' => 1000,
            'sort_order' => 2,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        Price::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'label' => 'Expired Price',
            'currency' => 'IDR',
            'amount' => 900,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDays(4),
            'active_until' => now()->subDay(),
        ]);

        Discount::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'name' => 'Active Discount',
            'discount_type' => 'fixed',
            'value' => 100,
            'sort_order' => 2,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => null,
        ]);

        Discount::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'name' => 'Future Discount',
            'discount_type' => 'fixed',
            'value' => 200,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->addDay(),
            'active_until' => now()->addDays(2),
        ]);

        $pricing = app(PackagePricingResolver::class)->resolvePackagePricing($tenant->id, $package->id, now());

        $this->assertNotNull($pricing);
        $this->assertCount(1, $pricing['prices']);
        $this->assertSame('Active Price', $pricing['prices'][0]['label']);
        $this->assertCount(1, $pricing['discounts']);
        $this->assertSame('Active Discount', $pricing['discounts'][0]['name']);
    }

    public function test_catalog_aliases_are_tenant_and_window_filtered(): void
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

        KnowledgeVersion::query()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'v1-a',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_until' => now()->addDay(),
        ]);
        KnowledgeVersion::query()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'v1-b',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_until' => now()->addDay(),
        ]);

        $catalogA = ServiceCatalog::query()->create([
            'tenant_id' => $tenantA->id,
            'code' => 'wedding',
            'name' => 'Wedding',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        $productA = Product::query()->create([
            'tenant_id' => $tenantA->id,
            'service_catalog_id' => $catalogA->id,
            'code' => 'photo',
            'name' => 'Photo',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        $packageA = Package::query()->create([
            'tenant_id' => $tenantA->id,
            'product_id' => $productA->id,
            'code' => 'gold',
            'name' => 'Gold',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        PackageAlias::query()->create([
            'tenant_id' => $tenantA->id,
            'package_id' => $packageA->id,
            'alias' => 'hemat',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        PackageAlias::query()->create([
            'tenant_id' => $tenantA->id,
            'package_id' => $packageA->id,
            'alias' => 'legacy',
            'sort_order' => 2,
            'is_active' => true,
            'active_from' => now()->subDays(5),
            'active_until' => now()->subDay(),
        ]);

        $catalogB = ServiceCatalog::query()->create([
            'tenant_id' => $tenantB->id,
            'code' => 'wedding',
            'name' => 'Wedding',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        $productB = Product::query()->create([
            'tenant_id' => $tenantB->id,
            'service_catalog_id' => $catalogB->id,
            'code' => 'video',
            'name' => 'Video',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        $packageB = Package::query()->create([
            'tenant_id' => $tenantB->id,
            'product_id' => $productB->id,
            'code' => 'silver',
            'name' => 'Silver',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        PackageAlias::query()->create([
            'tenant_id' => $tenantB->id,
            'package_id' => $packageB->id,
            'alias' => 'hemat-b',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $rows = app(CatalogResolver::class)->resolveCatalog($tenantA->id, now());

        $aliases = $rows[0]['products'][0]['packages'][0]['aliases'];
        $this->assertCount(1, $aliases);
        $this->assertSame('hemat', $aliases[0]['alias']);
    }
}
