<?php

use App\Models\CalendarSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const TENANT_ID = 1;
    private const TARGET_TIMEZONE = 'Asia/Jakarta';

    public function up(): void
    {
        $setting = CalendarSetting::query()
            ->where('tenant_id', self::TENANT_ID)
            ->first();

        if (! $setting) {
            return;
        }

        $rules = is_array($setting->rules) ? $setting->rules : [];
        $businessHours = is_array($rules['business_hours'] ?? null) ? $rules['business_hours'] : [];
        $businessHours['timezone'] = self::TARGET_TIMEZONE;
        $rules['business_hours'] = $businessHours;

        $setting->update([
            'timezone' => self::TARGET_TIMEZONE,
            'rules' => $rules,
        ]);
    }

    public function down(): void
    {
        $setting = CalendarSetting::query()
            ->where('tenant_id', self::TENANT_ID)
            ->first();

        if (! $setting) {
            return;
        }

        $rules = is_array($setting->rules) ? $setting->rules : [];
        $businessHours = is_array($rules['business_hours'] ?? null) ? $rules['business_hours'] : [];
        $businessHours['timezone'] = 'UTC+7';
        $rules['business_hours'] = $businessHours;

        $setting->update([
            'timezone' => 'UTC+7',
            'rules' => $rules,
        ]);
    }
};
