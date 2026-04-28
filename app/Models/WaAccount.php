<?php

namespace App\Models;

use App\Enums\WaAccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaAccount extends Model
{
    protected $fillable = [
        'tenant_id',
        'provider',
        'provider_ref',
        'phone',
        'status',
        'meta',
        'last_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => WaAccountStatus::class,
            'meta' => 'array',
            'last_payload' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WaSession::class);
    }
}
