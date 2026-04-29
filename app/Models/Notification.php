<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'handoff_id',
        'type',
        'channel',
        'status',
        'payload',
        'sent_at',
        'failed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
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

    public function handoff(): BelongsTo
    {
        return $this->belongsTo(Handoff::class);
    }
}
