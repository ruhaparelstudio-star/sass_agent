<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CalendarConnection;
use App\Models\CalendarSetting;
use App\Modules\Calendar\Services\GoogleCalendarOAuthService;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantCalendarController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
    ) {}

    public function saveCredentials(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        $payload = $request->validate([
            'client_id'           => ['required', 'string', 'max:255'],
            'client_secret'       => ['nullable', 'string', 'max:255'],
            'max_events_per_date' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $setting = CalendarSetting::query()
            ->firstOrCreate(
                ['tenant_id' => $tenantId],
                ['timezone' => 'Asia/Jakarta', 'rules' => []]
            );

        $rules     = is_array($setting->rules) ? $setting->rules : [];
        $googleCfg = is_array($rules['google_calendar'] ?? null) ? $rules['google_calendar'] : [];

        $googleCfg['client_id']           = $payload['client_id'];
        $googleCfg['max_events_per_date'] = (int) $payload['max_events_per_date'];

        if (! empty($payload['client_secret'])) {
            $googleCfg['client_secret_encrypted'] = Crypt::encryptString($payload['client_secret']);
        }

        $rules['google_calendar'] = $googleCfg;
        $setting->rules           = $rules;
        $setting->save();

        return redirect('/tenant/business-data?step=calendar')
            ->with('success', 'Konfigurasi Google Calendar berhasil disimpan.');
    }

    public function redirectToGoogle(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        [$clientId, $clientSecret] = $this->resolveTenantGoogleCredentials($tenantId);

        if ($clientId === '' || $clientSecret === '') {
            return redirect('/tenant/business-data?step=calendar')
                ->with('error', 'Simpan Google Client ID dan Secret terlebih dahulu sebelum menghubungkan.');
        }

        $oauthService = $this->makeOAuthService($clientId, $clientSecret);

        return redirect($oauthService->getAuthorizationUrl());
    }

    public function handleCallback(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        $error = $request->query('error');
        if (is_string($error) && $error !== '') {
            return redirect('/tenant/business-data?step=calendar')
                ->with('error', 'Koneksi Google Calendar dibatalkan: '.$error);
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect('/tenant/business-data?step=calendar')
                ->with('error', 'Kode otorisasi tidak diterima dari Google.');
        }

        [$clientId, $clientSecret] = $this->resolveTenantGoogleCredentials($tenantId);

        if ($clientId === '' || $clientSecret === '') {
            return redirect('/tenant/business-data?step=calendar')
                ->with('error', 'Konfigurasi Google belum tersimpan. Simpan Client ID dan Secret terlebih dahulu.');
        }

        $oauthService = $this->makeOAuthService($clientId, $clientSecret);

        try {
            $tokens = $oauthService->exchangeCodeForTokens($code);
        } catch (\Throwable $e) {
            return redirect('/tenant/business-data?step=calendar')
                ->with('error', 'Gagal menghubungkan Google Calendar: '.$e->getMessage());
        }

        $config = [
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_expiry'  => $tokens['token_expiry'],
            'calendar_id'   => 'primary',
            'email'         => $tokens['email'],
        ];

        CalendarConnection::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'provider' => 'google'],
            [
                'status'          => 'connected',
                'is_enabled'      => true,
                'config'          => $config,
                'last_checked_at' => now(),
            ]
        );

        return redirect('/tenant/business-data?step=calendar')
            ->with('success', 'Google Calendar berhasil dihubungkan'.($tokens['email'] ? ' ('.$tokens['email'].')' : '').'.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        CalendarConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', 'google')
            ->update([
                'status'     => 'disconnected',
                'is_enabled' => false,
                'config'     => null,
            ]);

        return redirect('/tenant/business-data?step=calendar')
            ->with('success', 'Google Calendar berhasil diputus.');
    }

    public function toggle(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        $connection = CalendarConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', 'google')
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            return redirect('/tenant/business-data?step=calendar')
                ->with('error', 'Tidak ada koneksi Google Calendar aktif.');
        }

        $connection->is_enabled = ! $connection->is_enabled;
        $connection->save();

        $label = $connection->is_enabled ? 'diaktifkan' : 'dinonaktifkan';

        return redirect('/tenant/business-data?step=calendar')
            ->with('success', "Google Calendar berhasil {$label}.");
    }

    /**
     * Resolve per-tenant Google OAuth credentials from CalendarSetting.
     *
     * @return array{0:string,1:string}  [clientId, clientSecret]
     */
    private function resolveTenantGoogleCredentials(int $tenantId): array
    {
        $setting = CalendarSetting::query()->where('tenant_id', $tenantId)->first();
        $rules   = is_array($setting?->rules) ? $setting->rules : [];
        $cfg     = is_array($rules['google_calendar'] ?? null) ? $rules['google_calendar'] : [];

        $clientId        = (string) ($cfg['client_id'] ?? '');
        $secretEncrypted = (string) ($cfg['client_secret_encrypted'] ?? '');

        if ($clientId === '' || $secretEncrypted === '') {
            return ['', ''];
        }

        try {
            $clientSecret = Crypt::decryptString($secretEncrypted);
        } catch (\Throwable) {
            return [$clientId, ''];
        }

        return [$clientId, $clientSecret];
    }

    private function makeOAuthService(string $clientId, string $clientSecret): GoogleCalendarOAuthService
    {
        return new GoogleCalendarOAuthService(
            $clientId,
            $clientSecret,
            rtrim((string) config('app.url'), '/').'/tenant/calendar/callback',
        );
    }

    private function resolveAuthorizedTenantId(Request $request): int
    {
        $user = $request->user();
        if ($user->role !== UserRole::TenantAdmin) {
            throw new HttpException(403, 'Forbidden role.');
        }

        $context = $this->tenantContextResolver->resolve($user);
        if (! is_int($context->tenantId)) {
            throw new HttpException(403, 'Tenant context unavailable.');
        }

        return $context->tenantId;
    }
}
