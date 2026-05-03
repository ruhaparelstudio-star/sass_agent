<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ActionLog;
use App\Models\BookingSetting;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\DecisionTrace;
use App\Models\Handoff;
use App\Models\KnowledgeVersion;
use App\Models\LeadProfile;
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
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\WhatsApp\Services\WaInboundTurnOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression test untuk conversation log chat aktual:
 *
 * Turn 1: "pagi ka"                                     → greeting
 * Turn 2: "boleh minta pricelistnya"                    → ask_pricelist, blocked missing_name
 * Turn 3: "saya aris egi saputra"                       → provide_name, ask event type
 * Turn 4: "untuk acara wedding ka"                      → provide_event_type, send pricelist
 * Turn 5: "untuk photo video wedding detailnya gimana"  → ask_package_detail
 * Turn 6: "okk ka aku mau booking"                      → booking_intent, blocked missing_event_date
 * Turn 7: "tanggal 30 may 2026 ka"                      → provide_date (normalized to booking_intent)
 *                                                          calendar disabled (Starter plan)
 *                                                          → handoff ke admin (bukan "kami bantu cek")
 */
class ConversationLogChatRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_log_chat_7_turn_calendar_disabled_triggers_handoff_not_false_check_message(): void
    {
        Queue::fake();

        // Starter plan: calendar_access = false — kondisi penyebab bug asli
        $this->bindFeatureGate(leadLimit: 0, automationEnabled: true, calendarAccess: false);

        $this->bindLlmJsonSequence([
            // Turn 1: "pagi ka"
            '{"intent":"greeting","confidence":0.96,"entities":{}}',
            // Turn 2: "boleh minta pricelistnya"
            '{"intent":"ask_pricelist","confidence":0.95,"entities":{}}',
            // Turn 3: "saya aris egi saputra"
            '{"intent":"provide_name","confidence":0.97,"entities":{"customer_name":"Aris Egi Saputra"}}',
            // Turn 4: "untuk acara wedding ka"
            '{"intent":"provide_event_type","confidence":0.95,"entities":{"event_type":"wedding"}}',
            // Turn 5: "untuk photo video wedding detailnya gimana yah"
            '{"intent":"ask_package_detail","confidence":0.93,"entities":{"package_query":"photo video wedding"}}',
            // Turn 6: "okk ka aku mau booking"
            '{"intent":"booking_intent","confidence":0.96,"entities":{"package_query":"photo video wedding"}}',
            // Turn 7: "tanggal 30 may 2026 ka"
            '{"intent":"provide_date","confidence":0.97,"entities":{"event_date":"2026-05-30"}}',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Test',
            'slug' => 'tenant-test-log-chat',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-log-chat',
            'phone' => '+6281234567890',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-log-chat',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $this->seedLogChatKnowledgeAndAssets($tenant);

        $scenarioTurns = [
            1 => 'pagi ka',
            2 => 'boleh minta pricelistnya',
            3 => 'saya aris egi saputra',
            4 => 'untuk acara wedding ka',
            5 => 'untuk photo video wedding detailnya gimana yah',
            6 => 'okk ka aku mau booking',
            7 => 'tanggal 30 may 2026 ka',
        ];

        $orchestrator = app(WaInboundTurnOrchestratorService::class);
        $results = [];
        $lastMessageId = 0;
        $lastTraceId = 0;
        $lastActionLogId = 0;
        $lastOutboundId = 0;

        foreach ($scenarioTurns as $turn => $text) {
            $inbound = WaInboundMessage::query()->create([
                'tenant_id' => $tenant->id,
                'wa_account_id' => $account->id,
                'wa_session_id' => $session->id,
                'provider' => 'meta',
                'provider_message_id' => sprintf('log-chat-turn-%d', $turn),
                'from' => '+6281234567890',
                'to' => '+6289876543210',
                'message_type' => 'text',
                'message_timestamp' => now()->addSeconds($turn),
                'payload' => [
                    'message' => ['conversation' => $text],
                ],
                'meta' => ['scenario' => 'log_chat', 'turn' => $turn],
            ]);

            $orchestrator->process($tenant, $inbound);

            $conversation = Conversation::query()
                ->where('tenant_id', $tenant->id)
                ->where('customer_phone', '6281234567890')
                ->latest('id')
                ->first();

            $state = $conversation
                ? ConversationState::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('conversation_id', $conversation->id)
                    ->first()
                : null;

            $leadProfile = LeadProfile::query()
                ->where('tenant_id', $tenant->id)
                ->where('customer_phone', '6281234567890')
                ->first();

            $newMessages = Message::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', '>', $lastMessageId)
                ->orderBy('id')
                ->get();

            $newDecisionTraces = DecisionTrace::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', '>', $lastTraceId)
                ->orderBy('id')
                ->get();

            $inboundTurnTrace = $newDecisionTraces->firstWhere('trace_key', 'inbound_turn');

            $newActionLogs = ActionLog::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', '>', $lastActionLogId)
                ->orderBy('id')
                ->get();

            $newOutbounds = WaOutboundMessage::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', '>', $lastOutboundId)
                ->orderBy('id')
                ->get();

            $results[$turn] = compact(
                'inbound', 'conversation', 'state', 'leadProfile',
                'newMessages', 'newDecisionTraces', 'inboundTurnTrace',
                'newActionLogs', 'newOutbounds'
            );

            $lastMessageId   = (int) (Message::query()->max('id') ?? 0);
            $lastTraceId     = (int) (DecisionTrace::query()->max('id') ?? 0);
            $lastActionLogId = (int) (ActionLog::query()->max('id') ?? 0);
            $lastOutboundId  = (int) (WaOutboundMessage::query()->max('id') ?? 0);
        }

        $failures = [];

        // --- Sanity: setiap turn harus menghasilkan trace dan outbound ---
        foreach ($results as $turn => $result) {
            $this->expectTrue($failures, $result['conversation'] instanceof Conversation, "Turn {$turn}: conversation row must exist.");
            $this->expectTrue($failures, $result['state'] instanceof ConversationState, "Turn {$turn}: conversation_states row must exist.");
            $this->expectTrue($failures, $result['inboundTurnTrace'] instanceof DecisionTrace, "Turn {$turn}: inbound_turn trace must exist.");
        }

        // --- Turn 1: greeting ---
        $t1 = $results[1];
        $t1Decision = (array) ($t1['inboundTurnTrace']?->decision_json ?? []);
        $this->expectTrue($failures, ($t1Decision['intent'] ?? null) === 'greeting', 'Turn 1: intent must be greeting.');
        $this->expectTrue($failures, $this->hasActionLog($t1['newActionLogs']->all(), 'send_text', 'executed'), 'Turn 1: send_text must be executed.');
        $t1Reply = mb_strtolower((string) ($t1['inboundTurnTrace']?->final_reply ?? ''));
        $this->expectTrue($failures, str_contains($t1Reply, 'asisten'), 'Turn 1: reply must contain greeting/asisten text.');

        // --- Turn 2: ask_pricelist, blocked missing_name ---
        $t2 = $results[2];
        $t2Decision = (array) ($t2['inboundTurnTrace']?->decision_json ?? []);
        $t2Blocked  = (array) ($t2Decision['blocked_actions'] ?? []);
        $this->expectTrue($failures, ($t2Decision['intent'] ?? null) === 'ask_pricelist', 'Turn 2: intent must be ask_pricelist.');
        $this->expectTrue($failures, ($t2['state']?->pending_action ?? null) === 'send_pricelist', 'Turn 2: pending_action must be send_pricelist.');
        $this->expectTrue($failures, ($t2['state']?->current_stage ?? null) === 'collecting_name', 'Turn 2: stage must be collecting_name.');
        $this->expectTrue(
            $failures,
            ($t2Blocked[0]['action'] ?? null) === 'send_file' && ($t2Blocked[0]['reason'] ?? null) === 'missing_name',
            'Turn 2: send_file must be blocked by missing_name.'
        );
        $t2Reply = mb_strtolower((string) ($t2['inboundTurnTrace']?->final_reply ?? ''));
        $this->expectTrue($failures, str_contains($t2Reply, 'nama kakak'), 'Turn 2: reply must ask for name.');

        // --- Turn 3: provide_name, name stored, ask event type ---
        $t3 = $results[3];
        $t3Decision = (array) ($t3['inboundTurnTrace']?->decision_json ?? []);
        $this->expectTrue($failures, ($t3Decision['intent'] ?? null) === 'provide_name', 'Turn 3: intent must be provide_name.');
        $this->expectTrue($failures, ($t3['state']?->customer_name ?? null) === 'Aris Egi Saputra', 'Turn 3: customer_name must be persisted.');
        $this->expectTrue($failures, ($t3['leadProfile']?->full_name ?? null) === 'Aris Egi Saputra', 'Turn 3: lead_profile.full_name must be persisted.');
        $this->expectTrue($failures, ($t3['state']?->current_stage ?? null) === 'collecting_service', 'Turn 3: stage must be collecting_service.');
        $t3Reply = mb_strtolower((string) ($t3['inboundTurnTrace']?->final_reply ?? ''));
        $this->expectTrue($failures, ! str_contains($t3Reply, 'nama kakak'), 'Turn 3: reply must not ask for name again.');

        // --- Turn 4: provide_event_type, pricelist sent ---
        $t4 = $results[4];
        $t4Decision = (array) ($t4['inboundTurnTrace']?->decision_json ?? []);
        $this->expectTrue($failures, ($t4Decision['intent'] ?? null) === 'provide_event_type', 'Turn 4: intent must be provide_event_type.');
        $this->expectTrue($failures, $this->hasActionLog($t4['newActionLogs']->all(), 'send_file', 'executed'), 'Turn 4: send_file must be executed.');
        $this->expectTrue($failures, ($t4['state']?->current_stage ?? null) === 'pricelist_sent', 'Turn 4: stage must be pricelist_sent.');
        $this->expectTrue($failures, ($t4['state']?->pending_action ?? null) === null, 'Turn 4: pending_action must be cleared.');
        $this->expectTrue($failures, ($t4['state']?->service_interest ?? null) === 'wedding', 'Turn 4: service_interest must be wedding.');
        $t4Reply = mb_strtolower((string) ($t4['inboundTurnTrace']?->final_reply ?? ''));
        $this->expectTrue($failures, str_contains($t4Reply, 'pricelist'), 'Turn 4: reply must mention pricelist.');

        // --- Turn 5: ask_package_detail ---
        $t5 = $results[5];
        $t5Decision = (array) ($t5['inboundTurnTrace']?->decision_json ?? []);
        $this->expectTrue($failures, ($t5Decision['intent'] ?? null) === 'ask_package_detail', 'Turn 5: intent must be ask_package_detail.');
        $this->expectTrue($failures, ($t5['state']?->current_stage ?? null) === 'explaining_package', 'Turn 5: stage must be explaining_package.');
        $t5Reply = mb_strtolower((string) ($t5['inboundTurnTrace']?->final_reply ?? ''));
        $this->expectTrue($failures, str_contains($t5Reply, 'detail photo+video'), 'Turn 5: reply must contain package detail.');
        $this->expectTrue($failures, ! str_contains($t5Reply, 'nama kakak'), 'Turn 5: reply must not ask for name.');

        // --- Turn 6: booking_intent, calendar disabled → handoff langsung (fix baru) ---
        // Dengan fix: ketika "booking" ada di pesan, shouldCheckCalendar=true,
        // calendarAccess=false → calendarCheck.reason='calendar_integration_disabled' → handoff dipicu SEKARANG.
        // PRD section 13: calendar disabled → handoff, bukan "kami bantu cek".
        $t6 = $results[6];
        $t6Decision = (array) ($t6['inboundTurnTrace']?->decision_json ?? []);
        $t6Reply    = mb_strtolower((string) ($t6['inboundTurnTrace']?->final_reply ?? ''));
        $this->expectTrue($failures, ($t6Decision['intent'] ?? null) === 'booking_intent', 'Turn 6: intent must be booking_intent.');
        $this->expectTrue($failures, ($t6['state']?->active_goal ?? null) === 'booking', 'Turn 6: active_goal must be booking.');
        $this->expectTrue(
            $failures,
            ($t6Decision['handoff_required'] ?? false) === true,
            'Turn 6: handoff_required must be true — calendar disabled triggers immediate handoff per PRD.'
        );
        $this->expectTrue(
            $failures,
            ($t6Decision['handoff_reason_code'] ?? null) === 'calendar_unavailable',
            'Turn 6: handoff_reason_code must be calendar_unavailable.'
        );
        $this->expectTrue(
            $failures,
            ($t6['state']?->agent_mode ?? null) === 'handoff',
            'Turn 6: agent_mode must become handoff.'
        );
        $this->expectTrue(
            $failures,
            $this->hasActionLog($t6['newActionLogs']->all(), 'handoff_to_human', 'executed'),
            'Turn 6: handoff_to_human must be executed.'
        );
        // Reply harus handoff, bukan "kami bantu cek" (bug lama terjadi di sini)
        $this->expectTrue(
            $failures,
            ! str_contains($t6Reply, 'kami bantu cek') && ! str_contains($t6Reply, 'ketersediaan jadwalnya'),
            'Turn 6: reply must NOT claim to check availability when calendar is disabled.'
        );
        $this->expectTrue(
            $failures,
            str_contains($t6Reply, 'admin') || str_contains($t6Reply, 'teruskan') || str_contains($t6Reply, 'tim kami'),
            'Turn 6: reply must be handoff message redirecting to admin.'
        );

        // Handoff record calendar_unavailable harus dibuat di turn 6
        $calendarHandoff = Handoff::query()
            ->where('tenant_id', $tenant->id)
            ->where('reason_code', 'calendar_unavailable')
            ->first();
        $this->expectTrue(
            $failures,
            $calendarHandoff instanceof Handoff,
            'Turn 6: handoff record with reason_code=calendar_unavailable must exist.'
        );

        // --- Turn 7: provide_date, agent_mode=handoff → auto-reply diblokir ---
        // Setelah handoff di turn 6, turn 7 masih diproses tapi tidak ada auto-reply.
        // event_date_iso tetap dipersistensi ke state (Bug 2 fix tetap bekerja).
        $t7 = $results[7];
        $t7State    = $t7['state'];
        $t7TraceMeta = (array) ($t7['inboundTurnTrace']?->meta ?? []);

        // event_date_iso harus tersimpan ke state meski auto-reply diblokir (Bug 2 fix)
        $this->expectTrue(
            $failures,
            ($t7State?->event_date_iso ?? null) === '2026-05-30',
            'Turn 7: event_date_iso=2026-05-30 must be persisted to conversation_states even when agent_mode=handoff.'
        );

        // Auto-reply harus diblokir karena agent_mode=handoff
        $this->expectTrue(
            $failures,
            ($t7TraceMeta['reply_dispatch']['status'] ?? null) === 'skipped',
            'Turn 7: reply_dispatch must be skipped because agent_mode=handoff.'
        );

        // Tidak ada outbound message baru di turn 7
        $this->expectTrue(
            $failures,
            $t7['newOutbounds']->isEmpty(),
            'Turn 7: no new outbound messages must be sent when agent_mode=handoff.'
        );

        $message = $failures === []
            ? ''
            : "Log chat regression assertions failed:\n- " . implode("\n- ", $failures);

        $this->assertSame([], $failures, $message);
    }

    /**
     * Verifikasi bahwa event_date_iso tersimpan ke state dan tersedia di turn berikutnya.
     * Ini memastikan date tidak hilang antar turn (Bug 2).
     */
    public function test_event_date_iso_persisted_to_state_and_available_in_subsequent_turn(): void
    {
        Queue::fake();
        $this->bindFeatureGate(leadLimit: 0, automationEnabled: true, calendarAccess: false);
        $this->bindLlmJsonSequence([
            // Turn 1: booking dengan tanggal
            '{"intent":"booking_intent","confidence":0.96,"entities":{"package_query":"photo video wedding","event_date":"2026-05-30"}}',
            // Turn 2: pesan lain setelah tanggal tersimpan
            '{"intent":"unclear_message","confidence":0.50,"entities":{}}',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Test',
            'slug' => 'tenant-test-date-persist',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-date-persist',
            'phone' => '+6281234567890',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-date-persist',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $this->seedLogChatKnowledgeAndAssets($tenant);

        LeadProfile::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '6281234567890',
            'full_name' => 'Aris Egi Saputra',
        ]);

        $orchestrator = app(WaInboundTurnOrchestratorService::class);

        // Turn 1: user mengirim tanggal dalam konteks booking
        $inbound1 = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'date-persist-turn-1',
            'from' => '+6281234567890',
            'to' => '+6289876543210',
            'message_type' => 'text',
            'message_timestamp' => now()->addSeconds(1),
            'payload' => ['message' => ['conversation' => 'mau booking photo video wedding tanggal 30 may 2026']],
            'meta' => ['scenario' => 'date_persist', 'turn' => 1],
        ]);
        $orchestrator->process($tenant, $inbound1);

        $conversation = Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', '6281234567890')
            ->latest('id')
            ->firstOrFail();

        $stateAfterTurn1 = ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        // event_date_iso harus tersimpan setelah turn 1
        $this->assertSame(
            '2026-05-30',
            $stateAfterTurn1->event_date_iso,
            'event_date_iso must be persisted to conversation_states after turn 1.'
        );

        // Turn 2: pesan tidak jelas — event_date_iso harus tetap ada di state
        $inbound2 = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'date-persist-turn-2',
            'from' => '+6281234567890',
            'to' => '+6289876543210',
            'message_type' => 'text',
            'message_timestamp' => now()->addSeconds(2),
            'payload' => ['message' => ['conversation' => 'hmm']],
            'meta' => ['scenario' => 'date_persist', 'turn' => 2],
        ]);
        $orchestrator->process($tenant, $inbound2);

        $stateAfterTurn2 = ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        // event_date_iso harus tetap tersimpan setelah turn 2 (tidak hilang)
        $this->assertSame(
            '2026-05-30',
            $stateAfterTurn2->event_date_iso,
            'event_date_iso must be retained in state across turns (must not be lost after turn 2).'
        );
    }

    // --- Helpers ---

    private function bindFeatureGate(int $leadLimit, bool $automationEnabled, bool $calendarAccess): void
    {
        $this->app->bind(FeatureGateService::class, fn () => new class($leadLimit, $automationEnabled, $calendarAccess) extends FeatureGateService
        {
            public function __construct(
                private readonly int $leadLimit,
                private readonly bool $automationEnabled,
                private readonly bool $calendarAccess,
            ) {}

            public function resolveForTenant(?int $tenantId): array
            {
                return [
                    'wa_agent_limit'     => 1,
                    'lead_limit'         => $this->leadLimit,
                    'calendar_access'    => $this->calendarAccess,
                    'automation_enabled' => $this->automationEnabled,
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
            /** @var list<string> */
            private array $responses;

            /** @param list<string> $responses */
            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                $content = array_shift($this->responses) ?? '{"intent":"unknown","confidence":0.0,"entities":{}}';

                return new LlmResponse(
                    content: $content,
                    model: 'test-model',
                    totalTokens: 32,
                    raw: ['tenant_id' => $tenantId, 'message' => $userMessage]
                );
            }
        });
    }

    private function seedLogChatKnowledgeAndAssets(Tenant $tenant): void
    {
        KnowledgeVersion::query()->create([
            'tenant_id'      => $tenant->id,
            'name'           => 'v1',
            'is_active'      => true,
            'effective_from' => now()->subDay(),
            'effective_until' => now()->addDay(),
        ]);

        $catalog = ServiceCatalog::query()->create([
            'tenant_id'   => $tenant->id,
            'code'        => 'wedding',
            'name'        => 'Wedding Services',
            'description' => 'Layanan wedding photography',
            'sort_order'  => 1,
            'is_active'   => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $product = Product::query()->create([
            'tenant_id'          => $tenant->id,
            'service_catalog_id' => $catalog->id,
            'code'               => 'wedding-photo-video-album',
            'name'               => 'Wedding Photo + Video + Album',
            'description'        => 'Paket foto video dan album pernikahan',
            'sort_order'         => 1,
            'is_active'          => true,
            'active_from'        => now()->subDay(),
            'active_until'       => now()->addDay(),
        ]);

        $package = Package::query()->create([
            'tenant_id'   => $tenant->id,
            'product_id'  => $product->id,
            'code'        => 'photo-video-album',
            'name'        => 'Wedding Photo + Video + Album',
            'description' => 'Paket utama',
            'sort_order'  => 1,
            'is_active'   => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        foreach (['photo video wedding', 'photo dan video wedding', 'photo video'] as $i => $alias) {
            PackageAlias::query()->create([
                'tenant_id'   => $tenant->id,
                'package_id'  => $package->id,
                'alias'       => $alias,
                'sort_order'  => $i + 1,
                'is_active'   => true,
                'active_from' => now()->subDay(),
                'active_until' => now()->addDay(),
            ]);
        }

        $items = ['Durasi 11 jam', '1 Photographer', '2 Videographer'];
        foreach ($items as $i => $name) {
            PackageItem::query()->create([
                'tenant_id'   => $tenant->id,
                'package_id'  => $package->id,
                'name'        => $name,
                'description' => null,
                'sort_order'  => $i + 1,
                'is_active'   => true,
                'active_from' => now()->subDay(),
                'active_until' => now()->addDay(),
            ]);
        }

        TenantAsset::query()->create([
            'tenant_id'         => $tenant->id,
            'asset_type'        => 'pricelist',
            'display_name'      => 'Pricelist Wedding',
            'original_filename' => 'file_pricelist_wedding.pdf',
            'storage_disk'      => 'local',
            'storage_path'      => 'tenant-assets/pricelist/file_pricelist_wedding.pdf',
            'uploaded_by_user_id' => null,
            'sort_order'        => 1,
            'is_active'         => true,
            'active_from'       => now()->subDay(),
            'active_until'      => now()->addDay(),
        ]);

        BookingSetting::query()->create([
            'tenant_id'   => $tenant->id,
            'booking_url' => 'https://booking.example.com/wedding',
            'sort_order'  => 1,
            'is_active'   => true,
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
     * @param  list<string>  $failures
     */
    private function expectTrue(array &$failures, bool $condition, string $message): void
    {
        if (! $condition) {
            $failures[] = $message;
        }
    }
}
