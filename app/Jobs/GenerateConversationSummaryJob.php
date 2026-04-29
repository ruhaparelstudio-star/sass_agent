<?php

namespace App\Jobs;

use App\Modules\Conversation\Services\ConversationSummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateConversationSummaryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $conversationId
    ) {
    }

    public function handle(ConversationSummaryService $summaryService): void
    {
        $summaryService->generateForConversation($this->tenantId, $this->conversationId);
    }
}
