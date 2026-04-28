<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Faq extends Model
{
    protected $fillable = [
        'tenant_id',
        'question',
        'answer',
        'sort_order',
        'is_active',
        'active_from',
        'active_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'active_from' => 'datetime',
            'active_until' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
