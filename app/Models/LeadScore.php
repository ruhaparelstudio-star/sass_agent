<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadScore extends Model
{
    protected $fillable = [
        'tenant_id',
        'lead_profile_id',
        'score_value',
        'score_label',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function leadProfile(): BelongsTo
    {
        return $this->belongsTo(LeadProfile::class);
    }
}
