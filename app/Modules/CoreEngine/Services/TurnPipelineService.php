<?php

namespace App\Modules\CoreEngine\Services;

use App\Models\Conversation;
use App\Models\LeadProfile;
use App\Models\Tenant;
use App\Modules\AiLayer\Enums\Intent;
use App\Modules\AiLayer\Services\InterpretationService;
use App\Modules\Validation\Contracts\ActionPermissionValidator;
use App\Modules\Validation\Contracts\GroundingValidator;
use App\Modules\Validation\Contracts\ModeValidator;
use App\Modules\Validation\Contracts\PolicyValidator;

class TurnPipelineService
{
    public function __construct(
        private readonly InterpretationService $interpretationService,
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

        if (($candidate['reasons'] ?? []) !== []) {
            $result['blocked'][] = $candidate;
        } else {
            $validationContext = [
                'tenant_id' => $tenant->id,
                'state' => [
                    'agent_mode' => $state->agent_mode,
                    'active_goal' => $state->active_goal,
                ],
                'entities' => $entities,
            ];

            $validationError = $this->runValidators($candidate, $validationContext);

            if ($validationError !== null) {
                $candidate['reasons'] = [$validationError];
                $result['blocked'][] = $candidate;
            } else {
                $result['allowed'][] = $candidate;
            }
        }

        return [
            'intent' => $interpretation->intent->value,
            'entities' => $entities,
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
                'executed' => false,
                'validator_order' => ['policy', 'grounding', 'permission', 'mode'],
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
            'action' => 'send_pricelist',
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
            'action' => 'request_booking',
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
}
