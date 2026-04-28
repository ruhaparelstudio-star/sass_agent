<?php

namespace App\Modules\DataKnowledge\Services;

use App\Models\BookingSetting;
use App\Modules\DataKnowledge\Support\KnowledgeWindowScope;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BookingLinkResolver
{
    public function __construct(
        private readonly KnowledgeWindowScope $windowScope,
        private readonly KnowledgeVersionResolver $knowledgeVersionResolver,
    ) {}

    public function resolveBookingLink(int $tenantId, CarbonInterface $at): ?array
    {
        $this->assertTenantId($tenantId);
        if ($this->knowledgeVersionResolver->resolveActiveVersion($tenantId, $at) === null) {
            return null;
        }

        $row = $this->windowScope
            ->apply(BookingSetting::query(), $tenantId, $at)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => $row->id,
            'booking_url' => $row->booking_url,
            'sort_order' => $row->sort_order,
        ];
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(422, 'Tenant id is required.');
        }
    }
}
