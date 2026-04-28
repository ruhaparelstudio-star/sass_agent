<?php

namespace Tests\Unit\AiLayer;

use App\Models\KnowledgeVersion;
use App\Models\Package;
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
            'saya mau tanya harga paket gold',
            '{"intent":"ask_price","confidence":0.88,"entities":{"package_query":"gold"}}'
        );

        $this->assertSame(Intent::AskPrice, $result->intent);
        $this->assertSame(0.88, $result->confidence);
        $this->assertSame('gold', $result->entities['package_query']);
        $this->assertSame('gold', $result->entities['resolved_package_code']);
        $this->assertSame('Gold Package', $result->entities['resolved_package_name']);
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
