<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarConnection;
use App\Models\CalendarSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Regression tests proving that Google Calendar OAuth now uses app-level
 * credentials from config/services.php, not per-tenant credentials.
 */
class GoogleCalendarOAuthRefactorTest extends TestCase
{
    use RefreshDatabase;

    // ─── Test 1: Connect URL uses app-level Google credentials ────────────────

    public function test_connect_url_uses_app_level_google_client_id(): void
    {
        Config::set('services.google.client_id', 'app-level-client-id-12345');
        Config::set('services.google.client_secret', 'app-level-secret');
        Config::set('services.google.redirect_uri', 'http://localhost/tenant/calendar/callback');

        [$tenant, $admin] = $this->createTenantAdmin('vendor-abc');

        $response = $this->actingAs($admin)->get('/tenant/calendar/connect');

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');

        $this->assertStringContainsString('app-level-client-id-12345', $location,
            'OAuth redirect URL must contain app-level client_id.'
        );
        $this->assertStringContainsString('accounts.google.com', $location,
            'OAuth redirect must point to Google authorization endpoint.'
        );
    }

    // ─── Test 2: Tenant client credentials are not required ───────────────────

    public function test_tenant_can_initiate_connect_without_any_calendar_settings(): void
    {
        Config::set('services.google.client_id', 'app-client-id');
        Config::set('services.google.client_secret', 'app-secret');
        Config::set('services.google.redirect_uri', 'http://localhost/tenant/calendar/callback');

        [$tenant, $admin] = $this->createTenantAdmin('vendor-no-creds');

        // Tenant has no CalendarSetting row at all.
        $this->assertDatabaseMissing('calendar_settings', ['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->get('/tenant/calendar/connect');

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location', ''));
    }

    // ─── Test 3: Tenant client credentials are ignored ────────────────────────

    public function test_connect_url_ignores_old_tenant_client_id_in_database(): void
    {
        Config::set('services.google.client_id', 'correct-app-client-id');
        Config::set('services.google.client_secret', 'correct-app-secret');
        Config::set('services.google.redirect_uri', 'http://localhost/tenant/calendar/callback');

        [$tenant, $admin] = $this->createTenantAdmin('vendor-with-old-creds');

        // Simulate old tenant-level credentials still present in DB.
        CalendarSetting::query()->create([
            'tenant_id'    => $tenant->id,
            'timezone'     => 'Asia/Jakarta',
            'is_active'    => true,
            'rules'        => [
                'google_calendar' => [
                    'client_id'              => 'tenant-old-client-id-should-be-ignored',
                    'client_secret_encrypted' => 'encrypted-old-secret-should-be-ignored',
                    'max_events_per_date'    => 3,
                ],
            ],
        ]);

        $response = $this->actingAs($admin)->get('/tenant/calendar/connect');

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');

        $this->assertStringContainsString('correct-app-client-id', $location,
            'OAuth URL must use app-level client_id, not the tenant database value.'
        );
        $this->assertStringNotContainsString('tenant-old-client-id-should-be-ignored', $location,
            'OAuth URL must NOT contain old tenant client_id.'
        );
    }

    // ─── Test 4: Business data API does not expose credentials ────────────────

    public function test_business_data_page_does_not_expose_client_id_or_secrets(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('vendor-secret-check');

        CalendarConnection::query()->create([
            'tenant_id'  => $tenant->id,
            'provider'   => 'google',
            'status'     => 'connected',
            'is_enabled' => true,
            'config'     => [
                'access_token'  => 'real-access-token-must-not-leak',
                'refresh_token' => 'real-refresh-token-must-not-leak',
                'token_expiry'  => time() + 3600,
                'calendar_id'   => 'primary',
                'email'         => 'vendor@gmail.com',
            ],
        ]);

        CalendarSetting::query()->create([
            'tenant_id' => $tenant->id,
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
            'rules'     => [
                'google_calendar' => [
                    'client_id'              => 'should-not-appear',
                    'client_secret_encrypted' => 'encrypted-secret-should-not-appear',
                    'max_events_per_date'    => 5,
                ],
            ],
        ]);

        $response = $this->actingAs($admin)->get('/tenant/business-data?step=calendar');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/BusinessData', false)
            ->has('data.calendarConnection')
            ->has('data.calendarSettings')
            ->missing('data.calendarCredentials')
        );

        $content = $response->getContent();
        $this->assertStringNotContainsString('should-not-appear', $content);
        $this->assertStringNotContainsString('real-access-token-must-not-leak', $content);
        $this->assertStringNotContainsString('real-refresh-token-must-not-leak', $content);
        $this->assertStringNotContainsString('client_secret', $content);
        $this->assertStringNotContainsString('client_id', $content);
    }

    // ─── Test 5: calendarSettings only exposes safe fields ───────────────────

    public function test_calendar_settings_page_data_only_has_safe_fields(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('vendor-safe-fields');

        CalendarSetting::query()->create([
            'tenant_id' => $tenant->id,
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
            'rules'     => [
                'google_calendar' => ['max_events_per_date' => 4],
            ],
        ]);

        $response = $this->actingAs($admin)->get('/tenant/business-data?step=calendar');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.calendarSettings.max_events_per_date', 4)
            ->missing('data.calendarSettings.client_id')
            ->missing('data.calendarSettings.client_secret')
            ->missing('data.calendarSettings.client_secret_set')
            ->missing('data.calendarSettings.credentials_ready')
        );
    }

    // ─── Test 6: Tenant isolation ─────────────────────────────────────────────

    public function test_tenant_b_cannot_see_tenant_a_calendar_connection(): void
    {
        [$tenantA, $adminA] = $this->createTenantAdmin('vendor-alpha');
        [$tenantB, $adminB] = $this->createTenantAdmin('vendor-beta');

        CalendarConnection::query()->create([
            'tenant_id'  => $tenantA->id,
            'provider'   => 'google',
            'status'     => 'connected',
            'is_enabled' => true,
            'config'     => [
                'access_token'  => 'alpha-access-token',
                'refresh_token' => 'alpha-refresh-token',
                'token_expiry'  => time() + 3600,
                'calendar_id'   => 'alpha@gmail.com',
                'email'         => 'alpha@gmail.com',
            ],
        ]);

        $response = $this->actingAs($adminB)->get('/tenant/business-data?step=calendar');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringNotContainsString('alpha-access-token', $content);
        $this->assertStringNotContainsString('alpha-refresh-token', $content);
        $this->assertStringNotContainsString('alpha@gmail.com', $content);

        $response->assertInertia(fn (Assert $page) => $page
            ->where('data.calendarConnection.status', 'disconnected')
        );
    }

    // ─── Test 7: Missing app config returns clear error ───────────────────────

    public function test_connect_returns_error_when_google_client_id_not_configured(): void
    {
        Config::set('services.google.client_id', '');
        Config::set('services.google.client_secret', '');
        Config::set('services.google.redirect_uri', '');

        [$tenant, $admin] = $this->createTenantAdmin('vendor-no-config');

        $response = $this->actingAs($admin)->get('/tenant/calendar/connect');

        $response->assertRedirect('/tenant/business-data?step=calendar');
        $response->assertSessionHas('error');

        $error = $response->getSession()->get('error', '');
        $this->assertStringContainsString('dikonfigurasi', $error,
            'Error message should mention platform configuration.'
        );
    }

    // ─── Test 8: saveSettings only stores max_events_per_date, strips old creds

    public function test_save_settings_cleans_old_tenant_credentials_from_rules(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('vendor-cleanup');

        CalendarSetting::query()->create([
            'tenant_id' => $tenant->id,
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
            'rules'     => [
                'google_calendar' => [
                    'client_id'              => 'old-client-id',
                    'client_secret_encrypted' => 'old-encrypted-secret',
                    'max_events_per_date'    => 1,
                ],
            ],
        ]);

        $this->actingAs($admin)->post('/tenant/calendar/settings', [
            '_token'              => csrf_token(),
            'max_events_per_date' => 7,
        ])->assertRedirect('/tenant/business-data?step=calendar');

        $setting   = CalendarSetting::query()->where('tenant_id', $tenant->id)->first();
        $googleCfg = $setting->rules['google_calendar'] ?? [];

        $this->assertSame(7, $googleCfg['max_events_per_date']);
        $this->assertArrayNotHasKey('client_id', $googleCfg,
            'client_id must be removed from tenant rules after saveSettings.'
        );
        $this->assertArrayNotHasKey('client_secret_encrypted', $googleCfg,
            'client_secret_encrypted must be removed from tenant rules after saveSettings.'
        );
    }

    // ─── Test 9: OAuth callback rejects invalid/missing state ─────────────────

    public function test_callback_with_invalid_state_redirects_with_error(): void
    {
        Config::set('services.google.client_id', 'app-client-id');
        Config::set('services.google.client_secret', 'app-secret');
        Config::set('services.google.redirect_uri', 'http://localhost/tenant/calendar/callback');

        [$tenant, $admin] = $this->createTenantAdmin('vendor-state-check');

        // Callback arrives with a state that does NOT match anything in session.
        $response = $this->actingAs($admin)
            ->withSession(['google_oauth_state' => 'expected-nonce', 'google_oauth_tenant_id' => $tenant->id])
            ->get('/tenant/calendar/callback?code=some-code&state=wrong-nonce');

        $response->assertRedirect('/tenant/business-data?step=calendar');
        $response->assertSessionHas('error');
    }

    // ─── Test 10: old /tenant/calendar/credentials route is gone ─────────────

    public function test_old_credentials_route_does_not_exist(): void
    {
        [$tenant, $admin] = $this->createTenantAdmin('vendor-old-route');

        $response = $this->actingAs($admin)->post('/tenant/calendar/credentials', [
            '_token'    => csrf_token(),
            'client_id' => 'anything',
        ]);

        // Should be 404 (route removed) or 405 (method not allowed).
        $this->assertContains($response->getStatusCode(), [404, 405],
            'The old /tenant/calendar/credentials route must no longer exist.'
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createTenantAdmin(string $slug): array
    {
        $tenant = Tenant::query()->create([
            'name'      => ucfirst(str_replace('-', ' ', $slug)),
            'slug'      => $slug,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['role' => 'tenant_admin']);
        $admin->tenants()->attach($tenant->id);

        return [$tenant, $admin];
    }
}
