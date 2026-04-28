<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeature extends Model
{
    protected $fillable = [
        'plan_id',
        'code',
        'name',
        'value_string',
        'value_int',
        'value_bool',
    ];

    protected function casts(): array
    {
        return [
            'value_bool' => 'bool',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}

