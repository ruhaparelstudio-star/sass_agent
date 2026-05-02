<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CalendarConnection;
use App\Models\CalendarSetting;
use App\Modules\Calendar\Exceptions\GoogleCalendarConfigException;
use App\Modules\Calendar\Services\GoogleCalendarOAuthService;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantCalendarController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
    ) {}

    public function saveSettings(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        $payload = $request->validate([
            'max_events_per_date' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $setting = CalendarSetting::query()
            ->firstOrCreate(
                ['tenant_id' => $tenantId],
                ['timezone' => 'Asia/Jakarta', 'rules' => []]
            );

        $rules     = is_array($setting->rules) ? $setting->rules : [];
        $googleCfg = is_array($rules['google_calendar'] ?? null) ? $rules['google_calendar'] : [];

        $googleCfg['max_events_per_date'] = (int) $payload['max_events_per_date'];

        // Remove any tenant-level OAuth credentials that may exist from the old flow.
        unset($googleCfg['client_id'], $googleCfg['client_secret_encrypted']);

        $rules['google_calendar'] = $googleCfg;
        $setting->rules           = $rules;
        $setting->save();

        return redirect('/tenant/business-data?step=calendar')
            ->with('success', 'Pengaturan Google Calendar berhasil disimpan.');
    }

    public function redirectToGoogle(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        $oauthService = new GoogleCalendarOAuthService();

        try {
            $state = $this->generateAndStoreOAuthState($request, $tenantId);
            $url   = $oauthService->getAuthorizationUrl($state);
        } catch (GoogleCalendarConfigException) {
            return redirect('/tenant/business-data?step=calendar')
                ->with('error', 'Google Calendar belum dikonfigurasi oleh platform. Hubungi administrator.');
        }

        return redirect($url);
    }

    public function handleCallback(Request $request): RedirectResponse
    {
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

        $state    = (string) ($request->query('state') ?? '');
        $tenantId = $this->validateOAuthStateAndResolveTenant($request, $state);

        if ($tenantId === null) {
            return redirect('/tenant/business-data?step=calendar')
                ->with('error', 'Sesi OAuth tidak valid. Silakan coba lagi.');
        }

        $oauthService = new GoogleCalendarOAuthService();

        try {
            $tokens = $oauthService->exchangeCodeForTokens($code);
        } catch (GoogleCalendarConfigException) {
            return redirect('/tenant/business-data?step=calendar')
                ->with('error', 'Google Calendar belum dikonfigurasi oleh platform. Hubungi administrator.');
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

    private function generateAndStoreOAuthState(Request $request, int $tenantId): string
    {
        $nonce = Str::random(32);
        $request->session()->put('google_oauth_state', $nonce);
        $request->session()->put('google_oauth_tenant_id', $tenantId);

        return $nonce;
    }

    /**
     * Validate state nonce and resolve tenant ID from session.
     * Falls back to resolving from authenticated session if state matches.
     */
    private function validateOAuthStateAndResolveTenant(Request $request, string $state): ?int
    {
        $storedNonce    = (string) $request->session()->pull('google_oauth_state', '');
        $storedTenantId = $request->session()->pull('google_oauth_tenant_id', null);

        if ($storedNonce === '' || $state !== $storedNonce) {
            return null;
        }

        return is_int($storedTenantId) ? $storedTenantId : null;
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
