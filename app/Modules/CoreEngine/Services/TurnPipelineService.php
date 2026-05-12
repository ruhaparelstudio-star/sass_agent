<?php

namespace App\Modules\CoreEngine\Services;

use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\ConversationState;
use App\Models\LeadProfile;
use App\Models\Tenant;
use App\Modules\AiLayer\Enums\Intent;
use App\Modules\AiLayer\Services\InterpretationService;
use App\Modules\Action\Services\ActionDispatcherService;
use App\Modules\Conversation\Services\ConversationService;
use App\Modules\Validation\Contracts\ActionPermissionValidator;
use App\Modules\Validation\Contracts\GroundingValidator;
use App\Modules\Validation\Contracts\ModeValidator;
use App\Modules\Validation\Contracts\PolicyValidator;

class TurnPipelineService
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.45;
    private const COMPACT_VALUE_MAX_CHARS = 80;
    private const COMPACT_BLOCK_MAX_CHARS = 320;

    /** @var list<string> */
    private const TRAILING_FILLER_WORDS = ['ka', 'kak', 'kakak', 'dong', 'ya', 'yah', 'nya'];

    /** @var list<string> */
    private const CONNECTOR_WORDS = ['dan', 'atau', 'dengan', 'and', 'or', 'with'];

    /** @var list<string> */
    private const CALENDAR_RELATED_BLOCKERS = ['missing_availability_check', 'grounding_calendar_missing_source'];

    public function __construct(
        private readonly InterpretationService $interpretationService,
        private readonly ActionDispatcherService $actionDispatcherService,
        private readonly PolicyValidator $policyValidator,
        private readonly GroundingValidator $groundingValidator,
        private readonly ActionPermissionValidator $actionPermissionValidator,
        private readonly ModeValidator $modeValidator,
        private readonly ResponseComposerService $responseComposer,
        private readonly ConversationService $conversationService,
    ) {}

    public function handle(
        Tenant $tenant,
        Conversation $conversation,
        string $userMessage,
        string $instruction,
        array $context = []
    ): array {
        $state = $conversation->state()->firstOrFail();
        // Capture pre-turn DB values; orchestrator-style state derivation downstream uses these
        // as fallbacks regardless of in-memory mutations done by resolveGoal() during this turn.
        $priorStage = $state->current_stage;
        $priorGoal = $state->active_goal;
        $priorPending = $state->pending_action;
        $dormantRetrieval = $this->resolveDormantRetrieval($tenant, $conversation, $context);
        $classificationInstruction = $this->buildClassificationInstruction($instruction, $state, $context);

        $interpretation = $this->interpretationService->interpret($tenant->id, $userMessage, $classificationInstruction);

        $entities = $this->mergeEntities(
            $context['entities'] ?? [],
            $interpretation->entities
        );

        $finalIntent = $this->normalizeIntentForContinuation(
            $interpretation->intent,
            $entities,
            $state->active_goal,
            $context
        );

        $resolvedGoal = $this->resolveGoal($finalIntent, $state->active_goal);
        $previousActiveGoal = $state->active_goal;
        // Resuming a blocked pricelist flow promotes goal to pricing (composer uses goal-driven templates).
        if ($this->shouldResumePricelistFlow($finalIntent, $entities, $context)) {
            $resolvedGoal = 'pricing';
        }
        // Never regress from booking to qualification — providing data mid-booking doesn't cancel intent
        $effectiveGoal = ($previousActiveGoal === 'booking' && $resolvedGoal === 'qualification')
            ? $previousActiveGoal
            : $resolvedGoal;
        if ($effectiveGoal !== $previousActiveGoal) {
            $state->active_goal = $effectiveGoal;
        }

        $leadProfile = LeadProfile::query()
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', $conversation->customer_phone)
            ->first();

        $candidate = $this->buildCandidate($finalIntent, $entities, $leadProfile, $context);

        $result = [
            'allowed' => [],
            'blocked' => [],
        ];
        $validationFailureReason = null;

        $dispatchTrace = [
            'executed' => false,
            'action' => $candidate['action'] ?? null,
            'status' => 'blocked',
            'reason' => null,
        ];

        if (($candidate['reasons'] ?? []) !== []) {
            $result['blocked'][] = $candidate;
            $dispatchResult = $this->actionDispatcherService->dispatch($tenant, $conversation, $candidate);
            $dispatchTrace = [
                'executed' => ($dispatchResult['status'] ?? null) === 'executed',
                'action' => $dispatchResult['action'] ?? ($candidate['action'] ?? null),
                'status' => $dispatchResult['status'] ?? 'blocked',
                'reason' => $dispatchResult['reason'] ?? null,
            ];
        } else {
            $calendarCheck = $this->normalizeCalendarCheck($context['calendar_check'] ?? null);
            $grounding = $this->mergeGrounding($context['grounding'] ?? null, $calendarCheck);

            $validationContext = [
                'tenant_id' => $tenant->id,
                'state' => [
                    'agent_mode' => $state->agent_mode,
                    'active_goal' => $state->active_goal,
                ],
                'entities' => $entities,
                'grounding' => $grounding,
                'policy' => is_array($context['policy'] ?? null) ? $context['policy'] : [],
                'permissions' => is_array($context['permissions'] ?? null) ? $context['permissions'] : null,
                'calendar_check' => $calendarCheck,
            ];

            $validationError = $this->runValidators($candidate, $validationContext);

            if ($validationError !== null) {
                $validationFailureReason = $validationError;
                $candidate['reasons'] = [$validationError];
                $result['blocked'][] = $candidate;
                $dispatchResult = $this->actionDispatcherService->dispatch($tenant, $conversation, $candidate);
                $dispatchTrace = [
                    'executed' => ($dispatchResult['status'] ?? null) === 'executed',
                    'action' => $dispatchResult['action'] ?? ($candidate['action'] ?? null),
                    'status' => $dispatchResult['status'] ?? 'blocked',
                    'reason' => $dispatchResult['reason'] ?? null,
                ];
            } else {
                $result['allowed'][] = $candidate;
                $dispatchResult = $this->actionDispatcherService->dispatch($tenant, $conversation, $candidate);
                $dispatchTrace = [
                    'executed' => ($dispatchResult['status'] ?? null) === 'executed',
                    'action' => $dispatchResult['action'] ?? ($candidate['action'] ?? null),
                    'status' => $dispatchResult['status'] ?? 'blocked',
                    'reason' => $dispatchResult['reason'] ?? null,
                ];
            }
        }

        $blockedActions = array_values(array_map(function (array $row): array {
            return [
                'action' => (string) ($row['action'] ?? ''),
                'reason' => (string) (($row['reasons'][0] ?? null) ?? 'blocked'),
            ];
        }, $result['blocked']));

        $allowedActions = array_values(array_map(function (array $row): string {
            return $this->normalizeDecisionActionName((string) ($row['action'] ?? ''));
        }, $result['allowed']));

        $handoffSignal = $this->resolveHandoffSignal(
            $finalIntent,
            $interpretation->confidence,
            $state->agent_mode,
            $result,
            $context,
            $dispatchTrace
        );
        $handoffRequired = $handoffSignal['required'];

        $decisionForCompose = [
            'intent' => $finalIntent->value,
            'active_goal' => $state->active_goal,
            'allowed_actions' => $allowedActions,
            'blocked_actions' => $blockedActions,
            'action_candidates' => $result,
        ];
        $groundingForCompose = is_array($context['grounding'] ?? null) ? $context['grounding'] : [];
        // Surface package detail summary into the grounding payload so the composer
        // can render package_explanation goals without reaching back into raw context.
        // Resolve via existing helper which understands both direct summary and lookup tables.
        $packageSummary = $this->resolvePackageDetailSummary($context, $entities);
        if ($packageSummary !== null) {
            $groundingForCompose['package_summary'] = $packageSummary;
        }
        $composedMessage = $this->responseComposer->compose(
            $decisionForCompose,
            $groundingForCompose,
            $entities,
            $tenant
        );

        // Single source of truth for state writes: derive stage/goal/pending_action here
        // (heuristics ported from WaInboundTurnOrchestratorService::persistDurableStateFromPipeline)
        // and persist with merged entities in one transaction. Replaces the legacy dual-writer.
        $pendingActionPayload = $this->derivePendingAction($candidate, $result);
        $stateUpdate = $this->derivePipelineStateUpdate(
            $finalIntent,
            $allowedActions,
            $blockedActions,
            $priorStage,
            $priorGoal,
            $priorPending,
            $pendingActionPayload,
            $state->active_goal
        );
        $entitiesForPersist = $this->enrichEntitiesForPersistence($entities);
        $this->conversationService->persistDurable(
            $conversation,
            $tenant,
            $entitiesForPersist,
            $stateUpdate
        );

        $response = [
            'intent' => $finalIntent->value,
            'confidence' => $interpretation->confidence,
            'entities' => $this->buildDecisionEntities($entities),
            'current_stage' => $state->current_stage,
            'active_goal' => $state->active_goal,
            'decision' => $this->deriveDecisionKeyword($result, $handoffRequired),
            'allowed_actions' => $allowedActions,
            'blocked_actions' => $blockedActions,
            'handoff_required' => $handoffRequired,
            'notification_required' => $handoffRequired,
            'handoff_reason_code' => $handoffSignal['reason_code'],
            'handoff_priority' => $handoffSignal['priority'],
            'grounding_refs' => $this->extractGroundingRefs($context['grounding'] ?? null),
            'reply_strategy' => $handoffRequired ? 'handoff_safe' : 'short_contextual_question',
            'state_snapshot' => [
                'current_stage' => $state->current_stage,
                'active_goal' => $state->active_goal,
                'agent_mode' => $state->agent_mode,
                'memory_mode' => $state->memory_mode,
            ],
            'action_candidates' => $result,
            'response_plan' => [
                'message' => $composedMessage,
            ],
            'trace' => [
                'executed' => $dispatchTrace['executed'],
                'action' => $dispatchTrace['action'],
                'status' => $dispatchTrace['status'],
                'reason' => $dispatchTrace['reason'],
                'validator_order' => ['policy', 'grounding', 'permission', 'mode'],
                'validation_failure_reason' => $validationFailureReason,
                'fallback_reason' => $interpretation->fallbackReason,
                'dormant_retrieval' => [
                    'triggered' => $dormantRetrieval['triggered'],
                    'status' => $dormantRetrieval['status'],
                    'reason' => $dormantRetrieval['reason'],
                ],
            ],
        ];

        if ($dormantRetrieval['status'] === 'loaded') {
            $response['memory'] = [
                'dormant' => $dormantRetrieval['data'],
            ];
        }

        return $response;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function buildClassificationInstruction(string $instruction, ConversationState $state, array $context): string
    {
        $compactContext = $this->buildCompactLiveContext($state, $context);
        if ($compactContext === '') {
            return $instruction;
        }

        return rtrim($instruction)."\n\nCONTEXT_COMPACT (live_state):\n".$compactContext;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function buildCompactLiveContext(ConversationState $state, array $context): string
    {
        $entities = is_array($context['entities'] ?? null) ? $context['entities'] : [];
        $previousBlockedAction = is_array($context['previous_blocked_action'] ?? null)
            ? $context['previous_blocked_action']
            : null;

        $packageInterest = $this->firstNonEmptyString([
            is_string($entities['package_interest'] ?? null) ? $entities['package_interest'] : null,
            is_string($entities['resolved_package_name'] ?? null) ? $entities['resolved_package_name'] : null,
        ]);

        $previousBlockedSummary = null;
        if ($previousBlockedAction !== null) {
            $blockedAction = trim((string) ($previousBlockedAction['action'] ?? ''));
            $blockedReason = trim((string) ($previousBlockedAction['reason'] ?? ''));
            if ($blockedAction !== '' && $blockedReason !== '') {
                $previousBlockedSummary = $blockedAction.':'.$blockedReason;
            } elseif ($blockedAction !== '') {
                $previousBlockedSummary = $blockedAction;
            }
        }

        $orderedPairs = [
            'current_stage' => $state->current_stage,
            'active_goal' => $state->active_goal,
            'agent_mode' => $state->agent_mode,
            'memory_mode' => $state->memory_mode,
            'pending_action' => $state->pending_action,
            'customer_name' => $this->firstNonEmptyString([
                is_string($entities['customer_name'] ?? null) ? $entities['customer_name'] : null,
                is_string($entities['name'] ?? null) ? $entities['name'] : null,
            ]),
            'event_type' => is_string($entities['event_type'] ?? null) ? $entities['event_type'] : null,
            'package_interest' => $packageInterest,
            'previous_blocked_action' => $previousBlockedSummary,
        ];

        $lines = [];
        foreach ($orderedPairs as $key => $rawValue) {
            $value = $this->sanitizeCompactValue($rawValue);
            if ($value === null) {
                continue;
            }

            $lines[] = $key.'='.$value;
        }

        if ($lines === []) {
            return '';
        }

        return $this->limitCompactBlock($lines);
    }

    private function sanitizeCompactValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $normalized = trim($value);
        } elseif (is_int($value) || is_float($value)) {
            $normalized = (string) $value;
        } else {
            return null;
        }

        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        if (! is_string($normalized)) {
            return null;
        }

        $normalized = trim($normalized);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > self::COMPACT_VALUE_MAX_CHARS) {
            $normalized = mb_substr($normalized, 0, self::COMPACT_VALUE_MAX_CHARS - 3).'...';
        }

        return $normalized;
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
     * @param  list<string>  $lines
     */
    private function limitCompactBlock(array $lines): string
    {
        $result = '';
        foreach ($lines as $line) {
            if ($result === '') {
                if (mb_strlen($line) <= self::COMPACT_BLOCK_MAX_CHARS) {
                    $result = $line;
                    continue;
                }

                return mb_substr($line, 0, self::COMPACT_BLOCK_MAX_CHARS - 3).'...';
            }

            $candidate = $result."\n".$line;
            if (mb_strlen($candidate) <= self::COMPACT_BLOCK_MAX_CHARS) {
                $result = $candidate;
                continue;
            }

            $remaining = self::COMPACT_BLOCK_MAX_CHARS - mb_strlen($result) - 1;
            if ($remaining > 0) {
                $truncatedLine = mb_substr($line, 0, max(0, $remaining - 3)).'...';
                $result .= "\n".$truncatedLine;
            }

            break;
        }

        return $result;
    }

    /**
     * Normalize ambiguous package-question intents into booking continuation only when
     * prior blocked booking context confirms we are filling required booking data.
     *
     * @param  array<string,mixed>  $entities
     * @param  array<string,mixed>  $context
     */
    private function normalizeIntentForContinuation(
        Intent $intent,
        array $entities,
        ?string $activeGoal,
        array $context
    ): Intent {
        // When user provides booking data (date/name/event type/preference) while in booking goal,
        // treat as continuing the booking flow rather than reverting to qualification.
        $bookingDataIntents = [
            Intent::ProvideDate,
            Intent::ProvideName,
            Intent::ProvideEventType,
            Intent::ProvidePreference,
        ];
        if (in_array($intent, $bookingDataIntents, true)) {
            $normalizedGoal = strtolower(trim((string) $activeGoal));
            if ($normalizedGoal === 'booking') {
                $previousBlockedAction = $context['previous_blocked_action'] ?? null;
                if (is_array($previousBlockedAction)
                    && ($previousBlockedAction['action'] ?? null) === 'send_booking_link'
                ) {
                    return Intent::BookingIntent;
                }
            }
        }

        if ($intent !== Intent::AskPackage) {
            return $intent;
        }

        if (! $this->hasBookingPackageInput($entities)) {
            return $intent;
        }

        $previousBlockedAction = $context['previous_blocked_action'] ?? null;
        if (! is_array($previousBlockedAction)) {
            return $intent;
        }

        if (($previousBlockedAction['action'] ?? null) !== 'send_booking_link') {
            return $intent;
        }

        if (($previousBlockedAction['reason'] ?? null) !== 'missing_package') {
            return $intent;
        }

        $normalizedGoal = strtolower(trim((string) $activeGoal));
        if ($normalizedGoal !== '' && ! in_array($normalizedGoal, ['booking', 'availability'], true)) {
            return $intent;
        }

        return Intent::BookingIntent;
    }

    /**
     * @param  array<string,mixed>  $entities
     */
    private function hasBookingPackageInput(array $entities): bool
    {
        $resolvedPackageCode = trim((string) ($entities['resolved_package_code'] ?? ''));
        if ($resolvedPackageCode !== '') {
            return true;
        }

        $packageQuery = trim((string) ($entities['package_query'] ?? ''));

        return $packageQuery !== '';
    }

    /**
     * Keep internal normalized entities while enforcing PRD decision contract keys.
     *
     * @param  array<string,mixed>  $entities
     * @return array<string,mixed>
     */
    private function buildDecisionEntities(array $entities): array
    {
        return array_merge(
            $entities,
            [
                'name' => $entities['customer_name'] ?? null,
                'event_date' => $entities['event_date_iso'] ?? null,
                'event_type' => $entities['event_type'] ?? null,
                'location' => $entities['location'] ?? null,
                'package_interest' => $entities['resolved_package_name'] ?? ($entities['package_query'] ?? null),
                'budget' => $entities['budget_amount'] ?? null,
                'budget_min' => $entities['budget_min'] ?? null,
                'budget_max' => $entities['budget_max'] ?? null,
                'invoice_reference' => $entities['invoice_reference'] ?? null,
            ]
        );
    }

    /**
     * @return array{
     *   triggered:bool,
     *   status:string,
     *   reason:?string,
     *   data:?array{summary:string,summary_structured:?array<string,mixed>,message_count:int,summarized_at:?string}
     * }
     */
    private function resolveDormantRetrieval(Tenant $tenant, Conversation $conversation, array $context): array
    {
        if (($context['dormant_retrieval'] ?? false) !== true) {
            return [
                'triggered' => false,
                'status' => 'not_requested',
                'reason' => 'flag_not_set',
                'data' => null,
            ];
        }

        $summary = ConversationSummary::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        if (! $summary) {
            return [
                'triggered' => true,
                'status' => 'miss',
                'reason' => 'not_found',
                'data' => null,
            ];
        }

        if ($summary->retention_until !== null && $summary->retention_until->lte(now())) {
            return [
                'triggered' => true,
                'status' => 'miss',
                'reason' => 'expired_retention',
                'data' => null,
            ];
        }

        return [
            'triggered' => true,
            'status' => 'loaded',
            'reason' => null,
            'data' => [
                'summary' => $summary->summary,
                'summary_structured' => is_array($summary->summary_json) ? $summary->summary_json : null,
                'message_count' => $summary->message_count,
                'summarized_at' => $summary->summarized_at?->toDateTimeString(),
            ],
        ];
    }

    /**
     * Deterministic precedence: state-loaded entities (previous) are source of truth.
     * Current LLM-extracted entities only fill gaps where previous is null/empty,
     * unless a correction is explicitly signaled.
     */
    private function mergeEntities(array $previous, array $current): array
    {
        $merged = is_array($previous) ? $previous : [];

        foreach ($current as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (! $this->shouldApplyCurrentEntityValue($value)) {
                continue;
            }

            // Only apply current value if previous is missing/empty.
            if (! array_key_exists($key, $merged) || ! $this->shouldApplyCurrentEntityValue($merged[$key])) {
                $merged[$key] = $value;
            }
        }

        $isCorrection = ($current['is_correction'] ?? false) === true;

        if (! $isCorrection) {
            return $merged;
        }
        $merged['is_correction'] = true;
        $merged['corrected_fields'] = $current['corrected_fields'] ?? [];
        $fields = $current['corrected_fields'] ?? [];

        if (! is_array($fields) || $fields === []) {
            return $merged;
        }

        foreach ($fields as $field) {
            if (! is_string($field)) {
                continue;
            }

            if ($field === 'package_query' && array_key_exists('package_query', $current)) {
                $merged['package_query'] = $current['package_query'];
                $merged['resolved_package_code'] = $current['resolved_package_code'] ?? null;
                $merged['resolved_package_name'] = $current['resolved_package_name'] ?? null;
            }

            if ($field === 'event_date' && array_key_exists('event_date_iso', $current)) {
                $merged['event_date_iso'] = $current['event_date_iso'];
            }

            if ($field === 'budget' && array_key_exists('budget_amount', $current)) {
                $merged['budget_amount'] = $current['budget_amount'];
            }

            if ($field === 'budget_min' && array_key_exists('budget_min', $current)) {
                $merged['budget_min'] = $current['budget_min'];
            }

            if ($field === 'budget_max' && array_key_exists('budget_max', $current)) {
                $merged['budget_max'] = $current['budget_max'];
            }

            if ($field === 'customer_name' && array_key_exists('customer_name', $current)) {
                $merged['customer_name'] = $current['customer_name'];
            }

            if ($field === 'location' && array_key_exists('location', $current)) {
                $merged['location'] = $current['location'];
            }

            if ($field === 'event_type' && array_key_exists('event_type', $current)) {
                $merged['event_type'] = $current['event_type'];
            }

            if ($field === 'invoice_reference' && array_key_exists('invoice_reference', $current)) {
                $merged['invoice_reference'] = $current['invoice_reference'];
            }
        }

        return $merged;
    }

    private function shouldApplyCurrentEntityValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function buildCandidate(Intent $intent, array $entities, ?LeadProfile $leadProfile, array $context): array
    {
        if ($this->shouldResumePricelistFlow($intent, $entities, $context)) {
            return $this->buildPricelistCandidate($leadProfile, $entities, $context);
        }

        return match ($intent) {
            Intent::AskPricelist,
            Intent::AskPrice => $this->buildPricelistCandidate($leadProfile, $entities, $context),
            Intent::BookingIntent,
            Intent::ConfirmBooking => $this->buildBookingCandidate($entities, $leadProfile, $context),
            Intent::ProvideName,
            Intent::ProvideEventType,
            Intent::ProvideDate,
            Intent::ProvideBudget,
            Intent::ProvidePreference => $this->buildQualificationCandidate($entities, $leadProfile, $context),
            Intent::Greeting,
            Intent::FirstContact,
            Intent::IntroInterest => $this->buildOpeningCandidate($intent),
            Intent::AskPackage,
            Intent::AskPackageDetail => $this->buildPackageExplanationCandidate($entities, $context),
            Intent::AskAvailability => $this->buildAvailabilityCandidate($entities, $context),
            Intent::AskFaq => $this->buildFaqCandidate($entities, $context),
            Intent::ObjectionPrice,
            Intent::ObjectionTime,
            Intent::ObjectionMisc => $this->buildObjectionCandidate($intent),
            Intent::RequestHandoff,
            Intent::Complaint => $this->buildHandoffCandidate($intent),
            Intent::PaymentRelated => $this->buildInvoicePhaseCandidate(),
            Intent::UnclearMessage,
            Intent::Unknown => $this->buildClarificationCandidate(),
            default => [
                'action' => 'reply_safe_text',
                'reasons' => [],
            ],
        };
    }

    private function buildOpeningCandidate(Intent $intent): array
    {
        return [
            'action' => 'reply_safe_text',
            'reasons' => [],
            'meta' => [
                'opening_phase' => $intent === Intent::Greeting ? 'greeting' : 'intro_interest',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $entities
     * @param  array<string,mixed>  $context
     */
    private function buildPackageExplanationCandidate(array $entities, array $context): array
    {
        $summary = $this->resolvePackageDetailSummary($context, $entities);
        if ($summary !== null) {
            return [
                'action' => 'reply_safe_text',
                'reasons' => [],
                'meta' => ['package_summary' => $summary],
            ];
        }

        $packagesList = $this->resolvePackagesList($context);
        if ($packagesList !== []) {
            return [
                'action' => 'reply_safe_text',
                'reasons' => [],
                'meta' => ['packages_list' => $packagesList],
            ];
        }

        return [
            'action' => 'reply_safe_text',
            'reasons' => [],
            'meta' => ['clarification_for' => 'package'],
        ];
    }

    /**
     * @param  array<string,mixed>  $entities
     * @param  array<string,mixed>  $context
     */
    private function buildAvailabilityCandidate(array $entities, array $context): array
    {
        $eventDate = trim((string) ($entities['event_date_iso'] ?? ''));
        if ($eventDate === '') {
            return [
                'action' => 'reply_safe_text',
                'reasons' => ['missing_event_date'],
            ];
        }

        $calendarCheck = $this->normalizeCalendarCheck($context['calendar_check'] ?? null);

        if ($calendarCheck['checked'] === true && $calendarCheck['available'] === true) {
            return [
                'action' => 'reply_safe_text',
                'reasons' => [],
                'meta' => [
                    'availability' => [
                        'confirmed_date' => $eventDate,
                        'status' => 'available',
                    ],
                ],
            ];
        }

        if (($calendarCheck['reason'] ?? null) === 'calendar_provider_error') {
            return [
                'action' => 'reply_safe_text',
                'reasons' => ['calendar_provider_error'],
            ];
        }

        if ($calendarCheck['checked'] === true && $calendarCheck['available'] === false) {
            return [
                'action' => 'reply_safe_text',
                'reasons' => ['calendar_unavailable'],
                'meta' => [
                    'availability' => [
                        'requested_date' => $eventDate,
                        'status' => 'unavailable',
                    ],
                ],
            ];
        }

        return [
            'action' => 'reply_safe_text',
            'reasons' => ['missing_availability_check'],
        ];
    }

    /**
     * @param  array<string,mixed>  $entities
     * @param  array<string,mixed>  $context
     */
    private function buildFaqCandidate(array $entities, array $context): array
    {
        $grounding = is_array($context['grounding'] ?? null) ? $context['grounding'] : [];
        $match = $this->resolveFaqMatch($grounding, $entities);

        if ($match !== null) {
            return [
                'action' => 'reply_safe_text',
                'reasons' => [],
                'meta' => ['faq_answer' => $match],
            ];
        }

        return [
            'action' => 'reply_safe_text',
            'reasons' => ['missing_faq_match'],
        ];
    }

    private function buildObjectionCandidate(Intent $intent): array
    {
        $type = match ($intent) {
            Intent::ObjectionPrice => 'price',
            Intent::ObjectionTime => 'time',
            default => 'misc',
        };

        return [
            'action' => 'reply_safe_text',
            'reasons' => [],
            'meta' => ['objection_type' => $type],
        ];
    }

    private function buildHandoffCandidate(Intent $intent): array
    {
        return [
            'action' => 'reply_safe_text',
            'reasons' => [],
            'meta' => [
                'handoff_trigger' => $intent === Intent::Complaint ? 'complaint' : 'request',
            ],
        ];
    }

    private function buildInvoicePhaseCandidate(): array
    {
        return [
            'action' => 'reply_safe_text',
            'reasons' => [],
            'meta' => ['invoice_phase' => 'route_to_human'],
        ];
    }

    private function buildClarificationCandidate(): array
    {
        return [
            'action' => 'reply_safe_text',
            'reasons' => [],
        ];
    }

    /**
     * Top-N active package names from catalog grounding (no specific package query).
     *
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function resolvePackagesList(array $context): array
    {
        $catalog = $context['catalog'] ?? null;
        if (! is_array($catalog) || $catalog === []) {
            return [];
        }

        $names = [];
        foreach ($catalog as $service) {
            if (! is_array($service)) {
                continue;
            }
            foreach ((array) ($service['products'] ?? []) as $product) {
                if (! is_array($product)) {
                    continue;
                }
                foreach ((array) ($product['packages'] ?? []) as $package) {
                    if (! is_array($package)) {
                        continue;
                    }
                    $name = trim((string) ($package['name'] ?? ''));
                    if ($name !== '' && ! in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                    if (count($names) >= 5) {
                        return $names;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Pick the FAQ entry whose question best matches the user message hint.
     *
     * @param  array<string,mixed>  $grounding
     * @param  array<string,mixed>  $entities
     */
    private function resolveFaqMatch(array $grounding, array $entities): ?string
    {
        $direct = $grounding['faq_match']['answer'] ?? ($grounding['faq']['data']['answer'] ?? null);
        if (is_string($direct) && trim($direct) !== '') {
            return trim($direct);
        }

        $faqList = $grounding['faq']['data']['items'] ?? ($grounding['faq_list'] ?? null);
        if (! is_array($faqList) || $faqList === []) {
            return null;
        }

        $hint = mb_strtolower(trim((string) ($entities['faq_query'] ?? ($entities['user_message_hint'] ?? ''))));
        if ($hint === '') {
            // No hint; surface the first FAQ as default.
            $first = $faqList[0] ?? null;
            $answer = is_array($first) ? trim((string) ($first['answer'] ?? '')) : '';
            return $answer !== '' ? $answer : null;
        }

        foreach ($faqList as $row) {
            if (! is_array($row)) {
                continue;
            }
            $question = mb_strtolower(trim((string) ($row['question'] ?? '')));
            if ($question === '') {
                continue;
            }
            if (str_contains($question, $hint) || str_contains($hint, $question)) {
                $answer = trim((string) ($row['answer'] ?? ''));
                if ($answer !== '') {
                    return $answer;
                }
            }
        }

        return null;
    }

    /**
     * Compute next-required field from PRD §14 ordering and emit a structured
     * reply candidate with `meta.next_required` so the composer can pick the
     * exact prompt template.
     */
    private function buildQualificationCandidate(array $entities, ?LeadProfile $leadProfile, array $context): array
    {
        $next = $this->nextRequiredQualificationField($entities, $leadProfile);

        if ($next === null) {
            // All qualification fields filled — fall back to reply_text with no missing reasons.
            return [
                'action' => 'reply_text',
                'reasons' => [],
                'meta' => ['next_required' => null],
            ];
        }

        return [
            'action' => 'reply_text',
            'reasons' => ['collect:'.$next],
            'missing_required' => [$next],
            'meta' => ['next_required' => $next],
        ];
    }

    private function nextRequiredQualificationField(array $entities, ?LeadProfile $leadProfile): ?string
    {
        if (! $this->hasKnownCustomerName($leadProfile, $entities)) {
            return 'customer_name';
        }
        if (! $this->hasKnownEventType($entities)) {
            return 'event_type';
        }
        $eventDate = trim((string) ($entities['event_date_iso'] ?? ''));
        if ($eventDate === '') {
            return 'event_date';
        }
        $packageInterest = $this->firstNonEmptyString([
            is_string($entities['package_interest'] ?? null) ? $entities['package_interest'] : null,
            is_string($entities['resolved_package_name'] ?? null) ? $entities['resolved_package_name'] : null,
            is_string($entities['package_query'] ?? null) ? $entities['package_query'] : null,
        ]);
        if ($packageInterest === null) {
            return 'package_interest';
        }

        return null;
    }

    /**
     * Build pending_action payload {action, reason, captured_at} for resume.
     * Returns null if no resume-worthy blocked candidate is present.
     */
    /**
     * Apply cross-field fallbacks for durable persistence. Mirrors the firstNonEmptyString chains
     * the legacy orchestrator writer used so absent service_interest/package_interest/selected_package
     * still get derived from the closest available signal in $entities.
     *
     * @param  array<string,mixed>  $entities
     * @return array<string,mixed>
     */
    private function enrichEntitiesForPersistence(array $entities): array
    {
        $enriched = $entities;

        // service_interest falls back to event_type when LLM only extracts the latter.
        if (! $this->isPersistableEntity($enriched['service_interest'] ?? null)) {
            if ($this->isPersistableEntity($enriched['event_type'] ?? null)) {
                $enriched['service_interest'] = $enriched['event_type'];
            }
        }

        // package_interest can be derived from resolved_package_name or package_query.
        if (! $this->isPersistableEntity($enriched['package_interest'] ?? null)) {
            if ($this->isPersistableEntity($enriched['resolved_package_name'] ?? null)) {
                $enriched['package_interest'] = $enriched['resolved_package_name'];
            } elseif ($this->isPersistableEntity($enriched['package_query'] ?? null)) {
                $enriched['package_interest'] = $enriched['package_query'];
            }
        }

        // selected_package mirrors resolved_package_name, falling back to package_query.
        if (! $this->isPersistableEntity($enriched['selected_package'] ?? null)) {
            if ($this->isPersistableEntity($enriched['resolved_package_name'] ?? null)) {
                $enriched['selected_package'] = $enriched['resolved_package_name'];
            } elseif ($this->isPersistableEntity($enriched['package_query'] ?? null)) {
                $enriched['selected_package'] = $enriched['package_query'];
            }
        }

        // customer_name can come in as 'name' from interpretation; map both.
        if (! $this->isPersistableEntity($enriched['customer_name'] ?? null)) {
            if ($this->isPersistableEntity($enriched['name'] ?? null)) {
                $enriched['customer_name'] = $enriched['name'];
            }
        }

        return $enriched;
    }

    private function isPersistableEntity(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return true;
    }

    /**
     * Derive durable state update (current_stage, active_goal, pending_action) at end of turn.
     * Stage/goal heuristics ported from WaInboundTurnOrchestratorService::persistDurableStateFromPipeline.
     * pending_action is persisted as the structured payload returned by derivePendingAction
     * (e.g. {action, reason, captured_at}), while a local string flag mirrors the legacy
     * 'send_pricelist' marker used for stage/goal branching.
     *
     * @param  list<string>                                              $allowedActions
     * @param  list<array{action:string,reason:string}>                  $blockedActions
     * @param  array{action:string,reason:string,captured_at:string}|null $pendingActionPayload
     * @param  ?string  $pipelineGoal  Pipeline-resolved semantic goal used as fallback when no
     *                                 orchestrator-style override applies (e.g. fresh conversation).
     * @return array{current_stage:?string, active_goal:?string, pending_action:?array<string,mixed>}
     */
    private function derivePipelineStateUpdate(
        Intent $finalIntent,
        array $allowedActions,
        array $blockedActions,
        ?string $priorStage,
        ?string $priorGoal,
        mixed $priorPending,
        ?array $pendingActionPayload,
        ?string $pipelineGoal
    ): array {
        $intent = strtolower(trim($finalIntent->value));
        $blockedAction = $blockedActions[0]['action'] ?? null;
        $blockedReason = $blockedActions[0]['reason'] ?? null;
        $allowedAction = $allowedActions[0] ?? null;

        // Legacy string flag — drives stage/goal branching only, not persisted.
        $priorPendingFlag = is_array($priorPending)
            ? (is_string($priorPending['action'] ?? null) ? $this->mapActionToPendingFlag((string) $priorPending['action']) : null)
            : (is_string($priorPending) ? $priorPending : null);
        $pendingFlag = $priorPendingFlag;
        if (is_string($blockedAction) && trim($blockedAction) !== '') {
            $pendingFlag = $this->mapActionToPendingFlag(trim($blockedAction));
        } elseif (is_string($allowedAction) && in_array($allowedAction, ['send_file', 'send_booking_link'], true)) {
            $pendingFlag = null;
        }

        // Goal precedence: orchestrator-style overrides (collect_lead_info, send_pricelist, booking)
        // win for the specific flows they cover. Otherwise fall back to the pipeline-resolved goal
        // (qualification, pricing, opening, ...) so fresh conversations don't end up with null goals.
        $activeGoal = $priorGoal;
        $orchestratorOverride = false;
        if ($pendingFlag === 'send_pricelist') {
            if ($intent === 'ask_pricelist' && $blockedReason === 'missing_name') {
                $activeGoal = 'collect_lead_info';
                $orchestratorOverride = true;
            } elseif (($intent === 'provide_name' || $intent === 'provide_event_type') && $allowedAction !== 'send_file') {
                $activeGoal = 'collect_lead_info';
                $orchestratorOverride = true;
            } elseif ($allowedAction === 'send_file') {
                $activeGoal = 'send_pricelist';
                $orchestratorOverride = true;
            }
        } elseif ($intent === 'booking_intent') {
            $activeGoal = 'booking';
            $orchestratorOverride = true;
        }
        if (! $orchestratorOverride && is_string($pipelineGoal) && $pipelineGoal !== '') {
            $activeGoal = $pipelineGoal;
        }

        $currentStage = $priorStage;
        if ($intent === 'ask_pricelist' && $blockedReason === 'missing_name') {
            $currentStage = 'collecting_name';
        } elseif (($intent === 'provide_name' || $blockedReason === 'missing_event_type') && $pendingFlag === 'send_pricelist') {
            $currentStage = 'collecting_service';
        } elseif ($allowedAction === 'send_file') {
            $currentStage = 'pricelist_sent';
        } elseif ($intent === 'ask_package_detail') {
            $currentStage = 'explaining_package';
        } elseif ($intent === 'booking_intent' && $allowedAction === 'send_booking_link') {
            $currentStage = 'booking_requested';
        }

        return [
            'current_stage' => $currentStage,
            'active_goal' => $activeGoal,
            'pending_action' => $pendingActionPayload,
        ];
    }

    /**
     * Map a candidate action name to the legacy pending flag used for stage/goal derivation.
     * Returns null for actions that should not stick as pending (e.g. plain reply_text).
     */
    private function mapActionToPendingFlag(string $action): ?string
    {
        return match ($action) {
            'send_file', 'send_pricelist_file' => 'send_pricelist',
            'send_booking_link' => 'send_booking_link',
            default => null,
        };
    }

    /**
     * Build pending_action payload {action, reason, captured_at} for resume.
     * Returns null if no resume-worthy blocked candidate is present.
     *
     * @return array{action:string,reason:string,captured_at:string}|null
     */
    private function derivePendingAction(array $candidate, array $result): ?array
    {
        $blocked = $result['blocked'] ?? [];
        $allowed = $result['allowed'] ?? [];

        // If an action was successfully allowed, clear pending.
        if ($allowed !== []) {
            return null;
        }

        if ($blocked === []) {
            return null;
        }

        $first = $blocked[0];
        $action = (string) ($first['action'] ?? '');
        $reasons = is_array($first['reasons'] ?? null) ? $first['reasons'] : [];
        $reason = (string) ($reasons[0] ?? '');

        $resumeWorthy = ['send_file', 'send_pricelist_file', 'send_booking_link'];
        if (! in_array($action, $resumeWorthy, true) || $reason === '') {
            return null;
        }

        return [
            'action' => $action,
            'reason' => $reason,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function buildPricelistCandidate(?LeadProfile $leadProfile, array $entities = [], array $context = []): array
    {
        if (! $this->hasKnownCustomerName($leadProfile, $entities)) {
            return [
                'action' => 'send_file',
                'reasons' => ['missing_name'],
            ];
        }

        if (! $this->hasKnownEventType($entities)) {
            return [
                'action' => 'send_file',
                'reasons' => ['missing_event_type'],
            ];
        }

        $sendFileMeta = $this->buildSendFileMeta($context);
        $candidate = [
            'action' => 'send_file',
            'reasons' => [],
        ];

        if ($sendFileMeta !== null) {
            $candidate['meta'] = [
                'send_file' => $sendFileMeta,
            ];
        }

        return $candidate;
    }

    private function buildBookingCandidate(array $entities, ?LeadProfile $leadProfile, array $context): array
    {
        $reasons = [];

        if (! $this->hasKnownCustomerName($leadProfile, $entities)) {
            $reasons[] = 'missing_name';
        }

        if (($entities['resolved_package_code'] ?? null) === null && ($entities['package_query'] ?? null) === null) {
            $reasons[] = 'missing_package';
        }

        if (($entities['event_date_iso'] ?? null) === null) {
            $reasons[] = 'missing_event_date';
        }

        if (($context['availability_checked'] ?? false) !== true) {
            $reasons[] = 'missing_availability_check';
        }

        $candidate = [
            'action' => 'send_booking_link',
            'reasons' => $reasons,
        ];

        $sendBookingLinkMeta = $this->buildSendBookingLinkMeta($context, $entities);
        if ($sendBookingLinkMeta !== null) {
            $candidate['meta'] = [
                'send_booking_link' => $sendBookingLinkMeta,
            ];
        }

        return $candidate;
    }

    private function shouldResumePricelistFlow(Intent $intent, array $entities, array $context): bool
    {
        if (! in_array($intent, [Intent::ProvideName, Intent::ProvideEventType], true)) {
            return false;
        }

        $previousBlockedAction = $context['previous_blocked_action'] ?? null;
        if (! is_array($previousBlockedAction)) {
            return false;
        }

        if (($previousBlockedAction['action'] ?? null) !== 'send_file') {
            return false;
        }

        $reason = (string) ($previousBlockedAction['reason'] ?? '');

        if ($reason === 'missing_name') {
            return $this->hasKnownCustomerName(null, $entities);
        }

        if ($reason === 'missing_event_type') {
            return $this->hasKnownEventType($entities);
        }

        return false;
    }

    private function hasKnownCustomerName(?LeadProfile $leadProfile, array $entities): bool
    {
        $leadName = trim((string) ($leadProfile?->full_name ?? ''));
        if ($leadName !== '') {
            return true;
        }

        $entityName = trim((string) ($entities['customer_name'] ?? $entities['name'] ?? ''));

        return $entityName !== '';
    }

    private function hasKnownEventType(array $entities): bool
    {
        $eventType = trim((string) ($entities['event_type'] ?? ''));
        if ($eventType !== '') {
            return true;
        }

        // Fallback: "photo wedding", "foto video pernikahan", dll sering diekstrak sebagai
        // service_interest bukan event_type — cukup untuk melanjutkan pricelist flow.
        $serviceInterest = trim((string) ($entities['service_interest'] ?? ''));

        return $serviceInterest !== '';
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $entities
     * @return array{package_name:string,items:array<int,string>}|null
     */
    private function resolvePackageDetailSummary(array $context, array $entities): ?array
    {
        $directSummary = $context['package_detail_summary'] ?? null;
        if (is_array($directSummary)) {
            $packageName = trim((string) ($directSummary['package_name'] ?? ''));
            $items = $this->normalizeStringList($directSummary['items'] ?? []);
            if ($packageName !== '' && $items !== []) {
                return [
                    'package_name' => $packageName,
                    'items' => $items,
                ];
            }
        }

        $lookup = $context['package_detail_lookup'] ?? null;
        if (! is_array($lookup) || $lookup === []) {
            return null;
        }

        $candidateKeys = array_filter([
            strtolower(trim((string) ($entities['resolved_package_code'] ?? ''))),
            strtolower(trim((string) ($entities['resolved_package_name'] ?? ''))),
            strtolower(trim((string) ($entities['package_query'] ?? ''))),
            strtolower(trim((string) ($entities['package_interest'] ?? ''))),
        ], static fn (string $value): bool => $value !== '');

        foreach ($candidateKeys as $key) {
            $match = $lookup[$key] ?? null;
            if (! is_array($match)) {
                $match = $this->findPackageLookupByRelaxedKey($lookup, $key);
                if (! is_array($match)) {
                    continue;
                }
            }

            $packageName = trim((string) ($match['package_name'] ?? ''));
            $items = $this->normalizeStringList($match['items'] ?? []);
            if ($packageName !== '' && $items !== []) {
                return [
                    'package_name' => $packageName,
                    'items' => $items,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $lookup
     * @return array<string,mixed>|null
     */
    private function findPackageLookupByRelaxedKey(array $lookup, string $candidateKey): ?array
    {
        $normalizedCandidate = $this->normalizeLookupComparisonText($candidateKey);
        if ($normalizedCandidate === '') {
            return null;
        }

        foreach ($lookup as $lookupKey => $match) {
            if (! is_string($lookupKey) || ! is_array($match)) {
                continue;
            }

            $normalizedLookupKey = $this->normalizeLookupComparisonText($lookupKey);
            if ($normalizedLookupKey === '') {
                continue;
            }

            if ($normalizedLookupKey === $normalizedCandidate) {
                return $match;
            }

            if (str_contains($normalizedCandidate, $normalizedLookupKey) || str_contains($normalizedLookupKey, $normalizedCandidate)) {
                return $match;
            }
        }

        return null;
    }

    private function normalizeLookupComparisonText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^a-z0-9\s]/u', ' ', $normalized);
        if (! is_string($normalized)) {
            return '';
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($normalized));
        if (! is_string($normalized) || $normalized === '') {
            return '';
        }

        $tokens = explode(' ', $normalized);
        while ($tokens !== [] && in_array(end($tokens), self::TRAILING_FILLER_WORDS, true)) {
            array_pop($tokens);
        }

        $tokens = array_values(array_filter($tokens, fn (string $t): bool => ! in_array($t, self::CONNECTOR_WORDS, true)));

        return implode(' ', $tokens);
    }

    /**
     * @param  mixed  $value
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $row) {
            if (! is_string($row)) {
                continue;
            }

            $item = trim($row);
            if ($item === '') {
                continue;
            }

            $normalized[] = $item;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<int,string>  $items
     */
    private function formatPackageItems(array $items): string
    {
        if ($items === []) {
            return 'bisa kami jelaskan lebih lanjut sesuai kebutuhan kakak';
        }

        $items = array_slice($items, 0, 3);

        return implode(', ', $items);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildSendFileMeta(array $context): ?array
    {
        $asset = $context['pricelist_asset'] ?? null;
        $delivery = $context['delivery_channel'] ?? null;

        if (! is_array($asset) || ! is_array($delivery)) {
            return null;
        }

        $provider = trim((string) ($delivery['provider'] ?? ''));
        $waAccountProviderRef = trim((string) ($delivery['wa_account_provider_ref'] ?? ''));
        $to = trim((string) ($delivery['to'] ?? ''));
        $tenantAssetId = (int) ($asset['id'] ?? 0);
        $originalFilename = trim((string) ($asset['original_filename'] ?? ''));
        $displayName = trim((string) ($asset['display_name'] ?? ''));

        if ($provider === '' || $waAccountProviderRef === '' || $to === '' || $tenantAssetId <= 0) {
            return null;
        }

        $fileName = $originalFilename !== '' ? $originalFilename : $displayName;
        if ($fileName === '') {
            return null;
        }

        return [
            'provider' => $provider,
            'wa_account_provider_ref' => $waAccountProviderRef,
            'wa_session_provider_ref' => $delivery['wa_session_provider_ref'] ?? null,
            'provider_message_id' => null,
            'to' => $to,
            'tenant_asset_id' => $tenantAssetId,
            'file_name' => $fileName,
            'mime_type' => $this->detectMimeTypeFromFilename($fileName),
            'caption' => $displayName !== '' ? $displayName : 'Pricelist',
            'meta' => [
                'source' => 'turn_pipeline',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $entities
     */
    private function buildSendBookingLinkMeta(array $context, array $entities = []): ?array
    {
        $delivery = $context['delivery_channel'] ?? null;
        if (! is_array($delivery)) {
            return null;
        }

        $provider = trim((string) ($delivery['provider'] ?? ''));
        $waAccountProviderRef = trim((string) ($delivery['wa_account_provider_ref'] ?? ''));
        $to = trim((string) ($delivery['to'] ?? ''));

        if ($provider === '' || $waAccountProviderRef === '' || $to === '') {
            return null;
        }

        $eventDateIso = is_string($entities['event_date_iso'] ?? null) && trim($entities['event_date_iso']) !== ''
            ? trim($entities['event_date_iso'])
            : null;

        return [
            'provider'               => $provider,
            'wa_account_provider_ref' => $waAccountProviderRef,
            'wa_session_provider_ref' => $delivery['wa_session_provider_ref'] ?? null,
            'provider_message_id'    => null,
            'to'                     => $to,
            'event_date_iso'         => $eventDateIso,
            'meta'                   => [
                'source' => 'turn_pipeline',
            ],
        ];
    }

    private function detectMimeTypeFromFilename(string $fileName): string
    {
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function normalizeDecisionActionName(string $action): string
    {
        if ($action === 'reply_safe_text') {
            return 'reply_text';
        }

        return $action;
    }

    private function runValidators(array $candidate, array $context): ?string
    {
        $policyError = $this->policyValidator->validate($candidate, $context);
        if ($policyError !== null) {
            return $policyError;
        }

        $groundingError = $this->groundingValidator->validate($candidate, $context);
        if ($groundingError !== null) {
            return $groundingError;
        }

        $permissionError = $this->actionPermissionValidator->validate($candidate, $context);
        if ($permissionError !== null) {
            return $permissionError;
        }

        $modeError = $this->modeValidator->validate($candidate, $context);
        if ($modeError !== null) {
            return $modeError;
        }

        return null;
    }

    private function goalFromIntent(Intent $intent): ?string
    {
        return match ($intent) {
            Intent::AskPrice, Intent::AskPricelist => 'pricing',
            Intent::BookingIntent, Intent::ConfirmBooking => 'booking',
            Intent::AskAvailability => 'availability',
            Intent::ProvideName, Intent::ProvideDate, Intent::ProvideEventType, Intent::ProvideBudget, Intent::ProvidePreference => 'qualification',
            Intent::Greeting, Intent::FirstContact, Intent::IntroInterest => 'opening',
            Intent::AskPackage, Intent::AskPackageDetail => 'package_explanation',
            Intent::AskFaq => 'faq',
            Intent::ObjectionPrice, Intent::ObjectionTime, Intent::ObjectionMisc => 'objection_handling',
            Intent::Complaint, Intent::RequestHandoff => 'handoff',
            Intent::PaymentRelated => 'invoice_phase',
            Intent::UnclearMessage, Intent::Unknown => 'clarification',
            default => null,
        };
    }

    /**
     * Resolve final goal — for TopicSwitch/Correction, inherit previous goal.
     */
    private function resolveGoal(Intent $intent, ?string $previousGoal): string
    {
        if (in_array($intent, [Intent::TopicSwitch, Intent::Correction], true)) {
            $prev = is_string($previousGoal) ? trim($previousGoal) : '';
            return $prev !== '' ? $prev : 'clarification';
        }

        $goal = $this->goalFromIntent($intent);
        return is_string($goal) && $goal !== '' ? $goal : 'clarification';
    }

    /**
     * @return array{status:string,checked:bool,available:bool,reason:?string,source:string}
     */
    private function normalizeCalendarCheck(mixed $value): array
    {
        if (! is_array($value)) {
            return [
                'status' => 'blocked',
                'checked' => false,
                'available' => false,
                'reason' => 'calendar_check_missing',
                'source' => 'context_missing',
            ];
        }

        return [
            'status' => is_string($value['status'] ?? null) ? $value['status'] : 'blocked',
            'checked' => ($value['checked'] ?? false) === true,
            'available' => ($value['available'] ?? false) === true,
            'reason' => is_string($value['reason'] ?? null) ? $value['reason'] : null,
            'source' => is_string($value['source'] ?? null) ? $value['source'] : 'context',
        ];
    }

    /**
     * @param  mixed  $grounding
     * @param  array{status:string,checked:bool,available:bool,reason:?string,source:string}  $calendarCheck
     * @return array<string,mixed>
     */
    private function mergeGrounding(mixed $grounding, array $calendarCheck): array
    {
        $resolved = is_array($grounding) ? $grounding : [];

        if (! array_key_exists('calendar', $resolved)) {
            $resolved['calendar'] = [
                'is_grounded' => $calendarCheck['checked'] === true && $calendarCheck['available'] === true,
                'reason' => $calendarCheck['reason'],
                'source' => $calendarCheck['source'],
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string,mixed>  $candidates
     * @param  array<string,mixed>  $context
     * @return array{required:bool,reason_code:?string,priority:?string}
     */
    private function resolveHandoffSignal(
        Intent $intent,
        float $confidence,
        string $agentMode,
        array $candidates,
        array $context,
        array $dispatchTrace
    ): array
    {
        $agentMode = $this->normalizeAgentMode($agentMode);

        if ($agentMode === 'paused') {
            return [
                'required' => true,
                'reason_code' => 'mode_paused',
                'priority' => 'critical',
            ];
        }

        if ($agentMode === 'handoff') {
            return [
                'required' => true,
                'reason_code' => 'mode_handoff',
                'priority' => 'critical',
            ];
        }

        if ($intent === Intent::Complaint) {
            return [
                'required' => true,
                'reason_code' => 'complaint_detected',
                'priority' => 'high',
            ];
        }

        if ($intent === Intent::RequestHandoff) {
            return [
                'required' => true,
                'reason_code' => 'request_handoff',
                'priority' => 'high',
            ];
        }

        if ($intent === Intent::BookingIntent || $intent === Intent::ConfirmBooking) {
            $calendarCheck = $context['calendar_check'] ?? null;

            // Calendar provider error is a hard handoff (not a free-pass to dispatch booking link).
            if (is_array($calendarCheck) && ($calendarCheck['reason'] ?? null) === 'calendar_provider_error') {
                return [
                    'required' => true,
                    'reason_code' => 'calendar_provider_error',
                    'priority' => 'high',
                ];
            }

            $bookingLinkDispatched = (($dispatchTrace['action'] ?? null) === 'send_booking_link')
                && (($dispatchTrace['status'] ?? null) === 'executed');

            if ($bookingLinkDispatched) {
                return [
                    'required' => true,
                    'reason_code' => 'booking_requested',
                    'priority' => 'high',
                ];
            }

            if (is_array($calendarCheck)
                && (($calendarCheck['checked'] ?? false) === true)
                && (($calendarCheck['available'] ?? false) === false)
            ) {
                return [
                    'required' => true,
                    'reason_code' => 'calendar_unavailable',
                    'priority' => 'high',
                ];
            }

            $blocked = $candidates['blocked'] ?? [];
            $blockedReasons = $blocked !== [] && is_array($blocked[0]['reasons'] ?? null)
                ? $blocked[0]['reasons']
                : [];

            $hasNonCalendarBlockers = array_filter(
                $blockedReasons,
                fn ($r) => ! in_array($r, self::CALENDAR_RELATED_BLOCKERS, true)
            ) !== [];

            // Handoff jika kalender belum dikonfigurasi, tapi hanya saat data booking lain sudah lengkap
            if (! $hasNonCalendarBlockers
                && is_array($calendarCheck)
                && ($calendarCheck['checked'] ?? false) === false
                && in_array($calendarCheck['reason'] ?? null, ['calendar_integration_disabled'], true)
            ) {
                return [
                    'required' => true,
                    'reason_code' => 'calendar_unavailable',
                    'priority' => 'high',
                ];
            }

            if (in_array('grounding_calendar_missing_source', $blockedReasons, true)) {
                return [
                    'required' => true,
                    'reason_code' => 'calendar_unavailable',
                    'priority' => 'high',
                ];
            }

            if (in_array('booking_link_not_available', $blockedReasons, true)) {
                return [
                    'required' => true,
                    'reason_code' => 'booking_link_unavailable',
                    'priority' => 'high',
                ];
            }

            if (in_array('policy_tenant_blocked', $blockedReasons, true)
                || in_array('policy_global_blocked', $blockedReasons, true)
                || in_array('policy_business_hours_blocked', $blockedReasons, true)
            ) {
                return [
                    'required' => true,
                    'reason_code' => 'policy_blocked',
                    'priority' => 'high',
                ];
            }

            return [
                'required' => false,
                'reason_code' => null,
                'priority' => null,
            ];
        }

        $leadLimit = $context['lead_limit'] ?? null;
        if (is_array($leadLimit) && (($leadLimit['limit_exhausted_for_new_lead'] ?? false) === true)) {
            return [
                'required' => true,
                'reason_code' => 'lead_limit_exhausted',
                'priority' => 'high',
            ];
        }

        if ($intent === Intent::AskAvailability) {
            $calendarCheck = $context['calendar_check'] ?? null;
            if (is_array($calendarCheck)) {
                if (($calendarCheck['reason'] ?? null) === 'calendar_provider_error') {
                    return [
                        'required' => true,
                        'reason_code' => 'calendar_provider_error',
                        'priority' => 'high',
                    ];
                }
                $checkedAndUnavailable = ($calendarCheck['checked'] ?? false) === true
                    && ($calendarCheck['available'] ?? false) === false;
                $calendarDisabled = ($calendarCheck['checked'] ?? false) === false
                    && in_array($calendarCheck['reason'] ?? null, ['calendar_integration_disabled'], true);
                if ($checkedAndUnavailable || $calendarDisabled) {
                    return [
                        'required' => true,
                        'reason_code' => 'calendar_unavailable',
                        'priority' => 'high',
                    ];
                }
            }
        }

        if ($confidence < self::LOW_CONFIDENCE_THRESHOLD && (($context['force_handoff_on_low_confidence'] ?? false) === true)) {
            return [
                'required' => true,
                'reason_code' => 'low_confidence',
                'priority' => 'medium',
            ];
        }

        $blocked = $candidates['blocked'] ?? [];
        if (! is_array($blocked) || $blocked === []) {
            return [
                'required' => false,
                'reason_code' => null,
                'priority' => null,
            ];
        }

        $firstReasons = $blocked[0]['reasons'] ?? [];
        if (! is_array($firstReasons)) {
            return [
                'required' => false,
                'reason_code' => null,
                'priority' => null,
            ];
        }

        $policyBlocked = in_array('policy_tenant_blocked', $firstReasons, true)
            || in_array('policy_global_blocked', $firstReasons, true)
            || in_array('policy_business_hours_blocked', $firstReasons, true);

        return [
            'required' => $policyBlocked,
            'reason_code' => $policyBlocked ? 'policy_blocked' : null,
            'priority' => $policyBlocked ? 'high' : null,
        ];
    }

    private function normalizeAgentMode(string $agentMode): string
    {
        $normalized = strtolower(trim($agentMode));

        return match ($normalized) {
            'ai' => 'assistant',
            default => $normalized,
        };
    }

    private function deriveDecisionKeyword(array $candidates, bool $handoffRequired): string
    {
        if ($handoffRequired) {
            return 'handoff_required';
        }

        if (($candidates['allowed'] ?? []) !== []) {
            return 'allow_action';
        }

        return 'ask_missing_required_info';
    }

    /**
     * @return array<int,string>
     */
    private function extractGroundingRefs(mixed $grounding): array
    {
        if (! is_array($grounding)) {
            return [];
        }

        $refs = [];
        foreach ($grounding as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (is_bool($value) && $value === true) {
                $refs[] = $key;
                continue;
            }

            if (is_array($value) && (($value['is_grounded'] ?? false) === true)) {
                $refs[] = $key;
            }
        }

        sort($refs);

        return $refs;
    }
}
