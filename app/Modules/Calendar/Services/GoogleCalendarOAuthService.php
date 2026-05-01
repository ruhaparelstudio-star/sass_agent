<?php

namespace App\Modules\Calendar\Services;

use RuntimeException;

class GoogleCalendarOAuthService
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE     = 'https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/userinfo.email';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {}

    public function getAuthorizationUrl(): string
    {
        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);

        return self::AUTH_URL.'?'.$params;
    }

    /**
     * @return array{access_token:string,refresh_token:?string,token_expiry:int,email:?string}
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $response = $this->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        $email = $this->fetchUserEmail($response['access_token']);

        return [
            'access_token'  => (string) $response['access_token'],
            'refresh_token' => isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            'token_expiry'  => time() + (int) ($response['expires_in'] ?? 3600),
            'email'         => $email,
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>  Updated config with new access_token and token_expiry
     */
    public function refreshAccessToken(array $config): array
    {
        $refreshToken = (string) ($config['refresh_token'] ?? '');
        if ($refreshToken === '') {
            throw new RuntimeException('No refresh token available.');
        }

        $response = $this->post(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'refresh_token',
        ]);

        return array_merge($config, [
            'access_token' => (string) $response['access_token'],
            'token_expiry' => time() + (int) ($response['expires_in'] ?? 3600),
        ]);
    }

    /**
     * Count events on a specific date in the given calendar.
     * Uses the Calendar Events API with day boundaries in tenant timezone.
     *
     * @param  array<string,mixed>  $config
     */
    public function countEventsOnDate(array $config, string $calendarId, string $dateIso, string $timezone = 'UTC'): int
    {
        $tz    = new \DateTimeZone($timezone);
        $utc   = new \DateTimeZone('UTC');
        $start = (new \DateTime("{$dateIso}T00:00:00", $tz))->setTimezone($utc);
        $end   = (new \DateTime("{$dateIso}T23:59:59", $tz))->setTimezone($utc);

        $url = 'https://www.googleapis.com/calendar/v3/calendars/'.urlencode($calendarId).'/events?'
            .http_build_query([
                'timeMin'      => $start->format('Y-m-d\TH:i:s\Z'),
                'timeMax'      => $end->format('Y-m-d\TH:i:s\Z'),
                'singleEvents' => 'true',
                'maxResults'   => 250,
            ]);

        $raw   = $this->getJson($url, (string) ($config['access_token'] ?? ''));
        $items = $raw['items'] ?? [];

        return is_array($items) ? count($items) : 0;
    }

    private function fetchUserEmail(string $accessToken): ?string
    {
        try {
            $raw = $this->getJsonRaw('https://www.googleapis.com/oauth2/v2/userinfo', $accessToken);
            if ($raw === false) {
                return null;
            }
            $data = json_decode($raw, true);

            return is_string($data['email'] ?? null) ? $data['email'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function getJson(string $url, string $accessToken): array
    {
        $raw = $this->getJsonRaw($url, $accessToken);
        if ($raw === false) {
            throw new RuntimeException('Google Calendar API request failed.');
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid JSON response from Google Calendar API.');
        }

        if (isset($data['error'])) {
            $msg = is_array($data['error']) ? ($data['error']['message'] ?? 'unknown') : (string) $data['error'];
            throw new RuntimeException('Google Calendar API error: '.$msg);
        }

        return $data;
    }

    private function getJsonRaw(string $url, string $accessToken): string|false
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer {$accessToken}\r\nAccept: application/json\r\n",
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        return @file_get_contents($url, false, $ctx);
    }

    /**
     * @param  array<string,string>  $fields
     * @return array<string,mixed>
     */
    private function post(string $url, array $fields): array
    {
        $body = http_build_query($fields);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n",
                'content'       => $body,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('Google OAuth request failed.');
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid JSON response from Google.');
        }

        if (isset($data['error'])) {
            throw new RuntimeException('Google OAuth error: '.($data['error_description'] ?? $data['error']));
        }

        return $data;
    }
}
