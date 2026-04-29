<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarConnection extends Model
{
    protected $fillable = [
        'tenant_id',
        'provider',
        'status',
        'is_enabled',
        'config',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'bool',
            'config' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function availabilityChecks(): HasMany
    {
        return $this->hasMany(CalendarAvailabilityCheck::class);
    }
}
