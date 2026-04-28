<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationState extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'current_stage',
        'active_goal',
        'agent_mode',
        'memory_mode',
        'retention_policy',
        'retention_until',
    ];

    protected function casts(): array
    {
        return [
            'retention_until' => 'datetime',
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
