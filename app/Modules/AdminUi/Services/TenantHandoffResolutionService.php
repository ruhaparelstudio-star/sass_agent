<?php

namespace App\Modules\AdminUi\Services;

use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\ConversationState;
use App\Models\Handoff;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantHandoffResolutionService
{
    public function resolve(int $tenantId, int $conversationId, int $handoffId): void
    {
        $conversation = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }

        $handoff = Handoff::query()->findOrFail($handoffId);

        if ($handoff->tenant_id !== $tenantId) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }

        if ((int) $handoff->conversation_id !== $conversation->id) {
            throw new HttpException(409, 'Handoff does not belong to conversation.');
        }

        if ($handoff->status !== 'pending') {
            throw new HttpException(409, 'Handoff is not pending.');
        }

        $handoff->status = 'resolved';
        $handoff->save();

        $state = ConversationState::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversation->id)
            ->first();

        ConversationContext::query()->create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->id,
            'summary' => $handoff->note,
            'reason' => $handoff->reason_code,
            'recommended_next_action' => $state?->active_goal,
        ]);
    }

    public function resumeAi(int $tenantId, int $conversationId, int $handoffId): void
    {
        $conversation = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }

        $handoff = Handoff::query()->findOrFail($handoffId);

        if ($handoff->tenant_id !== $tenantId) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }

        if ((int) $handoff->conversation_id !== $conversation->id) {
            throw new HttpException(409, 'Handoff does not belong to conversation.');
        }

        if ($handoff->status !== 'resolved') {
            throw new HttpException(409, 'Handoff must be resolved before resume.');
        }

        $state = ConversationState::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversation->id)
            ->first();

        if (! $state) {
            throw new HttpException(409, 'Conversation state not found.');
        }

        $state->agent_mode    = 'assistant';
        $state->active_goal   = null;
        $state->event_date_iso = null;
        $state->save();

        $conversation->forceFill([
            'agent_mode'  => 'assistant',
            'active_goal' => null,
        ])->save();
    }
}
