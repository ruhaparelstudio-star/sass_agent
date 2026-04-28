<?php

namespace App\Models;

use App\Enums\MessageDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'direction',
        'content',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'meta' => 'array',
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
