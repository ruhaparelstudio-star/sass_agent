<?php

namespace App\Modules\WhatsApp\Services;

use App\Enums\UserRole;
use App\Enums\WaAccountStatus;
use App\Enums\WaSessionStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaAccount;
use App\Models\WaInboundMessage;
use App\Models\WaSession;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WaSyncService
{
    public function __construct(private readonly TenantContextResolver $tenantContextResolver)
    {
    }

    public function assertTenantScope(User $user, Tenant $tenant): void
    {
        if ($user->role === UserRole::Superadmin) {
            return;
        }

        $this->tenantContextResolver->assertCanAccessTenant($user, $tenant);
    }

    public function assertPayloadContract(mixed $payload): void
    {
        if (! is_array($payload)) {
            throw new HttpException(422, 'Payload must be an object.');
        }
    }

    public function canTransitionAccountStatus(WaAccountStatus $from, WaAccountStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            WaAccountStatus::Disconnected => in_array($to, [WaAccountStatus::Connecting, WaAccountStatus::Connected], true),
            WaAccountStatus::Connecting => in_array($to, [WaAccountStatus::Connected, WaAccountStatus::Disconnected], true),
            // During reconnect flow, gateway can emit connected -> connecting before open.
            WaAccountStatus::Connected => in_array($to, [WaAccountStatus::Connecting, WaAccountStatus::Disconnected], true),
        };
    }

    public function canTransitionSessionStatus(WaSessionStatus $from, WaSessionStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            WaSessionStatus::Pending => in_array($to, [WaSessionStatus::Active, WaSessionStatus::Closed], true),
            // During reconnect, gateway can emit active -> pending before open/close.
            WaSessionStatus::Active => in_array($to, [WaSessionStatus::Pending, WaSessionStatus::Closed], true),
            // Gateway can reopen or move closed session back to pending during reconnect.
            WaSessionStatus::Closed => in_array($to, [WaSessionStatus::Pending, WaSessionStatus::Active], true),
        };
    }

    public function upsertAccount(Tenant $tenant, array $data): WaAccount
    {
        $status = WaAccountStatus::from($data['status']);
        $this->assertPayloadContract($data['payload']);

        $account = WaAccount::query()->firstOrNew([
            'tenant_id' => $tenant->id,
            'provider' => $data['provider'],
            'provider_ref' => $data['provider_ref'],
        ]);

        if ($account->exists && ! $this->canTransitionAccountStatus($account->status, $status)) {
            throw new HttpException(422, 'Invalid WA account status transition.');
        }

        $account->fill([
            'phone' => $data['phone'] ?? null,
            'status' => $status,
            'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : null,
            'last_payload' => $data['payload'],
        ]);
        $account->save();

        return $account->refresh();
    }

    public function upsertSession(Tenant $tenant, array $data): WaSession
    {
        $status = WaSessionStatus::from($data['status']);
        $this->assertPayloadContract($data['payload']);

        $account = WaAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $data['provider'])
            ->where('provider_ref', $data['wa_account_provider_ref'])
            ->first();

        if (! $account) {
            throw new HttpException(422, 'WA account reference is invalid for tenant.');
        }

        $session = WaSession::query()->firstOrNew([
            'tenant_id' => $tenant->id,
            'provider' => $data['provider'],
            'provider_ref' => $data['provider_ref'],
        ]);

        if ($session->exists && ! $this->canTransitionSessionStatus($session->status, $status)) {
            throw new HttpException(422, 'Invalid WA session status transition.');
        }

        $session->fill([
            'wa_account_id' => $account->id,
            'status' => $status,
            'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : null,
            'last_payload' => $data['payload'],
        ]);
        $session->save();

        return $session->refresh();
    }

    public function storeInboundMessage(Tenant $tenant, array $data): WaInboundMessage
    {
        $this->assertPayloadContract($data['payload']);

        $account = WaAccount::query()
            ->where('tenant_id', $tenant->id)
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

        $inboundMessage = WaInboundMessage::query()->firstOrNew([
            'tenant_id' => $tenant->id,
            'provider' => $data['provider'],
            'provider_message_id' => $data['provider_message_id'],
        ]);

        if ($inboundMessage->exists) {
            return $inboundMessage->refresh();
        }

        $inboundMessage->fill([
            'wa_account_id' => $account->id,
            'wa_session_id' => $session?->id,
            'from' => $data['from'],
            'to' => $data['to'],
            'message_type' => $data['message_type'],
            'message_timestamp' => $this->normalizeMessageTimestamp($data['message_timestamp']),
            'payload' => $data['payload'],
            'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : null,
        ]);
        $inboundMessage->save();

        return $inboundMessage->refresh();
    }

    private function normalizeMessageTimestamp(mixed $value): CarbonImmutable
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        }

        if (is_string($value)) {
            try {
                return CarbonImmutable::parse($value)->utc();
            } catch (\Throwable) {
                throw new HttpException(422, 'Message timestamp format is invalid.');
            }
        }

        throw new HttpException(422, 'Message timestamp format is invalid.');
    }
}
