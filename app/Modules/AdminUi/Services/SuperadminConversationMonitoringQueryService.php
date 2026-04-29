<?php

namespace App\Modules\AdminUi\Services;

use App\Models\Conversation;
use App\Models\Handoff;
use App\Models\Message;
use App\Models\Tenant;

class SuperadminConversationMonitoringQueryService
{
    /**
     * @return array{summary:array{total_conversations:int,open_conversations:int,pending_handoffs:int},rows:array<int,array<string,mixed>>}
     */
    public function getMonitoringData(): array
    {
        $rows = Conversation::query()
            ->with('tenant:id,name,slug')
            ->latest('id')
            ->limit(30)
            ->get(['id', 'tenant_id', 'customer_phone', 'status', 'created_at']);

        $conversationIds = $rows->pluck('id')->all();

        $lastMessages = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->orderByDesc('id')
            ->get(['conversation_id', 'content', 'created_at'])
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        $pendingCounts = Handoff::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('status', 'pending')
            ->selectRaw('conversation_id, COUNT(*) as pending_count')
            ->groupBy('conversation_id')
            ->pluck('pending_count', 'conversation_id');

        return [
            'summary' => [
                'total_conversations' => Conversation::query()->count(),
                'open_conversations' => Conversation::query()->where('status', 'open')->count(),
                'pending_handoffs' => Handoff::query()->where('status', 'pending')->count(),
            ],
            'rows' => $rows->map(function (Conversation $conversation) use ($lastMessages, $pendingCounts): array {
                $lastMessage = $lastMessages->get($conversation->id);
                $tenant = $conversation->tenant;

                return [
                    'id' => $conversation->id,
                    'tenant' => [
                        'id' => $tenant?->id,
                        'name' => $tenant?->name ?? 'Unknown',
                        'slug' => $tenant?->slug ?? '-',
                    ],
                    'customer_phone' => $conversation->customer_phone,
                    'status' => $conversation->status,
                    'status_label' => $conversation->status === 'open' ? 'Open' : ucfirst((string) $conversation->status),
                    'created_at' => $conversation->created_at,
                    'last_message_preview' => $lastMessage?->content ? mb_strimwidth($lastMessage->content, 0, 72, '...') : '-',
                    'last_activity_at' => $lastMessage?->created_at ?? $conversation->created_at,
                    'pending_handoffs' => (int) ($pendingCounts[$conversation->id] ?? 0),
                    'deep_link' => '/tenant/inbox?conversation_id='.$conversation->id,
                ];
            })->toArray(),
        ];
    }
}
