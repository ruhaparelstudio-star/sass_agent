<?php

namespace Tests\Unit;

use App\Models\ActivationToken;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Activation\Services\ActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivationTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_hash_is_stored_and_status_transitions_are_deterministic(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $issuer = User::factory()->create(['role' => 'superadmin']);

        $service = app(ActivationService::class);
        $issued = $service->issueToken($issuer, $tenant, 'unit@example.com');

        $row = ActivationToken::query()->firstOrFail();
        $this->assertNotSame($issued['token'], $row->token_hash);
        $this->assertSame(hash('sha256', $issued['token']), $row->token_hash);

        $this->assertSame('valid', $service->verifyToken($issued['token'], 'unit@example.com')['status']);

        $row->update(['expires_at' => now()->subSecond()]);
        $this->assertSame('expired', $service->verifyToken($issued['token'], 'unit@example.com')['status']);

        $row->update([
            'expires_at' => now()->addHour(),
            'used_at' => now(),
        ]);
        $this->assertSame('used', $service->verifyToken($issued['token'], 'unit@example.com')['status']);
    }
}

