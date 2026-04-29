<?php

namespace App\Modules\AdminUi\Services;

use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\LeadProfile;
use App\Models\Message;

class TenantConversationInboxQueryService
{
    /**
     * @return array{conversationList:array<int,array<string,mixed>>,selectedConversation:?array<string,mixed>,messages:array<int,array<string,mixed>>,handoffs:array<int,array<string,mixed>>,contextPanel:array<string,mixed>}
     */
    public function getInboxData(int $tenantId, ?int $conversationId, string $query = ''): array
    {
        $conversationListQuery = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->when($query !== '', fn ($q) => $q->where('customer_phone', 'like', '%'.$query.'%'))
            ->latest('id')
            ->limit(20);

        $conversationRows = $conversationListQuery->get(['id', 'tenant_id', 'customer_phone', 'status', 'created_at']);
        $conversationIds = $conversationRows->pluck('id')->all();

        $lastMessages = Message::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('conversation_id', $conversationIds)
            ->orderByDesc('id')
            ->get(['conversation_id', 'content', 'created_at'])
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        $conversationList = $conversationRows->map(function (Conversation $conversation) use ($lastMessages): array {
            $lastMessage = $lastMessages->get($conversation->id);

            return [
                'id' => $conversation->id,
                'tenant_id' => $conversation->tenant_id,
                'customer_phone' => $conversation->customer_phone,
                'status' => $conversation->status,
                'status_label' => $this->statusLabel((string) $conversation->status),
                'created_at' => $conversation->created_at,
                'last_message_preview' => $lastMessage?->content ? mb_strimwidth($lastMessage->content, 0, 58, '...') : '-',
                'last_activity_at' => $lastMessage?->created_at ?? $conversation->created_at,
            ];
        })->toArray();

        if ($conversationId === null && $conversationList !== []) {
            $conversationId = (int) $conversationList[0]['id'];
        }

        if ($conversationId === null) {
            return [
                'conversationList' => $conversationList,
                'selectedConversation' => null,
                'messages' => [],
                'handoffs' => [],
                'contextPanel' => $this->emptyContextPanel(),
            ];
        }

        $selectedConversation = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($conversationId)
            ->with('state')
            ->first();

        if (! $selectedConversation) {
            return [
                'conversationList' => $conversationList,
                'selectedConversation' => null,
                'messages' => [],
                'handoffs' => [],
                'contextPanel' => $this->emptyContextPanel(),
            ];
        }

        $messages = Message::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $selectedConversation->id)
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'conversation_id', 'direction', 'content', 'created_at'])
            ->map(fn (Message $message): array => [
                'id' => $message->id,
                'tenant_id' => $message->tenant_id,
                'conversation_id' => $message->conversation_id,
                'direction' => (string) $message->direction->value,
                'content' => $message->content,
                'created_at' => $message->created_at,
                'direction_label' => strtoupper((string) $message->direction->value),
            ])
            ->toArray();

        $handoffs = $selectedConversation->handoffs()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->get(['id', 'tenant_id', 'conversation_id', 'reason_code', 'note', 'status', 'created_at'])
            ->map(fn ($handoff): array => [
                'id' => $handoff->id,
                'tenant_id' => $handoff->tenant_id,
                'conversation_id' => $handoff->conversation_id,
                'reason_code' => $handoff->reason_code,
                'note' => $handoff->note,
                'status' => $handoff->status,
                'status_label' => $this->statusLabel((string) $handoff->status),
                'created_at' => $handoff->created_at,
                'can_resolve_handoff' => $handoff->status === 'pending',
                'can_resume_ai' => $handoff->status === 'resolved',
            ])
            ->toArray();

        $lead = LeadProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_phone', $selectedConversation->customer_phone)
            ->first();

        $latestContext = ConversationContext::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $selectedConversation->id)
            ->latest('id')
            ->first();

        return [
            'conversationList' => $conversationList,
            'selectedConversation' => [
                'id' => $selectedConversation->id,
                'tenant_id' => $selectedConversation->tenant_id,
                'customer_phone' => $selectedConversation->customer_phone,
                'status' => $selectedConversation->status,
                'created_at' => $selectedConversation->created_at,
                'state' => $selectedConversation->state?->only([
                    'id',
                    'tenant_id',
                    'conversation_id',
                    'current_stage',
                    'active_goal',
                    'agent_mode',
                    'memory_mode',
                    'updated_at',
                ]),
            ],
            'messages' => $messages,
            'handoffs' => $handoffs,
            'contextPanel' => [
                'lead' => $lead?->only([
                    'id',
                    'tenant_id',
                    'customer_phone',
                    'full_name',
                ]),
                'state' => $selectedConversation->state?->only([
                    'id',
                    'tenant_id',
                    'conversation_id',
                    'current_stage',
                    'active_goal',
                    'agent_mode',
                    'memory_mode',
                ]),
                'context' => [
                    'summary' => $latestContext?->summary,
                    'reason' => $latestContext?->reason,
                    'recommended_next_action' => $latestContext?->recommended_next_action,
                ],
            ],
        ];
    }

    /**
     * @return array{lead:null,state:null,context:array{summary:null,reason:null,recommended_next_action:null}}
     */
    private function emptyContextPanel(): array
    {
        return [
            'lead' => null,
            'state' => null,
            'context' => [
                'summary' => null,
                'reason' => null,
                'recommended_next_action' => null,
            ],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'Open',
            'closed' => 'Closed',
            'pending' => 'Pending',
            'resolved' => 'Resolved',
            default => ucfirst($status),
        };
    }
}
