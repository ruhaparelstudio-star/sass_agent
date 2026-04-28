<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaMessageDeliveryLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'wa_outbound_message_id',
        'attempt_number',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'attempted_at' => 'immutable_datetime',
        ];
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(WaOutboundMessage::class, 'wa_outbound_message_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
