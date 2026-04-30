<?php

namespace App\Jobs;

use App\Modules\Conversation\Services\ConversationSummaryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateConversationSummaryJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 180;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $conversationId
    ) {
    }

    public function handle(ConversationSummaryService $summaryService): void
    {
        $summaryService->generateForConversation($this->tenantId, $this->conversationId);
    }

    public function uniqueId(): string
    {
        return sprintf('conversation-summary:%d:%d', $this->tenantId, $this->conversationId);
    }
}
