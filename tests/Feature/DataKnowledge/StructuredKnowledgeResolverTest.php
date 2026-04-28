<?php

namespace Tests\Feature\DataKnowledge;

use App\Models\Discount;
use App\Models\Faq;
use App\Models\KnowledgeVersion;
use App\Models\Package;
use App\Models\Price;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\Tenant;
use App\Modules\DataKnowledge\Services\CatalogResolver;
use App\Modules\DataKnowledge\Services\FaqResolver;
use App\Modules\DataKnowledge\Services\PackagePricingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StructuredKnowledgeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_data_is_resolved_inside_window(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $this->createKnowledgeVersion($tenant->id, now()->subDay(), now()->addDay());

        $catalog = ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'wedding',
            'name' => 'Wedding Services',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'service_catalog_id' => $catalog->id,
            'code' => 'photo',
            'name' => 'Photography',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $package = Package::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'code' => 'gold',
            'name' => 'Gold Package',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $package->items()->create([
            'tenant_id' => $tenant->id,
            'name' => '2 Photographers',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        Price::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'label' => 'Base Price',
            'currency' => 'IDR',
            'amount' => 10000000,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        Discount::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'name' => 'Ramadan Promo',
            'discount_type' => 'percent',
            'value' => 10,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        Faq::query()->create([
            'tenant_id' => $tenant->id,
            'question' => 'Can we customize?',
            'answer' => 'Yes, based on request.',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $catalogs = app(CatalogResolver::class)->resolveCatalog($tenant->id, now());
        $pricing = app(PackagePricingResolver::class)->resolvePackagePricing($tenant->id, $package->id, now());
        $faqs = app(FaqResolver::class)->resolveFaq($tenant->id, now());

        $this->assertCount(1, $catalogs);
        $this->assertCount(1, $catalogs[0]['products']);
        $this->assertCount(1, $catalogs[0]['products'][0]['packages']);
        $this->assertCount(1, $catalogs[0]['products'][0]['packages'][0]['items']);
        $this->assertNotNull($pricing);
        $this->assertCount(1, $pricing['prices']);
        $this->assertCount(1, $pricing['discounts']);
        $this->assertCount(1, $faqs);
    }

    public function test_expired_and_future_data_are_ignored(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $this->createKnowledgeVersion($tenant->id, now()->subDay(), now()->addDay());

        Faq::query()->create([
            'tenant_id' => $tenant->id,
            'question' => 'Expired',
            'answer' => 'Old.',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDays(3),
            'active_until' => now()->subDay(),
        ]);

        Faq::query()->create([
            'tenant_id' => $tenant->id,
            'question' => 'Future',
            'answer' => 'Soon.',
            'sort_order' => 2,
            'is_active' => true,
            'active_from' => now()->addDay(),
            'active_until' => now()->addDays(3),
        ]);

        $faqs = app(FaqResolver::class)->resolveFaq($tenant->id, now());

        $this->assertSame([], $faqs);
    }

    public function test_open_ended_data_is_active_when_start_has_passed(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $this->createKnowledgeVersion($tenant->id, now()->subDay(), now()->addDay());

        Faq::query()->create([
            'tenant_id' => $tenant->id,
            'question' => 'Open ended',
            'answer' => 'Still valid.',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => null,
        ]);

        $faqs = app(FaqResolver::class)->resolveFaq($tenant->id, now());

        $this->assertCount(1, $faqs);
        $this->assertSame('Open ended', $faqs[0]['question']);
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

        Faq::query()->create([
            'tenant_id' => $tenantA->id,
            'question' => 'Tenant A FAQ',
            'answer' => 'A',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        $this->createKnowledgeVersion($tenantA->id, now()->subDay(), now()->addDay());
        $this->createKnowledgeVersion($tenantB->id, now()->subDay(), now()->addDay());

        $faqs = app(FaqResolver::class)->resolveFaq($tenantB->id, now());

        $this->assertSame([], $faqs);
    }

    public function test_missing_effective_version_returns_safe_empty_result(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        Faq::query()->create([
            'tenant_id' => $tenant->id,
            'question' => 'Should be blocked',
            'answer' => 'Blocked by version gate.',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $faqs = app(FaqResolver::class)->resolveFaq($tenant->id, now());

        $this->assertSame([], $faqs);
    }

    public function test_expired_or_future_versions_block_resolution(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        Faq::query()->create([
            'tenant_id' => $tenant->id,
            'question' => 'Blocked by version date',
            'answer' => 'Blocked.',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $this->createKnowledgeVersion($tenant->id, now()->subDays(4), now()->subDay(), true, 'expired');
        $this->createKnowledgeVersion($tenant->id, now()->addDay(), now()->addDays(3), true, 'future');

        $faqs = app(FaqResolver::class)->resolveFaq($tenant->id, now());

        $this->assertSame([], $faqs);
    }

    public function test_tenant_a_version_cannot_unlock_tenant_b_data(): void
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

        Faq::query()->create([
            'tenant_id' => $tenantB->id,
            'question' => 'Tenant B FAQ',
            'answer' => 'B',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $this->createKnowledgeVersion($tenantA->id, now()->subDay(), now()->addDay());

        $faqs = app(FaqResolver::class)->resolveFaq($tenantB->id, now());

        $this->assertSame([], $faqs);
    }

    public function test_latest_effective_version_is_chosen_deterministically_when_windows_overlap(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $this->createKnowledgeVersion($tenant->id, now()->subDays(3), now()->addDay(), true, 'older-window');
        $newer = $this->createKnowledgeVersion($tenant->id, now()->subDay(), now()->addDays(2), true, 'newer-window');

        $resolved = app(\App\Modules\DataKnowledge\Services\KnowledgeVersionResolver::class)
            ->resolveActiveVersion($tenant->id, now());

        $this->assertNotNull($resolved);
        $this->assertSame($newer->id, $resolved->id);
    }

    public function test_missing_tenant_context_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        app(FaqResolver::class)->resolveFaq(0, now());
    }

    private function createKnowledgeVersion(
        int $tenantId,
        \Carbon\CarbonInterface $from,
        ?\Carbon\CarbonInterface $until,
        bool $isActive = true,
        ?string $name = null,
    ): KnowledgeVersion {
        return KnowledgeVersion::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name ?? 'v1',
            'is_active' => $isActive,
            'effective_from' => $from,
            'effective_until' => $until,
        ]);
    }
}
