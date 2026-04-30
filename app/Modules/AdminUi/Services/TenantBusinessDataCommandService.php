<?php

namespace App\Modules\AdminUi\Services;

use App\Models\Discount;
use App\Models\Faq;
use App\Models\Package;
use App\Models\Price;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\BookingSetting;
use App\Models\CalendarSetting;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantBusinessDataCommandService
{
    private const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    public function createServiceCatalog(int $tenantId, array $payload): ServiceCatalog
    {
        $payload['code'] = $payload['code'] ?? $this->generateServiceCatalogCode($tenantId);

        return ServiceCatalog::query()->create(array_merge($payload, ['tenant_id' => $tenantId]));
    }

    public function updateServiceCatalog(int $tenantId, ServiceCatalog $serviceCatalog, array $payload): void
    {
        $this->assertTenantRecord($tenantId, (int) $serviceCatalog->tenant_id);
        $serviceCatalog->update($payload);
    }

    public function toggleServiceCatalog(int $tenantId, ServiceCatalog $serviceCatalog): void
    {
        $this->assertTenantRecord($tenantId, (int) $serviceCatalog->tenant_id);
        $serviceCatalog->update(['is_active' => ! $serviceCatalog->is_active]);
    }

    public function createProduct(int $tenantId, array $payload): Product
    {
        $catalog = ServiceCatalog::query()->findOrFail($payload['service_catalog_id']);
        $this->assertTenantRecord($tenantId, (int) $catalog->tenant_id);
        $payload['code'] = $payload['code'] ?? $this->generateCodeByPrefix(Product::class, $tenantId, 'PRD-');

        return Product::query()->create(array_merge($payload, ['tenant_id' => $tenantId]));
    }

    public function updateProduct(int $tenantId, Product $product, array $payload): void
    {
        $this->assertTenantRecord($tenantId, (int) $product->tenant_id);
        $catalog = ServiceCatalog::query()->findOrFail($payload['service_catalog_id']);
        $this->assertTenantRecord($tenantId, (int) $catalog->tenant_id);
        $product->update($payload);
    }

    public function toggleProduct(int $tenantId, Product $product): void
    {
        $this->assertTenantRecord($tenantId, (int) $product->tenant_id);
        $product->update(['is_active' => ! $product->is_active]);
    }

    public function createPackage(int $tenantId, array $payload): Package
    {
        $product = Product::query()->findOrFail($payload['product_id']);
        $this->assertTenantRecord($tenantId, (int) $product->tenant_id);
        $payload['code'] = $payload['code'] ?? $this->generateCodeByPrefix(Package::class, $tenantId, 'PKG-');

        return Package::query()->create(array_merge($payload, ['tenant_id' => $tenantId]));
    }

    public function updatePackage(int $tenantId, Package $package, array $payload): void
    {
        $this->assertTenantRecord($tenantId, (int) $package->tenant_id);
        $product = Product::query()->findOrFail($payload['product_id']);
        $this->assertTenantRecord($tenantId, (int) $product->tenant_id);
        $package->update($payload);
    }

    public function togglePackage(int $tenantId, Package $package): void
    {
        $this->assertTenantRecord($tenantId, (int) $package->tenant_id);
        $package->update(['is_active' => ! $package->is_active]);
    }

    public function createPrice(int $tenantId, array $payload): Price
    {
        $package = Package::query()->findOrFail($payload['package_id']);
        $this->assertTenantRecord($tenantId, (int) $package->tenant_id);

        return Price::query()->create(array_merge($payload, ['tenant_id' => $tenantId]));
    }

    public function updatePrice(int $tenantId, Price $price, array $payload): void
    {
        $this->assertTenantRecord($tenantId, (int) $price->tenant_id);
        $package = Package::query()->findOrFail($payload['package_id']);
        $this->assertTenantRecord($tenantId, (int) $package->tenant_id);
        $price->update($payload);
    }

    public function togglePrice(int $tenantId, Price $price): void
    {
        $this->assertTenantRecord($tenantId, (int) $price->tenant_id);
        $price->update(['is_active' => ! $price->is_active]);
    }

    public function createDiscount(int $tenantId, array $payload): Discount
    {
        $package = Package::query()->findOrFail($payload['package_id']);
        $this->assertTenantRecord($tenantId, (int) $package->tenant_id);

        return Discount::query()->create(array_merge($payload, ['tenant_id' => $tenantId]));
    }

    public function updateDiscount(int $tenantId, Discount $discount, array $payload): void
    {
        $this->assertTenantRecord($tenantId, (int) $discount->tenant_id);
        $package = Package::query()->findOrFail($payload['package_id']);
        $this->assertTenantRecord($tenantId, (int) $package->tenant_id);
        $discount->update($payload);
    }

    public function toggleDiscount(int $tenantId, Discount $discount): void
    {
        $this->assertTenantRecord($tenantId, (int) $discount->tenant_id);
        $discount->update(['is_active' => ! $discount->is_active]);
    }

    public function createFaq(int $tenantId, array $payload): Faq
    {
        return Faq::query()->create(array_merge($payload, ['tenant_id' => $tenantId]));
    }

    public function updateFaq(int $tenantId, Faq $faq, array $payload): void
    {
        $this->assertTenantRecord($tenantId, (int) $faq->tenant_id);
        $faq->update($payload);
    }

    public function toggleFaq(int $tenantId, Faq $faq): void
    {
        $this->assertTenantRecord($tenantId, (int) $faq->tenant_id);
        $faq->update(['is_active' => ! $faq->is_active]);
    }

    public function upsertBookingSetting(int $tenantId, array $payload): BookingSetting
    {
        $existing = BookingSetting::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($existing) {
            $existing->update([
                'booking_url' => $payload['booking_url'],
                'is_active' => ($payload['is_active'] ?? true) === true,
                'active_from' => $payload['active_from'] ?? null,
                'active_until' => $payload['active_until'] ?? null,
            ]);

            return $existing->refresh();
        }

        return BookingSetting::query()->create([
            'tenant_id' => $tenantId,
            'booking_url' => $payload['booking_url'],
            'sort_order' => 0,
            'is_active' => ($payload['is_active'] ?? true) === true,
            'active_from' => $payload['active_from'] ?? null,
            'active_until' => $payload['active_until'] ?? null,
        ]);
    }

    public function upsertBusinessHoursPolicy(int $tenantId, array $payload): CalendarSetting
    {
        $timezone = $this->resolveTimezone(
            $payload['timezone'] ?? null,
            null
        );

        $setting = CalendarSetting::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'timezone' => $timezone,
                'slot_minutes' => 60,
                'is_active' => true,
                'rules' => [],
            ]
        );

        $timezone = $this->resolveTimezone(
            $payload['timezone'] ?? null,
            is_string($setting->timezone) ? $setting->timezone : null
        );

        $rules = is_array($setting->rules) ? $setting->rules : [];
        $rules['business_hours'] = [
            'enabled' => ($payload['enabled'] ?? false) === true,
            'timezone' => $timezone,
            'start_time' => (string) ($payload['start_time'] ?? '09:00'),
            'end_time' => (string) ($payload['end_time'] ?? '17:00'),
            'days' => array_values(array_unique(array_filter(array_map(
                static fn ($value): string => is_string($value) ? strtolower(trim($value)) : '',
                is_array($payload['days'] ?? null) ? $payload['days'] : []
            )))),
        ];

        $setting->update([
            'timezone' => $timezone,
            'rules' => $rules,
        ]);

        return $setting->refresh();
    }

    private function resolveTimezone(mixed $candidate, ?string $fallback): string
    {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }

        if (is_string($fallback) && trim($fallback) !== '') {
            return trim($fallback);
        }

        return self::DEFAULT_TIMEZONE;
    }

    private function assertTenantRecord(int $expectedTenantId, int $recordTenantId): void
    {
        if ($expectedTenantId !== $recordTenantId) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }
    }

    private function generateServiceCatalogCode(int $tenantId): string
    {
        return $this->generateCodeByPrefix(ServiceCatalog::class, $tenantId, 'CAT-');
    }

    private function generateCodeByPrefix(string $modelClass, int $tenantId, string $prefix): string
    {
        $codes = $modelClass::query()
            ->where('tenant_id', $tenantId)
            ->where('code', 'like', $prefix.'%')
            ->pluck('code');

        $maxNumber = 0;
        foreach ($codes as $code) {
            $pattern = '/^'.preg_quote($prefix, '/').'(\d{4})$/';
            if (preg_match($pattern, (string) $code, $matches) === 1) {
                $maxNumber = max($maxNumber, (int) $matches[1]);
            }
        }

        do {
            $maxNumber++;
            $candidate = $prefix.str_pad((string) $maxNumber, 4, '0', STR_PAD_LEFT);
        } while ($modelClass::query()->where('tenant_id', $tenantId)->where('code', $candidate)->exists());

        return $candidate;
    }
}
