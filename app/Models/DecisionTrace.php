<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionTrace extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'message_id',
        'action_log_id',
        'trace_key',
        'token_usage_total',
        'input_snapshot_json',
        'interpretation_json',
        'decision_json',
        'validators_json',
        'blocked_actions_json',
        'grounding_refs_json',
        'final_reply',
        'model_name',
        'token_usage_json',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'token_usage_total' => 'int',
            'input_snapshot_json' => 'array',
            'interpretation_json' => 'array',
            'decision_json' => 'array',
            'validators_json' => 'array',
            'blocked_actions_json' => 'array',
            'grounding_refs_json' => 'array',
            'token_usage_json' => 'array',
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
