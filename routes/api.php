<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Activation\Http\Controllers\ActivationController;
use App\Modules\Plans\Http\Controllers\PlanController;
use App\Modules\Plans\Http\Controllers\TenantSubscriptionController;
use App\Modules\Tenancy\Http\Controllers\TenantContextController;
use App\Modules\Tenancy\Http\Controllers\TenantController;
use App\Modules\WhatsApp\Http\Controllers\WaInternalController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/activation/verify', [ActivationController::class, 'verify']);
Route::post('/activation/set-password', [ActivationController::class, 'setPassword']);

Route::middleware(['auth:sanctum', 'api.token'])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/activation-tokens', [ActivationController::class, 'issueToken']);
    Route::get('/me/context', [TenantContextController::class, 'show']);

    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants/{tenant}', [TenantController::class, 'show']);

    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::put('/plans/{plan}', [PlanController::class, 'update']);
    Route::post('/plans/{plan}/features', [PlanController::class, 'storeFeature']);
    Route::put('/plans/{plan}/features/{feature}', [PlanController::class, 'updateFeature']);
    Route::delete('/plans/{plan}/features/{feature}', [PlanController::class, 'destroyFeature']);

    Route::post('/tenant-subscriptions/assign', [TenantSubscriptionController::class, 'assign']);
    Route::post('/tenant-subscriptions/unassign', [TenantSubscriptionController::class, 'unassign']);

    Route::middleware(['wa.internal.secret'])->prefix('internal/whatsapp')->group(function (): void {
        Route::post('/accounts/upsert', [WaInternalController::class, 'upsertAccount']);
        Route::post('/sessions/upsert', [WaInternalController::class, 'upsertSession']);
        Route::post('/inbound-messages', [WaInternalController::class, 'storeInboundMessage']);
    });
});
