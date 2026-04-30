<?php

namespace App\Modules\AdminUi\Services;

use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\DecisionTrace;
use App\Models\Invoice;
use App\Models\LeadProfile;
use App\Models\Message;
use App\Models\TenantAsset;

class TenantConversationInboxQueryService
{
    /**
     * @return array{conversationList:array<int,array<string,mixed>>,selectedConversation:?array<string,mixed>,messages:array<int,array<string,mixed>>,handoffs:array<int,array<string,mixed>>,contextPanel:array<string,mixed>,invoiceAssets:array<int,array<string,mixed>>,invoices:array<int,array<string,mixed>>}
     */
    public function getInboxData(int $tenantId, ?int $conversationId, string $query = ''): array
    {
        $invoiceAssets = TenantAsset::query()
            ->where('tenant_id', $tenantId)
            ->where('asset_type', 'invoice')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'display_name', 'original_filename', 'is_active'])
            ->map(fn (TenantAsset $asset): array => [
                'id' => $asset->id,
                'display_name' => $asset->display_name,
                'original_filename' => $asset->original_filename,
                'is_active' => $asset->is_active,
                'label' => $asset->display_name ?: $asset->original_filename,
            ])
            ->toArray();

        $conversationListQuery = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->when($query !== '', fn ($q) => $q->where('customer_phone', 'like', '%'.$query.'%'))
            ->latest('id')
            ->limit(20);

        $conversationRows = $conversationListQuery->get([
            'id',
            'tenant_id',
            'customer_phone',
            'status',
            'current_stage',
            'active_goal',
            'agent_mode',
            'memory_mode',
            'last_message_at',
            'created_at',
        ]);
        $conversationIds = $conversationRows->pluck('id')->all();

        $lastMessages = Message::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('conversation_id', $conversationIds)
            ->orderByDesc('id')
            ->get(['conversation_id', 'content', 'created_at'])
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        $conversationList = $conversationRows->map(function (Conversation $conversation) use ($lastMessages): array {
            $lastMessage = $lastMessages->get($conversation->id);

            return [
                'id' => $conversation->id,
                'tenant_id' => $conversation->tenant_id,
                'customer_phone' => $conversation->customer_phone,
                'status' => $conversation->status,
                'status_label' => $this->statusLabel((string) $conversation->status),
                'created_at' => $conversation->created_at,
                'current_stage' => $conversation->current_stage,
                'active_goal' => $conversation->active_goal,
                'agent_mode' => $conversation->agent_mode,
                'memory_mode' => $conversation->memory_mode,
                'last_message_preview' => $lastMessage?->content ? mb_strimwidth($lastMessage->content, 0, 58, '...') : '-',
                'last_activity_at' => $conversation->last_message_at ?? $lastMessage?->created_at ?? $conversation->created_at,
            ];
        })->toArray();

        if ($conversationId === null && $conversationList !== []) {
            $conversationId = (int) $conversationList[0]['id'];
        }

        if ($conversationId === null) {
            return [
                'conversationList' => $conversationList,
                'selectedConversation' => null,
                'messages' => [],
                'handoffs' => [],
                'contextPanel' => $this->emptyContextPanel(),
                'invoiceAssets' => $invoiceAssets,
                'invoices' => [],
            ];
        }

        $selectedConversation = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($conversationId)
            ->with('state')
            ->first();

        if (! $selectedConversation) {
            return [
                'conversationList' => $conversationList,
                'selectedConversation' => null,
                'messages' => [],
                'handoffs' => [],
                'contextPanel' => $this->emptyContextPanel(),
                'invoiceAssets' => $invoiceAssets,
                'invoices' => [],
            ];
        }

        $decisionTraces = DecisionTrace::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $selectedConversation->id)
            ->latest('id')
            ->limit(80)
            ->get([
                'id',
                'message_id',
                'trace_key',
                'validators_json',
                'blocked_actions_json',
                'grounding_refs_json',
                'meta',
                'created_at',
            ])
            ->keyBy('message_id');

        $messages = Message::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $selectedConversation->id)
            ->orderBy('id')
            ->get([
                'id',
                'tenant_id',
                'conversation_id',
                'direction',
                'message_type',
                'content',
                'grounding_refs',
                'decision_trace_id',
                'created_at',
            ])
            ->map(function (Message $message) use ($decisionTraces): array {
                $trace = $message->decision_trace_id !== null
                    ? $decisionTraces->get($message->decision_trace_id)
                    : null;

                if (! $trace instanceof DecisionTrace) {
                    $trace = $decisionTraces->get($message->id);
                }

                $legacyDecision = is_array($trace?->meta['decision'] ?? null) ? $trace->meta['decision'] : [];

                return [
                    'id' => $message->id,
                    'tenant_id' => $message->tenant_id,
                    'conversation_id' => $message->conversation_id,
                    'direction' => (string) $message->direction->value,
                    'message_type' => $message->message_type ?? 'text',
                    'content' => $message->content,
                    'grounding_refs' => $message->grounding_refs
                        ?? $trace?->grounding_refs_json
                        ?? ($legacyDecision['grounding_refs'] ?? []),
                    'decision_trace_id' => $message->decision_trace_id,
                    'trace' => [
                        'id' => $trace?->id,
                        'trace_key' => $trace?->trace_key,
                        'validators' => $trace?->validators_json
                            ?? ['order' => $legacyDecision['trace']['validator_order'] ?? []],
                        'blocked_actions' => $trace?->blocked_actions_json
                            ?? ($legacyDecision['blocked_actions'] ?? []),
                        'fallback_reason' => $trace?->validators_json['fallback_reason']
                            ?? ($legacyDecision['trace']['fallback_reason'] ?? null),
                    ],
                    'created_at' => $message->created_at,
                    'direction_label' => strtoupper((string) $message->direction->value),
                ];
            })
            ->toArray();

        $handoffs = $selectedConversation->handoffs()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->get(['id', 'tenant_id', 'conversation_id', 'reason_code', 'note', 'status', 'created_at'])
            ->map(fn ($handoff): array => [
                'id' => $handoff->id,
                'tenant_id' => $handoff->tenant_id,
                'conversation_id' => $handoff->conversation_id,
                'reason_code' => $handoff->reason_code,
                'note' => $handoff->note,
                'status' => $handoff->status,
                'status_label' => $this->statusLabel((string) $handoff->status),
                'created_at' => $handoff->created_at,
                'can_resolve_handoff' => $handoff->status === 'pending',
                'can_resume_ai' => $handoff->status === 'resolved',
            ])
            ->toArray();

        $lead = LeadProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_phone', $selectedConversation->customer_phone)
            ->first();

        $invoices = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $selectedConversation->id)
            ->with('tenantAsset:id,display_name,original_filename')
            ->latest('id')
            ->get(['id', 'tenant_id', 'conversation_id', 'tenant_asset_id', 'customer_phone', 'status', 'issued_at', 'last_sent_at', 'created_at'])
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'tenant_asset_id' => $invoice->tenant_asset_id,
                'customer_phone' => $invoice->customer_phone,
                'status' => $invoice->status,
                'issued_at' => $invoice->issued_at,
                'last_sent_at' => $invoice->last_sent_at,
                'created_at' => $invoice->created_at,
                'asset_label' => $invoice->tenantAsset?->display_name ?: $invoice->tenantAsset?->original_filename,
            ])
            ->toArray();

        $latestContext = ConversationContext::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $selectedConversation->id)
            ->latest('id')
            ->first();

        return [
            'conversationList' => $conversationList,
            'selectedConversation' => [
                'id' => $selectedConversation->id,
                'tenant_id' => $selectedConversation->tenant_id,
                'customer_phone' => $selectedConversation->customer_phone,
                'status' => $selectedConversation->status,
                'created_at' => $selectedConversation->created_at,
                'current_stage' => $selectedConversation->current_stage,
                'active_goal' => $selectedConversation->active_goal,
                'agent_mode' => $selectedConversation->agent_mode,
                'memory_mode' => $selectedConversation->memory_mode,
                'last_message_at' => $selectedConversation->last_message_at,
                'state' => $selectedConversation->state?->only([
                    'id',
                    'tenant_id',
                    'conversation_id',
                    'current_stage',
                    'active_goal',
                    'agent_mode',
                    'memory_mode',
                    'updated_at',
                ]),
            ],
            'messages' => $messages,
            'handoffs' => $handoffs,
            'contextPanel' => [
                'lead' => $lead?->only([
                    'id',
                    'tenant_id',
                    'customer_phone',
                    'full_name',
                ]),
                'state' => $selectedConversation->state?->only([
                    'id',
                    'tenant_id',
                    'conversation_id',
                    'current_stage',
                    'active_goal',
                    'agent_mode',
                    'memory_mode',
                ]),
                'context' => [
                    'summary' => $latestContext?->summary,
                    'reason' => $latestContext?->reason,
                    'recommended_next_action' => $latestContext?->recommended_next_action,
                ],
            ],
            'invoiceAssets' => $invoiceAssets,
            'invoices' => $invoices,
        ];
    }

    /**
     * @return array{lead:null,state:null,context:array{summary:null,reason:null,recommended_next_action:null}}
     */
    private function emptyContextPanel(): array
    {
        return [
            'lead' => null,
            'state' => null,
            'context' => [
                'summary' => null,
                'reason' => null,
                'recommended_next_action' => null,
            ],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'Open',
            'closed' => 'Closed',
            'pending' => 'Pending',
            'resolved' => 'Resolved',
            default => ucfirst($status),
        };
    }
}
