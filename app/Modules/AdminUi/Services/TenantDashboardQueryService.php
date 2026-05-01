<?php

namespace App\Modules\AdminUi\Services;

use App\Models\Conversation;
use App\Models\Handoff;
use App\Models\LeadScore;
use App\Models\Notification;
use App\Modules\Analytics\Services\TenantMetricsQueryService;

class TenantDashboardQueryService
{
    public function __construct(private readonly TenantMetricsQueryService $tenantMetricsQueryService)
    {
    }

    /**
     * @return array{
     *   summary:array{
     *     conversations_total:int,
     *     conversations_open:int,
     *     handoffs_pending:int,
     *     notifications_queued:int,
     *     notifications_failed:int,
     *     lead_count:int,
     *     handoff_count:int,
     *     booking_action_count:int,
     *     token_usage_total:int
     *   },
     *   recentConversations:array<int,array<string,mixed>>,
     *   recentHandoffs:array<int,array<string,mixed>>,
     *   recentNotifications:array<int,array<string,mixed>>
     * }
     */
    public function getDashboardData(int $tenantId): array
    {
        $scores = LeadScore::query()
            ->whereHas('leadProfile', fn ($q) => $q->where('tenant_id', $tenantId))
            ->get(['lead_temperature']);
        $tempCounts = ['hot' => 0, 'warm' => 0, 'cold' => 0];
        foreach ($scores as $score) {
            $t = $score->lead_temperature ?? 'cold';
            if (isset($tempCounts[$t])) {
                $tempCounts[$t]++;
            }
        }

        $summary = [
            'conversations_total' => Conversation::query()->where('tenant_id', $tenantId)->count(),
            'conversations_open' => Conversation::query()->where('tenant_id', $tenantId)->where('status', 'open')->count(),
            'handoffs_pending' => Handoff::query()->where('tenant_id', $tenantId)->where('status', 'pending')->count(),
            'notifications_queued' => Notification::query()->where('tenant_id', $tenantId)->where('status', 'queued')->count(),
            'notifications_failed' => Notification::query()->where('tenant_id', $tenantId)->where('status', 'failed')->count(),
            'lead_hot' => $tempCounts['hot'],
            'lead_warm' => $tempCounts['warm'],
            'lead_cold' => $tempCounts['cold'],
        ];
        $summary = array_merge($summary, $this->tenantMetricsQueryService->getSummary($tenantId));

        $recentConversations = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit(5)
            ->get(['id', 'tenant_id', 'customer_phone', 'status', 'created_at'])
            ->toArray();

        $recentHandoffs = Handoff::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit(5)
            ->get(['id', 'tenant_id', 'conversation_id', 'reason_code', 'status', 'created_at'])
            ->toArray();

        $recentNotifications = Notification::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit(5)
            ->get(['id', 'tenant_id', 'conversation_id', 'type', 'channel', 'status', 'created_at'])
            ->toArray();

        return [
            'summary' => $summary,
            'recentConversations' => $recentConversations,
            'recentHandoffs' => $recentHandoffs,
            'recentNotifications' => $recentNotifications,
        ];
    }
}
