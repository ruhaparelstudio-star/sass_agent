<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'action',
        'status',
        'reason',
        'payload',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
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
}
