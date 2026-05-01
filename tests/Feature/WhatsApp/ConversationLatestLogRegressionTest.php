<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ActionLog;
use App\Models\BookingSetting;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\DecisionTrace;
use App\Models\Handoff;
use App\Models\KnowledgeVersion;
use App\Models\Message;
use App\Models\Package;
use App\Models\PackageAlias;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\Tenant;
use App\Models\TenantAsset;
use App\Models\WaAccount;
use App\Models\WaInboundMessage;
use App\Models\WaOutboundMessage;
use App\Models\WaSession;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
use App\Modules\Calendar\Services\CalendarAvailabilityService;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\WhatsApp\Services\WaInboundTurnOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConversationLatestLogRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_log_regression_detects_repeated_prompt_gap_on_provide_preference(): void
    {
        Queue::fake();
        $this->bindFeatureGateAlwaysOn();
        $this->bindCalendarAsAvailable();
        $this->bindLlmJsonSequence([
            '{"intent":"greeting","confidence":0.96,"entities":{}}',
            '{"intent":"ask_pricelist","confidence":0.95,"entities":{}}',
            '{"intent":"provide_name","confidence":0.97,"entities":{"customer_name":"Aris Egi Saputra"}}',
            '{"intent":"provide_event_type","confidence":0.95,"entities":{"event_type":"wedding","package_query":"paket wedding"}}',
            '{"intent":"ask_package_detail","confidence":0.93,"entities":{}}',
            '{"intent":"ask_package","confidence":0.92,"entities":{"package_query":"paket wedding"}}',
            '{"intent":"provide_preference","confidence":0.91,"entities":{"event_type":"wedding"}}',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Latest Log',
            'slug' => 'tenant-latest-log',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-latest-log',
            'phone' => '+628111111111',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-latest-log',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $this->seedKnowledgeAndAssets($tenant);

        $turns = [
            1 => 'malam ka',
            2 => 'boleh minta pricelistnya ka',
            3 => 'nama saya aris egi saputra',
            4 => 'untuk acara wedding sih ka',
            5 => 'boleh minta detailnya ka',
            6 => 'paket untuk wedding ka',
            7 => 'saya mau acara nya dirumah saja ka',
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
                'provider_message_id' => sprintf('latest-log-gap-turn-%d', $turn),
                'from' => '+628111111111',
                'to' => '+628222222222',
                'message_type' => 'text',
                'message_timestamp' => now()->addSeconds($turn),
                'payload' => [
                    'message' => [
                        'conversation' => $text,
                    ],
                ],
                'meta' => [
                    'scenario' => 'latest_log_prompt_gap',
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

            $this->assertNotNull($trace, sprintf('Turn %d should write inbound_turn decision trace.', $turn));
            $turnTraces[$turn] = $trace;
            $lastTraceId = (int) (DecisionTrace::query()->max('id') ?? $lastTraceId);
        }

        $turn6Reply = mb_strtolower((string) ($turnTraces[6]->final_reply ?? ''));
        $turn7Reply = mb_strtolower((string) ($turnTraces[7]->final_reply ?? ''));

        $this->assertNotSame('', trim($turn6Reply), 'Turn 6 final reply should not be empty.');
        $this->assertNotSame('', trim($turn7Reply), 'Turn 7 final reply should not be empty.');

        // Regression target: provide_preference should move context forward, not repeat prior generic prompt.
        $this->assertNotSame(
            $turn6Reply,
            $turn7Reply,
            'Gap detected: turn provide_preference still repeats same generic prompt as previous ask_package turn.'
        );

        $this->assertStringNotContainsString(
            'boleh ceritakan kebutuhan acaranya',
            $turn7Reply,
            'Gap detected: provide_preference reply falls back to generic re-ask instead of using known context.'
        );
    }

    public function test_latest_log_regression_handoff_mode_blocks_auto_reply_but_persists_trace_and_action(): void
    {
        Queue::fake();
        $this->bindFeatureGateAlwaysOn();
        $this->bindCalendarAsAvailable();
        $this->bindLlmJsonSequence([
            '{"intent":"greeting","confidence":0.96,"entities":{}}',
            '{"intent":"complaint","confidence":0.95,"entities":{}}',
            '{"intent":"ask_package","confidence":0.92,"entities":{}}',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Handoff Guard',
            'slug' => 'tenant-handoff-guard',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-handoff-guard',
            'phone' => '+628111111111',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-handoff-guard',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $this->seedKnowledgeAndAssets($tenant);

        $orchestrator = app(WaInboundTurnOrchestratorService::class);

        $firstInbound = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'latest-log-handoff-turn-1',
            'from' => '+628111111111',
            'to' => '+628222222222',
            'message_type' => 'text',
            'message_timestamp' => now(),
            'payload' => ['message' => ['conversation' => 'halo ka']],
            'meta' => ['scenario' => 'latest_log_handoff_guard', 'turn' => 1],
        ]);
        $orchestrator->process($tenant, $firstInbound);

        $complaintInbound = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'latest-log-handoff-turn-2',
            'from' => '+628111111111',
            'to' => '+628222222222',
            'message_type' => 'text',
            'message_timestamp' => now()->addSecond(),
            'payload' => ['message' => ['conversation' => 'kenapa ko di ulang2 pertanyaannya']],
            'meta' => ['scenario' => 'latest_log_handoff_guard', 'turn' => 2],
        ]);
        $orchestrator->process($tenant, $complaintInbound);

        $conversation = Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', '628111111111')
            ->latest('id')
            ->firstOrFail();

        $stateAfterComplaint = ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        $this->assertSame('handoff', $stateAfterComplaint->agent_mode, 'Conversation should enter handoff mode after complaint turn.');

        $outboundMessageCountBefore = Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->count();

        $waOutboundCountBefore = WaOutboundMessage::query()->where('tenant_id', $tenant->id)->count();
        $traceMaxIdBefore = (int) (DecisionTrace::query()->max('id') ?? 0);
        $actionMaxIdBefore = (int) (ActionLog::query()->max('id') ?? 0);

        $postHandoffInbound = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'latest-log-handoff-turn-3',
            'from' => '+628111111111',
            'to' => '+628222222222',
            'message_type' => 'text',
            'message_timestamp' => now()->addSeconds(2),
            'payload' => ['message' => ['conversation' => 'di sini ada paket apa saja yah ka']],
            'meta' => ['scenario' => 'latest_log_handoff_guard', 'turn' => 3],
        ]);
        $orchestrator->process($tenant, $postHandoffInbound);

        $outboundMessageCountAfter = Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->count();
        $this->assertSame(
            $outboundMessageCountBefore,
            $outboundMessageCountAfter,
            'No additional outbound message should be created after conversation is in handoff mode.'
        );

        $waOutboundCountAfter = WaOutboundMessage::query()->where('tenant_id', $tenant->id)->count();
        $this->assertSame(
            $waOutboundCountBefore,
            $waOutboundCountAfter,
            'No additional wa_outbound_messages should be queued after handoff mode is active.'
        );

        $newInboundTrace = DecisionTrace::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $traceMaxIdBefore)
            ->where('trace_key', 'inbound_turn')
            ->latest('id')
            ->first();

        $this->assertNotNull($newInboundTrace, 'Post-handoff inbound should still persist inbound_turn decision trace.');

        $newActionLogs = ActionLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $actionMaxIdBefore)
            ->orderBy('id')
            ->get();

        $this->assertTrue(
            $this->hasActionLogWithReason($newActionLogs->all(), 'reply_safe_text', 'blocked', 'mode_handoff_blocked'),
            'Post-handoff inbound should block reply_safe_text with reason mode_handoff_blocked.'
        );

        $this->assertTrue(
            $this->hasActionLog($newActionLogs->all(), 'handoff_to_human', 'executed'),
            'Post-handoff inbound should still create handoff_to_human action log with executed status.'
        );

        $this->assertDatabaseHas('handoffs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
        ]);

        $latestState = ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();
        $this->assertSame('handoff', $latestState->agent_mode, 'Conversation must stay in handoff mode after follow-up inbound.');
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

    private function seedKnowledgeAndAssets(Tenant $tenant): void
    {
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
            'name' => 'Wedding Services',
            'description' => 'Wedding photography catalog',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'service_catalog_id' => $catalog->id,
            'code' => 'wedding-photo-video',
            'name' => 'Wedding Photo + Video',
            'description' => 'Photo and video package',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $package = Package::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'code' => 'photo-video',
            'name' => 'Photo + Video Wedding',
            'description' => 'Main package',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        PackageAlias::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'alias' => 'paket wedding',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        PackageAlias::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'alias' => 'photo dan video',
            'sort_order' => 2,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        PackageItem::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'name' => '8 jam dokumentasi',
            'description' => null,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        PackageItem::query()->create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'name' => '2 fotografer + 1 videografer',
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

    /**
     * @param  list<ActionLog>  $logs
     */
    private function hasActionLog(array $logs, string $action, string $status): bool
    {
        foreach ($logs as $log) {
            if ($log->action === $action && $log->status === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ActionLog>  $logs
     */
    private function hasActionLogWithReason(array $logs, string $action, string $status, string $reason): bool
    {
        foreach ($logs as $log) {
            if ($log->action === $action && $log->status === $status && $log->reason === $reason) {
                return true;
            }
        }

        return false;
    }
}
