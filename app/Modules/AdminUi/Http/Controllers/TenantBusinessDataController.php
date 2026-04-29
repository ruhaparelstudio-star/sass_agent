<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Faq;
use App\Models\Package;
use App\Models\Price;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\TenantAsset;
use App\Modules\AdminUi\Services\TenantBusinessDataCommandService;
use App\Modules\AdminUi\Services\TenantBusinessDataQueryService;
use App\Modules\DataKnowledge\Services\InvoiceAssetUploadService;
use App\Modules\DataKnowledge\Services\PricelistAssetUploadService;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantBusinessDataController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
        private readonly TenantBusinessDataQueryService $queryService,
        private readonly TenantBusinessDataCommandService $commandService,
        private readonly PricelistAssetUploadService $pricelistAssetUploadService,
        private readonly InvoiceAssetUploadService $invoiceAssetUploadService,
    ) {
    }

    public function show(Request $request): Response
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        return Inertia::render('Tenant/BusinessData', [
            'tenantId' => $tenantId,
            'data' => $this->queryService->getPageData($tenantId),
            'assets' => TenantAsset::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit(25)
                ->get(['id', 'tenant_id', 'asset_type', 'display_name', 'original_filename', 'storage_disk', 'storage_path', 'uploaded_by_user_id', 'is_active', 'created_at'])
                ->toArray(),
            'discountTypes' => ['percentage', 'fixed'],
        ]);
    }

    public function uploadPricelistAsset(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:15360'],
            'display_name' => ['nullable', 'string', 'max:255'],
        ]);

        $this->pricelistAssetUploadService->uploadPricelistPdf(
            tenantId: $tenantId,
            ownerTenantId: $tenantId,
            file: $payload['file'],
            uploadedByUserId: $request->user()?->id,
            displayName: $payload['display_name'] ?? null,
        );

        return back()->with('success', 'Pricelist asset uploaded.');
    }

    public function uploadInvoiceAsset(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:15360'],
            'display_name' => ['nullable', 'string', 'max:255'],
        ]);

        $this->invoiceAssetUploadService->uploadInvoicePdf(
            tenantId: $tenantId,
            ownerTenantId: $tenantId,
            file: $payload['file'],
            uploadedByUserId: $request->user()?->id,
            displayName: $payload['display_name'] ?? null,
        );

        return back()->with('success', 'Invoice asset uploaded.');
    }

    public function storeServiceCatalog(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->serviceCatalogRules($tenantId));

        $this->commandService->createServiceCatalog($tenantId, $payload);

        return back()->with('success', 'Service catalog created.');
    }

    public function updateServiceCatalog(Request $request, ServiceCatalog $serviceCatalog): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->serviceCatalogRules($tenantId, $serviceCatalog->id));

        $this->commandService->updateServiceCatalog($tenantId, $serviceCatalog, $payload);

        return back()->with('success', 'Service catalog updated.');
    }

    public function toggleServiceCatalog(Request $request, ServiceCatalog $serviceCatalog): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $this->commandService->toggleServiceCatalog($tenantId, $serviceCatalog);

        return back()->with('success', 'Service catalog status updated.');
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->productRules($tenantId));

        $this->commandService->createProduct($tenantId, $payload);

        return back()->with('success', 'Product created.');
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->productRules($tenantId, $product->id));

        $this->commandService->updateProduct($tenantId, $product, $payload);

        return back()->with('success', 'Product updated.');
    }

    public function toggleProduct(Request $request, Product $product): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $this->commandService->toggleProduct($tenantId, $product);

        return back()->with('success', 'Product status updated.');
    }

    public function storePackage(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->packageRules($tenantId));

        $this->commandService->createPackage($tenantId, $payload);

        return back()->with('success', 'Package created.');
    }

    public function updatePackage(Request $request, Package $package): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->packageRules($tenantId, $package->id));

        $this->commandService->updatePackage($tenantId, $package, $payload);

        return back()->with('success', 'Package updated.');
    }

    public function togglePackage(Request $request, Package $package): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $this->commandService->togglePackage($tenantId, $package);

        return back()->with('success', 'Package status updated.');
    }

    public function storePrice(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->priceRules());

        $this->commandService->createPrice($tenantId, $payload);

        return back()->with('success', 'Price created.');
    }

    public function updatePrice(Request $request, Price $price): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->priceRules());

        $this->commandService->updatePrice($tenantId, $price, $payload);

        return back()->with('success', 'Price updated.');
    }

    public function togglePrice(Request $request, Price $price): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $this->commandService->togglePrice($tenantId, $price);

        return back()->with('success', 'Price status updated.');
    }

    public function storeDiscount(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->discountRules());

        $this->commandService->createDiscount($tenantId, $payload);

        return back()->with('success', 'Discount created.');
    }

    public function updateDiscount(Request $request, Discount $discount): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->discountRules());

        $this->commandService->updateDiscount($tenantId, $discount, $payload);

        return back()->with('success', 'Discount updated.');
    }

    public function toggleDiscount(Request $request, Discount $discount): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $this->commandService->toggleDiscount($tenantId, $discount);

        return back()->with('success', 'Discount status updated.');
    }

    public function storeFaq(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->faqRules());

        $this->commandService->createFaq($tenantId, $payload);

        return back()->with('success', 'FAQ created.');
    }

    public function updateFaq(Request $request, Faq $faq): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $payload = $request->validate($this->faqRules());

        $this->commandService->updateFaq($tenantId, $faq, $payload);

        return back()->with('success', 'FAQ updated.');
    }

    public function toggleFaq(Request $request, Faq $faq): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $this->commandService->toggleFaq($tenantId, $faq);

        return back()->with('success', 'FAQ status updated.');
    }

    private function serviceCatalogRules(int $tenantId, ?int $ignoreId = null): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'unique:service_catalogs,code,'.$ignoreId.',id,tenant_id,'.$tenantId],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date'],
        ];
    }

    private function productRules(int $tenantId, ?int $ignoreId = null): array
    {
        return [
            'service_catalog_id' => ['required', 'integer', 'exists:service_catalogs,id'],
            'code' => ['required', 'string', 'max:64', 'unique:products,code,'.$ignoreId.',id,tenant_id,'.$tenantId],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date'],
        ];
    }

    private function packageRules(int $tenantId, ?int $ignoreId = null): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'code' => ['required', 'string', 'max:64', 'unique:packages,code,'.$ignoreId.',id,tenant_id,'.$tenantId],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date'],
        ];
    }

    private function priceRules(): array
    {
        return [
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'label' => ['required', 'string', 'max:128'],
            'currency' => ['required', 'string', 'max:8'],
            'amount' => ['required', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date'],
        ];
    }

    private function discountRules(): array
    {
        return [
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'name' => ['required', 'string', 'max:128'],
            'discount_type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date'],
        ];
    }

    private function faqRules(): array
    {
        return [
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date'],
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
}
