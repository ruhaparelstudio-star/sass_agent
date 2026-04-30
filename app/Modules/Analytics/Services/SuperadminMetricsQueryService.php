<?php

namespace App\Modules\Analytics\Services;

use App\Models\ActionLog;
use App\Models\Conversation;
use App\Models\DecisionTrace;
use App\Models\Handoff;
use App\Models\LeadProfile;

class SuperadminMetricsQueryService
{
    /**
     * @return array{
     *   lead_count:int,
     *   handoff_count:int,
     *   booking_action_count:int,
     *   invoice_action_count:int,
     *   conversation_count:int,
     *   token_usage_total:int
     * }
     */
    public function getSummary(): array
    {
        $bookingActionLogs = ActionLog::query()
            ->where('action', 'send_booking_link')
            ->get(['status', 'result']);
        $invoiceActionLogs = ActionLog::query()
            ->where('action', 'send_invoice')
            ->get(['status']);

        $bookingActionCount = 0;
        $invoiceActionCount = 0;
        $tokenUsageTotal = (int) DecisionTrace::query()->sum('token_usage_total');

        foreach ($bookingActionLogs as $actionLog) {
            if ($actionLog->status === 'executed') {
                $bookingActionCount++;
            }
        }
        foreach ($invoiceActionLogs as $actionLog) {
            if ($actionLog->status === 'executed') {
                $invoiceActionCount++;
            }
        }

        return [
            'lead_count' => LeadProfile::query()->count(),
            'handoff_count' => Handoff::query()->count(),
            'booking_action_count' => $bookingActionCount,
            'invoice_action_count' => $invoiceActionCount,
            'conversation_count' => Conversation::query()->count(),
            'token_usage_total' => $tokenUsageTotal,
        ];
    }
}
