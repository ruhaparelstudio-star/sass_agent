<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Contracts\WaGatewayClient;
use Illuminate\Support\Facades\Http;

class HttpWaGatewayClient implements WaGatewayClient
{
    public function sendOutbound(array $payload): array
    {
        $url = (string) config('whatsapp.gateway_outbound_url', '');
        if ($url === '') {
            throw new \RuntimeException('WhatsApp gateway outbound URL is not configured.');
        }

        $response = Http::timeout((int) config('whatsapp.gateway_timeout_seconds', 10))
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('WhatsApp gateway outbound request failed.');
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
