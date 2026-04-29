<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSummary extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'message_count',
        'summary',
        'retention_until',
        'summarized_at',
    ];

    protected function casts(): array
    {
        return [
            'retention_until' => 'datetime',
            'summarized_at' => 'datetime',
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
