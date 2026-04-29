<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'tenant_asset_id',
        'customer_phone',
        'status',
        'issued_at',
        'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function tenantAsset(): BelongsTo
    {
        return $this->belongsTo(TenantAsset::class);
    }

    public function sendLogs(): HasMany
    {
        return $this->hasMany(InvoiceSendLog::class);
    }
}
