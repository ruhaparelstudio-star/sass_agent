<?php

namespace App\Modules\WhatsApp\Contracts;

interface WaGatewayClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendOutbound(array $payload): array;
}
