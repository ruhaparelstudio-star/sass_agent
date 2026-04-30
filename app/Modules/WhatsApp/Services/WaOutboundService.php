<?php

namespace App\Modules\WhatsApp\Services;

use App\Enums\WaOutboundMessageStatus;
use App\Jobs\DispatchWaOutboundMessageJob;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaMessageDeliveryLog;
use App\Models\WaOutboundMessage;
use App\Models\WaSession;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WaOutboundService
{
    public function __construct(private readonly WaSyncService $waSyncService)
    {
    }

    public function assertTenantScope(User $user, Tenant $tenant): void
    {
        $this->waSyncService->assertTenantScope($user, $tenant);
    }

    public function assertPayloadContract(mixed $payload): void
    {
        $this->waSyncService->assertPayloadContract($payload);
    }

    public function canTransitionOutboundStatus(WaOutboundMessageStatus $from, WaOutboundMessageStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            WaOutboundMessageStatus::Pending => in_array($to, [WaOutboundMessageStatus::Sent, WaOutboundMessageStatus::Failed, WaOutboundMessageStatus::Cancelled], true),
            WaOutboundMessageStatus::Sent, WaOutboundMessageStatus::Failed, WaOutboundMessageStatus::Cancelled => false,
        };
    }

    public function queueOutboundMessage(Tenant $tenant, array $data): WaOutboundMessage
    {
        $this->assertPayloadContract($data['payload']);

        $account = $tenant->waAccounts()
            ->where('provider', $data['provider'])
            ->where('provider_ref', $data['wa_account_provider_ref'])
            ->first();

        if (! $account) {
            throw new HttpException(422, 'WA account reference is invalid for tenant.');
        }

        $session = null;
        if (! empty($data['wa_session_provider_ref'])) {
            $session = WaSession::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', $data['provider'])
                ->where('provider_ref', $data['wa_session_provider_ref'])
                ->first();

            if (! $session) {
                throw new HttpException(422, 'WA session reference is invalid for tenant.');
            }
        }

        $outbound = null;
        if (! empty($data['provider_message_id'])) {
            $existing = WaOutboundMessage::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', $data['provider'])
                ->where('provider_message_id', $data['provider_message_id'])
                ->first();

            // Idempotent replay: keep first write and avoid duplicate dispatch side effects.
            if ($existing) {
                return $existing->refresh();
            }

            $outbound = new WaOutboundMessage();
            $outbound->tenant_id = $tenant->id;
        } else {
            $outbound = new WaOutboundMessage();
            $outbound->tenant_id = $tenant->id;
        }

        $outbound->fill([
            'wa_account_id' => $account->id,
            'wa_session_id' => $session?->id,
            'provider' => $data['provider'],
            'provider_message_id' => $data['provider_message_id'] ?? null,
            'to' => $data['to'],
            'message_type' => $data['message_type'],
            'status' => WaOutboundMessageStatus::Pending,
            'payload' => $data['payload'],
            'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : null,
            'queued_at' => now(),
            'sent_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ]);
        $outbound->save();

        DispatchWaOutboundMessageJob::dispatch($tenant->id, $outbound->id);

        return $outbound->refresh();
    }

    /**
     * @param  array<string,mixed>|null  $requestPayload
     * @param  array<string,mixed>|null  $responsePayload
     */
    public function appendDeliveryLog(
        WaOutboundMessage $outbound,
        WaOutboundMessageStatus $status,
        ?array $requestPayload,
        ?array $responsePayload,
        ?string $errorMessage,
        ?CarbonImmutable $attemptedAt = null
    ): WaMessageDeliveryLog {
        $attempt = WaMessageDeliveryLog::query()
            ->where('wa_outbound_message_id', $outbound->id)
            ->max('attempt_number');

        return WaMessageDeliveryLog::query()->create([
            'tenant_id' => $outbound->tenant_id,
            'wa_outbound_message_id' => $outbound->id,
            'attempt_number' => ((int) $attempt) + 1,
            'status' => $status->value,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'error_message' => $errorMessage,
            'attempted_at' => ($attemptedAt ?? now())->toImmutable(),
        ]);
    }
}
