<?php

namespace App\Modules\Calendar\Contracts;

interface CalendarAvailabilityProvider
{
    /**
     * @param  array<string,mixed>  $request
     * @return array{status:string,checked:bool,available:bool,reason:?string,source:string,meta?:array<string,mixed>}
     */
    public function checkAvailability(int $tenantId, array $request): array;
}
