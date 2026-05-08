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
        'customer_name',
        'event_type',
        'service_interest',
        'package_interest',
        'selected_package',
        'event_date_iso',
        'event_date_raw',
        'location',
        'budget_min',
        'budget_max',
        'pending_action',
        'agent_mode',
        'memory_mode',
        'retention_policy',
        'retention_until',
    ];

    protected function casts(): array
    {
        return [
            'retention_until' => 'datetime',
            'pending_action' => 'array',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
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
