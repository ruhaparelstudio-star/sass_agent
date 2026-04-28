<?php

namespace App\Models;

use App\Enums\WaOutboundMessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaOutboundMessage extends Model
{
    protected $fillable = [
        'tenant_id',
        'wa_account_id',
        'wa_session_id',
        'provider',
        'provider_message_id',
        'to',
        'message_type',
        'status',
        'payload',
        'meta',
        'queued_at',
        'sent_at',
        'failed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => WaOutboundMessageStatus::class,
            'payload' => 'array',
            'meta' => 'array',
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
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

    public function session(): BelongsTo
    {
        return $this->belongsTo(WaSession::class, 'wa_session_id');
    }

    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(WaMessageDeliveryLog::class);
    }
}
