<?php

namespace App\Modules\Action\Services;

use App\Models\ActionLog;
use App\Models\Conversation;
use App\Models\Tenant;

class ActionDispatcherService
{
    /**
     * @param  array{action:string,reasons?:array<int,string>}  $candidate
     * @return array{
     *   status:string,
     *   action:string,
     *   reason:?string,
     *   meta:array<string,mixed>
     * }
     */
    public function dispatch(Tenant $tenant, Conversation $conversation, array $candidate): array
    {
        $action = (string) ($candidate['action'] ?? '');
        $reasons = $candidate['reasons'] ?? [];

        if ($conversation->tenant_id !== $tenant->id) {
            return $this->logAndReturn(
                $tenant,
                $conversation,
                $action,
                'blocked',
                'tenant_conversation_mismatch',
                $candidate,
                ['executed' => false]
            );
        }

        if (is_array($reasons) && $reasons !== []) {
            $reason = is_string($reasons[0] ?? null) ? $reasons[0] : 'blocked_by_candidate_reason';

            return $this->logAndReturn(
                $tenant,
                $conversation,
                $action,
                'blocked',
                $reason,
                $candidate,
                ['executed' => false]
            );
        }

        $result = match ($action) {
            'reply_safe_text' => [
                'status' => 'executed',
                'reason' => null,
                'meta' => ['executed' => true],
            ],
            default => [
                'status' => 'blocked',
                'reason' => 'unsupported_action',
                'meta' => ['executed' => false],
            ],
        };

        return $this->logAndReturn(
            $tenant,
            $conversation,
            $action,
            $result['status'],
            $result['reason'],
            $candidate,
            $result['meta']
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $meta
     * @return array{
     *   status:string,
     *   action:string,
     *   reason:?string,
     *   meta:array<string,mixed>
     * }
     */
    private function logAndReturn(
        Tenant $tenant,
        Conversation $conversation,
        string $action,
        string $status,
        ?string $reason,
        array $payload,
        array $meta
    ): array {
        ActionLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => $action,
            'status' => $status,
            'reason' => $reason,
            'payload' => $payload,
            'result' => $meta,
        ]);

        return [
            'status' => $status,
            'action' => $action,
            'reason' => $reason,
            'meta' => $meta,
        ];
    }
}
