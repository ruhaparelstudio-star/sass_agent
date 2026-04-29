<?php

use App\Enums\UserRole;
use App\Modules\AdminUi\Http\Controllers\SuperadminPlanManagementController;
use App\Modules\AdminUi\Http\Controllers\SuperadminConversationMonitoringController;
use App\Modules\AdminUi\Http\Controllers\SuperadminDataMonitoringController;
use App\Modules\AdminUi\Http\Controllers\TenantBusinessDataController;
use App\Modules\AdminUi\Http\Controllers\TenantDashboardController;
use App\Modules\AdminUi\Http\Controllers\TenantConversationInboxController;
use App\Modules\AdminUi\Http\Controllers\TenantWhatsappQrController;
use App\Modules\Activation\Http\Controllers\ActivationController;
use App\Modules\AdminUi\Http\Controllers\SuperadminTenantController;
use App\Modules\Auth\Http\Controllers\WebLoginController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = Auth::user();

    if ($user === null) {
        return redirect('/login');
    }

    if ($user->role === UserRole::Superadmin) {
        return redirect('/superadmin/dashboard');
    }

    return redirect('/tenant/dashboard');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [WebLoginController::class, 'show'])->name('login');
    Route::post('/login', [WebLoginController::class, 'login']);
    Route::get('/activation', [ActivationController::class, 'webShow']);
    Route::post('/activation/set-password', [ActivationController::class, 'webSetPassword']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [WebLoginController::class, 'logout']);
    Route::get('/superadmin/dashboard', [SuperadminTenantController::class, 'dashboard']);
    Route::get('/superadmin/tenants', [SuperadminTenantController::class, 'index']);
    Route::get('/superadmin/tenants/create', [SuperadminTenantController::class, 'create']);
    Route::post('/superadmin/tenants', [SuperadminTenantController::class, 'store']);
    Route::get('/superadmin/tenants/{tenant}', [SuperadminTenantController::class, 'show']);
    Route::get('/superadmin/tenants/{tenant}/edit', [SuperadminTenantController::class, 'edit']);
    Route::put('/superadmin/tenants/{tenant}', [SuperadminTenantController::class, 'update']);
    Route::patch('/superadmin/tenants/{tenant}', [SuperadminTenantController::class, 'update']);
    Route::post('/superadmin/tenants/{tenant}/deactivate', [SuperadminTenantController::class, 'deactivate']);

    Route::get('/superadmin/plans', [SuperadminPlanManagementController::class, 'index']);
    Route::get('/superadmin/conversations', [SuperadminConversationMonitoringController::class, 'index']);
    Route::get('/superadmin/data-monitoring', [SuperadminDataMonitoringController::class, 'index']);
    Route::get('/superadmin/data-monitoring/{tenant}', [SuperadminDataMonitoringController::class, 'show']);
    Route::get('/superadmin/plans/create', [SuperadminPlanManagementController::class, 'create']);
    Route::post('/superadmin/plans', [SuperadminPlanManagementController::class, 'store']);
    Route::get('/superadmin/plans/{plan}/edit', [SuperadminPlanManagementController::class, 'edit']);
    Route::put('/superadmin/plans/{plan}', [SuperadminPlanManagementController::class, 'update']);
    Route::patch('/superadmin/plans/{plan}', [SuperadminPlanManagementController::class, 'update']);
    Route::post('/superadmin/plans/{plan}/features', [SuperadminPlanManagementController::class, 'storeFeature']);
    Route::put('/superadmin/plans/{plan}/features/{feature}', [SuperadminPlanManagementController::class, 'updateFeature']);
    Route::delete('/superadmin/plans/{plan}/features/{feature}', [SuperadminPlanManagementController::class, 'destroyFeature']);
    Route::post('/superadmin/subscriptions/assign', [SuperadminPlanManagementController::class, 'assignSubscription']);
    Route::post('/superadmin/subscriptions/unassign', [SuperadminPlanManagementController::class, 'unassignSubscription']);

    Route::get('/tenant/dashboard', [TenantDashboardController::class, 'show']);
    Route::get('/tenant/inbox', [TenantConversationInboxController::class, 'show']);
    Route::get('/tenant/whatsapp/qr', [TenantWhatsappQrController::class, 'show']);
    Route::get('/tenant/whatsapp/qr/image', [TenantWhatsappQrController::class, 'image']);
    Route::post('/tenant/inbox/{conversation}/handoff/{handoff}/resolve', [TenantConversationInboxController::class, 'resolveHandoff']);
    Route::post('/tenant/inbox/{conversation}/handoff/{handoff}/resume', [TenantConversationInboxController::class, 'resumeAi']);

    Route::get('/tenant/business-data', [TenantBusinessDataController::class, 'show']);
    Route::post('/tenant/business-data/service-catalogs', [TenantBusinessDataController::class, 'storeServiceCatalog']);
    Route::put('/tenant/business-data/service-catalogs/{serviceCatalog}', [TenantBusinessDataController::class, 'updateServiceCatalog']);
    Route::post('/tenant/business-data/service-catalogs/{serviceCatalog}/toggle', [TenantBusinessDataController::class, 'toggleServiceCatalog']);
    Route::post('/tenant/business-data/products', [TenantBusinessDataController::class, 'storeProduct']);
    Route::put('/tenant/business-data/products/{product}', [TenantBusinessDataController::class, 'updateProduct']);
    Route::post('/tenant/business-data/products/{product}/toggle', [TenantBusinessDataController::class, 'toggleProduct']);
    Route::post('/tenant/business-data/packages', [TenantBusinessDataController::class, 'storePackage']);
    Route::put('/tenant/business-data/packages/{package}', [TenantBusinessDataController::class, 'updatePackage']);
    Route::post('/tenant/business-data/packages/{package}/toggle', [TenantBusinessDataController::class, 'togglePackage']);
    Route::post('/tenant/business-data/prices', [TenantBusinessDataController::class, 'storePrice']);
    Route::put('/tenant/business-data/prices/{price}', [TenantBusinessDataController::class, 'updatePrice']);
    Route::post('/tenant/business-data/prices/{price}/toggle', [TenantBusinessDataController::class, 'togglePrice']);
    Route::post('/tenant/business-data/discounts', [TenantBusinessDataController::class, 'storeDiscount']);
    Route::put('/tenant/business-data/discounts/{discount}', [TenantBusinessDataController::class, 'updateDiscount']);
    Route::post('/tenant/business-data/discounts/{discount}/toggle', [TenantBusinessDataController::class, 'toggleDiscount']);
    Route::post('/tenant/business-data/faqs', [TenantBusinessDataController::class, 'storeFaq']);
    Route::put('/tenant/business-data/faqs/{faq}', [TenantBusinessDataController::class, 'updateFaq']);
    Route::post('/tenant/business-data/faqs/{faq}/toggle', [TenantBusinessDataController::class, 'toggleFaq']);
    Route::post('/tenant/business-data/assets/pricelist', [TenantBusinessDataController::class, 'uploadPricelistAsset']);
    Route::post('/tenant/business-data/assets/invoice', [TenantBusinessDataController::class, 'uploadInvoiceAsset']);
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'app',
    ]);
});

Route::middleware('wa.internal.secret')->get('/health/db', function () {
    DB::connection()->getPdo();

    return response()->json([
        'status' => 'ok',
        'service' => 'db',
    ]);
});

Route::middleware('wa.internal.secret')->get('/health/redis', function () {
    Redis::connection()->ping();

    return response()->json([
        'status' => 'ok',
        'service' => 'redis',
    ]);
});
