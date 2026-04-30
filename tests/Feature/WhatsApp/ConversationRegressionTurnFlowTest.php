<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ActionLog;
use App\Models\BookingSetting;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\DecisionTrace;
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
use App\Modules\Calendar\Services\CalendarAvailabilityService;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\WhatsApp\Services\WaInboundTurnOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConversationRegressionTurnFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_turn_by_turn_regression_for_context_state_and_dispatch_outcome_sync(): void
    {
        Queue::fake();
        $this->bindFeatureGateAlwaysOn();
        $this->bindCalendarAsAvailable();
        $this->bindLlmJsonSequence([
            '{"intent":"greeting","confidence":0.96,"entities":{}}',
            '{"intent":"ask_pricelist","confidence":0.95,"entities":{}}',
            '{"intent":"provide_name","confidence":0.97,"entities":{"customer_name":"Aris Egi"}}',
            '{"intent":"provide_event_type","confidence":0.95,"entities":{"event_type":"wedding","package_query":"photo graper wedding"}}',
            '{"intent":"ask_package_detail","confidence":0.93,"entities":{}}',
            '{"intent":"booking_intent","confidence":0.96,"entities":{"package_query":"photo dan video","event_date":"2026-07-20"}}',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Studio Wedding',
            'slug' => 'studio-wedding',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-studio-wedding',
            'phone' => '+628111111111',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-studio-wedding',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $this->seedKnowledgeAndAssets($tenant);

        $scenarioTurns = [
            1 => 'siang ka',
            2 => 'boleh minta pricelistnya',
            3 => 'nama aris egi',
            4 => 'photo graper wedding ka',
            5 => 'untuk detail photo videonya bisa dijelaskan ka',
            6 => 'ouh gitu yaudah aku mau ambil paket photo dan video ka',
        ];

        $results = [];
        $lastMessageId = 0;
        $lastTraceId = 0;
        $lastActionLogId = 0;
        $lastOutboundId = 0;
        $orchestrator = app(WaInboundTurnOrchestratorService::class);

        foreach ($scenarioTurns as $turn => $text) {
            $inbound = WaInboundMessage::query()->create([
                'tenant_id' => $tenant->id,
                'wa_account_id' => $account->id,
                'wa_session_id' => $session->id,
                'provider' => 'meta',
                'provider_message_id' => sprintf('scenario-turn-%d', $turn),
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
                    'scenario' => 'conversation_regression',
                    'turn' => $turn,
                ],
            ]);

            $orchestrator->process($tenant, $inbound);

            $conversation = Conversation::query()
                ->where('tenant_id', $tenant->id)
                ->where('customer_phone', '628111111111')
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
                ->where('customer_phone', '628111111111')
                ->first();

            $newMessages = Message::query()
                ->where('id', '>', $lastMessageId)
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->get();
            $newInboundTurnTrace = DecisionTrace::query()
                ->where('id', '>', $lastTraceId)
                ->where('tenant_id', $tenant->id)
                ->where('trace_key', 'inbound_turn')
                ->orderBy('id')
                ->first();
            $newActionLogs = ActionLog::query()
                ->where('id', '>', $lastActionLogId)
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->get();
            $newOutbounds = WaOutboundMessage::query()
                ->where('id', '>', $lastOutboundId)
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->get();

            $results[$turn] = [
                'inbound' => $inbound,
                'conversation' => $conversation,
                'state' => $state,
                'lead_profile' => $leadProfile,
                'trace' => $newInboundTurnTrace,
                'new_messages' => $newMessages,
                'new_action_logs' => $newActionLogs,
                'new_outbounds' => $newOutbounds,
            ];

            $lastMessageId = (int) (Message::query()->max('id') ?? 0);
            $lastTraceId = (int) (DecisionTrace::query()->max('id') ?? 0);
            $lastActionLogId = (int) (ActionLog::query()->max('id') ?? 0);
            $lastOutboundId = (int) (WaOutboundMessage::query()->max('id') ?? 0);
        }

        $failures = [];

        foreach ($results as $turn => $result) {
            $this->expectTrue($failures, $result['conversation'] instanceof Conversation, "Turn {$turn}: conversation should exist.");
            $this->expectTrue($failures, $result['state'] instanceof ConversationState, "Turn {$turn}: conversation_state should exist.");
            $this->expectTrue($failures, $result['lead_profile'] instanceof LeadProfile, "Turn {$turn}: lead_profile should exist.");
            $this->expectTrue($failures, $result['trace'] instanceof DecisionTrace, "Turn {$turn}: inbound_turn decision_trace should exist.");
            $this->expectTrue(
                $failures,
                $this->hasInboundMessageText($result['new_messages']->all(), $scenarioTurns[$turn]),
                "Turn {$turn}: inbound message should be stored in messages table."
            );
            $this->expectTrue(
                $failures,
                $this->hasOutboundMessage($result['new_messages']->all()),
                "Turn {$turn}: outbound message should be stored in messages table."
            );
            $this->expectTrue($failures, $result['new_action_logs']->count() > 0, "Turn {$turn}: action_logs should be written.");
            $this->expectTrue($failures, $result['new_outbounds']->count() > 0, "Turn {$turn}: wa_outbound_messages should be written.");
        }

        $turn1 = $results[1];
        $turn1Decision = (array) ($turn1['trace']?->decision_json ?? []);
        $this->expectTrue($failures, ($turn1Decision['intent'] ?? null) === 'greeting', 'Turn 1: intent should be greeting.');
        $this->expectTrue($failures, (($turn1['state']?->current_stage ?? null) === 'new') || (($turn1['state']?->current_stage ?? null) === 'greeting'), 'Turn 1: current_stage should be new/greeting.');
        $this->expectTrue($failures, $this->hasActionLog($turn1['new_action_logs']->all(), 'send_text', 'executed'), 'Turn 1: send_text should be executed.');

        $turn2 = $results[2];
        $turn2Decision = (array) ($turn2['trace']?->decision_json ?? []);
        $turn2Blocked = (array) ($turn2Decision['blocked_actions'] ?? []);
        $this->expectTrue($failures, ($turn2Decision['intent'] ?? null) === 'ask_pricelist', 'Turn 2: intent should be ask_pricelist.');
        $this->expectTrue(
            $failures,
            in_array((string) ($turn2['state']?->active_goal ?? ''), ['pricing', 'collect_lead_info', 'send_pricelist'], true),
            'Turn 2: active_goal should move to pricing/collect_lead_info/send_pricelist.'
        );
        $this->expectTrue(
            $failures,
            isset($turn2Blocked[0]['action'], $turn2Blocked[0]['reason']) && $turn2Blocked[0]['action'] === 'send_file' && $turn2Blocked[0]['reason'] === 'missing_name',
            'Turn 2: send_file should be blocked by missing_name.'
        );

        $turn3 = $results[3];
        $turn3Decision = (array) ($turn3['trace']?->decision_json ?? []);
        $turn3Entities = (array) ($turn3Decision['entities'] ?? []);
        $this->expectTrue($failures, ($turn3['lead_profile']?->full_name ?? null) === 'Aris Egi', 'Turn 3: lead_profile.full_name should be "Aris Egi".');
        $this->expectTrue($failures, ($turn3Decision['intent'] ?? null) === 'provide_name', 'Turn 3: intent should be provide_name.');
        $this->expectTrue($failures, ($turn3Entities['name'] ?? null) === 'Aris Egi', 'Turn 3: decision trace should carry known entity name "Aris Egi".');

        $turn4 = $results[4];
        $turn4Decision = (array) ($turn4['trace']?->decision_json ?? []);
        $turn4Entities = (array) ($turn4Decision['entities'] ?? []);
        $this->expectTrue($failures, $this->hasActionLog($turn4['new_action_logs']->all(), 'send_file', 'executed'), 'Turn 4: send_file must be executed.');
        $this->expectTrue($failures, $this->hasOutboundFile($turn4['new_outbounds']->all(), 'file_pricelist_wedding.pdf'), 'Turn 4: pricelist file must be queued to wa_outbound_messages.');
        $this->expectTrue($failures, ($turn4Entities['event_type'] ?? null) === 'wedding', 'Turn 4: service interest/event_type should be stored as wedding.');

        $turn5 = $results[5];
        $turn5Decision = (array) ($turn5['trace']?->decision_json ?? []);
        $turn5Entities = (array) ($turn5Decision['entities'] ?? []);
        $turn5Reply = (string) ($turn5['trace']?->final_reply ?? '');
        $this->expectTrue($failures, ($turn5Decision['intent'] ?? null) === 'ask_package_detail', 'Turn 5: intent should be ask_package_detail.');
        $this->expectTrue(
            $failures,
            ($turn5Entities['event_type'] ?? null) === 'wedding',
            'Turn 5: event_type should still be wedding (service interest retained from previous turn).'
        );
        $this->expectTrue(
            $failures,
            ! str_contains(mb_strtolower($turn5Reply), 'nama kakak'),
            'Turn 5: assistant should not ask name again.'
        );
        $this->expectTrue(
            $failures,
            ! str_contains(mb_strtolower($turn5Reply), 'layanan untuk acara apa'),
            'Turn 5: assistant should not ask service from zero again.'
        );
        $this->expectTrue(
            $failures,
            str_contains(mb_strtolower($turn5Reply), 'detail photo+video'),
            'Turn 5: package detail reply should use prior context and provide detail, not a reset prompt.'
        );

        $turn6 = $results[6];
        $turn6Decision = (array) ($turn6['trace']?->decision_json ?? []);
        $turn6Reply = (string) ($turn6['trace']?->final_reply ?? '');
        $bookingActionLog = $this->firstActionLog($turn6['new_action_logs']->all(), 'send_booking_link');
        $bookingDispatchSucceeded = $bookingActionLog !== null && $bookingActionLog->status === 'executed';
        $bookingUrlOutbounded = $this->hasOutboundTextContaining($turn6['new_outbounds']->all(), 'https://booking.example.com/wedding');

        $this->expectTrue($failures, ($turn6Decision['intent'] ?? null) === 'booking_intent', 'Turn 6: intent should be booking_intent.');
        $this->expectTrue(
            $failures,
            $bookingDispatchSucceeded === $bookingUrlOutbounded,
            'Turn 6: booking link should appear in outbound only when send_booking_link dispatch succeeds.'
        );

        if (! $bookingDispatchSucceeded) {
            $this->expectTrue(
                $failures,
                ! str_contains(mb_strtolower($turn6Reply), 'link booking sudah saya kirim')
                && ! str_contains(mb_strtolower($turn6Reply), 'pricelist sudah saya kirim'),
                'Turn 6: reply must not claim file/link was sent when dispatch failed.'
            );
        }

        $this->expectTrue(
            $failures,
            $this->hasActionLog($turn6['new_action_logs']->all(), 'handoff_to_human', 'executed'),
            'Turn 6: handoff_to_human should be executed after booking intent.'
        );

        $message = $failures === []
            ? ''
            : "Conversation regression assertions failed:\n- ".implode("\n- ", $failures);

        $this->assertSame([], $failures, $message);
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
            'alias' => 'photo graper wedding',
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
     * @param  list<Message>  $messages
     */
    private function hasInboundMessageText(array $messages, string $text): bool
    {
        foreach ($messages as $message) {
            if ($message->getRawOriginal('direction') !== 'inbound') {
                continue;
            }

            if ($message->content === $text) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Message>  $messages
     */
    private function hasOutboundMessage(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message->getRawOriginal('direction') === 'outbound') {
                return true;
            }
        }

        return false;
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
    private function firstActionLog(array $logs, string $action): ?ActionLog
    {
        foreach ($logs as $log) {
            if ($log->action === $action) {
                return $log;
            }
        }

        return null;
    }

    /**
     * @param  list<WaOutboundMessage>  $outbounds
     */
    private function hasOutboundFile(array $outbounds, string $filename): bool
    {
        foreach ($outbounds as $outbound) {
            if ($outbound->message_type !== 'file') {
                continue;
            }

            $outboundFilename = (string) ($outbound->payload['file']['filename'] ?? '');
            if ($outboundFilename === $filename) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<WaOutboundMessage>  $outbounds
     */
    private function hasOutboundTextContaining(array $outbounds, string $needle): bool
    {
        foreach ($outbounds as $outbound) {
            $text = (string) ($outbound->payload['text'] ?? '');
            if ($text !== '' && str_contains($text, $needle)) {
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
