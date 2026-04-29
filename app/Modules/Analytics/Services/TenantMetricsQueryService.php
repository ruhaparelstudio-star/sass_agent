<?php

namespace App\Modules\Analytics\Services;

use App\Models\ActionLog;
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
        $tokenUsageTotal = 0;

        foreach ($actionLogs as $actionLog) {
            if ($actionLog->status === 'executed') {
                $bookingActionCount++;
            }

            $raw = $actionLog->result['token_usage_total'] ?? 0;
            $tokenUsageTotal += is_numeric($raw) ? (int) $raw : 0;
        }

        return [
            'lead_count' => LeadProfile::query()->where('tenant_id', $tenantId)->count(),
            'handoff_count' => Handoff::query()->where('tenant_id', $tenantId)->count(),
            'booking_action_count' => $bookingActionCount,
            'token_usage_total' => $tokenUsageTotal,
        ];
    }
}
