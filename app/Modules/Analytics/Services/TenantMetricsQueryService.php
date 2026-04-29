<?php

namespace App\Modules\Analytics\Services;

use App\Models\ActionLog;
use App\Models\DecisionTrace;
use App\Models\Handoff;
use App\Models\LeadProfile;

class TenantMetricsQueryService
{
    /**
     * @return array{lead_count:int,handoff_count:int,booking_action_count:int,token_usage_total:int}
     */
    public function getSummary(int $tenantId): array
    {
        $actionLogs = ActionLog::query()
            ->where('tenant_id', $tenantId)
            ->where('action', 'send_booking_link')
            ->get(['status', 'result']);

        $bookingActionCount = 0;
        $tokenUsageTotal = (int) DecisionTrace::query()
            ->where('tenant_id', $tenantId)
            ->sum('token_usage_total');

        foreach ($actionLogs as $actionLog) {
            if ($actionLog->status === 'executed') {
                $bookingActionCount++;
            }
        }

        return [
            'lead_count' => LeadProfile::query()->where('tenant_id', $tenantId)->count(),
            'handoff_count' => Handoff::query()->where('tenant_id', $tenantId)->count(),
            'booking_action_count' => $bookingActionCount,
            'token_usage_total' => $tokenUsageTotal,
        ];
    }
}
