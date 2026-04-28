<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantContextController extends Controller
{
    public function __construct(private readonly TenantContextResolver $tenantContextResolver)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $context = $this->tenantContextResolver->resolve($request->user());

        return response()->json([
            'data' => $context->toArray(),
        ]);
    }
}
