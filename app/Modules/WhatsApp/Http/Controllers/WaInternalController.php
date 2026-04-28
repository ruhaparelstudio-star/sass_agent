<?php

namespace App\Modules\WhatsApp\Http\Controllers;

use App\Enums\WaAccountStatus;
use App\Enums\WaSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\WhatsApp\Services\WaSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class WaInternalController extends Controller
{
    public function __construct(private readonly WaSyncService $waSyncService)
    {
    }

    public function upsertAccount(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'provider' => ['required', 'string', 'max:100'],
            'provider_ref' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:64'],
            'status' => ['required', new Enum(WaAccountStatus::class)],
            'payload' => ['required', 'array'],
            'meta' => ['nullable', 'array'],
        ]);

        $tenant = Tenant::query()->findOrFail($payload['tenant_id']);
        $this->waSyncService->assertTenantScope($request->user(), $tenant);

        $account = $this->waSyncService->upsertAccount($tenant, $payload);

        return response()->json(['data' => $account]);
    }

    public function upsertSession(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'wa_account_provider_ref' => ['required', 'string', 'max:191'],
            'provider' => ['required', 'string', 'max:100'],
            'provider_ref' => ['required', 'string', 'max:191'],
            'status' => ['required', new Enum(WaSessionStatus::class)],
            'payload' => ['required', 'array'],
            'meta' => ['nullable', 'array'],
        ]);

        $tenant = Tenant::query()->findOrFail($payload['tenant_id']);
        $this->waSyncService->assertTenantScope($request->user(), $tenant);

        $session = $this->waSyncService->upsertSession($tenant, $payload);

        return response()->json(['data' => $session]);
    }

    public function storeInboundMessage(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'provider' => ['required', 'string', 'max:100'],
            'provider_message_id' => ['required', 'string', 'max:191'],
            'wa_account_provider_ref' => ['required', 'string', 'max:191'],
            'wa_session_provider_ref' => ['nullable', 'string', 'max:191'],
            'from' => ['required', 'string', 'max:64'],
            'to' => ['required', 'string', 'max:64'],
            'message_type' => ['required', 'string', 'max:64'],
            'message_timestamp' => ['required'],
            'payload' => ['required', 'array'],
            'meta' => ['nullable', 'array'],
        ]);

        $tenant = Tenant::query()->findOrFail($payload['tenant_id']);
        $this->waSyncService->assertTenantScope($request->user(), $tenant);

        $inboundMessage = $this->waSyncService->storeInboundMessage($tenant, $payload);

        return response()->json(['data' => $inboundMessage]);
    }
}
