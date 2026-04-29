<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarAvailabilityCheck extends Model
{
    protected $fillable = [
        'tenant_id',
        'calendar_connection_id',
        'status',
        'checked',
        'available',
        'reason',
        'source',
        'request_payload',
        'response_payload',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked' => 'bool',
            'available' => 'bool',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function calendarConnection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class);
    }
}
