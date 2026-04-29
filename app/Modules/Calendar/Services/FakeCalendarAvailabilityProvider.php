<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Contracts\CalendarAvailabilityProvider;

class FakeCalendarAvailabilityProvider implements CalendarAvailabilityProvider
{
    public function checkAvailability(int $tenantId, array $request): array
    {
        return [
            'status' => 'available',
            'checked' => true,
            'available' => true,
            'reason' => null,
            'source' => 'fake_provider',
            'meta' => [
                'tenant_id' => $tenantId,
                'echo_event_date_iso' => $request['event_date_iso'] ?? null,
            ],
        ];
    }
}
