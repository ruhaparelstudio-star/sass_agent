<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')->withTimestamps();
    }

    public function waAccounts(): HasMany
    {
        return $this->hasMany(WaAccount::class);
    }

    public function waSessions(): HasMany
    {
        return $this->hasMany(WaSession::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function leadProfiles(): HasMany
    {
        return $this->hasMany(LeadProfile::class);
    }

    public function leadScores(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }

    public function leadSources(): HasMany
    {
        return $this->hasMany(LeadSource::class);
    }

    public function serviceCatalogs(): HasMany
    {
        return $this->hasMany(ServiceCatalog::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class);
    }

    public function bookingSettings(): HasMany
    {
        return $this->hasMany(BookingSetting::class);
    }

    public function tenantAssets(): HasMany
    {
        return $this->hasMany(TenantAsset::class);
    }

    public function calendarConnections(): HasMany
    {
        return $this->hasMany(CalendarConnection::class);
    }

    public function calendarSettings(): HasMany
    {
        return $this->hasMany(CalendarSetting::class);
    }

    public function calendarAvailabilityChecks(): HasMany
    {
        return $this->hasMany(CalendarAvailabilityCheck::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function invoiceSendLogs(): HasMany
    {
        return $this->hasMany(InvoiceSendLog::class);
    }
}
