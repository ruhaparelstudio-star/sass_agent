<?php

namespace App\Jobs;

use App\Enums\WaOutboundMessageStatus;
use App\Models\WaOutboundMessage;
use App\Modules\WhatsApp\Contracts\WaGatewayClient;
use App\Modules\WhatsApp\Services\WaOutboundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchWaOutboundMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $outboundMessageId
    ) {
        // Job di-dispatch dari dalam DB::transaction() di WaInboundTurnOrchestratorService.
        // Tanpa afterCommit, worker bisa pickup job sebelum transaction commit sehingga
        // WaOutboundMessage belum ada di DB dan job early-return dengan status tetap pending.
        $this->afterCommit();
    }

    public function handle(WaGatewayClient $gatewayClient, WaOutboundService $outboundService): void
    {
        $outbound = WaOutboundMessage::query()
            ->where('id', $this->outboundMessageId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if (! $outbound || $outbound->status !== WaOutboundMessageStatus::Pending) {
            return;
        }

        $requestPayload = [
            'tenant_id' => $outbound->tenant_id,
            'provider' => $outbound->provider,
            'wa_account_id' => $outbound->wa_account_id,
            'wa_session_id' => $outbound->wa_session_id,
            'provider_message_id' => $outbound->provider_message_id,
            'to' => $outbound->to,
            'message_type' => $outbound->message_type,
            'payload' => $outbound->payload,
            'meta' => $outbound->meta,
        ];

        try {
            $responsePayload = $gatewayClient->sendOutbound($requestPayload);

            $providerMessageId = $responsePayload['provider_message_id'] ?? null;
            if (is_string($providerMessageId) && $providerMessageId !== '') {
                $outbound->provider_message_id = $providerMessageId;
            }

            if (! $outboundService->canTransitionOutboundStatus($outbound->status, WaOutboundMessageStatus::Sent)) {
                return;
            }

            $outbound->status = WaOutboundMessageStatus::Sent;
            $outbound->sent_at = now();
            $outbound->failed_at = null;
            $outbound->failure_reason = null;
            $outbound->save();

            $outboundService->appendDeliveryLog(
                $outbound,
                WaOutboundMessageStatus::Sent,
                $requestPayload,
                is_array($responsePayload) ? $responsePayload : null,
                null,
            );
        } catch (\Throwable $e) {
            if (! $outboundService->canTransitionOutboundStatus($outbound->status, WaOutboundMessageStatus::Failed)) {
                return;
            }

            $outbound->status = WaOutboundMessageStatus::Failed;
            $outbound->failed_at = now();
            $outbound->failure_reason = $e->getMessage();
            $outbound->save();

            $outboundService->appendDeliveryLog(
                $outbound,
                WaOutboundMessageStatus::Failed,
                $requestPayload,
                null,
                $e->getMessage(),
            );
        }
    }
}
