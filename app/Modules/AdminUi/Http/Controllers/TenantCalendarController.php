<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CalendarConnection;
use App\Modules\Calendar\Services\GoogleCalendarOAuthService;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantCalendarController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
        private readonly GoogleCalendarOAuthService $oauthService,
    ) {}

    public function redirectToGoogle(Request $request): RedirectResponse
    {
        $this->resolveAuthorizedTenantId($request);

        $clientId = (string) config('services.google.calendar.client_id', '');
        if ($clientId === '') {
            return redirect('/tenant/business-data?step=calendar')
                ->with('error', 'Google Calendar belum dikonfigurasi oleh administrator sistem.');
        }

        return redirect($this->oauthService->getAuthorizationUrl());
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

        try {
            $tokens = $this->oauthService->exchangeCodeForTokens($code);
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
