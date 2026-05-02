<?php

namespace App\Modules\AdminUi\Services;

use App\Models\Discount;
use App\Models\Faq;
use App\Models\Package;
use App\Models\Price;
use App\Models\Product;
use App\Models\ServiceCatalog;
use App\Models\BookingSetting;
use App\Models\CalendarConnection;
use App\Models\CalendarSetting;

class TenantBusinessDataQueryService
{
    private const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    /**
     * @return array<string,mixed>
     */
    public function getPageData(int $tenantId): array
    {
        $calendarSetting = CalendarSetting::query()
            ->where('tenant_id', $tenantId)
            ->first();
        $businessHours = $this->resolveBusinessHoursPolicy($calendarSetting);

        return [
            'serviceCatalogs' => ServiceCatalog::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'products' => Product::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'service_catalog_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'packages' => Package::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'product_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'prices' => Price::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'package_id', 'label', 'currency', 'amount', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'discounts' => Discount::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'package_id', 'name', 'discount_type', 'value', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'faqs' => Faq::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'question', 'answer', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'bookingSettings' => BookingSetting::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'booking_url', 'sort_order', 'is_active', 'active_from', 'active_until'])
                ->toArray(),
            'businessHoursPolicy' => $businessHours,
            'calendarConnection'  => $this->resolveCalendarConnection($tenantId),
            'calendarSettings'    => $this->resolveCalendarSettings($tenantId),
        ];
    }

    /**
     * @return array{status:string,is_enabled:bool,email:?string,calendar_id:string}
     */
    private function resolveCalendarConnection(int $tenantId): array
    {
        $conn = CalendarConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', 'google')
            ->latest()
            ->first();

        if (! $conn) {
            return [
                'status'      => 'disconnected',
                'is_enabled'  => false,
                'email'       => null,
                'calendar_id' => 'primary',
            ];
        }

        $config = is_array($conn->config) ? $conn->config : [];

        return [
            'status'      => (string) $conn->status,
            'is_enabled'  => (bool) $conn->is_enabled,
            'email'       => is_string($config['email'] ?? null) ? $config['email'] : null,
            'calendar_id' => is_string($config['calendar_id'] ?? null) && $config['calendar_id'] !== ''
                ? $config['calendar_id']
                : 'primary',
        ];
    }

    private function resolveCalendarSettings(int $tenantId): array
    {
        $setting   = CalendarSetting::query()->where('tenant_id', $tenantId)->first();
        $rules     = is_array($setting?->rules) ? $setting->rules : [];
        $googleCfg = is_array($rules['google_calendar'] ?? null) ? $rules['google_calendar'] : [];

        return [
            'max_events_per_date' => (int) ($googleCfg['max_events_per_date'] ?? 1),
        ];
    }

    private function resolveBusinessHoursPolicy(?CalendarSetting $calendarSetting): array
    {
        $rules = is_array($calendarSetting?->rules) ? $calendarSetting->rules : [];
        $policy = is_array($rules['business_hours'] ?? null) ? $rules['business_hours'] : [];

        $days = $policy['days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri'];
        if (! is_array($days)) {
            $days = ['mon', 'tue', 'wed', 'thu', 'fri'];
        }

        return [
            'enabled' => ($policy['enabled'] ?? false) === true,
            'timezone' => (string) ($policy['timezone'] ?? $calendarSetting?->timezone ?? self::DEFAULT_TIMEZONE),
            'start_time' => (string) ($policy['start_time'] ?? '09:00'),
            'end_time' => (string) ($policy['end_time'] ?? '17:00'),
            'days' => array_values(array_unique(array_filter(array_map(
                static fn ($value): string => is_string($value) ? strtolower(trim($value)) : '',
                $days
            )))),
        ];
    }
}
