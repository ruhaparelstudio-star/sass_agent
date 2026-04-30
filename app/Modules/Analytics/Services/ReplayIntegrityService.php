<?php

namespace App\Modules\Analytics\Services;

use App\Models\DecisionTrace;

class ReplayIntegrityService
{
    /**
     * @return array{
     *   ok:bool,
     *   tenant_id:int,
     *   conversation_id:int,
     *   checked_traces:int,
     *   issues_count:int,
     *   issues:array<int,array{trace_id:int,code:string,detail:string}>
     * }
     */
    public function checkTenantConversation(int $tenantId, int $conversationId): array
    {
        $traces = DecisionTrace::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->with('actionLog')
            ->get();

        $issues = [];

        foreach ($traces as $trace) {
            if ($trace->action_log_id === null) {
                continue;
            }

            $actionLog = $trace->actionLog;
            if ($actionLog === null) {
                $issues[] = [
                    'trace_id' => $trace->id,
                    'code' => 'trace_action_missing',
                    'detail' => 'Decision trace references missing action log.',
                ];
                continue;
            }

            if ((int) $actionLog->tenant_id !== $tenantId) {
                $issues[] = [
                    'trace_id' => $trace->id,
                    'code' => 'trace_action_tenant_mismatch',
                    'detail' => 'Action log tenant differs from replay tenant scope.',
                ];
            }

            if ((int) $actionLog->conversation_id !== $conversationId) {
                $issues[] = [
                    'trace_id' => $trace->id,
                    'code' => 'trace_action_conversation_mismatch',
                    'detail' => 'Action log conversation differs from replay conversation scope.',
                ];
            }
        }

        return [
            'ok' => $issues === [],
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'checked_traces' => $traces->count(),
            'issues_count' => count($issues),
            'issues' => $issues,
        ];
    }
}

