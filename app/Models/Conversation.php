<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = [
        'tenant_id',
        'wa_account_id',
        'customer_phone',
        'status',
        'current_stage',
        'active_goal',
        'agent_mode',
        'memory_mode',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function handoffs(): HasMany
    {
        return $this->hasMany(Handoff::class);
    }

    public function state(): HasOne
    {
        return $this->hasOne(ConversationState::class);
    }

    public function contexts(): HasMany
    {
        return $this->hasMany(ConversationContext::class);
    }

    public function summary(): HasOne
    {
        return $this->hasOne(ConversationSummary::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
