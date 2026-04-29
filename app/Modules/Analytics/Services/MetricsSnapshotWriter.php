<?php

namespace App\Modules\Analytics\Services;

use App\Models\AnalyticsSnapshot;

class MetricsSnapshotWriter
{
    /**
     * @param  array{lead_count:int,handoff_count:int,booking_action_count:int,token_usage_total:int}  $summary
     * @param  array<string,mixed>|null  $meta
     */
    public function writeTenantSummary(int $tenantId, array $summary, ?array $meta = null): int
    {
        $capturedAt = now();
        $written = 0;

        foreach ($summary as $metric => $value) {
            AnalyticsSnapshot::query()->create([
                'tenant_id' => $tenantId,
                'metric' => $metric,
                'value' => max(0, (int) $value),
                'captured_at' => $capturedAt,
                'meta' => $meta,
            ]);
            $written++;
        }

        return $written;
    }
}
