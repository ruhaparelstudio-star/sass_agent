<?php

namespace App\Modules\Calendar\Services;

use RuntimeException;

class GoogleCalendarOAuthService
{
    private const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const FREEBUSY_URL = 'https://www.googleapis.com/calendar/v3/freeBusy';
    private const SCOPE       = 'https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/userinfo.email';

    public function getAuthorizationUrl(): string
    {
        $params = http_build_query([
            'client_id'     => (string) config('services.google.calendar.client_id'),
            'redirect_uri'  => $this->redirectUri(),
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
            'client_id'     => (string) config('services.google.calendar.client_id'),
            'client_secret' => (string) config('services.google.calendar.client_secret'),
            'redirect_uri'  => $this->redirectUri(),
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
            'client_id'     => (string) config('services.google.calendar.client_id'),
            'client_secret' => (string) config('services.google.calendar.client_secret'),
            'grant_type'    => 'refresh_token',
        ]);

        return array_merge($config, [
            'access_token' => (string) $response['access_token'],
            'token_expiry' => time() + (int) ($response['expires_in'] ?? 3600),
        ]);
    }

    /**
     * Check freebusy for a calendar on a given date.
     * Returns true if the date has no busy intervals (available).
     *
     * @param  array<string,mixed>  $config
     */
    public function checkFreeBusy(array $config, string $calendarId, string $dateIso): bool
    {
        $accessToken = (string) ($config['access_token'] ?? '');

        $timeMin = $dateIso.'T00:00:00Z';
        $timeMax = $dateIso.'T23:59:59Z';

        $body = json_encode([
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'items'   => [['id' => $calendarId]],
        ]);

        $responseBody = $this->postJson(self::FREEBUSY_URL, $body, $accessToken);

        $busy = $responseBody['calendars'][$calendarId]['busy'] ?? [];

        return $busy === [];
    }

    private function fetchUserEmail(string $accessToken): ?string
    {
        try {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Authorization: Bearer {$accessToken}\r\nAccept: application/json\r\n",
                    'timeout' => 10,
                ],
            ]);
            $raw = @file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, $ctx);
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
     * @param  array<string,string>  $fields
     * @return array<string,mixed>
     */
    private function post(string $url, array $fields): array
    {
        $body = http_build_query($fields);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n",
                'content' => $body,
                'timeout' => 15,
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

    /**
     * @return array<string,mixed>
     */
    private function postJson(string $url, string $body, string $accessToken): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAuthorization: Bearer {$accessToken}\r\nContent-Length: ".strlen($body)."\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
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

    private function redirectUri(): string
    {
        $configured = (string) config('services.google.calendar.redirect_uri', '');
        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/tenant/calendar/callback';
    }
}
