<?php

namespace App\Modules\CoreEngine\Services;

use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\LeadProfile;
use App\Models\Tenant;
use App\Modules\AiLayer\Enums\Intent;
use App\Modules\AiLayer\Services\InterpretationService;
use App\Modules\Action\Services\ActionDispatcherService;
use App\Modules\Validation\Contracts\ActionPermissionValidator;
use App\Modules\Validation\Contracts\GroundingValidator;
use App\Modules\Validation\Contracts\ModeValidator;
use App\Modules\Validation\Contracts\PolicyValidator;

class TurnPipelineService
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.45;

    public function __construct(
        private readonly InterpretationService $interpretationService,
        private readonly ActionDispatcherService $actionDispatcherService,
        private readonly PolicyValidator $policyValidator,
        private readonly GroundingValidator $groundingValidator,
        private readonly ActionPermissionValidator $actionPermissionValidator,
        private readonly ModeValidator $modeValidator,
    ) {}

    public function handle(
        Tenant $tenant,
        Conversation $conversation,
        string $userMessage,
        string $instruction,
        array $context = []
    ): array {
        $state = $conversation->state()->firstOrFail();
        $dormantRetrieval = $this->resolveDormantRetrieval($tenant, $conversation, $context);

        $interpretation = $this->interpretationService->interpret($tenant->id, $userMessage, $instruction);

        $entities = $this->mergeEntities(
            $context['entities'] ?? [],
            $interpretation->entities
        );

        $updatedGoal = $this->goalFromIntent($interpretation->intent);
        if ($updatedGoal !== null && $updatedGoal !== $state->active_goal) {
            $state->active_goal = $updatedGoal;
            $state->save();
        }

        $leadProfile = LeadProfile::query()
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', $conversation->customer_phone)
            ->first();

        $candidate = $this->buildCandidate($interpretation->intent, $entities, $leadProfile, $context);

        $result = [
            'allowed' => [],
            'blocked' => [],
        ];

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

        $allowedActions = array_values(array_map(
            fn (array $row): string => (string) ($row['action'] ?? ''),
            $result['allowed']
        ));

        $handoffSignal = $this->resolveHandoffSignal(
            $interpretation->intent,
            $interpretation->confidence,
            $state->agent_mode,
            $result,
            $context
        );
        $handoffRequired = $handoffSignal['required'];

        $response = [
            'intent' => $interpretation->intent->value,
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
                'message' => $this->buildResponsePlanMessage($result),
            ],
            'trace' => [
                'executed' => $dispatchTrace['executed'],
                'action' => $dispatchTrace['action'],
                'status' => $dispatchTrace['status'],
                'reason' => $dispatchTrace['reason'],
                'validator_order' => ['policy', 'grounding', 'permission', 'mode'],
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
     *   data:?array{summary:string,message_count:int,summarized_at:?string}
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
                'message_count' => $summary->message_count,
                'summarized_at' => $summary->summarized_at?->toDateTimeString(),
            ],
        ];
    }

    private function mergeEntities(array $previous, array $current): array
    {
        $isCorrection = ($current['is_correction'] ?? false) === true;

        if (! $isCorrection) {
            return $current;
        }

        $merged = $previous;
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

    private function buildCandidate(Intent $intent, array $entities, ?LeadProfile $leadProfile, array $context): array
    {
        return match ($intent) {
            Intent::AskPricelist => $this->buildPricelistCandidate($leadProfile),
            Intent::BookingIntent => $this->buildBookingCandidate($entities, $leadProfile, $context),
            default => [
                'action' => 'reply_safe_text',
                'reasons' => [],
            ],
        };
    }

    private function buildPricelistCandidate(?LeadProfile $leadProfile): array
    {
        $missingName = trim((string) ($leadProfile?->full_name ?? '')) === '';

        return [
            'action' => 'send_file',
            'reasons' => $missingName ? ['missing_name'] : [],
        ];
    }

    private function buildBookingCandidate(array $entities, ?LeadProfile $leadProfile, array $context): array
    {
        $reasons = [];

        if (trim((string) ($leadProfile?->full_name ?? '')) === '') {
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

        return [
            'action' => 'send_booking_link',
            'reasons' => $reasons,
        ];
    }

    private function buildResponsePlanMessage(array $candidates): string
    {
        if (($candidates['blocked'] ?? []) === []) {
            return 'Respond safely based on allowed candidate only.';
        }

        $reasons = $candidates['blocked'][0]['reasons'] ?? [];

        if (in_array('missing_name', $reasons, true)) {
            return 'Minta nama lengkap pelanggan terlebih dahulu sebelum melanjutkan.';
        }

        return 'Jelaskan data yang masih kurang dan jangan eksekusi aksi.';
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
            Intent::BookingIntent => 'booking',
            Intent::AskAvailability => 'availability',
            default => null,
        };
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
        array $context
    ): array
    {
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

        $leadLimit = $context['lead_limit'] ?? null;
        if (is_array($leadLimit) && (($leadLimit['limit_exhausted_for_new_lead'] ?? false) === true)) {
            return [
                'required' => true,
                'reason_code' => 'lead_limit_exhausted',
                'priority' => 'high',
            ];
        }

        $calendarCheck = $context['calendar_check'] ?? null;
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

        if ($confidence < self::LOW_CONFIDENCE_THRESHOLD) {
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
