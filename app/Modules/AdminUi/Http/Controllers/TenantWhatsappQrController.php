<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\WaAccountStatus;
use App\Enums\WaSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\WaAccount;
use App\Models\WaSession;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantWhatsappQrController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
        private readonly FeatureGateService $featureGateService,
    ) {
    }

    public function show(Request $request): Response
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $agentLimit = $this->resolveAgentLimit($tenantId);
        $usedAgentCount = $this->resolveUsedAgentCount($tenantId);

        return Inertia::render('Tenant/WhatsappQr', [
            'tenantId' => $tenantId,
            'qr' => $this->fetchQrPayload($tenantId),
            'agent' => [
                'limit' => $agentLimit,
                'used' => $usedAgentCount,
                'remaining' => max(0, $agentLimit - $usedAgentCount),
                'canAdd' => $agentLimit > 0 && $usedAgentCount < $agentLimit,
                'accounts' => $this->resolveAgentAccounts($tenantId),
            ],
        ]);
    }

    public function connect(Request $request)
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $agentLimit = $this->resolveAgentLimit($tenantId);
        $usedAgentCount = $this->resolveUsedAgentCount($tenantId);

        if ($agentLimit <= 0) {
            return back()->withErrors([
                'operation' => 'Subscription tidak mengizinkan penambahan WhatsApp agent.',
            ]);
        }

        if ($usedAgentCount >= $agentLimit) {
            return back()->withErrors([
                'operation' => 'Batas WhatsApp agent pada subscription sudah tercapai.',
            ]);
        }

        $this->bootstrapGatewaySession($tenantId);

        return redirect('/tenant/whatsapp/qr');
    }

    public function disconnect(Request $request, WaAccount $waAccount)
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $this->assertTenantWaAccount($tenantId, $waAccount);

        $this->disconnectGatewaySession($tenantId, (string) $waAccount->provider, (string) $waAccount->provider_ref);

        $waAccount->status = WaAccountStatus::Disconnected;
        $waAccount->save();

        WaSession::query()
            ->where('tenant_id', $tenantId)
            ->where('wa_account_id', $waAccount->id)
            ->update(['status' => WaSessionStatus::Closed->value]);

        return redirect('/tenant/whatsapp/qr');
    }

    public function reconnect(Request $request, WaAccount $waAccount)
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $this->assertTenantWaAccount($tenantId, $waAccount);

        $agentLimit = $this->resolveAgentLimit($tenantId);
        $usedAgentCount = $this->resolveUsedAgentCount($tenantId);
        $isCurrentlyActive = in_array((string) $waAccount->status->value, [
            WaAccountStatus::Connecting->value,
            WaAccountStatus::Connected->value,
        ], true);

        if (! $isCurrentlyActive && $agentLimit > 0 && $usedAgentCount >= $agentLimit) {
            return back()->withErrors([
                'operation' => 'Batas WhatsApp agent pada subscription sudah tercapai.',
            ]);
        }

        $this->disconnectGatewaySession($tenantId, (string) $waAccount->provider, (string) $waAccount->provider_ref);
        $this->bootstrapGatewaySession($tenantId, (string) $waAccount->provider_ref);
        $waAccount->status = WaAccountStatus::Connecting;
        $waAccount->save();

        return redirect('/tenant/whatsapp/qr');
    }

    public function image(Request $request): HttpResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $qr = $this->fetchQrPayload($tenantId);

        if (($qr['status'] ?? 'unavailable') !== 'available' || ! is_string($qr['imageDataUrl'] ?? null)) {
            abort(404, 'QR image unavailable.');
        }

        $imageDataUrl = $qr['imageDataUrl'];
        if (! str_starts_with($imageDataUrl, 'data:image/png;base64,')) {
            abort(404, 'QR image format invalid.');
        }

        $binary = base64_decode(substr($imageDataUrl, strlen('data:image/png;base64,')), true);
        if (! is_string($binary) || $binary === '') {
            abort(404, 'QR image decode failed.');
        }

        return response($binary, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * @return array{status:string,provider:?string,code:?string,imageDataUrl:?string,expiresInSeconds:?int,generatedAt:?string}
     */
    private function fetchQrPayload(int $tenantId): array
    {
        $url = trim((string) config('whatsapp.gateway_qr_url', ''));
        if ($url === '') {
            return $this->unavailablePayload();
        }

        try {
            return $this->fetchQrFromGateway($url, $tenantId);
        } catch (\Throwable) {
            return $this->unavailablePayload();
        }
    }

    /**
     * @return array{status:string,provider:?string,code:?string,imageDataUrl:?string,expiresInSeconds:?int,generatedAt:?string}
     */
    private function fetchQrFromGateway(string $url, int $tenantId): array
    {
        $response = Http::timeout((int) config('whatsapp.gateway_timeout_seconds', 10))
            ->acceptJson()
            ->get($url, ['tenant_id' => $tenantId]);

        if (! $response->successful()) {
            return $this->unavailablePayload();
        }

        $payload = $response->json();
        if (! is_array($payload) || ! is_string($payload['qr_code'] ?? null)) {
            return $this->unavailablePayload();
        }

        $qrCode = trim((string) $payload['qr_code']);
        if ($qrCode === '' || str_starts_with($qrCode, 'dummy-')) {
            return $this->unavailablePayload();
        }

        return [
            'status' => 'available',
            'provider' => is_string($payload['provider'] ?? null) ? $payload['provider'] : null,
            'code' => $qrCode,
            'imageDataUrl' => is_string($payload['qr_image_data_url'] ?? null) ? $payload['qr_image_data_url'] : null,
            'expiresInSeconds' => isset($payload['expires_in_seconds']) && is_numeric($payload['expires_in_seconds'])
                ? (int) $payload['expires_in_seconds']
                : null,
            'generatedAt' => is_string($payload['generated_at'] ?? null) ? $payload['generated_at'] : null,
        ];
    }

    private function bootstrapGatewaySession(int $tenantId, ?string $accountProviderRef = null): void
    {
        $connectUrl = trim((string) config('whatsapp.gateway_connect_url', ''));
        if ($connectUrl === '') {
            return;
        }

        $resolvedAccountProviderRef = trim((string) ($accountProviderRef ?? ''));
        if ($resolvedAccountProviderRef === '') {
            $resolvedAccountProviderRef = sprintf('acct-%d-%d', $tenantId, now()->getTimestamp());
        }

        Http::timeout((int) config('whatsapp.gateway_timeout_seconds', 10))
            ->acceptJson()
            ->post($connectUrl, [
                'tenant_id' => $tenantId,
                'provider' => 'meta',
                'account_provider_ref' => $resolvedAccountProviderRef,
                'session_provider_ref' => sprintf('tenant-%d-admin-qr-%d', $tenantId, now()->getTimestamp()),
            ]);
    }

    private function resolveAgentLimit(int $tenantId): int
    {
        $features = $this->featureGateService->resolveForTenant($tenantId);
        return (int) ($features['wa_agent_limit'] ?? 0);
    }

    private function resolveUsedAgentCount(int $tenantId): int
    {
        return WaAccount::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [WaAccountStatus::Connecting->value, WaAccountStatus::Connected->value])
            ->count();
    }

    private function disconnectGatewaySession(int $tenantId, string $provider, string $accountProviderRef): void
    {
        $disconnectUrl = trim((string) config('whatsapp.gateway_disconnect_url', ''));
        if ($disconnectUrl === '') {
            return;
        }

        Http::timeout((int) config('whatsapp.gateway_timeout_seconds', 10))
            ->acceptJson()
            ->post($disconnectUrl, [
                'tenant_id' => $tenantId,
                'provider' => $provider,
                'account_provider_ref' => $accountProviderRef,
            ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function resolveAgentAccounts(int $tenantId): array
    {
        return WaAccount::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->get(['id', 'provider', 'provider_ref', 'phone', 'status', 'updated_at'])
            ->map(fn (WaAccount $account): array => [
                'id' => $account->id,
                'provider' => (string) $account->provider,
                'providerRef' => (string) $account->provider_ref,
                'phone' => $account->phone,
                'status' => $account->status->value,
                'updatedAt' => optional($account->updated_at)?->toDateTimeString(),
                'canDisconnect' => in_array($account->status->value, [
                    WaAccountStatus::Connecting->value,
                    WaAccountStatus::Connected->value,
                ], true),
                'canReconnect' => $account->status === WaAccountStatus::Disconnected,
            ])
            ->values()
            ->toArray();
    }

    /**
     * @return array{status:string,provider:?string,code:?string,imageDataUrl:?string,expiresInSeconds:?int,generatedAt:?string}
     */
    private function unavailablePayload(): array
    {
        return [
            'status' => 'unavailable',
            'provider' => null,
            'code' => null,
            'imageDataUrl' => null,
            'expiresInSeconds' => null,
            'generatedAt' => null,
        ];
    }

    private function resolveAuthorizedTenantId(Request $request): int
    {
        $user = $request->user();
        if ($user->role !== UserRole::TenantAdmin) {
            throw new HttpException(403, 'Forbidden role.');
        }

        $context = $this->tenantContextResolver->resolve($user);
        if (! is_int($context->tenantId)) {
            throw new HttpException(403, 'Tenant context unavailable.');
        }

        return $context->tenantId;
    }

    private function assertTenantWaAccount(int $tenantId, WaAccount $waAccount): void
    {
        if ((int) $waAccount->tenant_id !== $tenantId) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }
    }
}
