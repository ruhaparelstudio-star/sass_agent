<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSendLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'invoice_id',
        'tenant_asset_id',
        'wa_outbound_message_id',
        'status',
        'failure_reason',
        'sent_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'wa_outbound_message_id' => 'int',
            'sent_at' => 'datetime',
            'meta' => 'array',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function tenantAsset(): BelongsTo
    {
        return $this->belongsTo(TenantAsset::class);
    }
}
