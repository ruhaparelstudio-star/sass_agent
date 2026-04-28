<?php

namespace App\Models;

use App\Enums\WaSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaSession extends Model
{
    protected $fillable = [
        'tenant_id',
        'wa_account_id',
        'provider',
        'provider_ref',
        'status',
        'meta',
        'last_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => WaSessionStatus::class,
            'meta' => 'array',
            'last_payload' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WaAccount::class, 'wa_account_id');
    }
}
