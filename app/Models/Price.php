<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends Model
{
    protected $fillable = [
        'tenant_id',
        'package_id',
        'label',
        'currency',
        'amount',
        'sort_order',
        'is_active',
        'active_from',
        'active_until',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'int',
            'is_active' => 'bool',
            'active_from' => 'datetime',
            'active_until' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
