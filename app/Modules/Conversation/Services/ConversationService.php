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
                'customer_phone' => $normalizedPhone,
                'status' => 'open',
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
        ?array $meta = null
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

        return Message::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'content' => $normalizedContent,
            'meta' => $meta,
        ]);
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

        return ConversationState::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
            ],
            array_merge($defaults, $attributes)
        );
    }
}
