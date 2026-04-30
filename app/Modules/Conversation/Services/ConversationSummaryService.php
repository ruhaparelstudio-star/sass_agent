<?php

namespace App\Modules\Conversation\Services;

use App\Jobs\GenerateConversationSummaryJob;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\ConversationSummary;
use App\Models\DecisionTrace;
use App\Models\LeadProfile;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Collection;

class ConversationSummaryService
{
    private const MESSAGE_THRESHOLD = 20;

    public function queueIfEligible(Tenant $tenant, Conversation $conversation): void
    {
        $scopedConversation = Conversation::query()
            ->where('id', $conversation->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $scopedConversation) {
            return;
        }

        $state = ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        if ($state && $state->retention_until !== null && $state->retention_until->lte(now())) {
            return;
        }

        $messageCount = Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->count();

        if ($messageCount < self::MESSAGE_THRESHOLD) {
            return;
        }

        $existing = ConversationSummary::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        if ($existing && $existing->message_count >= $messageCount) {
            return;
        }

        GenerateConversationSummaryJob::dispatch($tenant->id, $conversation->id);
    }

    public function generateForConversation(int $tenantId, int $conversationId): void
    {
        $conversation = Conversation::query()
            ->where('id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $conversation) {
            return;
        }

        $state = ConversationState::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->first();

        if ($state && $state->retention_until !== null && $state->retention_until->lte(now())) {
            return;
        }

        $messageCount = Message::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->count();

        if ($messageCount < self::MESSAGE_THRESHOLD) {
            return;
        }

        $recentMessages = Message::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->latest('id')
            ->limit(6)
            ->get(['id', 'direction', 'content'])
            ->reverse()
            ->values();

        $leadProfile = LeadProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_phone', $conversation->customer_phone)
            ->first();

        $latestInboundTurnTrace = DecisionTrace::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->where('trace_key', 'inbound_turn')
            ->latest('id')
            ->first(['decision_json']);

        $decisionJson = is_array($latestInboundTurnTrace?->decision_json)
            ? $latestInboundTurnTrace->decision_json
            : [];

        $summaryJson = $this->buildDeterministicSummaryPayload(
            $conversation,
            $state,
            $leadProfile,
            $decisionJson,
            $recentMessages
        );

        ConversationSummary::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
            ],
            [
                'message_count' => $messageCount,
                'summary' => $this->buildHumanReadableSummary($summaryJson, $messageCount),
                'summary_json' => $summaryJson,
                'retention_until' => $state?->retention_until,
                'summarized_at' => now(),
            ]
        );
    }

    /**
     * @return array{summary:string,summary_structured:?array<string,mixed>,summary_source:string,message_count:int,summarized_at:?string}|null
     */
    public function getValidSummarySnapshot(int $tenantId, int $conversationId): ?array
    {
        $summary = ConversationSummary::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->first();

        if (! $summary) {
            return null;
        }

        if ($summary->retention_until !== null && $summary->retention_until->lte(now())) {
            return null;
        }

        return [
            'summary' => (string) $summary->summary,
            'summary_structured' => is_array($summary->summary_json) ? $summary->summary_json : null,
            'summary_source' => 'conversation_summary',
            'message_count' => (int) $summary->message_count,
            'summarized_at' => $summary->summarized_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string,mixed>  $decisionJson
     * @return array<string,mixed>
     */
    private function buildDeterministicSummaryPayload(
        Conversation $conversation,
        ?ConversationState $state,
        ?LeadProfile $leadProfile,
        array $decisionJson,
        Collection $recentMessages
    ): array {
        $entities = is_array($decisionJson['entities'] ?? null) ? $decisionJson['entities'] : [];

        $name = $this->firstNonEmptyString([
            $leadProfile?->full_name,
            $state?->customer_name,
            $entities['customer_name'] ?? null,
            $entities['name'] ?? null,
        ]);

        $eventType = $this->firstNonEmptyString([
            $state?->event_type,
            $entities['event_type'] ?? null,
        ]);

        $eventDate = $this->firstNonEmptyString([
            $entities['event_date'] ?? null,
            $entities['event_date_iso'] ?? null,
        ]);

        $location = $this->firstNonEmptyString([
            $entities['location'] ?? null,
        ]);

        $packageInterest = $this->firstNonEmptyString([
            $state?->package_interest,
            $state?->selected_package,
            $entities['package_interest'] ?? null,
            $entities['resolved_package_name'] ?? null,
            $entities['package_query'] ?? null,
        ]);

        $budget = $this->firstNonEmptyNumericString([
            $entities['budget'] ?? null,
            $entities['budget_amount'] ?? null,
        ]);

        $budgetMin = $this->firstNonEmptyNumericString([
            $entities['budget_min'] ?? null,
        ]);

        $budgetMax = $this->firstNonEmptyNumericString([
            $entities['budget_max'] ?? null,
        ]);

        $invoiceReference = $this->firstNonEmptyString([
            $entities['invoice_reference'] ?? null,
        ]);

        return [
            'lead_profile' => [
                'name' => $name,
                'phone' => (string) $conversation->customer_phone,
            ],
            'need' => $this->firstNonEmptyString([
                $state?->service_interest,
                $packageInterest,
                $eventType,
                $state?->active_goal,
            ]),
            'entities' => [
                'name' => $name,
                'event_type' => $eventType,
                'event_date' => $eventDate,
                'location' => $location,
                'budget' => $budget,
                'budget_min' => $budgetMin,
                'budget_max' => $budgetMax,
                'package_interest' => $packageInterest,
                'invoice_reference' => $invoiceReference,
            ],
            'objection' => $this->detectObjection($decisionJson, $recentMessages),
            'last_stage' => $this->firstNonEmptyString([
                $state?->current_stage,
                $conversation->current_stage,
            ]),
            'last_active_goal' => $this->firstNonEmptyString([
                $state?->active_goal,
                $conversation->active_goal,
            ]),
            'unresolved_action' => $this->resolveUnresolvedAction($state, $decisionJson),
        ];
    }

    /**
     * @param  array<string,mixed>  $summaryJson
     */
    private function buildHumanReadableSummary(array $summaryJson, int $messageCount): string
    {
        $lead = is_array($summaryJson['lead_profile'] ?? null) ? $summaryJson['lead_profile'] : [];
        $entities = is_array($summaryJson['entities'] ?? null) ? $summaryJson['entities'] : [];

        $segments = [
            sprintf('Lead: %s (%s)', $lead['name'] ?? '-', $lead['phone'] ?? '-'),
            sprintf('Need: %s', $summaryJson['need'] ?? '-'),
            sprintf('Stage: %s', $summaryJson['last_stage'] ?? '-'),
            sprintf('Goal: %s', $summaryJson['last_active_goal'] ?? '-'),
        ];

        $entityBits = [];
        foreach (['event_type', 'event_date', 'location', 'budget', 'package_interest', 'invoice_reference'] as $key) {
            $value = $entities[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $entityBits[] = sprintf('%s=%s', $key, $value);
            }
        }
        $segments[] = 'Entities: '.($entityBits !== [] ? implode(', ', $entityBits) : '-');

        $objection = $summaryJson['objection'] ?? null;
        $segments[] = sprintf('Objection: %s', is_string($objection) && trim($objection) !== '' ? $objection : '-');

        $unresolved = $summaryJson['unresolved_action'] ?? null;
        $segments[] = sprintf('Unresolved action: %s', is_string($unresolved) && trim($unresolved) !== '' ? $unresolved : '-');
        $segments[] = sprintf('Total messages: %d', $messageCount);

        return implode(' | ', $segments);
    }

    /**
     * @param  array<string,mixed>  $decisionJson
     */
    private function resolveUnresolvedAction(?ConversationState $state, array $decisionJson): ?string
    {
        $pendingAction = is_string($state?->pending_action ?? null) ? trim((string) $state?->pending_action) : '';
        if ($pendingAction !== '') {
            return $pendingAction;
        }

        $blockedActions = $decisionJson['blocked_actions'] ?? [];
        if (! is_array($blockedActions) || ! is_array($blockedActions[0] ?? null)) {
            return null;
        }

        $action = is_string($blockedActions[0]['action'] ?? null) ? trim((string) $blockedActions[0]['action']) : '';
        $reason = is_string($blockedActions[0]['reason'] ?? null) ? trim((string) $blockedActions[0]['reason']) : '';

        if ($action === '') {
            return null;
        }

        if ($reason === '') {
            return $action;
        }

        return $action.':'.$reason;
    }

    /**
     * @param  array<string,mixed>  $decisionJson
     */
    private function detectObjection(array $decisionJson, Collection $recentMessages): ?string
    {
        $intent = is_string($decisionJson['intent'] ?? null)
            ? strtolower(trim((string) $decisionJson['intent']))
            : '';

        if ($intent === 'complaint') {
            return 'complaint';
        }

        $recentText = $recentMessages
            ->filter(static fn (Message $message): bool => ($message->direction?->value ?? null) === 'inbound')
            ->map(static fn (Message $message): string => mb_strtolower(trim((string) $message->content)))
            ->implode(' ');

        if ($recentText === '') {
            return null;
        }

        if (preg_match('/\b(mahal|murah|diskon|promo|nego)\b/u', $recentText) === 1) {
            return 'price_negotiation';
        }

        if (preg_match('/\b(ragu|bingung|nanti dulu|belum yakin)\b/u', $recentText) === 1) {
            return 'hesitation';
        }

        if (preg_match('/\b(komplain|keluhan|tidak puas|kecewa)\b/u', $recentText) === 1) {
            return 'complaint';
        }

        return null;
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
     * @param  array<int,mixed>  $candidates
     */
    private function firstNonEmptyNumericString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_int($candidate) || is_float($candidate)) {
                return (string) $candidate;
            }

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
}
