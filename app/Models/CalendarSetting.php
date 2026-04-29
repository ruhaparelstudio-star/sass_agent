<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'timezone',
        'slot_minutes',
        'is_active',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'slot_minutes' => 'int',
            'is_active' => 'bool',
            'rules' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
