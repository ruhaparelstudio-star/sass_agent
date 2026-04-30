<?php

namespace App\Modules\Conversation\Services;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\Message;
use App\Models\Tenant;
use App\Modules\Lead\Services\LeadProfileService;
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
            'agent_mode' => 'assistant',
            'memory_mode' => 'short',
            'retention_policy' => 'standard',
            'retention_until' => null,
        ];

        $state = ConversationState::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
            ],
            array_merge($defaults, $attributes)
        );

        $conversation->forceFill([
            'current_stage' => $state->current_stage,
            'active_goal' => $state->active_goal,
            'agent_mode' => $state->agent_mode,
            'memory_mode' => $state->memory_mode,
        ])->save();

        return $state;
    }
}
