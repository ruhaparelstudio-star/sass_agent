<?php

namespace App\DTOs;

class TenantContextData
{
    /**
     * @param  array{wa_agent_limit:int,lead_limit:int,calendar_access:bool,automation_enabled:bool}|null  $featureGate
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $role,
        public readonly ?int $tenantId,
        public readonly ?array $featureGate = null,
    ) {
    }

    /**
     * @return array{user_id:int, role:string, tenant_id:int|null, feature_gate:array{wa_agent_limit:int,lead_limit:int,calendar_access:bool,automation_enabled:bool}|null}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'role' => $this->role,
            'tenant_id' => $this->tenantId,
            'feature_gate' => $this->featureGate,
        ];
    }
}
