<?php

namespace App\Modules\WhatsApp\Services;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\DecisionTrace;
use App\Models\Tenant;
use App\Models\WaInboundMessage;
use App\Modules\Action\Services\ActionDispatcherService;
use App\Modules\Calendar\Services\CalendarAvailabilityService;
use App\Modules\Conversation\Services\ConversationService;
use App\Modules\CoreEngine\Services\TurnPipelineService;
use App\Modules\DataKnowledge\Services\CatalogResolver;
use App\Modules\DataKnowledge\Services\PricelistAssetResolver;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Plans\Services\MonthlyUniqueLeadLimitService;
use Illuminate\Support\Facades\DB;

class WaInboundTurnOrchestratorService
{
    /**
     * @var list<string>
     */
    private const PIPELINE_STEPS = [
        'receive_inbound',
        'deduplicate',
        'load_tenant_and_wa_account',
        'check_tenant_status_and_plan',
        'load_conversation_and_state',
        'interpret_and_extract_entities',
        'retrieve_knowledge',
        'build_and_validate_decision',
        'compose_response',
        'send_reply_action',
        'store_trace',
    ];

    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly TurnPipelineService $turnPipelineService,
        private readonly ActionDispatcherService $actionDispatcherService,
        private readonly CalendarAvailabilityService $calendarAvailabilityService,
        private readonly CatalogResolver $catalogResolver,
        private readonly PricelistAssetResolver $pricelistAssetResolver,
        private readonly FeatureGateService $featureGateService,
        private readonly MonthlyUniqueLeadLimitService $monthlyUniqueLeadLimitService,
    ) {}

    public function process(Tenant $tenant, WaInboundMessage $inboundMessage): void
    {
        $alreadyProcessed = DecisionTrace::query()
            ->where('tenant_id', $tenant->id)
            ->where('trace_key', 'inbound_turn')
            ->where('meta->inbound_message_id', $inboundMessage->id)
            ->exists();

        if ($alreadyProcessed) {
            return;
        }

        $text = $this->extractText($inboundMessage->payload ?? []);
        if ($text === null) {
            return;
        }

        $customerPhone = $this->normalizePhone($inboundMessage->from);
        if ($customerPhone === null) {
            return;
        }

        DB::transaction(function () use ($tenant, $inboundMessage, $text, $customerPhone): void {
            $executedSteps = ['receive_inbound', 'deduplicate', 'load_tenant_and_wa_account'];

            $executedSteps[] = 'check_tenant_status_and_plan';
            $conversation = $this->conversationService->findOrCreateActiveConversation($tenant, $customerPhone);
            $state = $this->conversationService->upsertState($conversation, $tenant);
            $executedSteps[] = 'load_conversation_and_state';

            $this->conversationService->storeMessage($conversation, $tenant, MessageDirection::Inbound, $text, [
                'source' => 'whatsapp',
                'wa_inbound_message_id' => $inboundMessage->id,
                'provider_message_id' => $inboundMessage->provider_message_id,
            ]);

            $features = $this->featureGateService->resolveForTenant($tenant->id);
            $context = $this->buildContext($tenant, $conversation, $state->active_goal, $features, $text, $customerPhone);
            $executedSteps[] = 'retrieve_knowledge';
            $pipeline = $this->turnPipelineService->handle(
                $tenant,
                $conversation,
                $text,
                'Extract deterministic intent/entities and return safe decision JSON.',
                $context
            );
            $executedSteps[] = 'interpret_and_extract_entities';
            $executedSteps[] = 'build_and_validate_decision';

            $executedSteps[] = 'compose_response';
            $replyText = $this->composeReplyText($pipeline);

            $sendTextCandidate = [
                'action' => 'send_text',
                'reasons' => [],
                'meta' => [
                    'send_text' => [
                        'provider' => $inboundMessage->provider,
                        'wa_account_provider_ref' => (string) $inboundMessage->account?->provider_ref,
                        'wa_session_provider_ref' => $inboundMessage->session?->provider_ref,
                        'provider_message_id' => null,
                        'to' => $inboundMessage->from,
                        'text' => $replyText,
                        'meta' => [
                            'source' => 'turn_pipeline',
                            'inbound_message_id' => $inboundMessage->id,
                            'conversation_id' => $conversation->id,
                        ],
                    ],
                ],
            ];

            $dispatchResult = $this->actionDispatcherService->dispatch($tenant, $conversation, $sendTextCandidate);
            $executedSteps[] = 'send_reply_action';
            $handoffDispatch = $this->dispatchHandoffIfRequired(
                $tenant,
                $conversation,
                $inboundMessage,
                $pipeline
            );

            $this->conversationService->storeMessage($conversation, $tenant, MessageDirection::Outbound, $replyText, [
                'source' => 'whatsapp_turn_pipeline',
                'wa_inbound_message_id' => $inboundMessage->id,
                'dispatch_status' => $dispatchResult['status'] ?? null,
                'dispatch_reason' => $dispatchResult['reason'] ?? null,
                'handoff_dispatch_status' => $handoffDispatch['status'] ?? null,
                'handoff_dispatch_reason' => $handoffDispatch['reason'] ?? null,
            ]);

            $executedSteps[] = 'store_trace';
            DecisionTrace::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'action_log_id' => null,
                'trace_key' => 'inbound_turn',
                'token_usage_total' => 0,
                'meta' => [
                    'inbound_message_id' => $inboundMessage->id,
                    'provider_message_id' => $inboundMessage->provider_message_id,
                    'decision' => $pipeline,
                    'final_reply' => $replyText,
                    'handoff_dispatch' => $handoffDispatch,
                    'lead_limit' => $context['lead_limit'] ?? null,
                    'pipeline_steps' => $this->normalizeStepOrder($executedSteps),
                ],
            ]);
        });
    }

    /**
     * @param  list<string>  $executedSteps
     * @return list<string>
     */
    private function normalizeStepOrder(array $executedSteps): array
    {
        $executed = array_values(array_unique($executedSteps));

        return array_values(array_filter(
            self::PIPELINE_STEPS,
            static fn (string $step): bool => in_array($step, $executed, true)
        ));
    }

    /**
     * @param  array<string,mixed>  $features
     * @return array<string,mixed>
     */
    private function buildContext(
        Tenant $tenant,
        Conversation $conversation,
        ?string $activeGoal,
        array $features,
        string $userMessage,
        string $customerPhone
    ): array {
        $policy = [];
        if ($tenant->is_active !== true || ($features['automation_enabled'] ?? false) !== true) {
            $policy['tenant']['blocked_actions'] = ['send_file', 'send_booking_link', 'send_invoice'];
        }

        $leadLimit = (int) ($features['lead_limit'] ?? 0);
        $leadLimitEvaluation = $this->monthlyUniqueLeadLimitService->evaluate($tenant->id, $customerPhone, $leadLimit);
        if (($leadLimitEvaluation['limit_exhausted_for_new_lead'] ?? false) === true) {
            $policy['tenant']['blocked_actions'] = array_values(array_unique(array_merge(
                $policy['tenant']['blocked_actions'] ?? [],
                ['send_file', 'send_booking_link', 'send_invoice']
            )));
        }

        $catalog = $this->catalogResolver->resolveCatalog($tenant->id, now());
        $pricelist = $this->pricelistAssetResolver->resolvePricelistAsset($tenant->id, now());

        $grounding = [
            'price' => ['is_grounded' => $catalog !== [], 'source' => 'structured_catalog'],
            'package' => ['is_grounded' => $catalog !== [], 'source' => 'structured_catalog'],
            'file' => ['is_grounded' => $pricelist !== null, 'source' => 'tenant_asset'],
            'calendar' => ['is_grounded' => false, 'source' => 'calendar_unchecked'],
        ];

        $calendarCheck = [
            'status' => 'blocked',
            'checked' => false,
            'available' => false,
            'reason' => 'calendar_not_required',
            'source' => 'policy',
        ];

        if (($features['calendar_access'] ?? false) === true && $this->shouldCheckCalendar($activeGoal, $userMessage)) {
            $calendarCheck = $this->calendarAvailabilityService->check($tenant, [
                'conversation_id' => $conversation->id,
                'message_hint' => mb_substr($userMessage, 0, 120),
            ]);
            $grounding['calendar'] = [
                'is_grounded' => $calendarCheck['checked'] === true && $calendarCheck['available'] === true,
                'source' => $calendarCheck['source'],
                'reason' => $calendarCheck['reason'],
            ];
        }

        return [
            'grounding' => $grounding,
            'policy' => $policy,
            'permissions' => [
                'allowed_actions' => ['reply_safe_text', 'send_text', 'send_file', 'send_booking_link', 'send_invoice', 'handoff_to_human'],
                'blocked_actions' => [],
            ],
            'calendar_check' => $calendarCheck,
            'availability_checked' => $calendarCheck['checked'] === true,
            'lead_limit' => $leadLimitEvaluation,
        ];
    }

    private function shouldCheckCalendar(?string $activeGoal, string $userMessage): bool
    {
        if ($activeGoal === 'booking' || $activeGoal === 'availability') {
            return true;
        }

        return preg_match('/\b(booking|jadwal|tanggal|available|availability)\b/i', $userMessage) === 1;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function extractText(array $payload): ?string
    {
        $message = $payload['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }

        $conversation = $message['conversation'] ?? null;
        if (is_string($conversation) && trim($conversation) !== '') {
            return trim($conversation);
        }

        $extendedText = $message['extendedTextMessage']['text'] ?? null;
        if (is_string($extendedText) && trim($extendedText) !== '') {
            return trim($extendedText);
        }

        $text = $payload['text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        return null;
    }

    private function normalizePhone(string $raw): ?string
    {
        $normalized = preg_replace('/[^0-9]/', '', $raw);
        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $pipeline
     */
    private function composeReplyText(array $pipeline): string
    {
        $handoffRequired = ($pipeline['handoff_required'] ?? false) === true;
        if ($handoffRequired) {
            return 'Terima kasih, permintaan Anda akan kami teruskan ke admin untuk ditangani lebih lanjut.';
        }

        $message = $pipeline['response_plan']['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        return 'Terima kasih. Kami sedang memproses pesan Anda dengan aman.';
    }

    /**
     * @param  array<string,mixed>  $pipeline
     * @return array{status:string,reason:?string}
     */
    private function dispatchHandoffIfRequired(
        Tenant $tenant,
        Conversation $conversation,
        WaInboundMessage $inboundMessage,
        array $pipeline
    ): array {
        if (($pipeline['handoff_required'] ?? false) !== true) {
            return [
                'status' => 'skipped',
                'reason' => null,
            ];
        }

        $reasonCode = is_string($pipeline['handoff_reason_code'] ?? null) && trim((string) $pipeline['handoff_reason_code']) !== ''
            ? (string) $pipeline['handoff_reason_code']
            : 'handoff_required';
        $priority = is_string($pipeline['handoff_priority'] ?? null) && trim((string) $pipeline['handoff_priority']) !== ''
            ? (string) $pipeline['handoff_priority']
            : 'high';

        $candidate = [
            'action' => 'handoff_to_human',
            'reasons' => [],
            'meta' => [
                'handoff_to_human' => [
                    'reason_code' => $reasonCode,
                    'note' => 'Auto handoff from inbound policy matrix.',
                    'context' => [
                        'priority' => $priority,
                        'source' => 'inbound_turn_pipeline',
                        'inbound_message_id' => $inboundMessage->id,
                        'provider_message_id' => $inboundMessage->provider_message_id,
                        'intent' => $pipeline['intent'] ?? null,
                        'confidence' => $pipeline['confidence'] ?? null,
                        'blocked_actions' => $pipeline['blocked_actions'] ?? [],
                    ],
                ],
            ],
        ];

        $result = $this->actionDispatcherService->dispatch($tenant, $conversation, $candidate);

        return [
            'status' => (string) ($result['status'] ?? 'blocked'),
            'reason' => is_string($result['reason'] ?? null) ? $result['reason'] : null,
        ];
    }
}
