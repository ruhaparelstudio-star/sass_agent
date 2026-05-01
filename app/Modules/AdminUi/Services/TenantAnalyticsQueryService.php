<?php

namespace App\Modules\AdminUi\Services;

use App\Models\ActionLog;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\Handoff;
use App\Models\Invoice;
use App\Models\LeadProfile;
use App\Models\LeadScore;
use App\Models\Package;

class TenantAnalyticsQueryService
{
    public function getAnalyticsData(int $tenantId): array
    {
        $leadCount = LeadProfile::query()->where('tenant_id', $tenantId)->count();

        $scores = LeadScore::query()
            ->whereHas('leadProfile', fn ($q) => $q->where('tenant_id', $tenantId))
            ->get(['lead_temperature', 'interest_score', 'closing_readiness']);

        $temperatureCounts = ['hot' => 0, 'warm' => 0, 'cold' => 0];
        foreach ($scores as $score) {
            $temp = $score->lead_temperature ?? 'cold';
            if (isset($temperatureCounts[$temp])) {
                $temperatureCounts[$temp]++;
            }
        }

        $conversations = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->get(['status', 'current_stage', 'created_at']);

        $totalConversations = $conversations->count();
        $closedConversations = $conversations->where('status', 'closed')->count();
        $openConversations = $conversations->where('status', 'open')->count();
        $conversionRate = $totalConversations > 0
            ? round(($closedConversations / $totalConversations) * 100, 1)
            : 0;

        $stageBreakdown = $conversations->groupBy('current_stage')
            ->map(fn ($group) => $group->count())
            ->sortDesc()
            ->take(6)
            ->toArray();

        $handoffCount = Handoff::query()->where('tenant_id', $tenantId)->count();
        $handoffByReason = Handoff::query()
            ->where('tenant_id', $tenantId)
            ->get(['reason_code'])
            ->groupBy('reason_code')
            ->map(fn ($group) => $group->count())
            ->sortDesc()
            ->take(5)
            ->toArray();

        $bookingActionCount = ActionLog::query()
            ->where('tenant_id', $tenantId)
            ->where('action', 'send_booking_link')
            ->where('status', 'executed')
            ->count();

        $invoiceCount = Invoice::query()->where('tenant_id', $tenantId)->count();

        $topPackages = ActionLog::query()
            ->where('tenant_id', $tenantId)
            ->where('action', 'send_booking_link')
            ->where('status', 'executed')
            ->get(['result'])
            ->map(fn ($log) => is_array($log->result) ? ($log->result['package_slug'] ?? null) : null)
            ->filter()
            ->groupBy(fn ($v) => $v)
            ->map(fn ($group) => $group->count())
            ->sortDesc()
            ->take(5)
            ->toArray();

        $topFaqs = Faq::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit(5)
            ->get(['question'])
            ->pluck('question')
            ->toArray();

        $activePackages = Package::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit(5)
            ->get(['name', 'slug'])
            ->map(fn ($p) => ['name' => $p->name, 'slug' => $p->slug])
            ->toArray();

        return [
            'lead_count' => $leadCount,
            'temperature' => $temperatureCounts,
            'conversations_total' => $totalConversations,
            'conversations_open' => $openConversations,
            'conversations_closed' => $closedConversations,
            'conversion_rate' => $conversionRate,
            'stage_breakdown' => $stageBreakdown,
            'handoff_count' => $handoffCount,
            'handoff_by_reason' => $handoffByReason,
            'booking_action_count' => $bookingActionCount,
            'invoice_count' => $invoiceCount,
            'top_packages' => $activePackages,
            'top_faqs' => $topFaqs,
        ];
    }
}
