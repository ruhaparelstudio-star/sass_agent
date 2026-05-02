<?php

namespace App\Modules\Calendar\Services;

use App\Models\CalendarConnection;
use App\Models\CalendarSetting;
use App\Modules\Calendar\Contracts\CalendarAvailabilityProvider;

class GoogleCalendarProvider implements CalendarAvailabilityProvider
{
    /**
     * @param  array<string,mixed>  $request
     * @return array{status:string,checked:bool,available:bool,reason:?string,source:string,meta?:array<string,mixed>}
     */
    public function checkAvailability(int $tenantId, array $request): array
    {
        $connection = CalendarConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', 'google')
            ->where('is_enabled', true)
            ->where('status', 'connected')
            ->latest()
            ->first();

        if (! $connection) {
            return [
                'status'    => 'blocked',
                'checked'   => false,
                'available' => false,
                'reason'    => 'calendar_integration_disabled',
                'source'    => 'google_provider',
            ];
        }

        $setting   = CalendarSetting::query()->where('tenant_id', $tenantId)->first();
        $rules     = is_array($setting?->rules) ? $setting->rules : [];
        $googleCfg = is_array($rules['google_calendar'] ?? null) ? $rules['google_calendar'] : [];

        $maxEvents    = max(1, (int) ($googleCfg['max_events_per_date'] ?? 1));
        $timezone     = (string) ($setting?->timezone ?? 'Asia/Jakarta');
        $oauthService = new GoogleCalendarOAuthService();

        try {
            $config = $this->ensureFreshToken($connection, $oauthService);

            $calendarId = (string) ($config['calendar_id'] ?? 'primary');
            if ($calendarId === '') {
                $calendarId = 'primary';
            }

            $dateIso = $this->resolveDate($request);

            if ($dateIso === null) {
                return [
                    'status'    => 'available',
                    'checked'   => true,
                    'available' => true,
                    'reason'    => null,
                    'source'    => 'google_calendar',
                    'meta'      => ['note' => 'no_date_provided', 'calendar_id' => $calendarId],
                ];
            }

            $eventCount = $oauthService->countEventsOnDate($config, $calendarId, $dateIso, $timezone);
            $available  = $eventCount < $maxEvents;

            return [
                'status'    => $available ? 'available' : 'unavailable',
                'checked'   => true,
                'available' => $available,
                'reason'    => $available ? null : 'date_unavailable',
                'source'    => 'google_calendar',
                'meta'      => [
                    'calendar_id'  => $calendarId,
                    'date'         => $dateIso,
                    'event_count'  => $eventCount,
                    'max_events'   => $maxEvents,
                    'email'        => $config['email'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status'    => 'blocked',
                'checked'   => true,
                'available' => false,
                'reason'    => 'calendar_provider_error',
                'source'    => 'google_calendar',
                'meta'      => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Refresh token if expired and persist updated config.
     *
     * @return array<string,mixed>
     */
    private function ensureFreshToken(CalendarConnection $connection, GoogleCalendarOAuthService $oauthService): array
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $expiry = (int) ($config['token_expiry'] ?? 0);

        if ($expiry > 0 && time() < ($expiry - 60)) {
            return $config;
        }

        $config                      = $oauthService->refreshAccessToken($config);
        $connection->config          = $config;
        $connection->last_checked_at = now();
        $connection->save();

        return $config;
    }

    /**
     * Extract ISO date from request. Tries event_date_iso first, then parses message_hint.
     *
     * @param  array<string,mixed>  $request
     */
    private function resolveDate(array $request): ?string
    {
        $dateIso = is_string($request['event_date_iso'] ?? null)
            ? trim($request['event_date_iso'])
            : null;

        if ($dateIso !== '' && $dateIso !== null) {
            return $dateIso;
        }

        $hint = is_string($request['message_hint'] ?? null) ? $request['message_hint'] : '';
        if ($hint === '') {
            return null;
        }

        return $this->parseDateFromHint($hint);
    }

    /**
     * Very simple month + day extractor from Indonesian text.
     * Returns YYYY-MM-DD or null.
     */
    private function parseDateFromHint(string $hint): ?string
    {
        $months = [
            'januari' => '01', 'februari' => '02', 'maret' => '03',
            'april' => '04', 'mei' => '05', 'juni' => '06',
            'juli' => '07', 'agustus' => '08', 'september' => '09',
            'oktober' => '10', 'november' => '11', 'desember' => '12',
            'january' => '01', 'february' => '02', 'march' => '03',
            'may' => '05', 'june' => '06', 'july' => '07',
            'august' => '08', 'october' => '10', 'december' => '12',
        ];

        $lower = mb_strtolower($hint);

        foreach ($months as $name => $num) {
            if (str_contains($lower, $name)) {
                if (preg_match('/(\d{1,2})\s+'.preg_quote($name, '/').'|'.preg_quote($name, '/').'\s+(\d{1,2})/', $lower, $m)) {
                    $day = (int) ($m[1] !== '' ? $m[1] : $m[2]);
                    if ($day >= 1 && $day <= 31) {
                        $year     = (int) date('Y');
                        $monthInt = (int) $num;
                        if (checkdate($monthInt, $day, $year)) {
                            return sprintf('%04d-%02d-%02d', $year, $monthInt, $day);
                        }
                    }
                }
            }
        }

        return null;
    }
}
