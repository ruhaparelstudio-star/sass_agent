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
        'message_type',
        'body',
        'content',
        'raw_payload',
        'grounding_refs',
        'decision_trace_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'raw_payload' => 'array',
            'grounding_refs' => 'array',
            'meta' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getBodyAttribute(): string
    {
        $raw = $this->attributes['body'] ?? null;

        if (is_string($raw) && trim($raw) !== '') {
            return $raw;
        }

        return (string) $this->content;
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
