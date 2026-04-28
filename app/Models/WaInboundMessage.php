<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaInboundMessage extends Model
{
    protected $fillable = [
        'tenant_id',
        'wa_account_id',
        'wa_session_id',
        'provider',
        'provider_message_id',
        'from',
        'to',
        'message_type',
        'message_timestamp',
        'payload',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'message_timestamp' => 'immutable_datetime',
            'payload' => 'array',
            'meta' => 'array',
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
}
