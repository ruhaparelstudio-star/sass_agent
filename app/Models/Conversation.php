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
        'customer_phone',
        'status',
    ];

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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
