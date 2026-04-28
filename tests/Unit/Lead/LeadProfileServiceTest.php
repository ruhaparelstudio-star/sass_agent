<?php

namespace Tests\Unit\Lead;

use App\Models\Tenant;
use App\Modules\Lead\Services\LeadProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LeadProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_foundation_initializes_profile_score_and_source_once(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $service = app(LeadProfileService::class);

        $first = $service->ensureLeadFoundation($tenant, ' +628177777777 ');
        $second = $service->ensureLeadFoundation($tenant, '+628177777777');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('lead_profiles', 1);
        $this->assertDatabaseCount('lead_scores', 1);
        $this->assertDatabaseCount('lead_sources', 1);
    }

    public function test_cross_tenant_lead_score_write_is_blocked(): void
    {
        $tenantOne = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $tenantTwo = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $service = app(LeadProfileService::class);
        $profile = $service->ensureLeadFoundation($tenantOne, '+628166666666');

        $this->expectException(HttpException::class);
        $service->ensureScoreSnapshot($profile, $tenantTwo);
    }
}
