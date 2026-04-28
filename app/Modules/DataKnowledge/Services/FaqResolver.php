<?php

namespace App\Modules\DataKnowledge\Services;

use App\Models\Faq;
use App\Modules\DataKnowledge\Support\KnowledgeWindowScope;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FaqResolver
{
    public function __construct(
        private readonly KnowledgeWindowScope $windowScope,
        private readonly KnowledgeVersionResolver $knowledgeVersionResolver,
    ) {}

    public function resolveFaq(int $tenantId, CarbonInterface $at): array
    {
        $this->assertTenantId($tenantId);
        if ($this->knowledgeVersionResolver->resolveActiveVersion($tenantId, $at) === null) {
            return [];
        }

        $rows = $this->windowScope
            ->apply(Faq::query(), $tenantId, $at)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $rows->map(static fn (Faq $faq): array => [
            'id' => $faq->id,
            'question' => $faq->question,
            'answer' => $faq->answer,
            'sort_order' => $faq->sort_order,
        ])->values()->all();
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(422, 'Tenant id is required.');
        }
    }
}
