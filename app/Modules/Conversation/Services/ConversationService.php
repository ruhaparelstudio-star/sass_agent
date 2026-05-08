<?php

namespace App\Modules\Conversation\Services;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\Message;
use App\Models\Tenant;
use App\Modules\Lead\Services\LeadProfileService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConversationService
{
    public function __construct(
        private readonly LeadProfileService $leadProfileService,
        private readonly ConversationSummaryService $conversationSummaryService,
    ) {}

    public function findOrCreateActiveConversation(Tenant $tenant, string $customerPhone): Conversation
    {
        $normalizedPhone = trim($customerPhone);

        if ($normalizedPhone === '') {
            throw new HttpException(422, 'Customer phone is required.');
        }

        $conversation = Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', $normalizedPhone)
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if (! $conversation) {
            $conversation = Conversation::query()->create([
                'tenant_id' => $tenant->id,
                'wa_account_id' => null,
                'customer_phone' => $normalizedPhone,
                'status' => 'open',
                'current_stage' => 'new',
                'active_goal' => null,
                'agent_mode' => 'assistant',
                'memory_mode' => 'short',
                'last_message_at' => null,
            ]);
        }

        $this->upsertState($conversation, $tenant);
        $this->leadProfileService->ensureLeadFoundation($tenant, $normalizedPhone);

        return $conversation;
    }

    public function storeMessage(
        Conversation $conversation,
        Tenant $tenant,
        MessageDirection $direction,
        string $content,
        ?array $meta = null,
        string $messageType = 'text',
        ?array $rawPayload = null,
        ?array $groundingRefs = null,
        ?int $decisionTraceId = null
    ): Message {
        $existsInTenant = Conversation::query()
            ->whereKey($conversation->id)
            ->where('tenant_id', $tenant->id)
            ->exists();

        if (! $existsInTenant) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }

        $normalizedContent = trim($content);

        if ($normalizedContent === '') {
            throw new HttpException(422, 'Message content is required.');
        }

        $message = Message::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'message_type' => trim($messageType) !== '' ? trim($messageType) : 'text',
            'body' => $normalizedContent,
            'content' => $normalizedContent,
            'raw_payload' => $rawPayload,
            'grounding_refs' => $groundingRefs,
            'decision_trace_id' => $decisionTraceId,
            'meta' => $meta,
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
        ])->save();

        $this->conversationSummaryService->queueIfEligible($tenant, $conversation);

        return $message;
    }

    public function upsertState(Conversation $conversation, Tenant $tenant, array $attributes = []): ConversationState
    {
        $existsInTenant = Conversation::query()
            ->whereKey($conversation->id)
            ->where('tenant_id', $tenant->id)
            ->exists();

        if (! $existsInTenant) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }

        $defaults = [
            'current_stage' => 'new',
            'active_goal' => null,
            'customer_name' => null,
            'event_type' => null,
            'service_interest' => null,
            'package_interest' => null,
            'selected_package' => null,
            'pending_action' => null,
            'agent_mode' => 'assistant',
            'memory_mode' => 'short',
            'retention_policy' => 'standard',
            'retention_until' => null,
        ];

        $state = ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        if (! $state) {
            $state = new ConversationState([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
            ]);
            $state->fill(array_merge($defaults, $attributes));
            $state->save();
        } elseif ($attributes !== []) {
            $state->fill($attributes);
            $state->save();
        }

        $conversation->forceFill([
            'current_stage' => $state->current_stage,
            'active_goal' => $state->active_goal,
            'agent_mode' => $state->agent_mode,
            'memory_mode' => $state->memory_mode,
        ])->save();

        return $state;
    }

    /**
     * Persist durable conversation state in a single transaction.
     *
     * Entities with non-empty values overwrite existing state columns; null/empty
     * values are skipped (existing values preserved). $stateUpdate keys override
     * stage/goal/pending_action explicitly.
     *
     * @param  array<string,mixed>  $entities
     * @param  array<string,mixed>  $stateUpdate keys: current_stage, active_goal, pending_action
     */
    public function persistDurable(
        Conversation $conversation,
        Tenant $tenant,
        array $entities,
        array $stateUpdate = []
    ): ConversationState {
        $existsInTenant = Conversation::query()
            ->whereKey($conversation->id)
            ->where('tenant_id', $tenant->id)
            ->exists();

        if (! $existsInTenant) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }

        return DB::transaction(function () use ($conversation, $tenant, $entities, $stateUpdate): ConversationState {
            $state = ConversationState::query()
                ->where('tenant_id', $tenant->id)
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();

            if (! $state) {
                $state = new ConversationState([
                    'tenant_id' => $tenant->id,
                    'conversation_id' => $conversation->id,
                    'current_stage' => 'new',
                    'agent_mode' => 'assistant',
                    'memory_mode' => 'short',
                    'retention_policy' => 'standard',
                ]);
            }

            $entityToColumn = [
                'customer_name' => 'customer_name',
                'event_type' => 'event_type',
                'service_interest' => 'service_interest',
                'package_interest' => 'package_interest',
                'selected_package' => 'selected_package',
                'event_date_iso' => 'event_date_iso',
                'event_date_raw' => 'event_date_raw',
                'location' => 'location',
                'budget_min' => 'budget_min',
                'budget_max' => 'budget_max',
            ];

            foreach ($entityToColumn as $entityKey => $column) {
                if (! array_key_exists($entityKey, $entities)) {
                    continue;
                }
                $value = $entities[$entityKey];
                if (! $this->isPersistableValue($value)) {
                    continue;
                }
                $state->{$column} = $value;
            }

            if (array_key_exists('current_stage', $stateUpdate) && is_string($stateUpdate['current_stage']) && $stateUpdate['current_stage'] !== '') {
                $state->current_stage = $stateUpdate['current_stage'];
            }
            if (array_key_exists('active_goal', $stateUpdate)) {
                $goal = $stateUpdate['active_goal'];
                $state->active_goal = is_string($goal) && $goal !== '' ? $goal : null;
            }
            if (array_key_exists('pending_action', $stateUpdate)) {
                $pending = $stateUpdate['pending_action'];
                $state->pending_action = is_array($pending) && $pending !== [] ? $pending : null;
            }

            $state->save();

            $conversation->forceFill([
                'current_stage' => $state->current_stage,
                'active_goal' => $state->active_goal,
                'agent_mode' => $state->agent_mode,
                'memory_mode' => $state->memory_mode,
            ])->save();

            return $state;
        });
    }

    private function isPersistableValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        if (is_array($value) && $value === []) {
            return false;
        }
        return true;
    }

    /**
     * @param  array<string,mixed>  $entities
     */
    public function syncLeadFromEntities(Conversation $conversation, Tenant $tenant, array $entities): void
    {
        $existsInTenant = Conversation::query()
            ->whereKey($conversation->id)
            ->where('tenant_id', $tenant->id)
            ->exists();

        if (! $existsInTenant) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }

        $this->leadProfileService->syncFromEntities($tenant, (string) $conversation->customer_phone, $entities);
    }
}
