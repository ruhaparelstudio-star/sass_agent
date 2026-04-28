<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeadProfile extends Model
{
    protected $fillable = [
        'tenant_id',
        'customer_phone',
        'full_name',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function score(): HasOne
    {
        return $this->hasOne(LeadScore::class);
    }

    public function source(): HasOne
    {
        return $this->hasOne(LeadSource::class);
    }
}
