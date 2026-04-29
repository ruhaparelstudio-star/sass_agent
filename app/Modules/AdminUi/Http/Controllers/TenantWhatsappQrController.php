<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantWhatsappQrController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
    ) {
    }

    public function show(Request $request): Response
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        return Inertia::render('Tenant/WhatsappQr', [
            'tenantId' => $tenantId,
            'qr' => $this->fetchQrPayload($tenantId),
        ]);
    }

    /**
     * @return array{status:string,provider:?string,code:?string,expiresInSeconds:?int,generatedAt:?string}
     */
    private function fetchQrPayload(int $tenantId): array
    {
        $url = trim((string) config('whatsapp.gateway_qr_url', ''));
        if ($url === '') {
            return [
                'status' => 'unavailable',
                'provider' => null,
                'code' => null,
                'expiresInSeconds' => null,
                'generatedAt' => null,
            ];
        }

        try {
            $response = Http::timeout((int) config('whatsapp.gateway_timeout_seconds', 10))
                ->acceptJson()
                ->get($url, ['tenant_id' => $tenantId]);

            if (! $response->successful()) {
                return [
                    'status' => 'unavailable',
                    'provider' => null,
                    'code' => null,
                    'expiresInSeconds' => null,
                    'generatedAt' => null,
                ];
            }

            $payload = $response->json();
            if (! is_array($payload) || ! is_string($payload['qr_code'] ?? null)) {
                return [
                    'status' => 'unavailable',
                    'provider' => null,
                    'code' => null,
                    'expiresInSeconds' => null,
                    'generatedAt' => null,
                ];
            }

            return [
                'status' => 'available',
                'provider' => is_string($payload['provider'] ?? null) ? $payload['provider'] : null,
                'code' => $payload['qr_code'],
                'expiresInSeconds' => isset($payload['expires_in_seconds']) && is_numeric($payload['expires_in_seconds'])
                    ? (int) $payload['expires_in_seconds']
                    : null,
                'generatedAt' => is_string($payload['generated_at'] ?? null) ? $payload['generated_at'] : null,
            ];
        } catch (\Throwable) {
            return [
                'status' => 'unavailable',
                'provider' => null,
                'code' => null,
                'expiresInSeconds' => null,
                'generatedAt' => null,
            ];
        }
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
}

