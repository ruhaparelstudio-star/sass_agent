<?php

namespace App\Modules\Conversation\Services;

use App\Jobs\GenerateConversationSummaryJob;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\ConversationSummary;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
            ->limit(5)
            ->get(['direction', 'content'])
            ->reverse()
            ->values();

        $summaryText = $this->buildDeterministicSummary($conversation->customer_phone, $messageCount, $recentMessages);

        ConversationSummary::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
            ],
            [
                'message_count' => $messageCount,
                'summary' => $summaryText,
                'retention_until' => $state?->retention_until,
                'summarized_at' => now(),
            ]
        );
    }

    private function buildDeterministicSummary(string $customerPhone, int $messageCount, Collection $recentMessages): string
    {
        $recent = $recentMessages
            ->map(function (Message $message): string {
                $direction = $message->direction?->value ?? 'unknown';
                $content = Str::limit(trim((string) $message->content), 120, '...');

                return sprintf('[%s] %s', $direction, $content);
            })
            ->implode(' | ');

        return sprintf(
            'Conversation summary deterministic v1. Customer: %s. Total messages: %d. Recent exchanges: %s',
            $customerPhone,
            $messageCount,
            $recent
        );
    }
}
