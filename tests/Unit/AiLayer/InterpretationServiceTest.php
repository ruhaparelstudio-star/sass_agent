<?php

namespace Tests\Unit\AiLayer;

use App\Models\KnowledgeVersion;
use App\Models\Package;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\Tenant;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
use App\Modules\AiLayer\Enums\Intent;
use App\Modules\AiLayer\Services\InterpretationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InterpretationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_provider_json_flows_to_deterministic_classifier(): void
    {
        $tenant = $this->createTenantWithPackage('tenant-one', 'gold', 'Gold Package');

        $this->app->bind(LlmClientContract::class, fn () => new class implements LlmClientContract {
            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                return new LlmResponse(
                    content: '{"intent":"ask_price","confidence":0.88,"entities":{"package_query":"gold","event_date":"21/12/2026","budget":"15.000.000"}}',
                    model: 'fake-model',
                    totalTokens: 42,
                    raw: ['ok' => true],
                );
            }
        });

        $result = app(InterpretationService::class)->interpret($tenant->id, 'berapa harga paket gold?', 'extract intent');

        $this->assertSame(Intent::AskPrice, $result->intent);
        $this->assertSame('gold', $result->entities['resolved_package_code']);
        $this->assertSame('Gold Package', $result->entities['resolved_package_name']);
        $this->assertNull($result->fallbackReason);
    }

    public function test_invalid_provider_json_uses_deterministic_message_fallback(): void
    {
        $tenant = $this->createTenantWithPackage('tenant-one', 'gold', 'Gold Package');

        $this->app->bind(LlmClientContract::class, fn () => new class implements LlmClientContract {
            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                return new LlmResponse(
                    content: '{"intent":"ask_price"',
                    model: 'fake-model',
                    totalTokens: 1,
                    raw: [],
                );
            }
        });

        $result = app(InterpretationService::class)->interpret($tenant->id, 'halo', 'extract intent');

        $this->assertSame(Intent::Greeting, $result->intent);
        $this->assertSame(0.6, $result->confidence);
        $this->assertSame('invalid_json', $result->fallbackReason);
    }

    public function test_provider_error_returns_safe_fallback(): void
    {
        $tenant = $this->createTenantWithPackage('tenant-one', 'gold', 'Gold Package');

        $this->app->bind(LlmClientContract::class, fn () => new class implements LlmClientContract {
            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                throw new RuntimeException('timeout');
            }
        });

        $result = app(InterpretationService::class)->interpret($tenant->id, 'halo', 'extract intent');

        $this->assertSame(Intent::Unknown, $result->intent);
        $this->assertSame('provider_error', $result->fallbackReason);
    }

    public function test_tenant_isolation_is_preserved_in_interpretation_flow(): void
    {
        $tenantA = $this->createTenantWithPackage('tenant-a', 'gold', 'Gold Package A');
        $this->createTenantWithPackage('tenant-b', 'silver', 'Silver Package B');

        $this->app->bind(LlmClientContract::class, fn () => new class implements LlmClientContract {
            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                return new LlmResponse(
                    content: '{"intent":"ask_package","confidence":0.9,"entities":{"package_query":"silver"}}',
                    model: 'fake-model',
                    totalTokens: 42,
                    raw: ['ok' => true],
                );
            }
        });

        $result = app(InterpretationService::class)->interpret($tenantA->id, 'saya mau paket silver', 'extract intent');

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
