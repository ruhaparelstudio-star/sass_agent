<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingDateCapacity extends Model
{
    protected $table = 'booking_date_capacity';

    protected $fillable = ['tenant_id', 'booking_date', 'used_count'];

    protected $casts = [
        'used_count' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function getUsedCount(int $tenantId, string $bookingDate): int
    {
        $row = static::query()
            ->where('tenant_id', $tenantId)
            ->where('booking_date', $bookingDate)
            ->first(['used_count']);

        return $row ? (int) $row->used_count : 0;
    }

    public static function incrementForTenantDate(int $tenantId, string $bookingDate): void
    {
        $updated = static::query()
            ->where('tenant_id', $tenantId)
            ->where('booking_date', $bookingDate)
            ->increment('used_count');

        if ($updated === 0) {
            try {
                static::query()->create([
                    'tenant_id'    => $tenantId,
                    'booking_date' => $bookingDate,
                    'used_count'   => 1,
                ]);
            } catch (\Throwable) {
                static::query()
                    ->where('tenant_id', $tenantId)
                    ->where('booking_date', $bookingDate)
                    ->increment('used_count');
            }
        }
    }
}
