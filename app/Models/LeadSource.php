<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadSource extends Model
{
    protected $fillable = [
        'tenant_id',
        'lead_profile_id',
        'source',
        'first_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function leadProfile(): BelongsTo
    {
        return $this->belongsTo(LeadProfile::class);
    }
}
