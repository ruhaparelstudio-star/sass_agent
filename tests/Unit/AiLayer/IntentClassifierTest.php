<?php

namespace Tests\Unit\AiLayer;

use App\Models\KnowledgeVersion;
use App\Models\Package;
use App\Models\PackageAlias;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\Tenant;
use App\Modules\AiLayer\Enums\Intent;
use App\Modules\AiLayer\Services\DeterministicIntentClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntentClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_classification_and_entity_extraction(): void
    {
        $tenant = $this->createTenantWithPackage('tenant-one', 'gold', 'Gold Package');

        $classifier = app(DeterministicIntentClassifier::class);

        $result = $classifier->classify(
            $tenant->id,
            'saya mau tanya harga paket gold untuk tanggal 21/12/2026 budget 15.000.000',
            '{"intent":"ask_price","confidence":0.88,"entities":{"package_query":"gold","event_date":"21/12/2026","budget":"15.000.000"}}'
        );

        $this->assertSame(Intent::AskPrice, $result->intent);
        $this->assertSame(0.88, $result->confidence);
        $this->assertSame('gold', $result->entities['package_query']);
        $this->assertSame('gold', $result->entities['resolved_package_code']);
        $this->assertSame('Gold Package', $result->entities['resolved_package_name']);
        $this->assertSame('2026-12-21', $result->entities['event_date_iso']);
        $this->assertSame(15000000, $result->entities['budget_amount']);
        $this->assertFalse($result->entities['is_correction']);
        $this->assertSame([], $result->entities['corrected_fields']);
        $this->assertNull($result->fallbackReason);
    }

    public function test_invalid_json_falls_back_to_safe_unknown(): void
    {
        $tenant = $this->createTenantWithPackage('tenant-one', 'gold', 'Gold Package');

        $classifier = app(DeterministicIntentClassifier::class);

        $result = $classifier->classify(
            $tenant->id,
            'paket apa saja?',
            '{"intent":"ask_package"'
        );

        $this->assertSame(Intent::Unknown, $result->intent);
        $this->assertSame(0.0, $result->confidence);
        $this->assertNull($result->entities['package_query']);
        $this->assertNull($result->entities['resolved_package_code']);
        $this->assertNull($result->entities['resolved_package_name']);
        $this->assertNull($result->entities['event_date_iso']);
        $this->assertNull($result->entities['budget_amount']);
        $this->assertFalse($result->entities['is_correction']);
        $this->assertSame([], $result->entities['corrected_fields']);
        $this->assertSame('invalid_json', $result->fallbackReason);
    }

    public function test_unknown_package_is_not_hallucinated(): void
    {
        $tenant = $this->createTenantWithPackage('tenant-one', 'gold', 'Gold Package');

        $classifier = app(DeterministicIntentClassifier::class);

        $result = $classifier->classify(
            $tenant->id,
            'berapa harga paket platinum?',
            '{"intent":"ask_price","confidence":0.81,"entities":{"package_query":"platinum"}}'
        );

        $this->assertSame(Intent::AskPrice, $result->intent);
        $this->assertSame('platinum', $result->entities['package_query']);
        $this->assertNull($result->entities['resolved_package_code']);
        $this->assertNull($result->entities['resolved_package_name']);
        $this->assertNull($result->entities['event_date_iso']);
        $this->assertNull($result->entities['budget_amount']);
        $this->assertFalse($result->entities['is_correction']);
        $this->assertSame([], $result->entities['corrected_fields']);
    }

    public function test_package_resolution_is_tenant_isolated(): void
    {
        $tenantA = $this->createTenantWithPackage('tenant-a', 'gold', 'Gold Package A');
        $this->createTenantWithPackage('tenant-b', 'silver', 'Silver Package B');

        $classifier = app(DeterministicIntentClassifier::class);

        $result = $classifier->classify(
            $tenantA->id,
            'saya mau paket silver',
            '{"intent":"ask_package","confidence":0.9,"entities":{"package_query":"silver"}}'
        );

        $this->assertSame(Intent::AskPackage, $result->intent);
        $this->assertSame('silver', $result->entities['package_query']);
        $this->assertNull($result->entities['resolved_package_code']);
        $this->assertNull($result->entities['resolved_package_name']);
        $this->assertNull($result->entities['event_date_iso']);
        $this->assertNull($result->entities['budget_amount']);
        $this->assertFalse($result->entities['is_correction']);
        $this->assertSame([], $result->entities['corrected_fields']);
    }

    public function test_package_alias_is_resolved_from_same_tenant_only(): void
    {
        $tenantA = $this->createTenantWithPackage('tenant-a', 'gold', 'Gold Package A');
        $tenantB = $this->createTenantWithPackage('tenant-b', 'silver', 'Silver Package B');
        $goldPackageA = Package::query()->where('tenant_id', $tenantA->id)->where('code', 'gold')->firstOrFail();
        $silverPackageB = Package::query()->where('tenant_id', $tenantB->id)->where('code', 'silver')->firstOrFail();

        PackageAlias::query()->create([
            'tenant_id' => $tenantA->id,
            'package_id' => $goldPackageA->id,
            'alias' => 'hemat',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        PackageAlias::query()->create([
            'tenant_id' => $tenantB->id,
            'package_id' => $silverPackageB->id,
            'alias' => 'hemat',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $classifier = app(DeterministicIntentClassifier::class);

        $result = $classifier->classify(
            $tenantA->id,
            'saya mau paket hemat',
            '{"intent":"ask_package","confidence":0.9,"entities":{"package_query":"hemat"}}'
        );

        $this->assertSame(Intent::AskPackage, $result->intent);
        $this->assertSame('hemat', $result->entities['package_query']);
        $this->assertSame('gold', $result->entities['resolved_package_code']);
        $this->assertSame('Gold Package A', $result->entities['resolved_package_name']);
    }

    public function test_invalid_or_ambiguous_date_and_budget_return_null(): void
    {
        $tenant = $this->createTenantWithPackage('tenant-one', 'gold', 'Gold Package');

        $classifier = app(DeterministicIntentClassifier::class);

        $result = $classifier->classify(
            $tenant->id,
            'budget fleksibel, tanggal nanti saya kabari',
            '{"intent":"booking_intent","confidence":0.7,"entities":{"package_query":"gold","event_date":"nanti","budget":"fleksibel"}}'
        );

        $this->assertSame(Intent::BookingIntent, $result->intent);
        $this->assertSame('gold', $result->entities['resolved_package_code']);
        $this->assertNull($result->entities['event_date_iso']);
        $this->assertNull($result->entities['budget_amount']);
    }

    public function test_explicit_correction_sets_correction_flags_and_fields(): void
    {
        $tenant = $this->createTenantWithPackage('tenant-one', 'gold', 'Gold Package');

        $classifier = app(DeterministicIntentClassifier::class);

        $result = $classifier->classify(
            $tenant->id,
            'koreksi, bukan gold tapi silver. budget saya revisi jadi 20 juta',
            '{"intent":"ask_price","confidence":0.86,"entities":{"package_query":"silver","correction":true,"corrected_fields":["package_query","budget"],"budget":"20 juta"}}'
        );

        $this->assertSame(Intent::AskPrice, $result->intent);
        $this->assertTrue($result->entities['is_correction']);
        $this->assertSame(['package_query', 'budget'], $result->entities['corrected_fields']);
        $this->assertSame(20000000, $result->entities['budget_amount']);
    }

    public function test_ask_pricelist_intent_is_recognized(): void
    {
        $tenant = $this->createTenantWithPackage('tenant-one', 'gold', 'Gold Package');

        $classifier = app(DeterministicIntentClassifier::class);

        $result = $classifier->classify(
            $tenant->id,
            'tolong kirim pricelist paket gold',
            '{"intent":"ask_pricelist","confidence":0.91,"entities":{"package_query":"gold"}}'
        );

        $this->assertSame(Intent::AskPricelist, $result->intent);
        $this->assertSame('gold', $result->entities['resolved_package_code']);
    }

    private function createTenantWithPackage(string $slug, string $packageCode, string $packageName): Tenant
    {
        $tenant = Tenant::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
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
            'name' => 'Photography',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        Package::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'code' => $packageCode,
            'name' => $packageName,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        return $tenant;
    }
}
