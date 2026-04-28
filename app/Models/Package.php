<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'tenant_id',
        'product_id',
        'code',
        'name',
        'description',
        'sort_order',
        'is_active',
        'active_from',
        'active_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'active_from' => 'datetime',
            'active_until' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
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

    public function aliases(): HasMany
    {
        return $this->hasMany(PackageAlias::class);
    }
}
