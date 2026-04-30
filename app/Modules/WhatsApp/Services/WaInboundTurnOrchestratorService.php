<?php

namespace App\Modules\WhatsApp\Services;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\ConversationState;
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
    private const INTERPRETATION_INSTRUCTION = <<<'TEXT'
Return ONLY valid JSON object (no prose, no markdown, no code fence) with exact schema:
{
  "intent": "string",
  "confidence": 0.0,
  "entities": {
    "package_query": null,
    "customer_name": null,
    "event_type": null,
    "event_date": null,
    "location": null,
    "budget": null,
    "budget_min": null,
    "budget_max": null,
    "package_interest": null,
    "invoice_reference": null,
    "correction": false,
    "corrected_fields": []
  }
}
Allowed intent values:
greeting, first_contact, intro_interest, ask_package, ask_package_detail, ask_price, ask_pricelist, ask_availability, provide_name, provide_date, provide_event_type, provide_budget, provide_preference, booking_intent, request_handoff, complaint, payment_related, topic_switch, correction, unclear_message, unknown
If unsure set intent="unknown", confidence=0.0, keep entities null/empty.
TEXT;

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
            if ($conversation->wa_account_id === null && $inboundMessage->wa_account_id !== null) {
                $conversation->forceFill(['wa_account_id' => $inboundMessage->wa_account_id])->save();
            }
            $state = $this->conversationService->upsertState($conversation, $tenant);
            $initialAgentMode = (string) $state->agent_mode;
            $executedSteps[] = 'load_conversation_and_state';

            $inboundConversationMessage = $this->conversationService->storeMessage($conversation, $tenant, MessageDirection::Inbound, $text, [
                'source' => 'whatsapp',
                'wa_inbound_message_id' => $inboundMessage->id,
                'provider_message_id' => $inboundMessage->provider_message_id,
            ], 'text', $inboundMessage->payload ?? null);

            $features = $this->featureGateService->resolveForTenant($tenant->id);
            $context = $this->buildContext($tenant, $conversation, $state, $features, $inboundMessage, $text, $customerPhone);
            $executedSteps[] = 'retrieve_knowledge';
            $pipeline = $this->turnPipelineService->handle(
                $tenant,
                $conversation,
                $text,
                self::INTERPRETATION_INSTRUCTION,
                $context
            );
            $executedSteps[] = 'interpret_and_extract_entities';
            $executedSteps[] = 'build_and_validate_decision';
            $this->conversationService->syncLeadFromEntities($conversation, $tenant, is_array($pipeline['entities'] ?? null) ? $pipeline['entities'] : []);
            $this->persistDurableStateFromPipeline($tenant, $conversation, $state, $pipeline);

            $executedSteps[] = 'compose_response';
            $replyText = $this->composeReplyText($pipeline);
            $this->storeConversationContext($tenant, $conversation, $pipeline, $replyText);

            $dispatchResult = [
                'status' => 'skipped',
                'reason' => null,
            ];
            $replyDispatch = [
                'status' => 'skipped',
                'reason' => null,
            ];

            if ($this->shouldSendAutoReply($pipeline, $initialAgentMode)) {
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
                $replyDispatch = [
                    'status' => (string) ($dispatchResult['status'] ?? 'blocked'),
                    'reason' => is_string($dispatchResult['reason'] ?? null) ? $dispatchResult['reason'] : null,
                ];
            } else {
                $replyDispatch = [
                    'status' => 'skipped',
                    'reason' => 'skipped_mode_no_auto_reply',
                ];
            }
            $executedSteps[] = 'send_reply_action';

            $handoffDispatch = $this->dispatchHandoffIfRequired(
                $tenant,
                $conversation,
                $inboundMessage,
                $pipeline
            );

            $executedSteps[] = 'store_trace';
            $pipeline['trace']['pipeline_steps'] = $this->orderedUniqueSteps($executedSteps);
            $trace = DecisionTrace::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'message_id' => $inboundConversationMessage->id,
                'action_log_id' => null,
                'trace_key' => 'inbound_turn',
                'token_usage_total' => 0,
                'input_snapshot_json' => [
                    'user_message' => $text,
                    'provider_message_id' => $inboundMessage->provider_message_id,
                    'wa_inbound_message_id' => $inboundMessage->id,
                    'customer_phone' => $customerPhone,
                ],
                'interpretation_json' => [
                    'intent' => $pipeline['intent'] ?? null,
                    'confidence' => $pipeline['confidence'] ?? null,
                    'entities' => $pipeline['entities'] ?? [],
                    'fallback_reason' => $pipeline['trace']['fallback_reason'] ?? null,
                ],
                'decision_json' => $pipeline,
                'validators_json' => [
                    'order' => $pipeline['trace']['validator_order'] ?? [],
                    'status' => (($pipeline['blocked_actions'] ?? []) === []) ? 'passed' : 'blocked',
                    'fallback_reason' => $pipeline['trace']['fallback_reason'] ?? null,
                    'validation_failure_reason' => $pipeline['trace']['validation_failure_reason'] ?? null,
                ],
                'blocked_actions_json' => $pipeline['blocked_actions'] ?? [],
                'grounding_refs_json' => $pipeline['grounding_refs'] ?? [],
                'final_reply' => $replyText,
                'model_name' => null,
                'token_usage_json' => [
                    'total' => 0,
                    'source' => 'not_captured',
                ],
                'meta' => [
                    'inbound_message_id' => $inboundMessage->id,
                    'provider_message_id' => $inboundMessage->provider_message_id,
                    'decision' => $pipeline,
                    'final_reply' => $replyText,
                    'handoff_dispatch' => $handoffDispatch,
                    'reply_dispatch' => $replyDispatch,
                    'lead_limit' => $context['lead_limit'] ?? null,
                    'pipeline_steps' => $pipeline['trace']['pipeline_steps'] ?? [],
                ],
            ]);

            if (($replyDispatch['status'] ?? null) === 'executed') {
                $this->conversationService->storeMessage($conversation, $tenant, MessageDirection::Outbound, $replyText, [
                    'source' => 'whatsapp_turn_pipeline',
                    'wa_inbound_message_id' => $inboundMessage->id,
                    'dispatch_status' => $dispatchResult['status'] ?? null,
                    'dispatch_reason' => $dispatchResult['reason'] ?? null,
                    'handoff_dispatch_status' => $handoffDispatch['status'] ?? null,
                    'handoff_dispatch_reason' => $handoffDispatch['reason'] ?? null,
                ], 'text', null, $pipeline['grounding_refs'] ?? [], $trace->id);
            }
        });
    }

    /**
     * @param  list<string>  $executedSteps
     * @return list<string>
     */
    private function orderedUniqueSteps(array $executedSteps): array
    {
        $unique = array_values(array_unique($executedSteps));
        $ordered = [];

        foreach (self::PIPELINE_STEPS as $step) {
            if (in_array($step, $unique, true)) {
                $ordered[] = $step;
            }
        }

        foreach ($unique as $step) {
            if (! in_array($step, self::PIPELINE_STEPS, true)) {
                $ordered[] = $step;
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string,mixed>  $pipeline
     */
    private function persistDurableStateFromPipeline(
        Tenant $tenant,
        Conversation $conversation,
        ConversationState $state,
        array $pipeline
    ): void {
        $entities = is_array($pipeline['entities'] ?? null) ? $pipeline['entities'] : [];
        $blockedAction = $pipeline['blocked_actions'][0]['action'] ?? null;
        $allowedAction = $pipeline['allowed_actions'][0] ?? null;

        $pendingAction = $state->pending_action;
        if (is_string($blockedAction) && trim($blockedAction) !== '') {
            $pendingAction = trim($blockedAction);
        } elseif (is_string($allowedAction) && in_array($allowedAction, ['send_file', 'send_booking_link'], true)) {
            $pendingAction = null;
        }

        $customerName = $this->firstNonEmptyString([
            $entities['customer_name'] ?? null,
            $entities['name'] ?? null,
            $state->customer_name,
        ]);

        $eventType = $this->firstNonEmptyString([
            $entities['event_type'] ?? null,
            $state->event_type,
        ]);

        $serviceInterest = $this->firstNonEmptyString([
            $entities['service_interest'] ?? null,
            $entities['event_type'] ?? null,
            $entities['package_interest'] ?? null,
            $entities['package_query'] ?? null,
            $state->service_interest,
        ]);

        $packageInterest = $this->firstNonEmptyString([
            $entities['package_interest'] ?? null,
            $entities['resolved_package_name'] ?? null,
            $entities['package_query'] ?? null,
            $state->package_interest,
        ]);

        $selectedPackage = $this->firstNonEmptyString([
            $entities['resolved_package_name'] ?? null,
            $entities['package_query'] ?? null,
            $state->selected_package,
        ]);

        $this->conversationService->upsertState($conversation, $tenant, [
            'customer_name' => $customerName,
            'event_type' => $eventType,
            'service_interest' => $serviceInterest,
            'package_interest' => $packageInterest,
            'selected_package' => $selectedPackage,
            'pending_action' => $pendingAction,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPersistedEntityContext(ConversationState $state): array
    {
        $name = $this->firstNonEmptyString([$state->customer_name]);
        $eventType = $this->firstNonEmptyString([$state->event_type]);
        $serviceInterest = $this->firstNonEmptyString([$state->service_interest]);
        $packageInterest = $this->firstNonEmptyString([$state->package_interest]);
        $selectedPackage = $this->firstNonEmptyString([$state->selected_package]);

        return [
            'customer_name' => $name,
            'name' => $name,
            'event_type' => $eventType,
            'service_interest' => $serviceInterest,
            'package_interest' => $packageInterest,
            'package_query' => $packageInterest,
            'resolved_package_name' => $selectedPackage,
            'selected_package' => $selectedPackage,
        ];
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $features
     * @return array<string,mixed>
     */
    private function buildContext(
        Tenant $tenant,
        Conversation $conversation,
        ConversationState $state,
        array $features,
        WaInboundMessage $inboundMessage,
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
        $packageDetailLookup = $this->buildPackageDetailLookup($catalog);
        $pricelist = $this->pricelistAssetResolver->resolvePricelistAsset($tenant->id, now());
        $previousBlockedAction = $this->resolvePreviousBlockedAction($tenant, $conversation);

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

        if (($features['calendar_access'] ?? false) === true && $this->shouldCheckCalendar($state->active_goal, $userMessage)) {
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
            'previous_blocked_action' => $previousBlockedAction,
            'entities' => $this->buildPersistedEntityContext($state),
            'package_detail_lookup' => $packageDetailLookup,
            'pricelist_asset' => $pricelist,
            'delivery_channel' => [
                'provider' => $inboundMessage->provider,
                'wa_account_provider_ref' => (string) ($inboundMessage->account?->provider_ref ?? ''),
                'wa_session_provider_ref' => $inboundMessage->session?->provider_ref,
                'to' => $inboundMessage->from,
            ],
        ];
    }

    /**
     * @return array{action:string,reason:string}|null
     */
    private function resolvePreviousBlockedAction(Tenant $tenant, Conversation $conversation): ?array
    {
        $lastContext = ConversationContext::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->first();

        $reason = is_string($lastContext?->reason) ? trim($lastContext->reason) : '';
        if ($reason === '' || ! str_contains($reason, ':')) {
            return null;
        }

        [$action, $blockedReason] = explode(':', $reason, 2);
        $action = trim($action);
        $blockedReason = trim($blockedReason);
        if ($action === '' || $blockedReason === '') {
            return null;
        }

        return [
            'action' => $action,
            'reason' => $blockedReason,
        ];
    }

    private function shouldCheckCalendar(?string $activeGoal, string $userMessage): bool
    {
        if ($activeGoal === 'booking' || $activeGoal === 'availability') {
            return true;
        }

        return preg_match('/\b(booking|book|reservasi|jadwal|tanggal|available|availability|deal)\b/i', $userMessage) === 1
            || preg_match('/\bambil\s+paket\b/i', $userMessage) === 1;
    }

    /**
     * @param  array<int,array<string,mixed>>  $catalog
     * @return array<string,array{package_name:string,items:array<int,string>}>
     */
    private function buildPackageDetailLookup(array $catalog): array
    {
        $lookup = [];

        foreach ($catalog as $catalogRow) {
            if (! is_array($catalogRow)) {
                continue;
            }

            $products = $catalogRow['products'] ?? null;
            if (! is_array($products)) {
                continue;
            }

            foreach ($products as $product) {
                if (! is_array($product)) {
                    continue;
                }

                $packages = $product['packages'] ?? null;
                if (! is_array($packages)) {
                    continue;
                }

                foreach ($packages as $package) {
                    if (! is_array($package)) {
                        continue;
                    }

                    $packageName = trim((string) ($package['name'] ?? ''));
                    if ($packageName === '') {
                        continue;
                    }

                    $items = [];
                    foreach (($package['items'] ?? []) as $itemRow) {
                        if (! is_array($itemRow)) {
                            continue;
                        }

                        $name = trim((string) ($itemRow['name'] ?? ''));
                        if ($name === '') {
                            continue;
                        }

                        $items[] = $name;
                    }

                    if ($items === []) {
                        continue;
                    }

                    $normalizedSummary = [
                        'package_name' => $packageName,
                        'items' => array_values(array_unique($items)),
                    ];

                    $keys = [];
                    $code = strtolower(trim((string) ($package['code'] ?? '')));
                    if ($code !== '') {
                        $keys[] = $code;
                    }

                    $nameKey = strtolower($packageName);
                    if ($nameKey !== '') {
                        $keys[] = $nameKey;
                    }

                    foreach (($package['aliases'] ?? []) as $aliasRow) {
                        if (! is_array($aliasRow)) {
                            continue;
                        }

                        $alias = strtolower(trim((string) ($aliasRow['alias'] ?? '')));
                        if ($alias === '') {
                            continue;
                        }

                        $keys[] = $alias;
                    }

                    foreach (array_values(array_unique($keys)) as $key) {
                        $lookup[$key] = $normalizedSummary;
                    }
                }
            }
        }

        return $lookup;
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
    private function shouldSendAutoReply(array $pipeline, string $initialAgentMode): bool
    {
        $mode = $this->normalizeAgentMode($initialAgentMode);
        if (in_array($mode, ['paused', 'handoff'], true)) {
            return false;
        }

        $reason = is_string($pipeline['trace']['reason'] ?? null) ? (string) $pipeline['trace']['reason'] : null;
        if (in_array($reason, ['mode_paused_blocked', 'mode_handoff_blocked'], true)) {
            return false;
        }

        return true;
    }

    private function normalizeAgentMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        return match ($normalized) {
            'ai' => 'assistant',
            default => $normalized,
        };
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
            $normalized = trim($message);
            if (! $this->isInternalPlaceholderReply($normalized)) {
                return $normalized;
            }
        }

        return 'Terima kasih. Kami sedang memproses pesan Anda dengan aman.';
    }

    private function isInternalPlaceholderReply(string $reply): bool
    {
        return str_contains(mb_strtolower($reply), 'allowed candidate');
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

    /**
     * @param  array<string,mixed>  $pipeline
     */
    private function storeConversationContext(
        Tenant $tenant,
        Conversation $conversation,
        array $pipeline,
        string $replyText
    ): void {
        $intent = is_string($pipeline['intent'] ?? null) ? $pipeline['intent'] : 'unknown';
        $decision = is_string($pipeline['decision'] ?? null) ? $pipeline['decision'] : 'unknown';
        $confidence = is_numeric($pipeline['confidence'] ?? null)
            ? number_format((float) $pipeline['confidence'], 2, '.', '')
            : '0.00';

        $blockedAction = null;
        $firstBlocked = $pipeline['blocked_actions'][0] ?? null;
        if (is_array($firstBlocked)) {
            $action = is_string($firstBlocked['action'] ?? null) ? $firstBlocked['action'] : null;
            $reason = is_string($firstBlocked['reason'] ?? null) ? $firstBlocked['reason'] : null;
            if ($action !== null && $reason !== null) {
                $blockedAction = $action.':'.$reason;
            }
        }

        $reason = is_string($pipeline['handoff_reason_code'] ?? null)
            ? $pipeline['handoff_reason_code']
            : (is_string($pipeline['trace']['fallback_reason'] ?? null) ? $pipeline['trace']['fallback_reason'] : null);
        if ($reason === null) {
            $reason = $blockedAction ?? 'decision_executed';
        }

        ConversationContext::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'summary' => sprintf(
                'Intent=%s; Decision=%s; Confidence=%s; Reply=%s',
                $intent,
                $decision,
                $confidence,
                mb_substr($replyText, 0, 180)
            ),
            'reason' => $reason,
            'recommended_next_action' => is_string($pipeline['active_goal'] ?? null)
                ? $pipeline['active_goal']
                : null,
        ]);
    }
}
