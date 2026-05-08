<?php

namespace Tests\Feature\WhatsApp;

use App\Models\BookingSetting;
use App\Models\DecisionTrace;
use App\Models\KnowledgeVersion;
use App\Models\Package;
use App\Models\PackageAlias;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\Tenant;
use App\Models\TenantAsset;
use App\Models\WaAccount;
use App\Models\WaInboundMessage;
use App\Models\WaSession;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
use App\Modules\Calendar\Services\CalendarAvailabilityService;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\WhatsApp\Services\WaInboundTurnOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConversationLatestLogPackageDetailClarificationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_log_clarification_turn_returns_dynamic_package_detail_and_not_repeated_prompt(): void
    {
        Queue::fake();
        $this->bindFeatureGateAlwaysOn();
        $this->bindCalendarAsAvailable();

        $suffix = (string) now()->timestamp;
        $pkgName = "Paket Dokumentasi {$suffix}";
        $alias = "dokumentasi layanan {$suffix}";
        $item1 = "8 jam coverage {$suffix}";
        $item2 = "2 kameramen profesional {$suffix}";

        $this->bindLlmJsonSequence([
            '{"intent":"greeting","confidence":0.96,"entities":{}}',
            '{"intent":"ask_pricelist","confidence":0.95,"entities":{}}',
            '{"intent":"provide_name","confidence":0.97,"entities":{"customer_name":"Budi Test"}}',
            '{"intent":"provide_event_type","confidence":0.95,"entities":{"event_type":"wedding"}}',
            '{"intent":"ask_package_detail","confidence":0.93,"entities":{}}',
            json_encode([
                'intent' => 'ask_package_detail',
                'confidence' => 0.94,
                'entities' => ['package_query' => $alias],
            ]),
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Klarifikasi Paket',
            'slug' => 'tenant-klarifikasi-paket',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-klarifikasi-paket',
            'phone' => '+628133333333',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-klarifikasi-paket',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $this->seedKnowledgeForDynamicPackage($tenant, $pkgName, $suffix, $alias, $item1, $item2);

        $turns = [
            1 => 'selamat siang ka',
            2 => 'boleh minta pricelistnya ka',
            3 => 'nama saya budi',
            4 => 'untuk acara wedding ka',
            5 => 'bisa tolong jelaskan detail paketnya ka',
            6 => "saya mau tanya soal {$alias} ka",
        ];

        $orchestrator = app(WaInboundTurnOrchestratorService::class);
        $lastTraceId = 0;

        /** @var array<int, DecisionTrace> $turnTraces */
        $turnTraces = [];

        foreach ($turns as $turn => $text) {
            $inbound = WaInboundMessage::query()->create([
                'tenant_id' => $tenant->id,
                'wa_account_id' => $account->id,
                'wa_session_id' => $session->id,
                'provider' => 'meta',
                'provider_message_id' => sprintf('klarifikasi-paket-turn-%d-%s', $turn, $suffix),
                'from' => '+628133333333',
                'to' => '+628222222222',
                'message_type' => 'text',
                'message_timestamp' => now()->addSeconds($turn),
                'payload' => [
                    'message' => [
                        'conversation' => $text,
                    ],
                ],
                'meta' => [
                    'scenario' => 'latest_log_package_detail_clarification',
                    'turn' => $turn,
                ],
            ]);

            $orchestrator->process($tenant, $inbound);

            $trace = DecisionTrace::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', '>', $lastTraceId)
                ->where('trace_key', 'inbound_turn')
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($trace, sprintf('Turn %d harus menulis inbound_turn decision trace.', $turn));
            $turnTraces[$turn] = $trace;
            $lastTraceId = (int) (DecisionTrace::query()->max('id') ?? $lastTraceId);
        }

        $turn5Reply = (string) ($turnTraces[5]->final_reply ?? '');
        $turn6Reply = (string) ($turnTraces[6]->final_reply ?? '');

        $this->assertNotSame('', trim($turn5Reply), 'Turn 5 final reply tidak boleh kosong.');
        $this->assertNotSame('', trim($turn6Reply), 'Turn 6 final reply tidak boleh kosong.');

        // Turn 5: tanpa package_query, sistem belum boleh bocorkan detail item paket
        $this->assertStringNotContainsString(
            $item1,
            $turn5Reply,
            'Turn 5: tanpa package_query, sistem tidak boleh sudah menyebutkan item detail paket.'
        );
        $this->assertStringNotContainsString(
            $item2,
            $turn5Reply,
            'Turn 5: tanpa package_query, sistem tidak boleh sudah menyebutkan item detail paket.'
        );

        // Turn 6 reply harus berbeda dari turn 5
        $this->assertNotSame(
            mb_strtolower($turn5Reply),
            mb_strtolower($turn6Reply),
            'Gap terdeteksi: turn 6 mengulangi reply yang sama persis dengan turn 5.'
        );

        // Turn 6 reply harus memuat nama paket dari seed dinamis
        $this->assertStringContainsString(
            $pkgName,
            $turn6Reply,
            sprintf('Turn 6 reply harus memuat package name "%s" dari seed dinamis.', $pkgName)
        );

        // Turn 6 reply harus memuat minimal satu item dari seed dinamis
        $this->assertTrue(
            str_contains($turn6Reply, $item1) || str_contains($turn6Reply, $item2),
            sprintf('Turn 6 reply harus memuat minimal satu item paket dari seed dinamis ("%s" atau "%s").', $item1, $item2)
        );

        // Verifikasi format reply sesuai kontrak ResponseComposerService (goal=package_explanation)
        $expectedReply = sprintf('Untuk %s, detail photo+video-nya %s, %s.', $pkgName, $item1, $item2);
        $this->assertSame(
            $expectedReply,
            $turn6Reply,
            sprintf('Turn 6 reply tidak sesuai format kontrak. Expected: "%s"', $expectedReply)
        );
    }

    private function seedKnowledgeForDynamicPackage(
        Tenant $tenant,
        string $pkgName,
        string $suffix,
        string $alias,
        string $item1,
        string $item2
    ): void {
        KnowledgeVersion::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'v1',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_until' => now()->addDay(),
        ]);

        $catalog = ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => "wedding-{$suffix}",
            'name' => 'Wedding Services',
            'description' => 'Wedding catalog',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'service_catalog_id' => $catalog->id,
            'code' => "prod-{$suffix}",
            'name' => "Produk Dokumentasi {$suffix}",
            'description' => 'Produk foto dan video',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $package = Package::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'code' => "pkg-{$suffix}",
            'name' => $pkgName,
            'description' => 'Paket dokumentasi dinamis',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        PackageAlias::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'alias' => $alias,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        PackageItem::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'name' => $item1,
            'description' => null,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        PackageItem::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'name' => $item2,
            'description' => null,
            'sort_order' => 2,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        TenantAsset::query()->create([
            'tenant_id' => $tenant->id,
            'asset_type' => 'pricelist',
            'display_name' => 'Pricelist Wedding',
            'original_filename' => 'file_pricelist_wedding.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/pricelist/file_pricelist_wedding.pdf',
            'uploaded_by_user_id' => null,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        BookingSetting::query()->create([
            'tenant_id' => $tenant->id,
            'booking_url' => 'https://booking.example.com/wedding',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
    }

    private function bindFeatureGateAlwaysOn(): void
    {
        $this->app->bind(FeatureGateService::class, fn () => new class extends FeatureGateService
        {
            public function resolveForTenant(?int $tenantId): array
            {
                return [
                    'wa_agent_limit' => 1,
                    'lead_limit' => 0,
                    'calendar_access' => true,
                    'automation_enabled' => true,
                ];
            }
        });
    }

    private function bindCalendarAsAvailable(): void
    {
        $this->app->bind(CalendarAvailabilityService::class, fn () => new class extends CalendarAvailabilityService
        {
            public function __construct() {}

            public function check(Tenant $tenant, array $request): array
            {
                return [
                    'status' => 'available',
                    'checked' => true,
                    'available' => true,
                    'reason' => null,
                    'source' => 'test_stub',
                ];
            }
        });
    }

    /**
     * @param  list<string>  $jsonResponses
     */
    private function bindLlmJsonSequence(array $jsonResponses): void
    {
        $this->app->instance(LlmClientContract::class, new class($jsonResponses) implements LlmClientContract
        {
            /**
             * @var list<string>
             */
            private array $responses;

            /**
             * @param  list<string>  $responses
             */
            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                $content = array_shift($this->responses) ?? '{"intent":"unknown","confidence":0.0,"entities":{}}';

                return new LlmResponse(
                    content: $content,
                    model: 'feature-test-model',
                    totalTokens: 64,
                    raw: [
                        'tenant_id' => $tenantId,
                        'message' => $userMessage,
                    ]
                );
            }
        });
    }
}
