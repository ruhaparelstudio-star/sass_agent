<?php

namespace App\Modules\Action\Services;

use App\Models\ActionLog;
use App\Models\BookingDateCapacity;
use App\Models\BookingSetting;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\DecisionTrace;
use App\Models\Handoff;
use App\Models\Invoice;
use App\Models\InvoiceSendLog;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\TenantAsset;
use App\Jobs\DispatchNotificationJob;
use App\Modules\WhatsApp\Services\WaOutboundService;
use Illuminate\Support\Facades\DB;

class ActionDispatcherService
{
    public function __construct(private readonly WaOutboundService $waOutboundService)
    {
    }

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
            'send_text' => $this->dispatchSendText($tenant, $candidate),
            'send_file' => $this->dispatchSendFile($tenant, $candidate),
            'send_booking_link' => $this->dispatchSendBookingLink($tenant, $candidate),
            'send_invoice' => $this->dispatchSendInvoice($tenant, $conversation, $candidate),
            'handoff_to_human' => $this->dispatchHandoffToHuman($tenant, $conversation, $candidate),
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
        $tokenUsageFromCandidate = $payload['meta']['token_usage_total'] ?? null;
        if (is_numeric($tokenUsageFromCandidate)) {
            $meta['token_usage_total'] = (int) $tokenUsageFromCandidate;
        }

        DB::transaction(function () use ($tenant, $conversation, $action, $status, $reason, $payload, $meta) {
            $createdActionLog = ActionLog::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'action' => $action,
                'status' => $status,
                'reason' => $reason,
                'payload' => $payload,
                'result' => $meta,
            ]);

            DecisionTrace::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'action_log_id' => $createdActionLog->id,
                'trace_key' => 'action_dispatch',
                'token_usage_total' => max(0, (int) ($meta['token_usage_total'] ?? 0)),
                'decision_json' => [
                    'action' => $action,
                    'status' => $status,
                    'reason' => $reason,
                ],
                'validators_json' => [
                    'order' => ['policy', 'grounding', 'permission', 'mode'],
                    'status' => $status === 'blocked' ? 'blocked' : 'passed',
                ],
                'blocked_actions_json' => $status === 'blocked'
                    ? [['action' => $action, 'reason' => $reason ?? 'blocked']]
                    : [],
                'model_name' => null,
                'token_usage_json' => [
                    'total' => max(0, (int) ($meta['token_usage_total'] ?? 0)),
                ],
                'meta' => [
                    'action' => $action,
                    'status' => $status,
                    'reason' => $reason,
                ],
            ]);

        });

        return [
            'status' => $status,
            'action' => $action,
            'reason' => $reason,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{status:string,reason:?string,meta:array<string,mixed>}
     */
    private function dispatchSendText(Tenant $tenant, array $candidate): array
    {
        $sendText = $candidate['meta']['send_text'] ?? null;
        if (! is_array($sendText) || ! $this->hasValidSendTextContract($sendText)) {
            return [
                'status' => 'blocked',
                'reason' => 'invalid_send_text_contract',
                'meta' => ['executed' => false],
            ];
        }

        try {
            $outbound = $this->waOutboundService->queueOutboundMessage($tenant, [
                'provider' => $sendText['provider'],
                'wa_account_provider_ref' => $sendText['wa_account_provider_ref'],
                'wa_session_provider_ref' => $sendText['wa_session_provider_ref'] ?? null,
                'provider_message_id' => $sendText['provider_message_id'] ?? null,
                'to' => $sendText['to'],
                'message_type' => 'text',
                'payload' => ['text' => $sendText['text']],
                'meta' => is_array($sendText['meta'] ?? null) ? $sendText['meta'] : null,
            ]);
        } catch (\Throwable) {
            return [
                'status' => 'blocked',
                'reason' => 'send_text_dispatch_failed',
                'meta' => ['executed' => false],
            ];
        }

        return [
            'status' => 'executed',
            'reason' => null,
            'meta' => [
                'executed' => true,
                'wa_outbound_message_id' => $outbound->id,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $sendText
     */
    private function hasValidSendTextContract(array $sendText): bool
    {
        return $this->isNonEmptyString($sendText['provider'] ?? null)
            && $this->isNonEmptyString($sendText['wa_account_provider_ref'] ?? null)
            && $this->isNonEmptyString($sendText['to'] ?? null)
            && $this->isNonEmptyString($sendText['text'] ?? null)
            && $this->isOptionalNonEmptyString($sendText['wa_session_provider_ref'] ?? null)
            && $this->isOptionalNonEmptyString($sendText['provider_message_id'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{status:string,reason:?string,meta:array<string,mixed>}
     */
    private function dispatchSendFile(Tenant $tenant, array $candidate): array
    {
        $sendFile = $candidate['meta']['send_file'] ?? null;
        if (! is_array($sendFile) || ! $this->hasValidSendFileContract($sendFile)) {
            return [
                'status' => 'blocked',
                'reason' => 'invalid_send_file_contract',
                'meta' => ['executed' => false],
            ];
        }

        $asset = TenantAsset::query()
            ->where('id', (int) $sendFile['tenant_asset_id'])
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $asset) {
            return [
                'status' => 'blocked',
                'reason' => 'send_file_asset_not_owned',
                'meta' => ['executed' => false],
            ];
        }

        $payload = [
            'file' => [
                'tenant_asset_id' => $asset->id,
                'storage_disk' => $asset->storage_disk,
                'storage_path' => $asset->storage_path,
                'filename' => $sendFile['file_name'],
                'mime_type' => $sendFile['mime_type'],
            ],
            'caption' => $sendFile['caption'] ?? null,
        ];

        try {
            $outbound = $this->waOutboundService->queueOutboundMessage($tenant, [
                'provider' => $sendFile['provider'],
                'wa_account_provider_ref' => $sendFile['wa_account_provider_ref'],
                'wa_session_provider_ref' => $sendFile['wa_session_provider_ref'] ?? null,
                'provider_message_id' => $sendFile['provider_message_id'] ?? null,
                'to' => $sendFile['to'],
                'message_type' => 'file',
                'payload' => $payload,
                'meta' => is_array($sendFile['meta'] ?? null) ? $sendFile['meta'] : null,
            ]);
        } catch (\Throwable) {
            return [
                'status' => 'blocked',
                'reason' => 'send_file_dispatch_failed',
                'meta' => ['executed' => false],
            ];
        }

        return [
            'status' => 'executed',
            'reason' => null,
            'meta' => [
                'executed' => true,
                'wa_outbound_message_id' => $outbound->id,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $sendFile
     */
    private function hasValidSendFileContract(array $sendFile): bool
    {
        return $this->isNonEmptyString($sendFile['provider'] ?? null)
            && $this->isNonEmptyString($sendFile['wa_account_provider_ref'] ?? null)
            && $this->isNonEmptyString($sendFile['to'] ?? null)
            && $this->isPositiveInt($sendFile['tenant_asset_id'] ?? null)
            && $this->isNonEmptyString($sendFile['file_name'] ?? null)
            && $this->isNonEmptyString($sendFile['mime_type'] ?? null)
            && $this->isOptionalNonEmptyString($sendFile['caption'] ?? null)
            && $this->isOptionalNonEmptyString($sendFile['wa_session_provider_ref'] ?? null)
            && $this->isOptionalNonEmptyString($sendFile['provider_message_id'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{status:string,reason:?string,meta:array<string,mixed>}
     */
    private function dispatchSendBookingLink(Tenant $tenant, array $candidate): array
    {
        $sendBookingLink = $candidate['meta']['send_booking_link'] ?? null;
        if (! is_array($sendBookingLink) || ! $this->hasValidSendBookingLinkContract($sendBookingLink)) {
            return [
                'status' => 'blocked',
                'reason' => 'invalid_send_booking_link_contract',
                'meta' => ['executed' => false],
            ];
        }

        $booking = BookingSetting::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('active_from')->orWhere('active_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('active_until')->orWhere('active_until', '>=', now()))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (! $booking) {
            return [
                'status' => 'blocked',
                'reason' => 'booking_link_not_available',
                'meta' => ['executed' => false],
            ];
        }

        try {
            $outbound = $this->waOutboundService->queueOutboundMessage($tenant, [
                'provider' => $sendBookingLink['provider'],
                'wa_account_provider_ref' => $sendBookingLink['wa_account_provider_ref'],
                'wa_session_provider_ref' => $sendBookingLink['wa_session_provider_ref'] ?? null,
                'provider_message_id' => $sendBookingLink['provider_message_id'] ?? null,
                'to' => $sendBookingLink['to'],
                'message_type' => 'text',
                'payload' => ['text' => $booking->booking_url],
                'meta' => is_array($sendBookingLink['meta'] ?? null) ? $sendBookingLink['meta'] : null,
            ]);
        } catch (\Throwable) {
            return [
                'status' => 'blocked',
                'reason' => 'send_booking_link_dispatch_failed',
                'meta' => ['executed' => false],
            ];
        }

        $eventDateIso = is_string($sendBookingLink['event_date_iso'] ?? null)
            && trim($sendBookingLink['event_date_iso']) !== ''
            ? trim($sendBookingLink['event_date_iso'])
            : null;

        if ($eventDateIso !== null) {
            BookingDateCapacity::incrementForTenantDate($tenant->id, $eventDateIso);
        }

        return [
            'status' => 'executed',
            'reason' => null,
            'meta' => [
                'executed'               => true,
                'wa_outbound_message_id' => $outbound->id,
                'booking_date'           => $eventDateIso,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $sendBookingLink
     */
    private function hasValidSendBookingLinkContract(array $sendBookingLink): bool
    {
        return $this->isNonEmptyString($sendBookingLink['provider'] ?? null)
            && $this->isNonEmptyString($sendBookingLink['wa_account_provider_ref'] ?? null)
            && $this->isNonEmptyString($sendBookingLink['to'] ?? null)
            && $this->isOptionalNonEmptyString($sendBookingLink['wa_session_provider_ref'] ?? null)
            && $this->isOptionalNonEmptyString($sendBookingLink['provider_message_id'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{status:string,reason:?string,meta:array<string,mixed>}
     */
    private function dispatchSendInvoice(Tenant $tenant, Conversation $conversation, array $candidate): array
    {
        $sendInvoice = $candidate['meta']['send_invoice'] ?? null;
        if (! is_array($sendInvoice) || ! $this->hasValidSendInvoiceContract($sendInvoice)) {
            return [
                'status' => 'blocked',
                'reason' => 'invalid_send_invoice_contract',
                'meta' => ['executed' => false],
            ];
        }

        $invoice = Invoice::query()
            ->where('id', (int) $sendInvoice['invoice_id'])
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        if (! $invoice) {
            return [
                'status' => 'blocked',
                'reason' => 'send_invoice_invoice_not_owned',
                'meta' => ['executed' => false],
            ];
        }

        $asset = TenantAsset::query()
            ->where('id', (int) $sendInvoice['tenant_asset_id'])
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $asset) {
            return [
                'status' => 'blocked',
                'reason' => 'send_invoice_asset_not_owned',
                'meta' => ['executed' => false],
            ];
        }

        $maxSendCount = $sendInvoice['max_send_count'] ?? null;
        if (is_int($maxSendCount) && $maxSendCount > 0) {
            $sentCount = InvoiceSendLog::query()
                ->where('tenant_id', $tenant->id)
                ->where('invoice_id', $invoice->id)
                ->where('status', 'sent')
                ->count();

            if ($sentCount >= $maxSendCount) {
                return [
                    'status' => 'blocked',
                    'reason' => 'send_invoice_max_count_exceeded',
                    'meta' => ['executed' => false],
                ];
            }
        }

        $payload = [
            'file' => [
                'tenant_asset_id' => $asset->id,
                'storage_disk' => $asset->storage_disk,
                'storage_path' => $asset->storage_path,
                'filename' => $sendInvoice['file_name'],
                'mime_type' => $sendInvoice['mime_type'],
            ],
            'caption' => $sendInvoice['caption'] ?? null,
        ];

        try {
            return DB::transaction(function () use ($tenant, $conversation, $invoice, $asset, $sendInvoice, $payload): array {
                $outbound = $this->waOutboundService->queueOutboundMessage($tenant, [
                    'provider' => $sendInvoice['provider'],
                    'wa_account_provider_ref' => $sendInvoice['wa_account_provider_ref'],
                    'wa_session_provider_ref' => $sendInvoice['wa_session_provider_ref'] ?? null,
                    'provider_message_id' => $sendInvoice['provider_message_id'] ?? null,
                    'to' => $sendInvoice['to'],
                    'message_type' => 'file',
                    'payload' => $payload,
                    'meta' => is_array($sendInvoice['meta'] ?? null) ? $sendInvoice['meta'] : null,
                ]);

                $sendLog = InvoiceSendLog::query()->create([
                    'tenant_id' => $tenant->id,
                    'conversation_id' => $conversation->id,
                    'invoice_id' => $invoice->id,
                    'tenant_asset_id' => $asset->id,
                    'wa_outbound_message_id' => $outbound->id,
                    'status' => 'sent',
                    'failure_reason' => null,
                    'sent_at' => now(),
                    'meta' => is_array($sendInvoice['meta'] ?? null) ? $sendInvoice['meta'] : null,
                ]);

                $invoice->last_sent_at = now();
                $invoice->save();

                ConversationState::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('conversation_id', $conversation->id)
                    ->update([
                        'agent_mode' => 'limited',
                        'memory_mode' => 'dormant',
                    ]);

                return [
                    'status' => 'executed',
                    'reason' => null,
                    'meta' => [
                        'executed' => true,
                        'wa_outbound_message_id' => $outbound->id,
                        'invoice_send_log_id' => $sendLog->id,
                    ],
                ];
            });
        } catch (\Throwable) {
            InvoiceSendLog::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'invoice_id' => $invoice->id,
                'tenant_asset_id' => $asset->id,
                'wa_outbound_message_id' => null,
                'status' => 'failed',
                'failure_reason' => 'send_invoice_dispatch_failed',
                'sent_at' => null,
                'meta' => is_array($sendInvoice['meta'] ?? null) ? $sendInvoice['meta'] : null,
            ]);

            return [
                'status' => 'blocked',
                'reason' => 'send_invoice_dispatch_failed',
                'meta' => ['executed' => false],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $sendInvoice
     */
    private function hasValidSendInvoiceContract(array $sendInvoice): bool
    {
        return $this->isNonEmptyString($sendInvoice['provider'] ?? null)
            && $this->isNonEmptyString($sendInvoice['wa_account_provider_ref'] ?? null)
            && $this->isNonEmptyString($sendInvoice['to'] ?? null)
            && $this->isPositiveInt($sendInvoice['invoice_id'] ?? null)
            && $this->isPositiveInt($sendInvoice['tenant_asset_id'] ?? null)
            && $this->isNonEmptyString($sendInvoice['file_name'] ?? null)
            && $this->isNonEmptyString($sendInvoice['mime_type'] ?? null)
            && $this->isOptionalNonEmptyString($sendInvoice['caption'] ?? null)
            && $this->isOptionalNonEmptyString($sendInvoice['wa_session_provider_ref'] ?? null)
            && $this->isOptionalNonEmptyString($sendInvoice['provider_message_id'] ?? null)
            && $this->isOptionalPositiveInt($sendInvoice['max_send_count'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{status:string,reason:?string,meta:array<string,mixed>}
     */
    private function dispatchHandoffToHuman(Tenant $tenant, Conversation $conversation, array $candidate): array
    {
        $handoffPayload = $candidate['meta']['handoff_to_human'] ?? null;
        if (! is_array($handoffPayload) || ! $this->hasValidHandoffToHumanContract($handoffPayload)) {
            return [
                'status' => 'blocked',
                'reason' => 'invalid_handoff_to_human_contract',
                'meta' => ['executed' => false],
            ];
        }

        try {
            return DB::transaction(function () use ($tenant, $conversation, $handoffPayload): array {
                $state = ConversationState::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('conversation_id', $conversation->id)
                    ->first();

                if (! $state) {
                    return [
                        'status' => 'blocked',
                        'reason' => 'handoff_state_not_found',
                        'meta' => ['executed' => false],
                    ];
                }

                $handoff = Handoff::query()->create([
                    'tenant_id' => $tenant->id,
                    'conversation_id' => $conversation->id,
                    'reason_code' => $handoffPayload['reason_code'],
                    'note' => $handoffPayload['note'] ?? null,
                    'context' => is_array($handoffPayload['context'] ?? null) ? $handoffPayload['context'] : null,
                    'status' => 'pending',
                ]);

                $notification = Notification::query()->create([
                    'tenant_id' => $tenant->id,
                    'conversation_id' => $conversation->id,
                    'handoff_id' => $handoff->id,
                    'type' => 'handoff_created',
                    'channel' => 'internal_queue',
                    'status' => 'queued',
                    'payload' => [
                        'reason_code' => $handoff->reason_code,
                        'note' => $handoff->note,
                        'context' => $handoff->context,
                    ],
                ]);

                $state->agent_mode = 'handoff';
                $state->save();

                try {
                    DispatchNotificationJob::dispatch($tenant->id, $notification->id);
                } catch (\Throwable) {
                    throw new \RuntimeException('handoff_notification_queue_failed');
                }

                return [
                    'status' => 'executed',
                    'reason' => null,
                    'meta' => [
                        'executed' => true,
                        'handoff_id' => $handoff->id,
                        'notification_id' => $notification->id,
                    ],
                ];
            });
        } catch (\Throwable $e) {
            $reason = $e->getMessage() === 'handoff_notification_queue_failed'
                ? 'handoff_notification_queue_failed'
                : 'handoff_persistence_failed';

            return [
                'status' => 'blocked',
                'reason' => $reason,
                'meta' => ['executed' => false],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $handoffPayload
     */
    private function hasValidHandoffToHumanContract(array $handoffPayload): bool
    {
        return $this->isNonEmptyString($handoffPayload['reason_code'] ?? null)
            && $this->isOptionalNonEmptyString($handoffPayload['note'] ?? null)
            && $this->isOptionalArray($handoffPayload['context'] ?? null);
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isOptionalNonEmptyString(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return $this->isNonEmptyString($value);
    }

    private function isPositiveInt(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value > 0;
        }

        return false;
    }

    private function isOptionalArray(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return is_array($value);
    }

    private function isOptionalPositiveInt(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return $this->isPositiveInt($value);
    }
}
