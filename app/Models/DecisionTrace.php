<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionTrace extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'action_log_id',
        'trace_key',
        'token_usage_total',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'token_usage_total' => 'int',
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

    public function actionLog(): BelongsTo
    {
        return $this->belongsTo(ActionLog::class);
    }
}
