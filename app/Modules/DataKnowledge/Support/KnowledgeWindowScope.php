<?php

namespace App\Modules\DataKnowledge\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class KnowledgeWindowScope
{
    public function apply(Builder|Relation $query, int $tenantId, CarbonInterface $at): Builder|Relation
    {
        return $query
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('active_from')->orWhere('active_from', '<=', $at);
            })
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('active_until')->orWhere('active_until', '>=', $at);
            });
    }
}
