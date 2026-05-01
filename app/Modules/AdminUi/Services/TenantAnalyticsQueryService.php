<?php

namespace App\Modules\AdminUi\Services;

use App\Models\ActionLog;
use App\Models\Conversation;
use App\Models\ConversationState;
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
            ->get(['score_label']);

        $temperatureCounts = ['hot' => 0, 'warm' => 0, 'cold' => 0];
        foreach ($scores as $score) {
            $temp = $score->score_label ?? 'cold';
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

        // Count package_interest mentions from conversation states
        $packageInterestCounts = ConversationState::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('package_interest')
            ->get(['package_interest'])
            ->groupBy('package_interest')
            ->map(fn ($group) => $group->count())
            ->sortDesc()
            ->take(5);

        // Resolve display names: try matching against packages.code, fall back to raw value
        $packageCodes = $packageInterestCounts->keys()->all();
        $packageNameMap = Package::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('code', $packageCodes)
            ->get(['code', 'name'])
            ->pluck('name', 'code')
            ->toArray();

        $activePackages = $packageInterestCounts->map(function ($count, $code) use ($packageNameMap) {
            return ['name' => $packageNameMap[$code] ?? $code, 'count' => $count];
        })->values()->toArray();

        // Fall back to listing active packages (no count) if no interest data
        if (empty($activePackages)) {
            $activePackages = Package::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->limit(5)
                ->get(['name'])
                ->map(fn ($p) => ['name' => $p->name, 'count' => 0])
                ->toArray();
        }

        $topFaqs = Faq::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit(5)
            ->get(['question'])
            ->pluck('question')
            ->map(fn ($q) => ['question' => $q, 'count' => 0])
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
            'top_faqs' => array_values($topFaqs),
        ];
    }
}
