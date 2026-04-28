<?php

namespace App\Modules\Activation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Activation\Services\ActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivationController extends Controller
{
    public function __construct(private readonly ActivationService $activationService)
    {
    }

    public function issueToken(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'email' => ['required', 'email'],
        ]);

        $tenant = Tenant::query()->findOrFail($payload['tenant_id']);
        $issued = $this->activationService->issueToken($request->user(), $tenant, $payload['email']);

        return response()->json([
            'data' => [
                'status' => $issued['status'],
                'email' => $issued['email'],
                'expires_at' => $issued['expires_at'],
            ],
            'delivery' => [
                'token' => $issued['token'],
            ],
        ], 201);
    }

    public function verify(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $result = $this->activationService->verifyToken($payload['token'], $payload['email']);

        return response()->json(['data' => $result]);
    }

    public function setPassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', 'min:8'],
        ]);

        $result = $this->activationService->setPassword(
            $payload['token'],
            $payload['email'],
            $payload['password'],
        );

        return response()->json(['data' => $result]);
    }
}

