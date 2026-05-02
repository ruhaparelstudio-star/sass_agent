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
use App\Modules\Validation\Contracts\ActionPermissionValidator;
use App\Modules\Validation\Contracts\GroundingValidator;
use App\Modules\Validation\Contracts\ModeValidator;
use App\Modules\Validation\Contracts\PolicyValidator;

class TurnPipelineService
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.45;
    private const COMPACT_VALUE_MAX_CHARS = 80;
    private const COMPACT_BLOCK_MAX_CHARS = 320;

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

        $updatedGoal = $this->goalFromIntent($finalIntent);
        if ($updatedGoal !== null && $updatedGoal !== $state->active_goal) {
            // Never regress from booking to qualification — providing data mid-booking doesn't cancel intent
            if (! ($state->active_goal === 'booking' && $updatedGoal === 'qualification')) {
                $state->active_goal = $updatedGoal;
                $state->save();
                $conversation->forceFill([
                    'active_goal' => $state->active_goal,
                ])->save();
            }
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
                'message' => $this->buildResponsePlanMessage($finalIntent, $result, $tenant, $entities, $context),
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

            $merged[$key] = $value;
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
            Intent::AskPricelist => $this->buildPricelistCandidate($leadProfile, $entities, $context),
            Intent::BookingIntent => $this->buildBookingCandidate($entities, $leadProfile, $context),
            default => [
                'action' => 'reply_safe_text',
                'reasons' => [],
            ],
        };
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

    private function buildResponsePlanMessage(Intent $intent, array $candidates, Tenant $tenant, array $entities = [], array $context = []): string
    {
        if ($intent === Intent::Greeting) {
            $tenantName = trim((string) $tenant->name);
            if ($tenantName === '') {
                $tenantName = 'kami';
            }

            return sprintf('Perkenalkan saya asisten %s, ada yang bisa saya bantu?', $tenantName);
        }

        if ($intent === Intent::UnclearMessage) {
            return 'Boleh ceritakan kebutuhan acara kakak dulu ya, misalnya paket, tanggal, atau budget.';
        }

        $allowedAction = (string) (($candidates['allowed'][0]['action'] ?? '') ?: '');
        if ($allowedAction === 'send_file') {
            return 'Ini pricelist terbaru kami ya kak, kalau ada yang kurang jelas boleh langsung ditanyakan.';
        }

        if (($candidates['blocked'] ?? []) === []) {
            if ($intent === Intent::AskPackageDetail) {
                $summary = $this->resolvePackageDetailSummary($context, $entities);
                if ($summary !== null) {
                    $packageName = trim((string) ($summary['package_name'] ?? 'Paket pilihan'));
                    if ($packageName === '') {
                        $packageName = 'Paket pilihan';
                    }

                    $itemSentence = $this->formatPackageItems($summary['items'] ?? []);

                    return sprintf('Untuk %s, detail photo+video-nya %s.', $packageName, $itemSentence);
                }

                $namedPackage = trim((string) (
                    $entities['resolved_package_name']
                        ?? $entities['package_query']
                        ?? $entities['package_interest']
                        ?? ''
                ));
                if ($namedPackage !== '') {
                    return sprintf(
                        'Untuk paket %s, detail lengkapnya bisa langsung ditanyakan ke tim kami ya kak.',
                        $namedPackage
                    );
                }

                return 'Boleh sebutkan paket atau layanan yang mau dijelaskan detailnya ya kak?';
            }

            if ($intent === Intent::ProvideName) {
                return 'Makasih kak, namanya sudah saya catat. Siap kak, kakak lagi cari layanan untuk acara apa ya?';
            }

            if ($intent === Intent::ProvidePreference) {
                return 'Siap kak, preferensinya sudah saya catat. Boleh share tanggal acaranya supaya saya bantu rekomendasi paket yang paling pas?';
            }

            return 'Terima kasih kak, boleh ceritakan kebutuhan acaranya biar saya bantu rekomendasi paket yang paling sesuai?';
        }

        $reasons = $candidates['blocked'][0]['reasons'] ?? [];

        if (in_array('missing_name', $reasons, true)) {
            return 'Sebelum kita lanjut, aku boleh tahu nama kakak?';
        }

        if (in_array('missing_event_type', $reasons, true)) {
            return 'Siap kak, kakak lagi cari layanan untuk acara apa ya?';
        }

        if (in_array('missing_package', $reasons, true)) {
            return 'Boleh info paket yang kakak minati dulu ya?';
        }

        if (in_array('missing_event_date', $reasons, true)) {
            return 'Boleh share tanggal acara yang direncanakan ya kak?';
        }

        if (in_array('missing_availability_check', $reasons, true)) {
            return 'Siap kak, kami bantu cek dulu ketersediaan jadwalnya ya.';
        }

        return 'Boleh dibantu lengkapi informasi yang masih kurang ya kak, supaya saya bisa lanjut bantu dengan tepat.';
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

        return $eventType !== '';
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
        while ($tokens !== [] && in_array(end($tokens), ['ka', 'kak', 'kakak', 'dong', 'ya', 'yah'], true)) {
            array_pop($tokens);
        }

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
            Intent::BookingIntent => 'booking',
            Intent::AskAvailability => 'availability',
            Intent::ProvideName, Intent::ProvideDate, Intent::ProvideEventType, Intent::ProvideBudget, Intent::ProvidePreference => 'qualification',
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

        if ($intent === Intent::BookingIntent) {
            $bookingLinkDispatched = (($dispatchTrace['action'] ?? null) === 'send_booking_link')
                && (($dispatchTrace['status'] ?? null) === 'executed');

            if ($bookingLinkDispatched) {
                return [
                    'required' => true,
                    'reason_code' => 'booking_requested',
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

            // Handoff jika kalender belum dikonfigurasi saat user ingin booking
            if (is_array($calendarCheck)
                && ($calendarCheck['checked'] ?? false) === false
                && in_array($calendarCheck['reason'] ?? null, ['calendar_integration_disabled'], true)
            ) {
                return [
                    'required' => true,
                    'reason_code' => 'calendar_unavailable',
                    'priority' => 'high',
                ];
            }

            $blocked = $candidates['blocked'] ?? [];
            $blockedReasons = is_array($blocked) && is_array($blocked[0]['reasons'] ?? null)
                ? $blocked[0]['reasons']
                : [];

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

        // Handoff jika kalender belum dikonfigurasi admin
        if (is_array($calendarCheck)
            && ($calendarCheck['checked'] ?? false) === false
            && in_array($calendarCheck['reason'] ?? null, ['calendar_integration_disabled'], true)
        ) {
            return [
                'required' => true,
                'reason_code' => 'calendar_unavailable',
                'priority' => 'high',
            ];
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
