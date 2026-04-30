<?php

namespace App\Modules\Lead\Services;

use App\Models\LeadProfile;
use App\Models\LeadScore;
use App\Models\LeadSource;
use App\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LeadProfileService
{
    public function ensureLeadFoundation(Tenant $tenant, string $customerPhone): LeadProfile
    {
        $profile = $this->findOrCreateByPhone($tenant, $customerPhone);

        $this->ensureScoreSnapshot($profile, $tenant);
        $this->ensureSource($profile, $tenant);

        return $profile;
    }

    public function findOrCreateByPhone(Tenant $tenant, string $customerPhone): LeadProfile
    {
        $normalizedPhone = trim($customerPhone);

        if ($normalizedPhone === '') {
            throw new HttpException(422, 'Customer phone is required.');
        }

        return LeadProfile::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'customer_phone' => $normalizedPhone,
            ],
            [
                'full_name' => null,
            ]
        );
    }

    public function ensureScoreSnapshot(LeadProfile $profile, Tenant $tenant): LeadScore
    {
        $this->assertProfileTenantScope($profile, $tenant);

        return LeadScore::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'lead_profile_id' => $profile->id,
            ],
            [
                'score_value' => 0,
                'score_label' => 'unscored',
            ]
        );
    }

    public function ensureSource(LeadProfile $profile, Tenant $tenant, string $source = 'whatsapp'): LeadSource
    {
        $this->assertProfileTenantScope($profile, $tenant);

        $normalizedSource = trim($source);

        if ($normalizedSource === '') {
            throw new HttpException(422, 'Lead source is required.');
        }

        return LeadSource::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'lead_profile_id' => $profile->id,
            ],
            [
                'source' => $normalizedSource,
                'first_seen_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $entities
     */
    public function syncFromEntities(Tenant $tenant, string $customerPhone, array $entities): LeadProfile
    {
        $profile = $this->findOrCreateByPhone($tenant, $customerPhone);

        $candidateName = $entities['customer_name'] ?? ($entities['name'] ?? null);
        $normalizedName = is_string($candidateName) ? trim($candidateName) : '';
        $isCorrection = ($entities['is_correction'] ?? false) === true;
        $correctedFields = is_array($entities['corrected_fields'] ?? null) ? $entities['corrected_fields'] : [];
        $correctedName = in_array('customer_name', $correctedFields, true);

        if ($normalizedName !== '' && ($profile->full_name === null || trim((string) $profile->full_name) === '' || $isCorrection || $correctedName)) {
            $profile->full_name = $normalizedName;
            $profile->save();
        }

        return $profile;
    }

    private function assertProfileTenantScope(LeadProfile $profile, Tenant $tenant): void
    {
        if ((int) $profile->tenant_id !== (int) $tenant->id) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }
    }
}
