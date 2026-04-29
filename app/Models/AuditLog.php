<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'event_key',
        'tenant_id',
        'actor_user_id',
        'endpoint',
        'status_code',
        'reason',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'int',
            'actor_user_id' => 'int',
            'status_code' => 'int',
            'context' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
